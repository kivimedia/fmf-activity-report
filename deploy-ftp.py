"""
Deploy the fmf-activity-report plugin to theprofitableflorist.com.

Two transports, one shared file list:
  SFTP (preferred - encrypted)  needs FMF_SFTP_HOST/USER and PASS or KEY
  FTP  (legacy, plaintext)      needs FMF_FTP_HOST/USER/PASS

Picks SFTP automatically when FMF_SFTP_HOST is set, otherwise FTP. Force either
with --sftp / --ftp. Reads creds from .fmf-deploy.env (gitignored) or the
environment. Use --dry-run to see exactly what would upload, with no connection.

Run from the repo root:  py deploy-ftp.py            # auto-detect transport
                         py deploy-ftp.py --dry-run  # no network, just the plan
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

INCLUDE_DIRS = {'includes', 'templates', 'templates/emails', 'templates/partials', 'assets', 'assets/css'}
SKIP_TOPLEVEL = {'docs', 'plans', '__pycache__', '.git', '.github', '.idea', '.vscode'}
SKIP_FILES = {'deploy-ftp.py', '.fmf-deploy.env', '.gitignore'}

# Where wp-content/plugins lives. The FTP account lands in a dir containing the
# domain folder; an SFTP/SSH account usually lands in $HOME instead, so the two
# need different defaults. Override either with FMF_REMOTE_PLUGINS_DIR.
FTP_PLUGINS_DIR = 'theprofitableflorist.com/public_html/wp-content/plugins'
SFTP_PLUGINS_CANDIDATES = [
    'theprofitableflorist.com/public_html/wp-content/plugins',
    'public_html/wp-content/plugins',
    'domains/theprofitableflorist.com/public_html/wp-content/plugins',
    'www/wp-content/plugins',
    '/var/www/html/wp-content/plugins',
]


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
    if any(p == '__pycache__' for p in parts):
        return True
    if rel_posix.endswith(('.pyc', '.pyo')):
        return True
    if parts[0].startswith('.'):
        return True
    return False


def collect_files():
    """The single source of truth for what gets deployed, shared by both transports."""
    out = []
    for root, dirs, files in os.walk(REPO_ROOT):
        rel_root = Path(root).relative_to(REPO_ROOT).as_posix()
        if rel_root == '.':
            rel_root = ''
        dirs[:] = [d for d in dirs if not (rel_root == '' and d in SKIP_TOPLEVEL)
                   and not (rel_root == '' and d.startswith('.'))]
        for filename in files:
            rel = (Path(rel_root) / filename).as_posix() if rel_root else filename
            if should_skip(rel) or rel in SKIP_FILES:
                continue
            out.append(rel)
    return sorted(out)


def remote_dirs_needed(files):
    """Every dir the file list implies, parents before children."""
    needed = set()
    for rel in files:
        parent = Path(rel).parent.as_posix()
        if parent != '.':
            parts = parent.split('/')
            for i in range(1, len(parts) + 1):
                needed.add('/'.join(parts[:i]))
    return sorted(needed | INCLUDE_DIRS, key=lambda s: s.count('/'))


def deploy_ftp(files):
    host = require('FMF_FTP_HOST')
    user = require('FMF_FTP_USER')
    password = require('FMF_FTP_PASS')

    print(f"Connecting to {host} as {user} over FTP (plaintext)...")
    ftp = ftplib.FTP(host)
    ftp.login(user, password)
    ftp.set_pasv(True)

    ftp.cwd(os.environ.get('FMF_REMOTE_PLUGINS_DIR', FTP_PLUGINS_DIR))
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
    for sub in remote_dirs_needed(files):
        ensure_dir(f"{remote_base}/{sub}")

    for i, rel in enumerate(files, 1):
        with open(REPO_ROOT / rel, 'rb') as fh:
            ftp.storbinary(f'STOR {remote_base}/{rel}', fh)
        print(f'[{i}/{len(files)}] Uploaded: {rel}')

    ftp.quit()
    return remote_base


def deploy_sftp(files):
    try:
        import paramiko
    except ImportError:
        sys.exit("paramiko is required for SFTP. Install it with: py -m pip install paramiko")

    host = require('FMF_SFTP_HOST')
    user = require('FMF_SFTP_USER')
    port = int(os.environ.get('FMF_SFTP_PORT', '22'))
    password = os.environ.get('FMF_SFTP_PASS')
    key_path = os.environ.get('FMF_SFTP_KEY')
    if not password and not key_path:
        sys.exit("Need FMF_SFTP_PASS or FMF_SFTP_KEY (path to a private key).")

    print(f"Connecting to {host}:{port} as {user} over SFTP...")
    client = paramiko.SSHClient()
    client.load_system_host_keys()
    # Accept an unknown host key: this is a one-off deploy to a known hostname
    # over an encrypted channel, and there is no pinned key to compare against.
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    connect_args = {'hostname': host, 'port': port, 'username': user, 'timeout': 30}
    if key_path:
        connect_args['key_filename'] = os.path.expanduser(key_path)
        if os.environ.get('FMF_SFTP_KEY_PASS'):
            connect_args['passphrase'] = os.environ['FMF_SFTP_KEY_PASS']
    else:
        connect_args['password'] = password
        connect_args['look_for_keys'] = False
        connect_args['allow_agent'] = False
    client.connect(**connect_args)
    sftp = client.open_sftp()

    def exists(path):
        try:
            sftp.stat(path)
            return True
        except IOError:
            return False

    plugins_dir = os.environ.get('FMF_REMOTE_PLUGINS_DIR')
    if plugins_dir:
        if not exists(plugins_dir):
            sys.exit(f"FMF_REMOTE_PLUGINS_DIR does not exist on the server: {plugins_dir}")
    else:
        home = sftp.normalize('.')
        print('Login dir:', home)
        for cand in SFTP_PLUGINS_CANDIDATES:
            probe = cand if cand.startswith('/') else f"{home}/{cand}"
            if exists(probe):
                plugins_dir = probe
                break
        if not plugins_dir:
            sys.exit(
                "Could not find wp-content/plugins. Tried:\n  "
                + "\n  ".join(SFTP_PLUGINS_CANDIDATES)
                + f"\n(relative to {home})\nSet FMF_REMOTE_PLUGINS_DIR to the absolute path."
            )
    print('Plugins dir:', plugins_dir)

    remote_base = f"{plugins_dir}/{PLUGIN_SLUG}"
    if not exists(remote_base):
        # Guard against creating a brand-new (and therefore INACTIVE) plugin dir
        # because of a typo in PLUGIN_SLUG or a wrong plugins path.
        print(f"\n!! {remote_base} does not exist on the server.")
        print("   The active plugin dir should already be there. Refusing to create a")
        print("   new one - that would deploy to a directory WordPress is not loading.")
        print("   Existing plugin dirs:")
        for name in sorted(sftp.listdir(plugins_dir)):
            print(f"     {name}")
        sys.exit(1)

    def ensure_dir(path):
        if not exists(path):
            sftp.mkdir(path)
            print(f'Created: {path}')

    for sub in remote_dirs_needed(files):
        ensure_dir(f"{remote_base}/{sub}")

    for i, rel in enumerate(files, 1):
        sftp.put(str(REPO_ROOT / rel), f"{remote_base}/{rel}")
        print(f'[{i}/{len(files)}] Uploaded: {rel}')

    sftp.close()
    client.close()
    return remote_base


def main():
    args = set(sys.argv[1:])
    load_env()
    files = collect_files()

    if '--dry-run' in args:
        print(f"DRY RUN - nothing will be uploaded.\n")
        print(f"Target dir: <plugins>/{PLUGIN_SLUG}")
        print(f"\nRemote dirs to ensure ({len(remote_dirs_needed(files))}), parents first:")
        for sub in remote_dirs_needed(files):
            print(f"    {sub}")
        print(f"\n{len(files)} files would upload:")
        for rel in files:
            print(f"    {rel}")
        return

    if '--ftp' in args:
        use_sftp = False
    elif '--sftp' in args:
        use_sftp = True
    else:
        use_sftp = bool(os.environ.get('FMF_SFTP_HOST'))

    if not use_sftp and not os.environ.get('FMF_FTP_HOST'):
        sys.exit(
            f"No credentials found. Add either an SFTP block (preferred) or an FTP\n"
            f"block to {ENV_FILE}:\n\n"
            "  FMF_SFTP_HOST=...\n  FMF_SFTP_USER=...\n  FMF_SFTP_PASS=...\n"
            "  # FMF_SFTP_PORT=22            (optional)\n"
            "  # FMF_SFTP_KEY=~/.ssh/id_ed25519   (instead of PASS)\n"
            "  # FMF_REMOTE_PLUGINS_DIR=...  (optional, if auto-detect fails)\n"
        )

    remote_base = deploy_sftp(files) if use_sftp else deploy_ftp(files)
    print(f'\nDone - {len(files)} files uploaded to {remote_base}')
    print('Verify: /wp-json/fmf/v1/diagnose should report version 1.4.0')


if __name__ == '__main__':
    main()
