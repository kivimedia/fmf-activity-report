<?php
/**
 * Weekly activity report email body.
 *
 * Receives:
 *  $group, $leader,
 *  $week_start_label, $week_end_label,
 *  $members_with_activity, $members_without_activity,
 *  $total_completions, $total_members, $has_activity,
 *  $course_url, $unsubscribe_url, $is_test
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$brand_purple = '#5b3fa5';
$brand_cream  = '#fdf8ee';
$brand_ink    = '#2a2740';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your 15 Minute Florist team activity</title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $brand_cream ); ?>;font-family:Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $brand_ink ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( $brand_cream ); ?>;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">

        <?php if ( $is_test ) : ?>
        <tr><td style="background:#fff3cd;color:#664d03;padding:10px 20px;font-size:12px;text-align:center;">TEST EMAIL - this is a preview, not a real weekly report.</td></tr>
        <?php endif; ?>

        <tr>
          <td style="background:<?php echo esc_attr( $brand_purple ); ?>;padding:28px 32px;color:#fff;">
            <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;margin-bottom:8px;">The 15 Minute Florist - Weekly Activity</div>
            <div style="font-size:26px;font-weight:700;line-height:1.25;">Your team's week of <?php echo esc_html( $week_start_label ); ?></div>
            <div style="font-size:14px;opacity:.95;margin-top:10px;"><?php echo esc_html( $group['title'] ); ?></div>
          </td>
        </tr>

        <tr>
          <td style="padding:28px 32px 6px;">
            <p style="margin:0 0 14px;font-size:16px;line-height:1.55;">Hi <?php echo esc_html( $leader['display_name'] ?: 'there' ); ?>,</p>
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Here's what your team watched in The 15 Minute Florist last week (<?php echo esc_html( $week_start_label ); ?> - <?php echo esc_html( $week_end_label ); ?>).</p>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px;border-collapse:separate;border-spacing:8px 0;">
              <tr>
                <td style="background:<?php echo esc_attr( $brand_cream ); ?>;border-radius:8px;padding:12px 18px;">
                  <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#766;">Total lessons watched</div>
                  <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo intval( $total_completions ); ?></div>
                </td>
                <td style="background:<?php echo esc_attr( $brand_cream ); ?>;border-radius:8px;padding:12px 18px;">
                  <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#766;">Active staff</div>
                  <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo count( $members_with_activity ); ?> of <?php echo intval( $total_members ); ?></div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <?php if ( ! empty( $members_with_activity ) ) : ?>
        <tr>
          <td style="padding:0 32px 8px;">
            <h3 style="margin:18px 0 10px;font-size:16px;color:<?php echo esc_attr( $brand_purple ); ?>;">Who watched what</h3>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eee;border-radius:8px;overflow:hidden;">
              <?php foreach ( $members_with_activity as $row ) :
                $u = $row['user'];
              ?>
                <tr>
                  <td style="padding:14px 16px;border-bottom:1px solid #f1edea;vertical-align:top;">
                    <div style="font-size:14px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo esc_html( $u['display_name'] ); ?></div>
                    <div style="font-size:12px;color:#888;margin-top:2px;"><?php echo intval( $row['lesson_count'] ); ?> lesson<?php echo $row['lesson_count'] === 1 ? '' : 's'; ?> - last active <?php echo esc_html( $row['last_at'] ); ?></div>
                    <ul style="margin:8px 0 0 18px;padding:0;font-size:13px;color:#444;line-height:1.55;">
                      <?php foreach ( $row['lessons'] as $lesson ) : ?>
                        <li><?php echo esc_html( $lesson['lesson_title'] ); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $members_without_activity ) ) : ?>
        <tr>
          <td style="padding:0 32px 8px;">
            <h3 style="margin:22px 0 8px;font-size:15px;color:#a43a3a;">No activity this week</h3>
            <p style="margin:0 0 8px;font-size:13px;color:#666;line-height:1.55;">These team members didn't watch anything in the last 7 days. A quick nudge can re-engage them.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #f4e4e4;border-radius:8px;overflow:hidden;background:#fdf6f6;">
              <?php foreach ( $members_without_activity as $u ) : ?>
                <tr>
                  <td style="padding:10px 14px;border-bottom:1px solid #f4e4e4;font-size:13px;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo esc_html( $u['display_name'] ); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>
        <?php endif; ?>

        <tr>
          <td align="center" style="padding:28px 32px 8px;">
            <a href="<?php echo esc_url( $course_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $brand_purple ); ?>;color:#fff;text-decoration:none;padding:14px 26px;border-radius:6px;font-weight:600;font-size:15px;">
              Open The 15 Minute Florist
            </a>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 24px;text-align:center;font-size:12px;color:#9a8a8a;line-height:1.6;">
            You're getting this because your team is enrolled in The 15 Minute Florist at <a href="https://theprofitableflorist.com" style="color:#9a8a8a;">theprofitableflorist.com</a>.<br>
            <a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#9a8a8a;">Stop these weekly reports for this group</a>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
