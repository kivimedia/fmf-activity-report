<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Admin {

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_fmf_save_settings', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'admin_post_fmf_toggle_group',  array( __CLASS__, 'handle_toggle_group' ) );
        add_action( 'admin_post_fmf_save_recipient', array( __CLASS__, 'handle_save_recipient' ) );
        add_action( 'admin_post_fmf_save_recipients_bulk', array( __CLASS__, 'handle_save_recipients_bulk' ) );
        add_action( 'admin_post_fmf_import_recipients_csv', array( __CLASS__, 'handle_import_recipients_csv' ) );
        add_action( 'admin_post_fmf_send_test',     array( __CLASS__, 'handle_send_test' ) );
        add_action( 'admin_post_fmf_preview',       array( __CLASS__, 'handle_preview' ) );
        add_action( 'admin_post_fmf_run_now',       array( __CLASS__, 'handle_run_now' ) );
        add_action( 'admin_enqueue_scripts',        array( __CLASS__, 'assets' ) );
    }

    public static function menu() {
        add_menu_page(
            '15 Min Florist Reports',
            '15 Min Florist',
            'manage_options',
            'fmf-activity-report',
            array( __CLASS__, 'render' ),
            'dashicons-email-alt',
            58
        );
    }

    public static function assets( $hook ) {
        if ( false === strpos( $hook, 'fmf-activity-report' ) ) {
            return;
        }
        wp_enqueue_style( 'fmf-admin', FMF_PLUGIN_URL . 'assets/css/fmf-admin.css', array(), FMF_VERSION );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $overrides = get_option( 'fmf_group_overrides', array() );
        $sent_log  = get_option( 'fmf_sent_log', array() );
        $last_run  = get_option( 'fmf_last_run', null );
        $last_send = get_option( 'fmf_last_successful_send', null );
        $next_ts   = wp_next_scheduled( FMF_CRON_HOOK );

        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );

        $recipients = get_option( 'fmf_group_recipients', array() );
        $rows = array();
        foreach ( $groups as $g ) {
            $members  = FMF_LifterLMS_Reader::group_members( $g['id'] );
            $leaders  = FMF_LifterLMS_Reader::group_leaders( $members );
            $students = FMF_LifterLMS_Reader::group_students( $members );
            $enabled  = ! ( isset( $overrides[ $g['id'] ] ) && empty( $overrides[ $g['id'] ] ) );
            $override_email = isset( $recipients[ $g['id'] ] ) ? trim( (string) $recipients[ $g['id'] ] ) : '';
            $effective_email = $override_email ?: ( $leaders ? $leaders[0]['email'] : '' );
            $last_sent = '';
            if ( ! empty( $sent_log[ $g['id'] ] ) ) {
                $ts = max( $sent_log[ $g['id'] ] );
                $last_sent = wp_date( 'Y-m-d H:i', $ts );
            }
            $rows[] = array(
                'group'           => $g,
                'members_total'   => count( $members ),
                'student_count'   => count( $students ),
                'leader_count'    => count( $leaders ),
                'leader_email'    => $leaders ? $leaders[0]['email'] : '',
                'override_email'  => $override_email,
                'effective_email' => $effective_email,
                'enabled'         => $enabled,
                'last_sent'       => $last_sent,
                'qualifies'       => count( $students ) >= 2 && ( $effective_email !== '' ),
            );
        }

        // Weekly send history - the simple "did the email go out?" view.
        $send_history = self::build_send_history( $sent_log );
        list( $cur_week_start_gmt, ) = FMF_Report_Builder::previous_week_bounds_gmt();
        $current_week_key   = FMF_Report_Builder::week_key_for( $cur_week_start_gmt );
        $current_week_sent  = 0;
        $current_week_label = '';
        foreach ( $send_history as $h ) {
            if ( $h['week_key'] === $current_week_key ) {
                $current_week_sent  = $h['count'];
                $current_week_label = $h['label'];
                break;
            }
        }
        if ( '' === $current_week_label ) {
            $current_week_label = self::week_label( $current_week_key );
        }

        include FMF_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Turn a week_key (the Monday "Y-m-d" of the reported week) into a friendly
     * "Jun 22 - 28, 2026" label spanning that Monday through the following Sunday.
     */
    private static function week_label( $week_key ) {
        $start = date_create_from_format( 'Y-m-d', (string) $week_key, new DateTimeZone( 'UTC' ) );
        if ( ! $start ) {
            return (string) $week_key;
        }
        $end = clone $start;
        $end->modify( '+6 days' );
        $end_fmt = ( $start->format( 'M' ) === $end->format( 'M' ) ) ? 'j' : 'M j';
        return $start->format( 'M j' ) . ' - ' . $end->format( $end_fmt ) . ', ' . $end->format( 'Y' );
    }

    /**
     * Build the weekly send history from fmf_sent_log: one entry per reported
     * week (newest first), with how many shop reports were delivered and when
     * the send ran (in the site's local time).
     */
    private static function build_send_history( $sent_log ) {
        $weeks = array();
        foreach ( (array) $sent_log as $gid => $wk_map ) {
            foreach ( (array) $wk_map as $wk => $ts ) {
                $ts = intval( $ts );
                if ( ! isset( $weeks[ $wk ] ) ) {
                    $weeks[ $wk ] = array( 'count' => 0, 'first' => $ts, 'last' => $ts );
                }
                $weeks[ $wk ]['count']++;
                $weeks[ $wk ]['first'] = min( $weeks[ $wk ]['first'], $ts );
                $weeks[ $wk ]['last']  = max( $weeks[ $wk ]['last'], $ts );
            }
        }
        krsort( $weeks ); // newest week first
        $out = array();
        foreach ( $weeks as $wk => $d ) {
            $out[] = array(
                'week_key' => $wk,
                'label'    => self::week_label( $wk ),
                'count'    => $d['count'],
                'sent_on'  => wp_date( 'D, M j Y, g:i A', $d['first'] ),
            );
        }
        return $out;
    }

    public static function handle_save_settings() {
        check_admin_referer( 'fmf_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        $existing = get_option( 'fmf_settings', array() );
        $new = wp_parse_args( array(
            'course_id'      => intval( $_POST['course_id'] ?? FMF_DEFAULT_COURSE_ID ),
            'min_team_size'  => max( 2, intval( $_POST['min_team_size'] ?? 2 ) ),
            'send_hour_local'=> max( 0, min( 23, intval( $_POST['send_hour_local'] ?? 6 ) ) ),
            'timezone'       => sanitize_text_field( $_POST['timezone'] ?? 'America/New_York' ),
            'enable_send'    => ! empty( $_POST['enable_send'] ) ? 1 : 0,
            'test_recipient' => sanitize_email( $_POST['test_recipient'] ?? 'ziv@kivimedia.co' ),
            'from_name'      => sanitize_text_field( $_POST['from_name'] ?? get_option( 'blogname' ) ),
            'from_email'     => sanitize_email( $_POST['from_email'] ?? get_option( 'admin_email' ) ),
            'cc_admin'       => sanitize_email( $_POST['cc_admin'] ?? '' ),
            'cc_office'      => sanitize_email( $_POST['cc_office'] ?? '' ),
        ), $existing );
        update_option( 'fmf_settings', $new, false );

        // Reschedule cron in case the local hour changed.
        $ts = wp_next_scheduled( FMF_CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, FMF_CRON_HOOK );
        }
        FMF_Cron::schedule();

        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&saved=1' ) );
        exit;
    }

    public static function handle_toggle_group() {
        check_admin_referer( 'fmf_toggle_group' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        $gid    = intval( $_POST['group_id'] ?? 0 );
        $on     = ! empty( $_POST['enabled'] );
        $overrides = get_option( 'fmf_group_overrides', array() );
        $overrides[ $gid ] = $on ? 1 : 0;
        update_option( 'fmf_group_overrides', $overrides, false );
        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&toggled=' . $gid ) );
        exit;
    }

    public static function handle_save_recipient() {
        check_admin_referer( 'fmf_save_recipient' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
        $gid   = intval( $_POST['group_id'] ?? 0 );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $recipients = get_option( 'fmf_group_recipients', array() );
        if ( $email && is_email( $email ) ) {
            $recipients[ $gid ] = $email;
        } else {
            unset( $recipients[ $gid ] );
        }
        update_option( 'fmf_group_recipients', $recipients, false );
        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&recipient_saved=' . $gid ) );
        exit;
    }

    public static function handle_save_recipients_bulk() {
        check_admin_referer( 'fmf_save_recipients_bulk' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
        $recipients = get_option( 'fmf_group_recipients', array() );
        $payload = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] ) ? $_POST['recipients'] : array();
        foreach ( $payload as $gid_raw => $email_raw ) {
            $gid = intval( $gid_raw );
            $email = sanitize_email( $email_raw );
            if ( ! $gid ) { continue; }
            if ( $email && is_email( $email ) ) {
                $recipients[ $gid ] = $email;
            } else {
                unset( $recipients[ $gid ] );
            }
        }
        update_option( 'fmf_group_recipients', $recipients, false );
        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&recipients_saved=1' ) );
        exit;
    }

    public static function handle_import_recipients_csv() {
        check_admin_referer( 'fmf_import_recipients_csv' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }

        $raw = isset( $_POST['csv'] ) ? wp_unslash( $_POST['csv'] ) : '';
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );

        $by_id   = array();
        $by_slug = array();
        $by_title= array();
        foreach ( $groups as $g ) {
            $by_id[ intval( $g['id'] ) ]                              = $g;
            $by_slug[ strtolower( $g['slug'] ) ]                      = $g;
            $by_title[ strtolower( html_entity_decode( $g['title'] ) ) ] = $g;
        }

        $recipients = get_option( 'fmf_group_recipients', array() );
        $matched = array(); $unknown = array(); $bad_email = array();

        // Accept comma OR tab OR semicolon between fields. One row per line.
        $lines = preg_split( "/\r?\n/", trim( (string) $raw ) );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) { continue; }
            // Split on comma/tab/semicolon, allow surrounding spaces
            $parts = preg_split( '/[\t,;]+/', $line, 2 );
            if ( count( $parts ) < 2 ) {
                $unknown[] = array( 'line' => $line, 'reason' => 'no email column' );
                continue;
            }
            $key   = trim( $parts[0], " \"'" );
            $email = sanitize_email( trim( $parts[1], " \"'" ) );
            if ( ! $email || ! is_email( $email ) ) {
                $bad_email[] = array( 'line' => $line, 'email' => $parts[1] );
                continue;
            }

            $g = null;
            if ( ctype_digit( $key ) && isset( $by_id[ intval( $key ) ] ) ) {
                $g = $by_id[ intval( $key ) ];
            } elseif ( isset( $by_slug[ strtolower( $key ) ] ) ) {
                $g = $by_slug[ strtolower( $key ) ];
            } elseif ( isset( $by_title[ strtolower( html_entity_decode( $key ) ) ] ) ) {
                $g = $by_title[ strtolower( html_entity_decode( $key ) ) ];
            }
            if ( ! $g ) {
                $unknown[] = array( 'line' => $line, 'reason' => 'no group matched key "' . $key . '"' );
                continue;
            }

            $recipients[ $g['id'] ] = $email;
            $matched[] = array( 'group_id' => $g['id'], 'title' => $g['title'], 'email' => $email );
        }

        update_option( 'fmf_group_recipients', $recipients, false );
        set_transient( 'fmf_csv_import_result', array(
            'matched'   => $matched,
            'unknown'   => $unknown,
            'bad_email' => $bad_email,
            'at'        => time(),
        ), 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&csv_imported=1#fmf-csv-result' ) );
        exit;
    }

    public static function handle_send_test() {
        check_admin_referer( 'fmf_send_test' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        $gid       = intval( $_POST['group_id'] ?? 0 );
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $to        = sanitize_email( $_POST['recipient'] ?? ( $settings['test_recipient'] ?? get_option( 'admin_email' ) ) );

        list( $week_start_gmt, $week_end_gmt ) = FMF_Report_Builder::previous_week_bounds_gmt();

        $group = self::get_group( $gid, $course_id );
        if ( ! $group ) {
            wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&test_error=group_not_found' ) );
            exit;
        }

        $report = FMF_Report_Builder::build_for_group( $group, $week_start_gmt, $week_end_gmt, $course_id );
        if ( ! $report ) {
            wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&test_error=no_team' ) );
            exit;
        }
        $result = FMF_Mailer::send_group_report( $report, $to );
        $status = $result['sent'] ? 'sent' : 'failed';
        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&test_' . $status . '=' . urlencode( $to ) ) );
        exit;
    }

    public static function handle_preview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'fmf_preview' );

        $gid       = intval( $_GET['group_id'] ?? 0 );
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        list( $week_start_gmt, $week_end_gmt ) = FMF_Report_Builder::previous_week_bounds_gmt();

        $group = self::get_group( $gid, $course_id );
        if ( ! $group ) { wp_die( 'Group not found' ); }
        $report = FMF_Report_Builder::build_for_group( $group, $week_start_gmt, $week_end_gmt, $course_id );
        if ( ! $report ) {
            wp_die( 'This group does not qualify for a report (needs 2+ students AND at least one leader).' );
        }
        echo FMF_Mailer::render_html( $report, 'preview-only' );
        exit;
    }

    public static function handle_run_now() {
        check_admin_referer( 'fmf_run_now' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        $force = ! empty( $_POST['force'] );
        $result = FMF_Cron::run( array( 'force' => $force ) );
        $msg = sprintf( 'sent=%d skipped=%d errors=%d', $result['sent'], $result['skipped'], count( $result['errors'] ) );
        wp_safe_redirect( admin_url( 'admin.php?page=fmf-activity-report&run=' . urlencode( $msg ) ) );
        exit;
    }

    private static function get_group( $gid, $course_id ) {
        foreach ( FMF_LifterLMS_Reader::list_groups_for_course( $course_id ) as $g ) {
            if ( intval( $g['id'] ) === intval( $gid ) ) {
                return $g;
            }
        }
        return null;
    }
}
