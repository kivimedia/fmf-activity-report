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

    /**
     * Program-wide weekly roll-up for Tim: every shop that had ANY activity in
     * the window, and every watcher within it - owners/leaders AND staff alike
     * (unlike the per-shop report, which counts students only). Shops with zero
     * activity are omitted and summarised as a silent count.
     *
     * @return array{
     *   week_start_label:string,
     *   week_end_label:string,
     *   shops:array<int,array{title:string,people_total:int,lesson_total:int,
     *     watchers:array<int,array{display_name:string,is_leader:bool,lesson_count:int,last_at:string}>}>,
     *   shops_active:int,
     *   shops_total:int,
     *   people_active:int,
     *   lessons_total:int,
     *   shops_silent:int
     * }
     */
    public static function build_program_rollup( $course_id, $week_start_gmt, $week_end_gmt ) {
        $groups = FMF_LifterLMS_Reader::list_groups_for_course( $course_id );

        $shops         = array();
        $unique_people = array();
        $lessons_total = 0;

        foreach ( $groups as $g ) {
            $members = FMF_LifterLMS_Reader::group_members( $g['id'] );
            if ( empty( $members ) ) {
                continue;
            }

            $user_ids = array_map( function( $m ) { return $m['user_id']; }, $members );
            $activity = FMF_LifterLMS_Reader::lesson_completions_for_users( $user_ids, $week_start_gmt, $week_end_gmt, $course_id );
            if ( empty( $activity ) ) {
                continue; // silent shop
            }

            $watchers     = array();
            $shop_lessons = 0;
            foreach ( $members as $m ) {
                $rows = isset( $activity[ $m['user_id'] ] ) ? $activity[ $m['user_id'] ] : array();
                if ( empty( $rows ) ) {
                    continue;
                }
                $count       = count( $rows );
                $watchers[]  = array(
                    'display_name' => $m['display_name'],
                    'is_leader'    => in_array( $m['role'], FMF_LifterLMS_Reader::LEADER_ROLES, true ),
                    'lesson_count' => $count,
                    'last_at'      => self::latest_completion_local( $rows ),
                );
                $shop_lessons += $count;
                $unique_people[ $m['user_id'] ] = true;
            }

            if ( empty( $watchers ) ) {
                continue;
            }

            // Most-active watcher first, then name.
            usort( $watchers, function( $a, $b ) {
                if ( $a['lesson_count'] !== $b['lesson_count'] ) {
                    return $b['lesson_count'] - $a['lesson_count'];
                }
                return strcasecmp( $a['display_name'], $b['display_name'] );
            } );

            $shops[] = array(
                'title'        => $g['title'],
                'people_total' => count( $watchers ),
                'lesson_total' => $shop_lessons,
                'watchers'     => $watchers,
            );
            $lessons_total += $shop_lessons;
        }

        // Most-active shop first, then title.
        usort( $shops, function( $a, $b ) {
            if ( $a['lesson_total'] !== $b['lesson_total'] ) {
                return $b['lesson_total'] - $a['lesson_total'];
            }
            return strcasecmp( $a['title'], $b['title'] );
        } );

        $tz = self::site_tz();
        $week_start_dt = ( new DateTime( $week_start_gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
        $week_end_dt   = ( new DateTime( $week_end_gmt,   new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

        return array(
            'week_start_label' => $week_start_dt->format( 'M j' ),
            'week_end_label'   => $week_end_dt->format( 'M j, Y' ),
            'shops'            => $shops,
            'shops_active'     => count( $shops ),
            'shops_total'      => count( $groups ),
            'people_active'    => count( $unique_people ),
            'lessons_total'    => $lessons_total,
            'shops_silent'     => max( 0, count( $groups ) - count( $shops ) ),
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

    /**
     * Build a real report (no synthesis) over a custom date window.
     * Used for proof-of-concept demos that show the full pipeline:
     * real LifterLMS DB read -> real activity -> real email.
     */
    public static function build_real_for_group_window( array $group, $days_back, $course_id ) {
        $now_utc = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $end_gmt = $now_utc->format( 'Y-m-d H:i:s' );
        $start_dt = clone $now_utc;
        $start_dt->modify( '-' . max( 1, intval( $days_back ) ) . ' days' );
        $start_gmt = $start_dt->format( 'Y-m-d H:i:s' );

        return self::build_for_group( $group, $start_gmt, $end_gmt, $course_id );
    }

    /**
     * Build a *synthesised* report using a real group's real staff but
     * mocked lesson-completion activity, so Tim can see what the email
     * looks like before any real florist watches anything. Picks 1-4
     * lessons per student at random, leaves ~25% of students inactive
     * to demonstrate the "no activity this week" surfacing.
     */
    public static function synthesize_demo_for_group( array $group, $course_id ) {
        $members  = FMF_LifterLMS_Reader::group_members( $group['id'] );
        $students = FMF_LifterLMS_Reader::group_students( $members );
        $leaders  = FMF_LifterLMS_Reader::group_leaders( $members );

        // Manual recipient override beats auto-detected leaders.
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
        if ( empty( $leaders ) ) {
            $leaders = array(
                array(
                    'user_id'      => 0,
                    'role'         => 'demo',
                    'email'        => 'demo@example.com',
                    'display_name' => $group['title'] . ' team',
                ),
            );
        }
        if ( empty( $students ) ) {
            return null;
        }

        $lesson_ids = FMF_LifterLMS_Reader::lesson_ids_for_course( $course_id );
        if ( empty( $lesson_ids ) ) {
            return null;
        }

        list( $week_start_gmt, $week_end_gmt ) = self::previous_week_bounds_gmt();
        $tz = self::site_tz();
        $week_start_dt = ( new DateTime( $week_start_gmt, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
        $week_end_dt   = ( new DateTime( $week_end_gmt,   new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

        $with    = array();
        $without = array();
        $total   = 0;

        foreach ( $students as $idx => $s ) {
            // ~25% inactive, rest get 1-4 random lessons.
            $is_active = ( $idx % 4 ) !== 3;
            if ( ! $is_active ) {
                $without[] = $s;
                continue;
            }
            $count = 1 + ( crc32( $s['display_name'] ) % 4 );
            $picks = array();
            $used  = array();
            for ( $i = 0; $i < $count && count( $used ) < count( $lesson_ids ); $i++ ) {
                do {
                    $li = $lesson_ids[ ( crc32( $s['display_name'] . '|' . $i ) ) % count( $lesson_ids ) ];
                } while ( isset( $used[ $li ] ) && count( $used ) < count( $lesson_ids ) );
                $used[ $li ] = true;

                // Spread completion timestamps across the week, deterministically.
                $offset_days = ( $i + ( crc32( $s['display_name'] . $i ) % 7 ) ) % 7;
                $stamp_dt = clone $week_start_dt;
                $stamp_dt->modify( '+' . $offset_days . ' days' )->setTime( 14, 5 );
                $picks[] = array(
                    'lesson_id'    => $li,
                    'lesson_title' => get_the_title( $li ) ?: ( 'Lesson #' . $li ),
                    'completed_at' => $stamp_dt->format( 'Y-m-d H:i:s' ),
                );
            }
            $with[] = array(
                'user'         => $s,
                'lessons'      => $picks,
                'lesson_count' => count( $picks ),
                'last_at'      => self::latest_completion_local( $picks ),
            );
            $total += count( $picks );
        }

        return array(
            'group'                    => $group,
            'leader'                   => $leaders[0],
            'leaders'                  => $leaders,
            'week_start_local'         => $week_start_dt->format( 'Y-m-d H:i' ),
            'week_end_local'           => $week_end_dt->format( 'Y-m-d H:i' ),
            'week_start_label'         => $week_start_dt->format( 'M j' ),
            'week_end_label'           => $week_end_dt->format( 'M j, Y' ),
            'members_with_activity'    => $with,
            'members_without_activity' => $without,
            'total_completions'        => $total,
            'total_members'            => count( $students ),
            'has_activity'             => $total > 0,
        );
    }
}
