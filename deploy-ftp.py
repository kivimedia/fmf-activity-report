"""
Deploy the fmf-activity-report plugin to theprofitableflorist.com via FTP.

Reads creds from .fmf-deploy.env (gitignored) or environment.
Run from the repo root: python deploy-ftp.py
"""
import ftplib
import os
import sys
from pathlib import Path

REPO_ROOT  = Path(__file__).resolve().parent
ENV_FILE   = REPO_ROOT / '.fmf-deploy.env'
# The ACTIVE plugin on theprofitableflorist.com lives in `fmf-activity-report-main`
# (installed from a GitHub ZIP/clone - note the `-main` suffix), NOT plain
# `fmf-activity-report` (that dir exists too but is INACTIVE). Deploying to the
# wrong dir silently no-ops the live site. Verify after every deploy via
# /wp-json/wp/v2/plugins (which dir is `active`) + /wp-json/fmf/v1/diagnose version.
PLUGIN_SLUG = 'fmf-activity-report-main'

INCLUDE_DIRS = {'includes', 'templates', 'templates/emails', 'assets', 'assets/css'}
SKIP_TOPLEVEL = {'docs', 'plans', '.git', '.github', '.idea', '.vscode'}


def load_env():
    if not ENV_FILE.exists():
        return
    for raw in ENV_FILE.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, _, value = line.partition('=')
        os.environ.setdefault(key.strip(), value.strip())


def require(name):
    val = os.environ.get(name)
    if not val:
        sys.exit(f"Missing {name}. Add it to {ENV_FILE} or export it.")
    return val


def should_skip(rel_posix: str) -> bool:
    parts = rel_posix.split('/')
    if parts[0] in SKIP_TOPLEVEL:
        return True
    if parts[0].startswith('.'):
        return True
    return False


def main():
    load_env()
    host = require('FMF_FTP_HOST')
    user = require('FMF_FTP_USER')
    password = require('FMF_FTP_PASS')

    print(f"Connecting to {host} as {user}...")
    ftp = ftplib.FTP(host)
    ftp.login(user, password)
    ftp.set_pasv(True)

    ftp.cwd('theprofitableflorist.com/public_html/wp-content/plugins')
    plugins_dir = ftp.pwd()
    print('Plugins dir:', plugins_dir)

    remote_base = f"{plugins_dir}/{PLUGIN_SLUG}"

    def ensure_dir(path):
        try:
            ftp.mkd(path)
            print(f'Created: {path}')
        except ftplib.error_perm:
            pass

    ensure_dir(remote_base)
    for sub in sorted(INCLUDE_DIRS, key=lambda s: s.count('/')):
        ensure_dir(f"{remote_base}/{sub}")

    uploaded = 0
    for root, dirs, files in os.walk(REPO_ROOT):
        rel_root = Path(root).relative_to(REPO_ROOT).as_posix()
        if rel_root == '.':
            rel_root = ''
        dirs[:] = [d for d in dirs if not (rel_root == '' and d in SKIP_TOPLEVEL)
                   and not (rel_root == '' and d.startswith('.'))]
        for filename in files:
            rel = (Path(rel_root) / filename).as_posix() if rel_root else filename
            if should_skip(rel):
                continue
            if rel in {'deploy-ftp.py', '.fmf-deploy.env', '.gitignore'}:
                continue
            local_path = Path(root) / filename
            remote_path = f"{remote_base}/{rel}"
            with open(local_path, 'rb') as fh:
                ftp.storbinary(f'STOR {remote_path}', fh)
            uploaded += 1
            print(f'[{uploaded}] Uploaded: {rel}')

    ftp.quit()
    print(f'Done - {uploaded} files uploaded to {remote_base}')


if __name__ == '__main__':
    main()
