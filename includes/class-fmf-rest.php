


































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

        // Dump full member roster (roles) for one group, by id/slug/title.
        register_rest_route( self::NS, '/group-members', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'group_members' ),
            'args'                => array(
                'group' => array( 'type' => 'string', 'required' => true ),
            ),
        ) );

        // For each target shop, return (creating if needed) the reusable OPEN
        // invite link the leader shares with staff, + the group management URL.
        register_rest_route( self::NS, '/invite-links', array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'permit_admin_or_token' ),
            'callback'            => array( __CLASS__, 'invite_links' ),
            'args'                => array(
                'create_if_missing' => array( 'type' => 'boolean', 'default' => true ),
            ),
        ) );

        // Read-only: full enrolled-student roster + enrollment-trigger linkage
        // (students sharing a WC order / group trigger likely belong together).
        register_rest_route( self::NS, '/student-roster', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'student_roster' ),
        ) );

        // Read-only: infer each shop's likely staff by context (email domain,
        // WooCommerce company, shop-name tokens) from the enrolled student base.
        register_rest_route( self::NS, '/staff-suggest', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'staff_suggest' ),
        ) );

        // Deep read-only introspection of one group: seats, ALL members via the
        // official LifterLMS Groups query (incl. pending/invited), child posts,
        // invitation rows, and group meta. Read-only; admin gated.
        register_rest_route( self::NS, '/group-detail', array(
            'methods'             => 'GET',
            'permission_callback' => array( __CLASS__, 'permit_admin' ),
            'callback'            => array( __CLASS__, 'group_detail' ),
            'args'                => array(
                'group' => array( 'type' => 'string', 'required' => true ),
            ),
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
            'last_successful_send' => get_option( 'fmf_last_successful_send', null ),
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
     * Read-only: full member roster for one group (id|slug|title), with each
     * member's _group_role and whether they are enrolled in the course.
     * Also lists ALL distinct _group_role values present, to expose role-naming
     * surprises (e.g. staff stored under an unexpected role string).
     */
    public static function group_members( $request ) {
        global $wpdb;
        list( , $by_id, $by_slug, $by_title, $settings ) = self::index_groups();
        $g = self::match_group_key( (string) $request->get_param( 'group' ), $by_id, $by_slug, $by_title );
        if ( ! $g ) {
            return new WP_Error( 'group_not_found', 'no group matched key', array( 'status' => 404 ) );
        }
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        $table = $wpdb->prefix . 'lifterlms_user_postmeta';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value AS role FROM {$table} WHERE post_id=%d AND meta_key=%s",
            intval( $g['id'] ), '_group_role'
        ), ARRAY_A );

        $leader_roles = FMF_LifterLMS_Reader::LEADER_ROLES;
        $members = array();
        foreach ( $rows as $r ) {
            $uid = intval( $r['user_id'] );
            $u   = get_userdata( $uid );
            $enrolled = function_exists( 'llms_is_user_enrolled' ) ? (bool) llms_is_user_enrolled( $uid, $course_id ) : null;
            $members[] = array(
                'user_id'      => $uid,
                'role'         => (string) $r['role'],
                'counted_as'   => in_array( (string) $r['role'], $leader_roles, true ) ? 'leader' : 'student',
                'email'        => $u ? $u->user_email : '(no wp user)',
                'display_name' => $u ? $u->display_name : '',
                'enrolled_in_course' => $enrolled,
                'user_exists'  => (bool) $u,
            );
        }

        return array(
            'group_id'        => $g['id'],
            'title'           => $g['title'],
            'slug'            => $g['slug'],
            'leader_roles'    => $leader_roles,
            'member_count'    => count( $members ),
            'members'         => $members,
        );
    }

    /**
     * For each target shop (groups with a recipient override) return the reusable
     * OPEN invite link a leader shares with staff (anyone with it self-registers
     * into the group as a member while seats remain), plus the group management
     * page URL and whether the assigned owner can actually manage members.
     */
    public static function invite_links( $request ) {
        if ( ! function_exists( 'llms_groups' ) || ! is_object( llms_groups() ) || ! method_exists( llms_groups(), 'invitations' ) ) {
            return new WP_Error( 'no_groups_api', 'LifterLMS Groups invitations API unavailable', array( 'status' => 500 ) );
        }
        $create = (bool) $request->get_param( 'create_if_missing' );
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );
        $gmeta = array();
        foreach ( $groups as $g ) { $gmeta[ intval( $g['id'] ) ] = $g; }

        $invs = llms_groups()->invitations();
        $recipients = get_option( 'fmf_group_recipients', array() );
        $manage_roles = array( 'admin', 'leader', 'primary_admin' );

        $out = array();
        foreach ( $recipients as $gid => $owner_email ) {
            $gid = intval( $gid );
            $g   = isset( $gmeta[ $gid ] ) ? $gmeta[ $gid ] : null;

            $open = $invs->get_open_link( $gid );
            $created = false;
            if ( ! $open && $create ) {
                $open = $invs->create( array( 'group_id' => $gid, 'email' => '', 'role' => 'member' ) );
                $created = ! is_wp_error( $open );
            }
            $link = ( $open && ! is_wp_error( $open ) ) ? $open->get_accept_link() : '';

            // Seats.
            $members  = FMF_LifterLMS_Reader::group_members( $gid );
            $seats = null;
            if ( function_exists( 'llms_get_post' ) ) {
                $m = llms_get_post( $gid );
                if ( $m && method_exists( $m, 'get' ) ) { $seats = intval( $m->get( 'seats' ) ); }
            }

            // Can the assigned owner actually manage/invite?
            $owner_uid  = email_exists( $owner_email );
            $owner_role = '';
            foreach ( $members as $mm ) { if ( intval( $mm['user_id'] ) === intval( $owner_uid ) ) { $owner_role = $mm['role']; break; } }

            $out[] = array(
                'group_id'         => $gid,
                'shop'             => $g ? $g['title'] : '',
                'manage_page_url'  => $g ? $g['permalink'] : get_permalink( $gid ),
                'open_invite_link' => $link,
                'invite_created'   => $created,
                'owner_email'      => $owner_email,
                'owner_role'       => $owner_role ?: '(not a member of this group)',
                'owner_can_manage' => in_array( $owner_role, $manage_roles, true ),
                'seats_total'      => $seats,
                'seats_used'       => count( $members ),
                'seats_open'       => ( $seats !== null ) ? max( 0, $seats - count( $members ) ) : null,
            );
        }

        return array(
            'note'       => 'open_invite_link is reusable by multiple staff until seats fill. manage_page_url is where the leader can invite by email + copy this link.',
            'myaccount_invite_pattern' => '{my-account}/?invite={invite_key}',
            'shops'      => $out,
        );
    }

    /**
     * Read-only: full enrolled-student roster with course enrollment trigger,
     * start date, registration date, and group memberships. Also returns the
     * 13 target shops' owner triggers + group creation dates, and a map of every
     * enrollment trigger shared by 2+ students (the order/group linkage signal).
     */
    public static function student_roster( $request ) {
        global $wpdb;
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $upm = $wpdb->prefix . 'lifterlms_user_postmeta';

        // Course-level meta per user (status, trigger, start date).
        $cmeta = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_key, meta_value FROM `$upm` WHERE post_id=%d AND meta_key IN ('_status','_enrollment_trigger','_start_date')",
            $course_id
        ), ARRAY_A );
        $cm = array();
        foreach ( $cmeta as $r ) { $cm[ intval( $r['user_id'] ) ][ $r['meta_key'] ] = $r['meta_value']; }

        // Group memberships (all course groups).
        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );
        $gtitle = array();
        foreach ( $groups as $g ) { $gtitle[ intval( $g['id'] ) ] = $g['title']; }
        $role_rows = $wpdb->get_results( "SELECT post_id, user_id, meta_value AS role FROM `$upm` WHERE meta_key='_group_role'", ARRAY_A );
        $ug = array();
        foreach ( $role_rows as $r ) {
            $gid = intval( $r['post_id'] ); $uid = intval( $r['user_id'] );
            if ( isset( $gtitle[ $gid ] ) ) { $ug[ $uid ][] = $gtitle[ $gid ] . ':' . $r['role']; }
        }

        // Enrolled student ids.
        $enrolled = array();
        foreach ( $cm as $uid => $m ) { if ( ( $m['_status'] ?? '' ) === 'enrolled' ) { $enrolled[] = $uid; } }

        // User rows.
        $udata = array();
        if ( $enrolled ) {
            foreach ( array_chunk( $enrolled, 500 ) as $chunk ) {
                $ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                foreach ( $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, user_email, display_name, user_registered FROM {$wpdb->users} WHERE ID IN ($ph)", $chunk
                ), ARRAY_A ) as $u ) { $udata[ intval( $u['ID'] ) ] = $u; }
            }
        }

        // Build roster + trigger index.
        $students = array();
        $trigger_index = array();
        $hist = array();
        foreach ( $enrolled as $uid ) {
            $u = $udata[ $uid ] ?? array( 'user_email' => '', 'display_name' => '', 'user_registered' => '' );
            $trig = $cm[ $uid ]['_enrollment_trigger'] ?? '';
            $students[] = array(
                'uid'   => $uid,
                'email' => $u['user_email'],
                'name'  => $u['display_name'],
                'reg'   => $u['user_registered'],
                'start' => $cm[ $uid ]['_start_date'] ?? '',
                'trig'  => $trig,
                'groups'=> $ug[ $uid ] ?? array(),
            );
            if ( $trig ) {
                $trigger_index[ $trig ][] = $u['user_email'];
                $norm = preg_replace( '/\d+/', '#', $trig );
                $hist[ $norm ] = ( $hist[ $norm ] ?? 0 ) + 1;
            }
        }

        // Owners of the 13 target shops + their triggers + group creation date.
        $recipients = get_option( 'fmf_group_recipients', array() );
        $owners = array();
        foreach ( $recipients as $gid => $owner_email ) {
            $gid = intval( $gid );
            $owner_uid = email_exists( $owner_email );
            $owner_trig = $owner_uid ? ( $cm[ $owner_uid ]['_enrollment_trigger'] ?? '(not course-enrolled)' ) : '(no user)';
            $post = get_post( $gid );
            $owners[] = array(
                'group_id'     => $gid,
                'shop'         => $gtitle[ $gid ] ?? '',
                'owner_email'  => $owner_email,
                'owner_trigger'=> $owner_trig,
                'group_created'=> $post ? $post->post_date_gmt : '',
                'shared_with_owner_trigger' => ( $owner_trig && isset( $trigger_index[ $owner_trig ] ) ) ? $trigger_index[ $owner_trig ] : array(),
            );
        }

        // Triggers shared by 2+ students (potential same-shop cohorts).
        $shared = array();
        foreach ( $trigger_index as $t => $emails ) {
            if ( count( $emails ) >= 2 ) { $shared[ $t ] = $emails; }
        }

        return array(
            'course_id'       => $course_id,
            'enrolled_count'  => count( $enrolled ),
            'distinct_users_with_any_course_meta' => count( $cm ),
            'trigger_histogram' => $hist,
            'owners'          => $owners,
            'shared_triggers_2plus' => $shared,
            'students'        => $students,
        );
    }

    /**
     * Read-only: infer each shop's likely staff from the enrolled-student base by
     * three contextual signals - (1) email domain == owner's branded domain,
     * (2) WooCommerce billing company contains the shop name, (3) shop-name token
     * appears in the user's name/company/email local-part. Never writes.
     */
    public static function staff_suggest( $request ) {
        global $wpdb;
        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $upm = $wpdb->prefix . 'lifterlms_user_postmeta';

        $free = array( 'gmail.com','yahoo.com','hotmail.com','outlook.com','comcast.net','aol.com',
            'icloud.com','me.com','live.com','msn.com','sbcglobal.net','att.net','verizon.net',
            'ymail.com','protonmail.com','mail.com','gmx.com','bellsouth.net','cox.net','mac.com' );
        $stop = array( 'the','and','of','llc','inc','co','company','florist','florists','flowers','flower',
            'shop','gifts','gift','events','event','floral','by','a','&','design','designs','studio' );

        // The 13 target shops = groups with a recipient override set.
        $recipients = get_option( 'fmf_group_recipients', array() );
        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );
        $gtitle = array();
        foreach ( $groups as $g ) { $gtitle[ intval( $g['id'] ) ] = $g['title']; }

        // All enrolled students in the course.
        $student_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM `$upm` WHERE post_id=%d AND meta_key='_status' AND meta_value='enrolled'", $course_id
        ) );
        $student_ids = array_map( 'intval', $student_ids );

        // user_id -> [ {gid,title,role} ] for every group membership (one query).
        $role_rows = $wpdb->get_results( "SELECT post_id, user_id, meta_value AS role FROM `$upm` WHERE meta_key='_group_role'", ARRAY_A );
        $user_groups = array();
        foreach ( $role_rows as $r ) {
            $uid = intval( $r['user_id'] ); $gid = intval( $r['post_id'] );
            if ( ! isset( $gtitle[ $gid ] ) ) { continue; } // only course groups
            $user_groups[ $uid ][] = array( 'gid' => $gid, 'title' => $gtitle[ $gid ], 'role' => $r['role'] );
        }

        // Fetch email/name/registered for all enrolled students in one query.
        $users = array();
        if ( $student_ids ) {
            $ph = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, user_email, display_name, user_registered FROM {$wpdb->users} WHERE ID IN ($ph)", $student_ids
            ), ARRAY_A );
            foreach ( $rows as $u ) { $users[ intval( $u['ID'] ) ] = $u; }
        }

        $domain_of = function( $email ) { $p = strpos( (string) $email, '@' ); return $p === false ? '' : strtolower( substr( $email, $p + 1 ) ); };
        $tokens_of = function( $s ) use ( $stop ) {
            $s = strtolower( html_entity_decode( (string) $s ) );
            $s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
            $t = array_filter( preg_split( '/\s+/', trim( $s ) ), function( $w ) use ( $stop ) { return strlen( $w ) >= 3 && ! in_array( $w, $stop, true ); } );
            return array_values( array_unique( $t ) );
        };

        // Domain histogram across enrolled students (non-free, >=1).
        $domain_users = array();
        foreach ( $users as $uid => $u ) {
            $d = $domain_of( $u['user_email'] );
            if ( $d && ! in_array( $d, $free, true ) ) { $domain_users[ $d ][] = $uid; }
        }

        $owner_emails = array_map( 'strtolower', array_values( $recipients ) );
        $owner_emails[] = 'tim@theprofitableflorist.com';

        $brief = function( $uid ) use ( $users, $user_groups ) {
            $u = $users[ $uid ];
            $only_general = false; $grps = isset( $user_groups[ $uid ] ) ? $user_groups[ $uid ] : array();
            $gids = array_map( function( $x ) { return $x['gid']; }, $grps );
            if ( $gids === array( 6164 ) ) { $only_general = true; }
            return array(
                'user_id'      => $uid,
                'email'        => $u['user_email'],
                'name'         => $u['display_name'],
                'registered'   => $u['user_registered'],
                'company'      => get_user_meta( $uid, 'billing_company', true ),
                'groups'       => array_map( function( $x ) { return $x['title'] . ' (' . $x['role'] . ')'; }, $grps ),
                'only_in_general' => $only_general,
            );
        };

        $shops = array();
        foreach ( $recipients as $gid => $owner_email ) {
            $gid = intval( $gid );
            $owner_domain = $domain_of( $owner_email );
            $is_free = in_array( $owner_domain, $free, true ) || $owner_domain === '';
            $shop_title = isset( $gtitle[ $gid ] ) ? $gtitle[ $gid ] : '';
            $shop_tokens = $tokens_of( $shop_title );

            $domain_matches = array();
            if ( ! $is_free && isset( $domain_users[ $owner_domain ] ) ) {
                foreach ( $domain_users[ $owner_domain ] as $uid ) {
                    if ( in_array( strtolower( $users[ $uid ]['user_email'] ), $owner_emails, true ) ) { continue; }
                    $domain_matches[] = $brief( $uid );
                }
            }

            // Token matches (company / name / email local-part) - secondary signal.
            $token_matches = array();
            if ( $shop_tokens ) {
                foreach ( $users as $uid => $u ) {
                    if ( in_array( strtolower( $u['user_email'] ), $owner_emails, true ) ) { continue; }
                    $hay = strtolower( $u['display_name'] . ' ' . get_user_meta( $uid, 'billing_company', true ) . ' ' . substr( $u['user_email'], 0, strpos( $u['user_email'] . '@', '@' ) ) );
                    $hit = array_filter( $shop_tokens, function( $t ) use ( $hay ) { return strpos( $hay, $t ) !== false; } );
                    if ( $hit ) { $b = $brief( $uid ); $b['matched_tokens'] = array_values( $hit ); $token_matches[] = $b; }
                }
            }

            // ALL accounts on the owner's branded domain, enrolled or not - catches
            // staff who have a user but were never enrolled / never grouped.
            $all_domain_accounts = array();
            if ( ! $is_free ) {
                $like = '%@' . $wpdb->esc_like( $owner_domain );
                $acct = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, user_email, display_name, user_registered FROM {$wpdb->users} WHERE user_email LIKE %s", $like
                ), ARRAY_A );
                foreach ( $acct as $a ) {
                    $uid = intval( $a['ID'] );
                    if ( in_array( strtolower( $a['user_email'] ), $owner_emails, true ) ) { continue; }
                    $grps = isset( $user_groups[ $uid ] ) ? $user_groups[ $uid ] : array();
                    $all_domain_accounts[] = array(
                        'user_id'    => $uid,
                        'email'      => $a['user_email'],
                        'name'       => $a['display_name'],
                        'registered' => $a['user_registered'],
                        'enrolled_in_course' => in_array( $uid, $student_ids, true ),
                        'groups'     => array_map( function( $x ) { return $x['title'] . ' (' . $x['role'] . ')'; }, $grps ),
                    );
                }
            }

            $shops[] = array(
                'group_id'      => $gid,
                'shop'          => $shop_title,
                'owner_email'   => $owner_email,
                'owner_domain'  => $owner_domain,
                'owner_domain_is_free' => $is_free,
                'all_domain_accounts' => $all_domain_accounts,
                'domain_staff'  => $domain_matches,
                'domain_staff_count' => count( $domain_matches ),
                'token_matches' => $token_matches,
            );
        }

        // Non-free domain clusters with 2+ enrolled students (catch shops by domain
        // even when the owner used a free email).
        $clusters = array();
        foreach ( $domain_users as $d => $uids ) {
            if ( count( $uids ) >= 2 ) {
                $clusters[] = array( 'domain' => $d, 'count' => count( $uids ),
                    'users' => array_map( function( $uid ) use ( $users ) { return $users[ $uid ]['user_email'] . ' | ' . $users[ $uid ]['display_name']; }, $uids ) );
            }
        }
        usort( $clusters, function( $a, $b ) { return $b['count'] - $a['count']; } );

        return array(
            'course_id'        => $course_id,
            'enrolled_total'   => count( $users ),
            'target_shops'     => count( $shops ),
            'shops'            => $shops,
            'nonfree_domain_clusters' => $clusters,
        );
    }

    /**
     * Read-only deep introspection: where do this group's staff actually live?
     * Uses the official LLMS_Group_Members_Query / Invitations query when present
     * so we catch members/invites the raw _group_role read misses.
     */
    public static function group_detail( $request ) {
        global $wpdb;
        list( , $by_id, $by_slug, $by_title, ) = self::index_groups();
        $g = self::match_group_key( (string) $request->get_param( 'group' ), $by_id, $by_slug, $by_title );
        if ( ! $g ) {
            return new WP_Error( 'group_not_found', 'no group matched key', array( 'status' => 404 ) );
        }
        $gid = intval( $g['id'] );
        $info = array( 'group_id' => $gid, 'title' => $g['title'], 'slug' => $g['slug'] );

        // Seats + model accessors.
        if ( function_exists( 'llms_get_post' ) ) {
            $m = llms_get_post( $gid );
            $info['model_class'] = $m ? get_class( $m ) : 'null';
            if ( $m ) {
                if ( method_exists( $m, 'get' ) ) {
                    $info['seats']      = $m->get( 'seats' );
                    $info['post_id']    = $m->get( 'post_id' ); // linked course/membership
                }
                foreach ( array( 'get_seats_available', 'get_seats_used', 'get_seats_total', 'get_members_count' ) as $fn ) {
                    if ( method_exists( $m, $fn ) ) {
                        try { $info[ $fn ] = $m->$fn(); } catch ( \Throwable $e ) { $info[ $fn ] = 'ERR'; }
                    }
                }
            }
        }

        // Official members query (canonical: includes role + status, incl. invited/pending).
        if ( class_exists( 'LLMS_Group_Members_Query' ) ) {
            try {
                $q = new LLMS_Group_Members_Query( array( 'group' => $gid, 'per_page' => 200, 'status' => array() ) );
                $rows = array();
                foreach ( (array) $q->get_members() as $mem ) {
                    $uid  = is_object( $mem ) && method_exists( $mem, 'get' ) ? intval( $mem->get( 'user_id' ) ) : ( is_array( $mem ) ? intval( $mem['user_id'] ?? 0 ) : intval( $mem ) );
                    $role = is_object( $mem ) && method_exists( $mem, 'get_role' ) ? $mem->get_role() : ( is_array( $mem ) ? ( $mem['role'] ?? '' ) : '' );
                    $stat = is_object( $mem ) && method_exists( $mem, 'get' ) ? $mem->get( 'status' ) : ( is_array( $mem ) ? ( $mem['status'] ?? '' ) : '' );
                    $u    = $uid ? get_userdata( $uid ) : null;
                    $rows[] = array( 'user_id' => $uid, 'role' => $role, 'status' => $stat, 'email' => $u ? $u->user_email : '', 'name' => $u ? $u->display_name : '' );
                }
                $info['members_query'] = array( 'found' => method_exists( $q, 'get_found_results' ) ? $q->get_found_results() : count( $rows ), 'rows' => $rows );
            } catch ( \Throwable $e ) {
                $info['members_query'] = 'ERR: ' . $e->getMessage();
            }
        } else {
            $info['members_query'] = 'class LLMS_Group_Members_Query not found';
        }

        // Raw _group_role rows (what FMF currently reads).
        $upm = $wpdb->prefix . 'lifterlms_user_postmeta';
        $info['group_role_rows'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value AS role FROM `$upm` WHERE post_id=%d AND meta_key='_group_role'", $gid
        ), ARRAY_A );
        $info['user_postmeta_keys'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, COUNT(*) n FROM `$upm` WHERE post_id=%d GROUP BY meta_key", $gid
        ), ARRAY_A );

        // Child posts (invitations are often a child CPT).
        $info['child_posts'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_parent=%d", $gid
        ), ARRAY_A );

        // Invitation-style group meta (seats, invitations serialized, etc.).
        $meta = get_post_meta( $gid );
        $info['group_meta'] = array();
        foreach ( $meta as $k => $v ) {
            $info['group_meta'][ $k ] = ( is_array( $v ) && count( $v ) === 1 ) ? maybe_unserialize( $v[0] ) : $v;
        }

        // Dedicated invitation tables, if any.
        foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '%invit%'" ) as $it ) {
            $cols = $wpdb->get_col( "DESCRIBE `$it`" );
            $key  = in_array( 'group_id', $cols, true ) ? 'group_id' : ( in_array( 'post_id', $cols, true ) ? 'post_id' : null );
            if ( $key ) {
                $info[ 'invite_table_' . $it ] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$it` WHERE `$key`=%d", $gid ), ARRAY_A );
            }
        }

        return $info;
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

    /**
     * Public, tokenised full-leaderboard page: the "see the full list" button in the
     * roll-up email. Same shape as maybe_handle_unsubscribe_query() above - HMAC key
     * in the query string, hash_equals check, no login.
     *
     * Read-only by construction: it renders the aggregate rankings and nothing else.
     * The email's per-person "who watched what" section is not reproduced.
     */
    public static function maybe_handle_leaderboard_query() {
        if ( empty( $_GET['fmf_leaderboard'] ) ) {
            return;
        }
        $key = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) );
        if ( ! $key ) {
            wp_die( 'Invalid leaderboard link.', 'Leaderboards', array( 'response' => 400 ) );
        }
        if ( ! hash_equals( FMF_Mailer::leaderboards_key(), $key ) ) {
            wp_die( 'Invalid leaderboard signature.', 'Leaderboards', array( 'response' => 403 ) );
        }

        $settings  = get_option( 'fmf_settings', array() );
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;

        list( $week_start_gmt, $week_end_gmt ) = FMF_Report_Builder::previous_week_bounds_gmt();

        // Cache the build for this route only. Nobody has to authenticate to reach
        // this page, and each build runs a completions query per shop - without a
        // cache the link doubles as a way to hammer the database by refreshing.
        // The admin page stays uncached so it always shows live numbers.
        $cache_key = 'fmf_leaderboard_' . intval( $course_id ) . '_' . FMF_Report_Builder::week_key_for( $week_start_gmt );
        $rollup    = get_transient( $cache_key );
        if ( ! is_array( $rollup ) ) {
            $rollup = FMF_Report_Builder::build_program_rollup( $course_id, $week_start_gmt, $week_end_gmt );
            set_transient( $cache_key, $rollup, 10 * MINUTE_IN_SECONDS );
        }

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Referrer-Policy: no-referrer', true );
        header( 'Content-Type: text/html; charset=utf-8' );

        include FMF_PLUGIN_DIR . 'templates/leaderboard-public.php';
        exit;
    }
}
