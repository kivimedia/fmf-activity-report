<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_REST {

    const NS = 'fmf/v1';

    public static function register_routes() {
        register_rest_route( self::NS, '/diagnose', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'diagnose' ),
        ) );

        register_rest_route( self::NS, '/find-user-group', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'find_user_group' ),
            'args'                => array(
                'user_id' => array( 'type' => 'integer', 'required' => true ),
            ),
        ) );

        register_rest_route( self::NS, '/probe-completions', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'probe_completions' ),
        ) );

        register_rest_route( self::NS, '/run-real-window', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin_or_token' ),
            'callback'            => array( __CLASS__, 'run_real_window' ),
            'args'                => array(
                'group_id'    => array( 'type' => 'integer', 'required' => true ),
                'override_to' => array( 'type' => 'string', 'required' => true ),
                'days_back'   => array( 'type' => 'integer', 'default' => 90 ),
            ),
        ) );

        register_rest_route( self::NS, '/run-demo', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin_or_token' ),
            'callback'            => array( __CLASS__, 'run_demo' ),
            'args'                => array(
                'group_id'    => array( 'type' => 'integer', 'required' => true ),
                'override_to' => array( 'type' => 'string', 'required' => true ),
            ),
        ) );

        register_rest_route( self::NS, '/run-weekly', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin_or_token' ),
            'callback'            => array( __CLASS__, 'run_weekly' ),
            'args'                => array(
                'force'       => array( 'type' => 'boolean', 'default' => false ),
                'only_group'  => array( 'type' => 'integer', 'default' => 0 ),
                'override_to' => array( 'type' => 'string',  'default' => '' ),
            ),
        ) );

        // Bulk-set per-group recipient (team-leader) emails. Body:
        // { "recipients": { "<group_id|slug|title>": "<email>", ... }, "replace": false }
        register_rest_route( self::NS, '/set-recipients', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin_or_token' ),
            'callback'            => array( __CLASS__, 'set_recipients' ),
        ) );

        // List every group with its current recipient + qualification status.
        register_rest_route( self::NS, '/recipients', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'list_recipients' ),
        ) );
    }

    public static function permit_admin() {
        return current_user_can( 'manage_options' );
    }

    public static function permit_admin_or_token( $request ) {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        $settings = get_option( 'fmf_settings', array() );
        $expected = isset( $settings['cron_token'] ) ? $settings['cron_token'] : '';
        $given    = $request->get_param( 'token' );
        if ( ! $given ) {
            $given = $request->get_header( 'x-fmf-token' );
        }
        return $expected && hash_equals( (string) $expected, (string) $given );
    }

    public static function find_user_group( $request ) {
        global $wpdb;
        $uid = intval( $request->get_param( 'user_id' ) );
        $table = $wpdb->prefix . 'lifterlms_user_postmeta';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value AS role FROM {$table} WHERE user_id=%d AND meta_key=%s",
            $uid, '_group_role'
        ), ARRAY_A );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'group_id' => intval( $r['post_id'] ),
                'role'     => $r['role'],
                'title'    => get_the_title( intval( $r['post_id'] ) ),
            );
        }
        return array( 'user_id' => $uid, 'groups' => $out );
    }

    public static function probe_completions() {
        global $wpdb;
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $lesson_ids = FMF_LifterLMS_Reader::lesson_ids_for_course( $course_id );
        $table = $wpdb->prefix . 'lifterlms_user_postmeta';

        // Cross-reference: of users who completed lessons, which ones are in any group?
        $completers = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE meta_key=%s AND meta_value=%s",
            '_is_complete', 'yes'
        ) );
        $by_user = array();
        if ( $completers ) {
            $ph = implode( ',', array_fill( 0, count( $completers ), '%d' ) );
            $params = array_merge( array( '_is_complete', 'yes' ), array_map( 'intval', $completers ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, COUNT(*) AS n, MAX(updated_date) AS last_at FROM {$table} WHERE meta_key=%s AND meta_value=%s AND user_id IN ({$ph}) GROUP BY user_id ORDER BY last_at DESC",
                $params
            ), ARRAY_A );
            foreach ( $rows as $r ) {
                $uid = intval( $r['user_id'] );
                $u = get_userdata( $uid );
                $group_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, meta_value AS role FROM {$table} WHERE user_id=%d AND meta_key=%s",
                    $uid, '_group_role'
                ), ARRAY_A );
                $by_user[] = array(
                    'user_id'    => $uid,
                    'name'       => $u ? $u->display_name : '(unknown)',
                    'email'      => $u ? $u->user_email : '',
                    'completions'=> intval( $r['n'] ),
                    'last_at'    => $r['last_at'],
                    'groups'     => array_map( function( $g ) {
                        return array(
                            'group_id' => intval( $g['post_id'] ),
                            'title'    => get_the_title( intval( $g['post_id'] ) ),
                            'role'     => $g['role'],
                        );
                    }, $group_rows ),
                );
            }
        }

        $key_freq = $wpdb->get_results( "SELECT meta_key, COUNT(*) AS n FROM {$table} GROUP BY meta_key ORDER BY n DESC LIMIT 12", ARRAY_A );

        $total_complete = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key=%s AND meta_value=%s", '_is_complete', 'yes' ) );

        $latest = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, post_id, updated_date FROM {$table} WHERE meta_key=%s AND meta_value=%s ORDER BY updated_date DESC LIMIT 1", '_is_complete', 'yes' ), ARRAY_A );

        $course_complete_count = 0;
        $course_latest = null;
        if ( ! empty( $lesson_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
            $sql = "SELECT COUNT(*) FROM {$table} WHERE meta_key=%s AND meta_value=%s AND post_id IN ({$placeholders})";
            $params = array_merge( array( '_is_complete', 'yes' ), array_map( 'intval', $lesson_ids ) );
            $course_complete_count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

            $sql2 = "SELECT user_id, post_id, updated_date FROM {$table} WHERE meta_key=%s AND meta_value=%s AND post_id IN ({$placeholders}) ORDER BY updated_date DESC LIMIT 5";
            $course_latest = $wpdb->get_results( $wpdb->prepare( $sql2, $params ), ARRAY_A );
        }

        return array(
            'lifterlms_user_postmeta_keys' => $key_freq,
            'all_courses_total_complete'   => (int) $total_complete,
            'all_courses_latest_complete'  => $latest,
            'course_5685_total_complete'   => $course_complete_count,
            'course_5685_latest_5'         => $course_latest,
            'course_5685_lesson_count'     => count( $lesson_ids ),
            'completers_breakdown'         => $by_user,
        );
    }

    public static function diagnose() {
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );

        list( $week_start_gmt, $week_end_gmt ) = FMF_Report_Builder::previous_week_bounds_gmt();

        $recipients = get_option( 'fmf_group_recipients', array() );
        $sample = array();
        $teams_count = 0;
        $teams_with_recipient = 0;
        foreach ( $groups as $g ) {
            $members = FMF_LifterLMS_Reader::group_members( $g['id'] );
            $leaders = FMF_LifterLMS_Reader::group_leaders( $members );
            $students = FMF_LifterLMS_Reader::group_students( $members );
            $override = isset( $recipients[ $g['id'] ] ) ? trim( (string) $recipients[ $g['id'] ] ) : '';
            $effective_email = $override ?: ( $leaders ? $leaders[0]['email'] : '' );
            if ( count( $students ) >= 2 && ! empty( $leaders ) ) {
                $teams_count++;
            }
            if ( count( $students ) >= 2 && $effective_email ) {
                $teams_with_recipient++;
            }
            if ( count( $sample ) < 5 ) {
                $sample[] = array(
                    'group_id'         => $g['id'],
                    'title'            => $g['title'],
                    'members_total'    => count( $members ),
                    'student_count'    => count( $students ),
                    'auto_leader_email'=> $leaders ? $leaders[0]['email'] : '',
                    'override_email'   => $override,
                    'effective_email'  => $effective_email,
                );
            }
        }

        $lesson_ids = FMF_LifterLMS_Reader::lesson_ids_for_course( $course_id );

        return array(
            'lifterlms_active'  => FMF_LifterLMS_Reader::is_lifterlms_active(),
            'groups_active'     => FMF_LifterLMS_Reader::is_groups_active(),
            'course_id'         => $course_id,
            'course_title'      => get_the_title( $course_id ),
            'lesson_count'      => count( $lesson_ids ),
            'group_count'       => count( $groups ),
            'qualifying_teams'  => $teams_count,
            'teams_with_recipient' => $teams_with_recipient,
            'previous_week_gmt' => array( 'start' => $week_start_gmt, 'end' => $week_end_gmt ),
            'sample_groups'     => $sample,
            'next_cron_gmt'     => wp_next_scheduled( FMF_CRON_HOOK ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( FMF_CRON_HOOK ) ) : null,
            'last_run'          => get_option( 'fmf_last_run', null ),
            'enable_send'       => ! empty( $settings['enable_send'] ),
            'plugin_version'    => FMF_VERSION,
        );
    }

    public static function run_real_window( $request ) {
        $gid = intval( $request->get_param( 'group_id' ) );
        $to  = sanitize_email( (string) $request->get_param( 'override_to' ) );
        $days = max( 1, intval( $request->get_param( 'days_back' ) ) );
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        $group = null;
        foreach ( FMF_LifterLMS_Reader::list_groups_for_course( $course_id ) as $g ) {
            if ( intval( $g['id'] ) === $gid ) { $group = $g; break; }
        }
        if ( ! $group ) {
            return new WP_Error( 'group_not_found', 'group not in target course', array( 'status' => 404 ) );
        }

        $report = FMF_Report_Builder::build_real_for_group_window( $group, $days, $course_id );
        if ( ! $report ) {
            return new WP_Error( 'cannot_build', 'group does not qualify (need 2+ students + recipient)', array( 'status' => 422 ) );
        }
        $result = FMF_Mailer::send_group_report( $report, $to );
        return array(
            'sent'              => $result['sent'],
            'recipient'         => $to,
            'group_id'          => $gid,
            'group_title'       => $group['title'],
            'days_back'         => $days,
            'total_completions' => $report['total_completions'],
            'active_members'    => count( $report['members_with_activity'] ),
            'inactive_members'  => count( $report['members_without_activity'] ),
            'error'             => $result['error'],
        );
    }

    public static function run_demo( $request ) {
        $gid = intval( $request->get_param( 'group_id' ) );
        $to  = sanitize_email( (string) $request->get_param( 'override_to' ) );
        if ( ! $gid || ! $to ) {
            return new WP_Error( 'bad_request', 'group_id and override_to are required', array( 'status' => 400 ) );
        }
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        $group = null;
        foreach ( FMF_LifterLMS_Reader::list_groups_for_course( $course_id ) as $g ) {
            if ( intval( $g['id'] ) === $gid ) { $group = $g; break; }
        }
        if ( ! $group ) {
            return new WP_Error( 'group_not_found', 'group not in target course', array( 'status' => 404 ) );
        }

        $report = FMF_Report_Builder::synthesize_demo_for_group( $group, $course_id );
        if ( ! $report ) {
            return new WP_Error( 'cannot_build', 'group has no students or no lessons', array( 'status' => 422 ) );
        }
        $result = FMF_Mailer::send_group_report( $report, $to );
        return array(
            'sent'              => $result['sent'],
            'recipient'         => $to,
            'group_id'          => $gid,
            'group_title'       => $group['title'],
            'total_completions' => $report['total_completions'],
            'active_members'    => count( $report['members_with_activity'] ),
            'inactive_members'  => count( $report['members_without_activity'] ),
            'error'             => $result['error'],
        );
    }

    public static function run_weekly( $request ) {
        $args = array(
            'force'       => (bool)   $request->get_param( 'force' ),
            'only_group'  => intval(  $request->get_param( 'only_group' ) ),
            'override_to' => sanitize_email( (string) $request->get_param( 'override_to' ) ),
        );
        return FMF_Cron::run( $args );
    }

    /**
     * Resolve the group list for the active course, indexed by id / slug / title
     * for flexible key matching (mirrors the admin CSV importer).
     */
    private static function index_groups() {
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );
        $by_id = array(); $by_slug = array(); $by_title = array();
        foreach ( $groups as $g ) {
            $by_id[ intval( $g['id'] ) ]                                = $g;
            $by_slug[ strtolower( $g['slug'] ) ]                        = $g;
            $by_title[ strtolower( html_entity_decode( $g['title'] ) ) ] = $g;
        }
        return array( $groups, $by_id, $by_slug, $by_title, $settings );
    }

    private static function match_group_key( $key, $by_id, $by_slug, $by_title ) {
        $key = trim( (string) $key );
        if ( ctype_digit( $key ) && isset( $by_id[ intval( $key ) ] ) ) {
            return $by_id[ intval( $key ) ];
        }
        if ( isset( $by_slug[ strtolower( $key ) ] ) ) {
            return $by_slug[ strtolower( $key ) ];
        }
        $title_key = strtolower( html_entity_decode( $key ) );
        if ( isset( $by_title[ $title_key ] ) ) {
            return $by_title[ $title_key ];
        }
        return null;
    }

    private static function min_team_size( $settings ) {
        return isset( $settings['min_team_size'] ) ? max( 2, intval( $settings['min_team_size'] ) ) : 2;
    }

    /**
     * Bulk-assign per-group recipient (team-leader) emails into fmf_group_recipients.
     * Accepts a map keyed by group_id, group slug, or exact group title.
     */
    public static function set_recipients( $request ) {
        $input = $request->get_param( 'recipients' );
        if ( ! is_array( $input ) ) {
            return new WP_Error( 'bad_request', 'recipients must be an object map of { group_id|slug|title : email }', array( 'status' => 400 ) );
        }
        $replace = (bool) $request->get_param( 'replace' );

        list( , $by_id, $by_slug, $by_title, $settings ) = self::index_groups();
        $min = self::min_team_size( $settings );

        $recipients = $replace ? array() : get_option( 'fmf_group_recipients', array() );
        $matched = array(); $unknown = array(); $bad_email = array();

        foreach ( $input as $key => $email_raw ) {
            $g = self::match_group_key( $key, $by_id, $by_slug, $by_title );
            if ( ! $g ) {
                $unknown[] = array( 'key' => (string) $key, 'email' => (string) $email_raw, 'reason' => 'no group matched' );
                continue;
            }
            $email = sanitize_email( (string) $email_raw );
            if ( ! $email || ! is_email( $email ) ) {
                $bad_email[] = array( 'key' => (string) $key, 'group_id' => $g['id'], 'email' => (string) $email_raw );
                continue;
            }
            $recipients[ $g['id'] ] = $email;

            $members  = FMF_LifterLMS_Reader::group_members( $g['id'] );
            $students = FMF_LifterLMS_Reader::group_students( $members );
            $matched[] = array(
                'group_id'      => $g['id'],
                'title'         => $g['title'],
                'email'         => $email,
                'members_total' => count( $members ),
                'student_count' => count( $students ),
                'qualifies_now' => ( count( $students ) >= $min ),
            );
        }

        update_option( 'fmf_group_recipients', $recipients, false );

        return array(
            'ok'                   => true,
            'replace'              => $replace,
            'min_team_size'        => $min,
            'matched_count'        => count( $matched ),
            'matched'              => $matched,
            'unknown'              => $unknown,
            'bad_email'            => $bad_email,
            'total_recipients_now' => count( $recipients ),
        );
    }

    /**
     * Read-only: every group in the course with its recipient + qualification status.
     */
    public static function list_recipients() {
        list( $groups, , , , $settings ) = self::index_groups();
        $min = self::min_team_size( $settings );
        $recipients = get_option( 'fmf_group_recipients', array() );
        $overrides  = get_option( 'fmf_group_overrides', array() );

        $out = array();
        foreach ( $groups as $g ) {
            $members  = FMF_LifterLMS_Reader::group_members( $g['id'] );
            $leaders  = FMF_LifterLMS_Reader::group_leaders( $members );
            $students = FMF_LifterLMS_Reader::group_students( $members );
            $override = isset( $recipients[ $g['id'] ] ) ? trim( (string) $recipients[ $g['id'] ] ) : '';
            $effective = $override ?: ( $leaders ? $leaders[0]['email'] : '' );
            $enabled  = ! ( isset( $overrides[ $g['id'] ] ) && empty( $overrides[ $g['id'] ] ) );
            $out[] = array(
                'group_id'        => $g['id'],
                'title'           => $g['title'],
                'slug'            => $g['slug'],
                'members_total'   => count( $members ),
                'student_count'   => count( $students ),
                'override_email'  => $override,
                'auto_leader'     => $leaders ? $leaders[0]['email'] : '',
                'effective_email' => $effective,
                'enabled'         => $enabled,
                'qualifies'       => ( count( $students ) >= $min && $effective !== '' && $enabled ),
            );
        }
        return array(
            'min_team_size'    => $min,
            'group_count'      => count( $out ),
            'recipients_set'   => count( $recipients ),
            'groups'           => $out,
        );
    }

    /**
     * Front-end one-click unsubscribe handler. Triggered on `init` when
     * fmf_unsub query var is present.
     */
    public static function maybe_handle_unsubscribe_query() {
        if ( empty( $_GET['fmf_unsub'] ) ) {
            return;
        }
        $gid = intval( $_GET['g'] ?? 0 );
        $key = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) );
        if ( ! $gid || ! $key ) {
            wp_die( 'Invalid unsubscribe link.', 'Unsubscribe', array( 'response' => 400 ) );
        }
        $expected = FMF_Mailer::unsubscribe_key( $gid );
        if ( ! hash_equals( $expected, $key ) ) {
            wp_die( 'Invalid unsubscribe signature.', 'Unsubscribe', array( 'response' => 403 ) );
        }
        $overrides = get_option( 'fmf_group_overrides', array() );
        $overrides[ $gid ] = 0;
        update_option( 'fmf_group_overrides', $overrides, false );

        $title = get_the_title( $gid );
        wp_die(
            sprintf(
                '<h2 style="font-family:sans-serif;">Unsubscribed</h2><p style="font-family:sans-serif;color:#444;">Weekly activity reports are now turned off for <strong>%s</strong>. Reply to any past report email to turn them back on.</p>',
                esc_html( $title ? $title : 'this group' )
            ),
            'Unsubscribed',
            array( 'response' => 200 )
        );
    }
}
