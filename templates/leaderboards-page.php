<?php
/**
 * "Leaderboards" admin page - the full, untruncated rankings behind the Top 10
 * lists in Tim's roll-up email.
 *
 * Expects: $rollup (FMF_Report_Builder::build_program_rollup() output),
 *          $public_url, $build_seconds
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$lb_shops_week       = $rollup['top_shops_week'];
$lb_lessons_week     = $rollup['top_lessons_week'];
$lb_shops_alltime    = $rollup['top_shops_alltime'];
$lb_lessons_alltime  = $rollup['top_lessons_alltime'];
$lb_week_start_label = $rollup['week_start_label'];
$lb_week_end_label   = $rollup['week_end_label'];
?>
<div class="wrap fmf-wrap">
  <h1>Leaderboards</h1>
  <p class="description" style="max-width:760px;">
    Every shop and every class, ranked in full. The roll-up email only has room for the
    top <?php echo intval( FMF_Mailer::ROLLUP_LEADERBOARD_LIMIT ); ?> of each &mdash; this is the rest of the list.
  </p>

  <section class="fmf-panel" style="margin-top:20px;">
    <?php include FMF_PLUGIN_DIR . 'templates/partials/leaderboard-tables.php'; ?>
  </section>

  <section class="fmf-panel" style="margin-top:24px;">
    <h2>Shareable link</h2>
    <p class="description">
      This is the link in the roll-up email's &ldquo;see the full list&rdquo; button. It needs no
      login, so it works for Tim and anyone else who only ever sees the email. It shows
      these rankings only &mdash; never the per-person &ldquo;who watched what&rdquo; names.
    </p>
    <p>
      <input type="text" readonly value="<?php echo esc_attr( $public_url ); ?>"
        onclick="this.select();" style="width:100%;max-width:720px;font-family:monospace;font-size:12px;padding:6px 8px;">
    </p>
    <p class="description">
      <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener">Open it in a new tab &rarr;</a>
      &nbsp;&middot;&nbsp; The link is stable &mdash; it keeps working week to week.
    </p>
  </section>

  <?php if ( null !== $build_seconds ) : ?>
  <p class="description" style="margin-top:16px;color:#999;">
    Built in <?php echo esc_html( number_format( $build_seconds, 1 ) ); ?>s
    across <?php echo intval( $rollup['shops_total'] ); ?> shops.
  </p>
  <?php endif; ?>
</div>
