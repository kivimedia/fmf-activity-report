<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Cron {

    public static function register() {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_weekly_schedule' ) );
        add_action( FMF_CRON_HOOK, array( __CLASS__, 'run' ) );
        // Self-heal: if cron got dropped (or activation race left it unscheduled),
        // re-schedule on the next admin request.
        add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
    }

    public static function add_weekly_schedule( $schedules ) {
        if ( ! isset( $schedules['fmf_weekly'] ) ) {
            $schedules['fmf_weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => 'Once weekly (FMF)',
            );
        }
        return $schedules;
    }

    public static function schedule() {
        if ( wp_next_scheduled( FMF_CRON_HOOK ) ) {
            return;
        }
        $next = self::next_monday_morning_gmt();
        wp_schedule_event( $next, 'fmf_weekly', FMF_CRON_HOOK );
    }

    /**
     * Returns the next Monday at the configured local hour, expressed as a UTC timestamp.
     */
    public static function next_monday_morning_gmt() {
        $settings = get_option( 'fmf_settings', array() );
        $tz_name  = ! empty( $settings['timezone'] ) ? $settings['timezone'] : 'America/New_York';
        $hour     = isset( $settings['send_hour_local'] ) ? intval( $settings['send_hour_local'] ) : 8;
        try {
            $tz = new DateTimeZone( $tz_name );
        } catch ( Exception $e ) {
            $tz = new DateTimeZone( 'America/New_York' );
        }
        $now_local = new DateTime( 'now', $tz );
        $next = clone $now_local;
        $next->modify( 'next monday' )->setTime( $hour, 0, 0 );
        $next->setTimezone( new DateTimeZone( 'UTC' ) );
        return $next->getTimestamp();
    }

    /**
     * Cron callback. Iterates qualifying groups and emails each leader.
     */
    public static function run( $args = array() ) {
        $force_send = ! empty( $args['force'] );
        $only_group = isset( $args['only_group'] ) ? intval( $args['only_group'] ) : 0;
        $override_to = isset( $args['override_to'] ) ? $args['override_to'] : '';

        $settings  = get_option( 'fmf_settings', array() );
        if ( empty( $force_send ) && empty( $settings['enable_send'] ) ) {
            return array( 'sent' => 0, 'skipped' => 0, 'errors' => array(), 'reason' => 'disabled' );
        }

        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        list( $week_start_gmt, $week_end_gmt ) = FMF_Report_Builder::previous_week_bounds_gmt();
        $week_key  = FMF_Report_Builder::week_key_for( $week_start_gmt );

        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );
        $overrides = get_option( 'fmf_group_overrides', array() );
        $sent_log  = get_option( 'fmf_sent_log', array() );

        $sent = 0; $skipped = 0; $errors = array();
        foreach ( $groups as $g ) {
            $gid = intval( $g['id'] );
            if ( $only_group && $gid !== $only_group ) {
                continue;
            }
            if ( ! $force_send && isset( $overrides[ $gid ] ) && empty( $overrides[ $gid ] ) ) {
                $skipped++;
                continue;
            }
            if ( ! $force_send && ! empty( $sent_log[ $gid ][ $week_key ] ) ) {
                $skipped++;
                continue; // already sent this week
            }

            $report = FMF_Report_Builder::build_for_group( $g, $week_start_gmt, $week_end_gmt, $course_id );
            if ( ! $report ) {
                $skipped++;
                continue; // no team / no leader
            }

            $result = FMF_Mailer::send_group_report( $report, $override_to );
            if ( $result['sent'] ) {
                $sent++;
                if ( ! $override_to ) {
                    if ( ! isset( $sent_log[ $gid ] ) ) { $sent_log[ $gid ] = array(); }
                    $sent_log[ $gid ][ $week_key ] = time();
                }
            } else {
                $errors[] = sprintf( 'group %d: %s', $gid, $result['error'] );
            }
        }

        update_option( 'fmf_sent_log', $sent_log, false );
        update_option( 'fmf_last_run', array(
            'at_gmt'    => gmdate( 'Y-m-d H:i:s' ),
            'sent'      => $sent,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'week_key'  => $week_key,
        ), false );

        return array( 'sent' => $sent, 'skipped' => $skipped, 'errors' => $errors );
    }
}
