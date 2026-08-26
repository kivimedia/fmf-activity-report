<?php
/**
 * Standalone, tokenised public leaderboard page - the destination of the roll-up
 * email's "see the full list" button, for recipients with no wp-admin account.
 *
 * Deliberately self-contained (no theme, no admin CSS) and deliberately limited
 * to the AGGREGATE rankings: shop titles with their counts, class titles with
 * theirs. The roll-up email's per-person "who watched what" section is NOT
 * reproduced here - those are named individuals, and this URL needs no login.
 *
 * Expects: $rollup, $lb_*
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$lb_shops_week       = $rollup['top_shops_week'];
$lb_lessons_week     = $rollup['top_lessons_week'];
$lb_shops_alltime    = $rollup['top_shops_alltime'];
$lb_lessons_alltime  = $rollup['top_lessons_alltime'];
$lb_week_start_label = $rollup['week_start_label'];
$lb_week_end_label   = $rollup['week_end_label'];

$pub_purple = '#5b3fa5';
$pub_cream  = '#fdf8ee';
$pub_ink    = '#2a2740';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Leaderboards - The 15 Minute Florist</title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $pub_cream ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $pub_ink ); ?>;">
  <div style="max-width:900px;margin:0 auto;padding:0 0 40px;">

    <div style="background:<?php echo esc_attr( $pub_purple ); ?>;color:#fff;padding:28px 32px;">
      <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;margin-bottom:8px;">The 15 Minute Florist - Program Roll-up</div>
      <div style="font-size:26px;font-weight:700;line-height:1.25;">Leaderboards</div>
      <div style="font-size:14px;opacity:.95;margin-top:8px;">Every shop and every class, ranked in full.</div>
    </div>

    <div style="background:#fff;padding:26px 32px;">
      <?php include FMF_PLUGIN_DIR . 'templates/partials/leaderboard-tables.php'; ?>
    </div>

    <p style="text-align:center;font-size:12px;color:#9a8a8a;line-height:1.6;margin:22px 32px 0;">
      Program roll-up for The 15 Minute Florist at
      <a href="https://theprofitableflorist.com" style="color:#9a8a8a;">theprofitableflorist.com</a>.<br>
      Rankings only &mdash; individual names stay in the weekly email.
    </p>

  </div>
</body>
</html>
