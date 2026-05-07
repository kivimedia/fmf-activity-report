<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap fmf-wrap">
  <h1>15 Minute Florist - Weekly Activity Report</h1>

  <?php if ( ! empty( $_GET['saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['test_sent'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Test email sent to <code><?php echo esc_html( $_GET['test_sent'] ); ?></code>.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['test_failed'] ) ) : ?>
    <div class="notice notice-error is-dismissible"><p>Test send to <code><?php echo esc_html( $_GET['test_failed'] ); ?></code> failed.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['test_error'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p>Test error: <code><?php echo esc_html( $_GET['test_error'] ); ?></code>.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['toggled'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Group <code><?php echo intval( $_GET['toggled'] ); ?></code> updated.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['run'] ) ) : ?>
    <div class="notice notice-info is-dismissible"><p>Run complete: <code><?php echo esc_html( $_GET['run'] ); ?></code></p></div>
  <?php endif; ?>

  <div class="fmf-grid">
    <section class="fmf-panel">
      <h2>Status</h2>
      <table class="fmf-status">
        <tr><th>LifterLMS active</th><td><?php echo FMF_LifterLMS_Reader::is_lifterlms_active() ? 'yes' : 'no'; ?></td></tr>
        <tr><th>Groups add-on active</th><td><?php echo FMF_LifterLMS_Reader::is_groups_active() ? 'yes' : 'no'; ?></td></tr>
        <tr><th>Course</th><td><?php echo intval( $course_id ); ?> - <?php echo esc_html( get_the_title( $course_id ) ); ?></td></tr>
        <tr><th>Lessons in course</th><td><?php echo count( FMF_LifterLMS_Reader::lesson_ids_for_course( $course_id ) ); ?></td></tr>
        <tr><th>Groups</th><td><?php echo count( $groups ); ?></td></tr>
        <tr><th>Send enabled</th><td><?php echo ! empty( $settings['enable_send'] ) ? 'yes' : 'no'; ?></td></tr>
        <tr><th>Next cron (UTC)</th><td><?php echo $next_ts ? gmdate( 'Y-m-d H:i', $next_ts ) : '(not scheduled)'; ?></td></tr>
        <tr><th>Last run</th><td>
          <?php if ( $last_run ) : ?>
            <?php echo esc_html( $last_run['at_gmt'] ); ?> UTC - sent <?php echo intval( $last_run['sent'] ); ?>, skipped <?php echo intval( $last_run['skipped'] ); ?>, errors <?php echo count( $last_run['errors'] ); ?>
          <?php else : ?>
            (never)
          <?php endif; ?>
        </td></tr>
      </table>

      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
        <?php wp_nonce_field( 'fmf_run_now' ); ?>
        <input type="hidden" name="action" value="fmf_run_now">
        <label><input type="checkbox" name="force" value="1"> Force-resend (ignore weekly idempotency log)</label><br>
        <button class="button button-primary" type="submit" style="margin-top:8px;">Run weekly send now</button>
      </form>
    </section>

    <section class="fmf-panel">
      <h2>Settings</h2>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'fmf_save_settings' ); ?>
        <input type="hidden" name="action" value="fmf_save_settings">
        <table class="form-table">
          <tr><th>Course ID</th><td><input name="course_id" type="number" value="<?php echo intval( $settings['course_id'] ?? FMF_DEFAULT_COURSE_ID ); ?>"></td></tr>
          <tr><th>Min team size</th><td><input name="min_team_size" type="number" min="2" value="<?php echo intval( $settings['min_team_size'] ?? 2 ); ?>"></td></tr>
          <tr><th>Send hour (local)</th><td><input name="send_hour_local" type="number" min="0" max="23" value="<?php echo intval( $settings['send_hour_local'] ?? 8 ); ?>"> (Monday)</td></tr>
          <tr><th>Timezone</th><td><input name="timezone" type="text" value="<?php echo esc_attr( $settings['timezone'] ?? 'America/New_York' ); ?>"></td></tr>
          <tr><th>Send enabled</th><td><label><input type="checkbox" name="enable_send" value="1" <?php checked( ! empty( $settings['enable_send'] ) ); ?>> Send live emails on schedule</label></td></tr>
          <tr><th>Test recipient</th><td><input name="test_recipient" type="email" value="<?php echo esc_attr( $settings['test_recipient'] ?? 'ziv@kivimedia.co' ); ?>" size="40"></td></tr>
          <tr><th>From name</th><td><input name="from_name" type="text" value="<?php echo esc_attr( $settings['from_name'] ?? get_option( 'blogname' ) ); ?>"></td></tr>
          <tr><th>From email</th><td><input name="from_email" type="email" value="<?php echo esc_attr( $settings['from_email'] ?? get_option( 'admin_email' ) ); ?>" size="40"></td></tr>
        </table>
        <button class="button button-primary" type="submit">Save settings</button>
      </form>
    </section>
  </div>

  <?php if ( ! empty( $_GET['recipients_saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Recipient mappings saved.</p></div>
  <?php endif; ?>
  <?php if ( ! empty( $_GET['recipient_saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Recipient updated for group <code><?php echo intval( $_GET['recipient_saved'] ); ?></code>.</p></div>
  <?php endif; ?>

  <section class="fmf-panel" style="margin-top:24px;" id="fmf-csv-importer">
    <h2>Bulk-import shop owner emails (CSV / paste)</h2>
    <p class="description">Paste one row per group. Each row needs a <strong>group identifier</strong> (id, slug, OR exact title) AND an <strong>email</strong>, separated by a comma, tab, or semicolon. Lines starting with <code>#</code> are ignored. Existing mappings are kept unless overwritten by a row in the paste.</p>
    <details style="margin:8px 0 12px;">
      <summary style="cursor:pointer;color:#5b3fa5;">Starter list - all groups with 2+ students (copy, paste, fill in emails)</summary>
      <p style="font-size:12px;color:#666;margin:6px 0;">Pre-formatted CSV - one line per qualifying group. Add the shop owner's email after each comma, then paste into the textarea below.</p>
      <textarea readonly rows="14" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select();">
<?php
foreach ( $rows as $row ) :
  $g = $row['group'];
  if ( $row['student_count'] < 2 ) { continue; }
?><?php echo intval( $g['id'] ); ?>,<?php if ( $row['override_email'] ) { echo ' ' . esc_html( $row['override_email'] ); } ?> # <?php echo esc_html( html_entity_decode( $g['title'] ) ); ?> (<?php echo intval( $row['student_count'] ); ?> students)
<?php endforeach; ?></textarea>
    </details>
    <details style="margin:8px 0 12px;">
      <summary style="cursor:pointer;color:#5b3fa5;">Show example formats</summary>
      <pre style="background:#f6f6f6;padding:10px;font-size:12px;">
6171, owner@flowerbasketvale.com
always-in-season, owner@alwaysinseason.com
Bishop's Flowers, owner@bishopsflowers.com
6180,	owner@breensflorist.com
# Comments are ignored
</pre>
    </details>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <?php wp_nonce_field( 'fmf_import_recipients_csv' ); ?>
      <input type="hidden" name="action" value="fmf_import_recipients_csv">
      <textarea name="csv" rows="8" style="width:100%;font-family:monospace;font-size:13px;" placeholder="group_id_or_slug_or_title, owner_email"></textarea>
      <div style="margin-top:10px;">
        <button class="button button-primary" type="submit">Import</button>
        <span class="description" style="margin-left:8px;">After import, scroll down to see what matched.</span>
      </div>
    </form>

    <?php
    $csv_result = ! empty( $_GET['csv_imported'] ) ? get_transient( 'fmf_csv_import_result' ) : false;
    if ( $csv_result ) :
    ?>
      <div id="fmf-csv-result" style="margin-top:16px;border-top:1px solid #eee;padding-top:14px;">
        <h3 style="margin-top:0;">Import result</h3>
        <p>
          <strong style="color:#2a7;"><?php echo count( $csv_result['matched'] ); ?> saved</strong> -
          <strong style="color:#a43a3a;"><?php echo count( $csv_result['unknown'] ); ?> unmatched groups</strong> -
          <strong style="color:#a43a3a;"><?php echo count( $csv_result['bad_email'] ); ?> bad emails</strong>
        </p>
        <?php if ( ! empty( $csv_result['matched'] ) ) : ?>
          <details open><summary>Saved (<?php echo count( $csv_result['matched'] ); ?>)</summary>
          <table class="widefat striped"><thead><tr><th>Group ID</th><th>Title</th><th>Email</th></tr></thead><tbody>
          <?php foreach ( $csv_result['matched'] as $m ) : ?>
            <tr><td><?php echo intval( $m['group_id'] ); ?></td><td><?php echo esc_html( $m['title'] ); ?></td><td><code><?php echo esc_html( $m['email'] ); ?></code></td></tr>
          <?php endforeach; ?>
          </tbody></table></details>
        <?php endif; ?>
        <?php if ( ! empty( $csv_result['unknown'] ) ) : ?>
          <details open style="margin-top:10px;"><summary>Unmatched (<?php echo count( $csv_result['unknown'] ); ?>) - fix the group identifier and re-paste</summary>
          <table class="widefat striped"><thead><tr><th>Line</th><th>Reason</th></tr></thead><tbody>
          <?php foreach ( $csv_result['unknown'] as $m ) : ?>
            <tr><td><code><?php echo esc_html( $m['line'] ); ?></code></td><td><?php echo esc_html( $m['reason'] ); ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></details>
        <?php endif; ?>
        <?php if ( ! empty( $csv_result['bad_email'] ) ) : ?>
          <details style="margin-top:10px;"><summary>Bad emails (<?php echo count( $csv_result['bad_email'] ); ?>)</summary>
          <table class="widefat striped"><thead><tr><th>Line</th><th>Bad email field</th></tr></thead><tbody>
          <?php foreach ( $csv_result['bad_email'] as $m ) : ?>
            <tr><td><code><?php echo esc_html( $m['line'] ); ?></code></td><td><?php echo esc_html( $m['email'] ); ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="fmf-panel" style="margin-top:24px;">
    <h2>Groups (<?php echo count( $rows ); ?>)</h2>
    <p class="description">A group qualifies when it has 2+ students AND a recipient email (either an auto-detected leader OR a manually mapped shop-owner email). LifterLMS Groups on this site only stores the site admin as the "admin" role - so you'll usually need to fill in the shop owner's email manually here.</p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <?php wp_nonce_field( 'fmf_save_recipients_bulk' ); ?>
      <input type="hidden" name="action" value="fmf_save_recipients_bulk">

      <table class="widefat striped fmf-groups">
        <thead>
          <tr>
            <th>ID</th>
            <th>Group</th>
            <th>Students</th>
            <th>Auto-detected leader</th>
            <th>Shop-owner email (override)</th>
            <th>Last sent</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $rows as $row ) : $g = $row['group']; ?>
            <tr>
              <td><?php echo intval( $g['id'] ); ?></td>
              <td><a href="<?php echo esc_url( $g['permalink'] ); ?>" target="_blank"><?php echo esc_html( $g['title'] ); ?></a></td>
              <td><?php echo intval( $row['student_count'] ); ?></td>
              <td><?php echo $row['leader_email'] ? '<code style="font-size:11px;">' . esc_html( $row['leader_email'] ) . '</code>' : '<em>none</em>'; ?></td>
              <td>
                <input
                  type="email"
                  name="recipients[<?php echo intval( $g['id'] ); ?>]"
                  value="<?php echo esc_attr( $row['override_email'] ); ?>"
                  placeholder="shop-owner@example.com"
                  size="28"
                >
              </td>
              <td><?php echo $row['last_sent'] ? esc_html( $row['last_sent'] ) : '<em>never</em>'; ?></td>
              <td>
                <?php if ( ! $row['qualifies'] ) : ?>
                  <span style="color:#999;">no recipient</span>
                <?php elseif ( ! $row['enabled'] ) : ?>
                  <span style="color:#a43a3a;">paused</span>
                <?php else : ?>
                  <span style="color:#2a7;">on</span>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap;">
                <a class="button button-small" target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fmf_preview&group_id=' . intval( $g['id'] ) ), 'fmf_preview' ) ); ?>">Preview</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
        <button class="button button-primary" type="submit">Save recipient mappings</button>
        <span class="description">Tip: leave a field blank to clear an override and fall back to auto-detection.</span>
      </div>
    </form>

    <h3 style="margin-top:24px;">Per-group actions</h3>
    <table class="widefat striped fmf-groups">
      <thead><tr><th>ID</th><th>Group</th><th>Effective recipient</th><th>Toggle</th><th>Test</th></tr></thead>
      <tbody>
        <?php foreach ( $rows as $row ) : $g = $row['group']; if ( ! $row['qualifies'] ) continue; ?>
          <tr>
            <td><?php echo intval( $g['id'] ); ?></td>
            <td><?php echo esc_html( $g['title'] ); ?></td>
            <td><code style="font-size:11px;"><?php echo esc_html( $row['effective_email'] ); ?></code></td>
            <td>
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'fmf_toggle_group' ); ?>
                <input type="hidden" name="action" value="fmf_toggle_group">
                <input type="hidden" name="group_id" value="<?php echo intval( $g['id'] ); ?>">
                <input type="hidden" name="enabled" value="<?php echo $row['enabled'] ? '0' : '1'; ?>">
                <button class="button button-small" type="submit"><?php echo $row['enabled'] ? 'Pause' : 'Resume'; ?></button>
              </form>
            </td>
            <td>
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'fmf_send_test' ); ?>
                <input type="hidden" name="action" value="fmf_send_test">
                <input type="hidden" name="group_id" value="<?php echo intval( $g['id'] ); ?>">
                <input type="hidden" name="recipient" value="<?php echo esc_attr( $settings['test_recipient'] ?? '' ); ?>">
                <button class="button button-small" type="submit">Send test to me</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="fmf-panel" style="margin-top:24px;">
    <h2>External cron trigger</h2>
    <p>If WP-Cron is unreliable, hit this endpoint with a system cron (Cloudways scheduled job, Linode VPS, etc):</p>
    <pre style="background:#f6f6f6;padding:12px;overflow-x:auto;"><?php
      $token = isset( $settings['cron_token'] ) ? $settings['cron_token'] : '';
      echo esc_html( sprintf(
        "curl -X POST '%s' -H 'x-fmf-token: %s'",
        rest_url( 'fmf/v1/run-weekly' ),
        $token
      ) );
    ?></pre>
    <p>Diagnose endpoint (admin auth):</p>
    <pre style="background:#f6f6f6;padding:12px;overflow-x:auto;"><?php
      echo esc_html( sprintf( "curl -u 'USER:APP_PASSWORD' '%s'", rest_url( 'fmf/v1/diagnose' ) ) );
    ?></pre>
  </section>
</div>
