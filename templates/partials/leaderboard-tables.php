<?php
/**
 * Shared full-leaderboard renderer - the ONE place the ranked tables are built.
 *
 * Used by both entry points so they can never drift apart:
 *   - templates/leaderboards-page.php  (wp-admin, manage_options)
 *   - templates/leaderboard-public.php (tokenised no-login view for Tim)
 *
 * Unlike the roll-up email, nothing here is trimmed - these are the complete
 * lists straight from FMF_Report_Builder::build_program_rollup().
 *
 * Expects:
 *   $lb_shops_week, $lb_lessons_week, $lb_shops_alltime, $lb_lessons_alltime
 *   $lb_week_start_label, $lb_week_end_label
 *
 * Styles are inline rather than in fmf-admin.css because the public view is
 * rendered outside the admin (and outside the active theme), and both views
 * should look identical.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$lb_purple = '#5b3fa5';
$lb_cream  = '#fdf8ee';
$lb_ink    = '#2a2740';

/**
 * One ranked table. $rows: list of ['label'=>string,'metric'=>string(pre-escaped)].
 */
$lb_table = function( $rows, $heading, $empty_note ) use ( $lb_purple, $lb_ink ) {
    $count = count( $rows );
    ?>
    <div style="flex:1 1 340px;min-width:300px;">
      <h3 style="margin:0 0 2px;font-size:15px;color:<?php echo esc_attr( $lb_purple ); ?>;">
        <?php echo esc_html( $heading ); ?>
        <span style="font-weight:400;color:#998;font-size:13px;">&mdash; all <?php echo intval( $count ); ?></span>
      </h3>
      <?php if ( ! $count ) : ?>
        <p style="margin:8px 0 18px;font-size:13px;color:#998;"><?php echo esc_html( $empty_note ); ?></p>
      <?php else : ?>
      <table cellpadding="0" cellspacing="0" border="0" style="width:100%;border:1px solid #eee;border-radius:8px;border-collapse:separate;overflow:hidden;margin:8px 0 18px;background:#fff;">
        <?php $rank = 0; $last = $count - 1; foreach ( $rows as $i => $r ) : $rank++; $border = $i === $last ? '' : 'border-bottom:1px solid #f4f2f7;'; ?>
        <tr>
          <td width="34" style="padding:8px 4px 8px 14px;font-size:13px;font-weight:700;color:<?php echo esc_attr( $lb_purple ); ?>;<?php echo $border; ?>"><?php echo intval( $rank ); ?></td>
          <td style="padding:8px;font-size:13px;color:<?php echo esc_attr( $lb_ink ); ?>;<?php echo $border; ?>"><?php echo esc_html( $r['label'] ); ?></td>
          <td align="right" style="padding:8px 14px 8px 8px;font-size:12px;color:#766;white-space:nowrap;<?php echo $border; ?>"><?php echo $r['metric']; // pre-escaped ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
    <?php
};

// "N lessons . M people"
$lb_shop_rows = function( $list ) {
    $out = array();
    foreach ( $list as $s ) {
        $lessons = intval( $s['lesson_total'] );
        $people  = intval( $s['people_total'] );
        $out[] = array(
            'label'  => $s['title'],
            'metric' => esc_html( $lessons . ' lesson' . ( 1 === $lessons ? '' : 's' ) )
                . ' <span style="color:#bbb;">&middot;</span> '
                . esc_html( $people . ' ' . ( 1 === $people ? 'person' : 'people' ) ),
        );
    }
    return $out;
};

// "N watches"
$lb_lesson_rows = function( $list ) {
    $out = array();
    foreach ( $list as $l ) {
        $c = intval( $l['count'] );
        $out[] = array(
            'label'  => $l['title'],
            'metric' => esc_html( $c . ' watch' . ( 1 === $c ? '' : 'es' ) ),
        );
    }
    return $out;
};
?>

<div class="fmf-lb" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $lb_ink ); ?>;">

  <div class="fmf-lb-filter" style="margin:0 0 18px;">
    <button type="button" class="fmf-lb-btn" data-range="week" aria-pressed="true"
      style="font:600 13px/1 inherit;padding:9px 18px;border:1px solid <?php echo esc_attr( $lb_purple ); ?>;border-radius:6px 0 0 6px;background:<?php echo esc_attr( $lb_purple ); ?>;color:#fff;cursor:pointer;">
      This week
    </button>
    <button type="button" class="fmf-lb-btn" data-range="alltime" aria-pressed="false"
      style="font:600 13px/1 inherit;padding:9px 18px;border:1px solid <?php echo esc_attr( $lb_purple ); ?>;border-left:0;border-radius:0 6px 6px 0;background:#fff;color:<?php echo esc_attr( $lb_purple ); ?>;cursor:pointer;">
      All time
    </button>
  </div>

  <div class="fmf-lb-pane" data-range="week">
    <p style="margin:0 0 14px;font-size:13px;color:#766;">
      Week of <strong><?php echo esc_html( $lb_week_start_label ); ?> &ndash; <?php echo esc_html( $lb_week_end_label ); ?></strong>
      &mdash; the same window as the roll-up email.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:0 28px;">
      <?php
      $lb_table( $lb_shop_rows( $lb_shops_week ), 'Most active shops', 'No shop had any activity this week.' );
      $lb_table( $lb_lesson_rows( $lb_lessons_week ), 'Classes watched', 'No classes were watched this week.' );
      ?>
    </div>
  </div>

  <div class="fmf-lb-pane" data-range="alltime" style="display:none;">
    <p style="margin:0 0 14px;font-size:13px;color:#766;">
      Everything since the course began.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:0 28px;">
      <?php
      $lb_table( $lb_shop_rows( $lb_shops_alltime ), 'Most active shops', 'No activity recorded yet.' );
      $lb_table( $lb_lesson_rows( $lb_lessons_alltime ), 'Classes watched', 'No classes watched yet.' );
      ?>
    </div>
  </div>

</div>

<script>
/* Weekly / All time filter. Both datasets are already on the page, so switching
   is instant - no reload, and no re-running the expensive roll-up build.
   With JS off, both panes stay visible (the inline display:none is the only
   thing hiding one), which degrades to a readable stacked page. */
(function () {
  var root = document.currentScript.previousElementSibling;
  if ( ! root ) { return; }
  var btns  = root.querySelectorAll( '.fmf-lb-btn' );
  var panes = root.querySelectorAll( '.fmf-lb-pane' );
  var purple = '<?php echo esc_js( $lb_purple ); ?>';

  function show( range ) {
    for ( var i = 0; i < panes.length; i++ ) {
      panes[ i ].style.display = panes[ i ].getAttribute( 'data-range' ) === range ? '' : 'none';
    }
    for ( var j = 0; j < btns.length; j++ ) {
      var on = btns[ j ].getAttribute( 'data-range' ) === range;
      btns[ j ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
      btns[ j ].style.background = on ? purple : '#fff';
      btns[ j ].style.color      = on ? '#fff' : purple;
    }
  }

  for ( var k = 0; k < btns.length; k++ ) {
    btns[ k ].addEventListener( 'click', function () {
      show( this.getAttribute( 'data-range' ) );
    } );
  }
})();
</script>
