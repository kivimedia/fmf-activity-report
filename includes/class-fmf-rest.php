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
