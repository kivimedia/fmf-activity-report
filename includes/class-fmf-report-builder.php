<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Report_Builder {

    /**
     * Build the data structure passed to the email template for one group.
     *
     * @return array{
     *   group:array,
     *   leader:array|null,
     *   leaders:array,
     *   week_start_local:string,
     *   week_end_local:string,
     *   week_start_label:string,
     *   members_with_activity:array,
     *   members_without_activity:array,
     *   total_completions:int,
     *   total_members:int,
     *   has_activity:bool
     * }|null
     */
    public static function build_for_group( array $group, $week_start_gmt, $week_end_gmt, $course_id ) {
        $members  = FMF_LifterLMS_Reader::group_members( $group['id'] );
        $leaders  = FMF_LifterLMS_Reader::group_leaders( $members );
        $students = FMF_LifterLMS_Reader::group_students( $members );

        // Build recipient list. Manual override (set in admin) takes precedence;
        // otherwise auto-detected leaders. We synthesise a "leader" record for the
        // override so the template still has a name/email pair to render.
        $recipients = get_option( 'fmf_group_recipients', array() );
        $override = isset( $recipients[ $group['id'] ] ) ? trim( (string) $recipients[ $group['id'] ] ) : '';
        if ( $override && is_email( $override ) ) {
            $leaders = array(
                array(
                    'user_id'      => 0,
                    'role'         => 'override',
                    'email'        => $override,
                    'display_name' => self::display_name_for_email( $override, $group ),
                ),
            );
        }

        if ( count( $students ) < self::min_team_size() ) {
            return null; // not a team
        }
        if ( empty( $leaders ) ) {
            return null; // nobody to send to
        }

        $student_ids = array_map( function( $m ) { return $m['user_id']; }, $students );
        $activity = FMF_LifterLMS_Reader::lesson_completions_for_users( $student_ids, $week_start_gmt, $week_end_gmt, $course_id );

        $members_with_activity    = array();
        $members_without_activity = array();
        $total_completions = 0;

        foreach ( $students as $s ) {
            $rows = isset( $activity[ $s['user_id'] ] ) ? $activity[ $s['user_id'] ] : array();
            if ( empty( $rows ) ) {
                $members_without_activity[] = $s;
            } else {
                $members_with_activity[] = array(
                    'user'      => $s,
                    'lessons'   => $rows,
                    'lesson_count' => count( $rows ),
                    'last_at'   => self::latest_completion_local( $rows ),
                );
                $total_completions += count( $rows );
            }
        }

        $tz = self::site_tz();
        $week_start_dt = ( new DateTime( $week_start_gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
        $week_end_dt   = ( new DateTime( $week_end_gmt,   new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

        return array(
            'group'                    => $group,
            'leader'                   => $leaders[0],
            'leaders'                  => $leaders,
            'week_start_local'         => $week_start_dt->format( 'Y-m-d H:i' ),
            'week_end_local'           => $week_end_dt->format( 'Y-m-d H:i' ),
            'week_start_label'         => $week_start_dt->format( 'M j' ),
            'week_end_label'           => $week_end_dt->format( 'M j, Y' ),
            'members_with_activity'    => $members_with_activity,
            'members_without_activity' => $members_without_activity,
            'total_completions'        => $total_completions,
            'total_members'            => count( $students ),
            'has_activity'             => $total_completions > 0,
        );
    }

    private static function latest_completion_local( array $rows ) {
        $latest = null;
        foreach ( $rows as $r ) {
            if ( ! $latest || strtotime( $r['completed_at'] ) > strtotime( $latest ) ) {
                $latest = $r['completed_at'];
            }
        }
        if ( ! $latest ) {
            return '';
        }
        $dt = new DateTime( $latest, new DateTimeZone( 'UTC' ) );
        $dt->setTimezone( self::site_tz() );
        return $dt->format( 'D M j' );
    }

    private static function site_tz() {
        $settings = get_option( 'fmf_settings', array() );
        $tzname = ! empty( $settings['timezone'] ) ? $settings['timezone'] : 'America/New_York';
        try {
            return new DateTimeZone( $tzname );
        } catch ( Exception $e ) {
            return new DateTimeZone( 'America/New_York' );
        }
    }

    private static function display_name_for_email( $email, $group ) {
        $u = get_user_by( 'email', $email );
        if ( $u ) {
            return $u->display_name ?: trim( $u->first_name . ' ' . $u->last_name );
        }
        return $group['title'] . ' team';
    }

    private static function min_team_size() {
        $settings = get_option( 'fmf_settings', array() );
        return isset( $settings['min_team_size'] ) ? max( 2, intval( $settings['min_team_size'] ) ) : 2;
    }

    /**
     * UTC bounds for the most-recently-completed week (Mon 00:00 - Sun 23:59 in site TZ).
     *
     * @return array{0:string,1:string} ISO datetimes in UTC.
     */
    public static function previous_week_bounds_gmt() {
        $tz   = self::site_tz();
        $now  = new DateTime( 'now', $tz );

        // Start of this week's Monday in site TZ, then subtract 7 days.
        $start_local = clone $now;
        $start_local->modify( 'monday this week' )->setTime( 0, 0, 0 );
        $start_local->modify( '-7 days' );

        $end_local = clone $start_local;
        $end_local->modify( '+7 days' )->modify( '-1 second' ); // Sunday 23:59:59 local

        $start_local->setTimezone( new DateTimeZone( 'UTC' ) );
        $end_local->setTimezone( new DateTimeZone( 'UTC' ) );

        return array(
            $start_local->format( 'Y-m-d H:i:s' ),
            $end_local->format( 'Y-m-d H:i:s' ),
        );
    }

    public static function week_key_for( $week_start_gmt ) {
        return substr( $week_start_gmt, 0, 10 );
    }
}
