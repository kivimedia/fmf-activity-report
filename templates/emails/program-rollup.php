<?php
/**
 * Program-wide weekly roll-up email body (for Tim / office).
 *
 * Receives:
 *  $week_start_label, $week_end_label,
 *  $shops, $shops_active, $shops_total, $people_active, $lessons_total, $shops_silent,
 *  $top_shops_week, $top_lessons_week, $top_shops_alltime, $top_lessons_alltime,
 *  $admin_url, $is_test
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
<title>15 Minute Florist - Weekly Program Roll-up</title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $brand_cream ); ?>;font-family:Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $brand_ink ); ?>;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( $brand_cream ); ?>;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">

        <?php if ( $is_test ) : ?>
        <tr><td style="background:#fff3cd;color:#664d03;padding:10px 20px;font-size:12px;text-align:center;">TEST EMAIL - this is a preview, not the real weekly roll-up.</td></tr>
        <?php endif; ?>

        <tr>
          <td style="background:<?php echo esc_attr( $brand_purple ); ?>;padding:28px 32px;color:#fff;">
            <div style="font-size:11px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;margin-bottom:8px;">The 15 Minute Florist - Program Roll-up</div>
            <div style="font-size:26px;font-weight:700;line-height:1.25;">Program activity - week of <?php echo esc_html( $week_start_label ); ?></div>
            <div style="font-size:14px;opacity:.95;margin-top:10px;">All shops &middot; <?php echo esc_html( $week_start_label ); ?> - <?php echo esc_html( $week_end_label ); ?></div>
          </td>
        </tr>

        <tr>
          <td style="padding:28px 32px 6px;">
            <p style="margin:0 0 14px;font-size:16px;line-height:1.55;">Hi Tim,</p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Here's everyone across every shop who watched something in The 15 Minute Florist last week - owners and staff alike.</p>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px;border-collapse:separate;border-spacing:8px 0;width:100%;">
              <tr>
                <td style="background:<?php echo esc_attr( $brand_cream ); ?>;border-radius:8px;padding:12px 16px;width:33%;">
                  <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#766;">Shops active</div>
                  <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo intval( $shops_active ); ?> <span style="font-size:14px;font-weight:600;color:#998;">of <?php echo intval( $shops_total ); ?></span></div>
                </td>
                <td style="background:<?php echo esc_attr( $brand_cream ); ?>;border-radius:8px;padding:12px 16px;width:33%;">
                  <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#766;">People active</div>
                  <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo intval( $people_active ); ?></div>
                </td>
                <td style="background:<?php echo esc_attr( $brand_cream ); ?>;border-radius:8px;padding:12px 16px;width:33%;">
                  <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#766;">Lessons watched</div>
                  <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo intval( $lessons_total ); ?></div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <?php if ( ! empty( $shops ) ) : ?>
        <tr>
          <td style="padding:0 32px 8px;">
            <h3 style="margin:14px 0 12px;font-size:16px;color:<?php echo esc_attr( $brand_purple ); ?>;">Who watched what, by shop</h3>

            <?php foreach ( $shops as $shop ) : ?>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eee;border-radius:8px;overflow:hidden;margin-bottom:14px;">
              <tr>
                <td style="background:#f7f4fb;padding:12px 16px;border-bottom:1px solid #ece6f5;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="font-size:15px;font-weight:700;color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo esc_html( $shop['title'] ); ?></td>
                    <td align="right" style="font-size:12px;color:#766;white-space:nowrap;"><?php echo intval( $shop['people_total'] ); ?> <?php echo intval( $shop['people_total'] ) === 1 ? 'person' : 'people'; ?> &middot; <?php echo intval( $shop['lesson_total'] ); ?> lesson<?php echo intval( $shop['lesson_total'] ) === 1 ? '' : 's'; ?></td>
                  </tr></table>
                </td>
              </tr>
              <tr><td style="padding:6px 16px 12px;">
                <?php
                $last_idx = count( $shop['watchers'] ) - 1;
                foreach ( $shop['watchers'] as $i => $w ) :
                  $border = $i === $last_idx ? '' : 'border-bottom:1px solid #f4f2f7;';
                ?>
                <div style="font-size:13px;color:<?php echo esc_attr( $brand_ink ); ?>;padding:6px 0;<?php echo $border; ?>"><?php echo esc_html( $w['display_name'] ); ?><?php if ( ! empty( $w['is_leader'] ) ) : ?> <span style="font-size:10px;font-weight:700;color:<?php echo esc_attr( $brand_purple ); ?>;background:#efeaf9;border-radius:10px;padding:1px 7px;letter-spacing:.04em;">OWNER</span><?php endif; ?> <span style="color:#888;">- <?php echo intval( $w['lesson_count'] ); ?> lesson<?php echo intval( $w['lesson_count'] ) === 1 ? '' : 's'; ?><?php if ( ! empty( $w['last_at'] ) ) : ?>, last <?php echo esc_html( $w['last_at'] ); ?><?php endif; ?></span></div>
                <?php endforeach; ?>
              </td></tr>
            </table>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php else : ?>
        <tr>
          <td style="padding:0 32px 8px;">
            <div style="background:#fdf6f6;border:1px solid #f4e4e4;border-radius:8px;padding:16px;font-size:14px;color:#a43a3a;">No one watched anything in any shop last week.</div>
          </td>
        </tr>
        <?php endif; ?>

        <?php
        /**
         * Renders a compact ranked leaderboard table (This week / All time pair).
         * $rows: list of ['label'=>string, 'metric'=>string(pre-escaped HTML)].
         */
        $fmf_render_leaderboard = function( $rows, $window_label ) use ( $brand_ink, $brand_purple ) {
            if ( empty( $rows ) ) {
                return;
            }
            ?>
            <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#998;margin:10px 0 6px;"><?php echo esc_html( $window_label ); ?></div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #eee;border-radius:8px;overflow:hidden;margin-bottom:12px;">
              <?php $rank = 0; $last = count( $rows ) - 1; foreach ( $rows as $i => $r ) : $rank++; $border = $i === $last ? '' : 'border-bottom:1px solid #f4f2f7;'; ?>
              <tr>
                <td width="28" style="padding:8px 4px 8px 14px;font-size:13px;font-weight:700;color:<?php echo esc_attr( $brand_purple ); ?>;<?php echo $border; ?>"><?php echo intval( $rank ); ?></td>
                <td style="padding:8px 8px;font-size:13px;color:<?php echo esc_attr( $brand_ink ); ?>;<?php echo $border; ?>"><?php echo esc_html( $r['label'] ); ?></td>
                <td align="right" style="padding:8px 14px 8px 8px;font-size:12px;color:#766;white-space:nowrap;<?php echo $border; ?>"><?php echo $r['metric']; // pre-escaped ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php
        };

        // Shape shop rows: "N lessons . M people".
        $fmf_shop_rows = function( $shops_list ) {
            $out = array();
            foreach ( $shops_list as $s ) {
                $lessons = intval( $s['lesson_total'] );
                $people  = intval( $s['people_total'] );
                $metric  = esc_html( $lessons . ' lesson' . ( 1 === $lessons ? '' : 's' ) )
                    . ' <span style="color:#bbb;">&middot;</span> '
                    . esc_html( $people . ' ' . ( 1 === $people ? 'person' : 'people' ) );
                $out[] = array( 'label' => $s['title'], 'metric' => $metric );
            }
            return $out;
        };

        // Shape lesson rows: "N watches".
        $fmf_lesson_rows = function( $lessons_list ) {
            $out = array();
            foreach ( $lessons_list as $l ) {
                $c = intval( $l['count'] );
                $out[] = array(
                    'label'  => $l['title'],
                    'metric' => esc_html( $c . ' watch' . ( 1 === $c ? '' : 'es' ) ),
                );
            }
            return $out;
        };
        ?>

        <?php if ( ! empty( $top_shops_week ) || ! empty( $top_shops_alltime ) ) : ?>
        <tr>
          <td style="padding:8px 32px 0;">
            <h3 style="margin:14px 0 4px;font-size:16px;color:<?php echo esc_attr( $brand_purple ); ?>;">Top 10 most active shops</h3>
            <?php
            $fmf_render_leaderboard( $fmf_shop_rows( $top_shops_week ), 'This week' );
            $fmf_render_leaderboard( $fmf_shop_rows( $top_shops_alltime ), 'All time' );
            ?>
          </td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $top_lessons_week ) || ! empty( $top_lessons_alltime ) ) : ?>
        <tr>
          <td style="padding:8px 32px 0;">
            <h3 style="margin:14px 0 4px;font-size:16px;color:<?php echo esc_attr( $brand_purple ); ?>;">Top 10 classes watched</h3>
            <?php
            $fmf_render_leaderboard( $fmf_lesson_rows( $top_lessons_week ), 'This week' );
            $fmf_render_leaderboard( $fmf_lesson_rows( $top_lessons_alltime ), 'All time' );
            ?>
          </td>
        </tr>
        <?php endif; ?>

        <?php if ( $shops_silent > 0 ) : ?>
        <tr>
          <td style="padding:8px 32px 6px;">
            <div style="background:#faf9f6;border:1px solid #eee;border-radius:8px;padding:12px 16px;font-size:13px;color:#766;line-height:1.5;">
              <strong style="color:<?php echo esc_attr( $brand_ink ); ?>;"><?php echo intval( $shops_silent ); ?> of <?php echo intval( $shops_total ); ?> shops had no activity this week.</strong> These are the teams to nudge - nobody there watched anything in the last 7 days.
            </div>
          </td>
        </tr>
        <?php endif; ?>

        <tr>
          <td align="center" style="padding:20px 32px 8px;">
            <a href="<?php echo esc_url( $admin_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $brand_purple ); ?>;color:#fff;text-decoration:none;padding:14px 26px;border-radius:6px;font-weight:600;font-size:15px;">
              Open the admin dashboard
            </a>
          </td>
        </tr>

        <tr>
          <td style="padding:8px 32px 24px;text-align:center;font-size:12px;color:#9a8a8a;line-height:1.6;">
            Program roll-up for The 15 Minute Florist at <a href="https://theprofitableflorist.com" style="color:#9a8a8a;">theprofitableflorist.com</a>.<br>
            Sent every Monday alongside the per-shop reports.
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
