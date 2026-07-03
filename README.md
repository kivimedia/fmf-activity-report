# 15 Minute Florist - Weekly Activity Report

WordPress plugin for theprofitableflorist.com. Sends a weekly Monday-morning
email to each LifterLMS Group leader summarising which staff watched which
workshop in The 15 Minute Florist over the prior 7 days.

Built for Tim Huckabee. Promised on the 2026-05-07 Fathom call as "sales
dynamite" - the missing accountability layer that makes shop owners feel the
program is paying off.

## What it does

- Iterates every LifterLMS Group attached to course 5685 (The 15 Minute Florist).
- Filters to groups with 2+ student members AND at least one leader.
- For each qualifying group, computes lesson completions in the prior week.
- Emails the leader an HTML report listing per-staff lesson activity, with a
  highlighted "no activity this week" sub-list to surface inactive staff.
- Idempotent: per-(group, week) sent log prevents double-sends.
- Each email contains a one-click HMAC-signed unsubscribe URL that pauses
  reports for that group only.
- Also emails Tim + office a program-wide weekly roll-up: everyone across all
  shops (owners AND staff) who watched anything last week, grouped by shop,
  most-active first, with a count of the shops that had no activity. Sent once
  per week alongside the per-shop reports; own idempotency key.

## Architecture

- `fmf-activity-report.php` - bootstrap, constants, hook registration
- `includes/class-fmf-lifterlms-reader.php` - data adapter (groups, members, completions)
- `includes/class-fmf-report-builder.php` - shapes raw data into per-group reports
- `includes/class-fmf-mailer.php` - renders + sends emails via wp_mail()
- `includes/class-fmf-cron.php` - weekly WP-Cron event + manual run entry point
- `includes/class-fmf-admin.php` - settings page + per-group toggles + send-test
- `includes/class-fmf-rest.php` - REST endpoints (diagnose, run-weekly, unsub)
- `templates/emails/weekly-report.php` - HTML email template
- `templates/emails/program-rollup.php` - program-wide roll-up email template (for Tim)
- `templates/admin-page.php` - admin UI

## Deploy

```
python deploy-ftp.py
```

Reads creds from `.fmf-deploy.env` (gitignored). Bump `FMF_VERSION` in
`fmf-activity-report.php` before each deploy so cached CSS busts.

## Activate (first deploy only)

```
curl -u "USER:APP_PASSWORD" \
  -X POST "https://theprofitableflorist.com/wp-json/wp/v2/plugins/fmf-activity-report/fmf-activity-report" \
  -H "Content-Type: application/json" -d '{"status":"active"}'
```

## Verify

```
curl -u "USER:APP_PASSWORD" \
  "https://theprofitableflorist.com/wp-json/fmf/v1/diagnose"
```

Returns LifterLMS state, group counts, qualifying-team count, lesson count,
sample groups (with their leaders + member counts), and last-run summary.

## External cron trigger

If WP-Cron is unreliable, hit the REST endpoint with the per-install token
shown in the admin page footer:

```
curl -X POST 'https://theprofitableflorist.com/wp-json/fmf/v1/run-weekly' \
     -H 'x-fmf-token: <token>'
```
