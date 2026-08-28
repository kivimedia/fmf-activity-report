<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Mailer {

    /**
     * How many rows of each leaderboard the roll-up EMAIL shows. The builder now
     * returns the complete sorted lists; this is purely a display limit so the
     * email stays readable. The Leaderboards page shows everything.
     */
    const ROLLUP_LEADERBOARD_LIMIT = 10;

    /**
     * Render and send the weekly report for one group to its leader(s).
     *
     * @param array  $report   Output of FMF_Report_Builder::build_for_group()
     * @param string $override_to  Optional - if set, ignore leader emails and send to this address (test mode).
     * @return array{sent:bool,recipients:string[],error:string}
     */
    public static function send_group_report( array $report, $override_to = '' ) {
        $settings = self::settings();
        $recipients = array();
        if ( $override_to ) {
            $recipients = array( $override_to );
        } else {
            foreach ( $report['leaders'] as $l ) {
                if ( ! empty( $l['email'] ) && is_email( $l['email'] ) ) {
                    $recipients[] = $l['email'];
                }
            }
            $recipients = array_unique( $recipients );
        }

        if ( empty( $recipients ) ) {
            return array( 'sent' => false, 'recipients' => array(), 'error' => 'No leader email available' );
        }

        $subject = self::subject_for( $report );
        $body    = self::render_html( $report, $override_to );
        $headers = self::headers( $settings );

        // CC the admin + office on real weekly reports (but not on test/preview
        // sends, and never if the address is already a recipient).
        if ( ! $override_to ) {
            $recip_lower = array_map( 'strtolower', $recipients );
            foreach ( array( $settings['cc_admin'], $settings['cc_office'] ) as $cc ) {
                if ( ! empty( $cc ) && is_email( $cc ) && ! in_array( strtolower( $cc ), $recip_lower, true ) ) {
                    $headers[] = 'Cc: ' . $cc;
                }
            }
        }

        $ok = wp_mail( $recipients, $subject, $body, $headers );

        return array(
            'sent'       => (bool) $ok,
            'recipients' => $recipients,
            'error'      => $ok ? '' : 'wp_mail returned false',
        );
    }

    /**
     * Render and send the program-wide weekly roll-up (for Tim / office).
     *
     * Recipients are the same two addresses CC'd on the per-shop reports
     * (cc_admin + cc_office). Unlike send_group_report(), these are the primary
     * To recipients here since the roll-up is addressed to them directly.
     *
     * @param array  $rollup       Output of FMF_Report_Builder::build_program_rollup()
     * @param string $override_to  Optional - if set, send only to this address (test mode).
     * @return array{sent:bool,recipients:string[],error:string}
     */
    public static function send_program_rollup( array $rollup, $override_to = '' ) {
        $settings = self::settings();

        if ( $override_to ) {
            $recipients = array( $override_to );
        } else {
            $recipients = array();
            foreach ( array( $settings['cc_admin'], $settings['cc_office'] ) as $addr ) {
                if ( ! empty( $addr ) && is_email( $addr ) ) {
                    $recipients[] = $addr;
                }
            }
            $recipients = array_values( array_unique( $recipients ) );
        }

        if ( empty( $recipients ) ) {
            return array( 'sent' => false, 'recipients' => array(), 'error' => 'No roll-up recipient configured (set CC admin/office in settings)' );
        }

        $subject = sprintf( 'The 15 Minute Florist - program activity, week of %s', $rollup['week_start_label'] );
        $body    = self::render_rollup_html( $rollup, $override_to );
        $headers = self::headers( $settings );

        $ok = wp_mail( $recipients, $subject, $body, $headers );

        return array(
            'sent'       => (bool) $ok,
            'recipients' => $recipients,
            'error'      => $ok ? '' : 'wp_mail returned false',
        );
    }

    public static function render_rollup_html( array $rollup, $override_to = '' ) {
        $template = FMF_PLUGIN_DIR . 'templates/emails/program-rollup.php';
        // Variables consumed by the template:
        $week_start_label = $rollup['week_start_label'];
        $week_end_label   = $rollup['week_end_label'];
        $shops            = $rollup['shops'];
        $shops_active     = $rollup['shops_active'];
        $shops_total      = $rollup['shops_total'];
        $people_active    = $rollup['people_active'];
        $lessons_total    = $rollup['lessons_total'];
        $shops_silent     = $rollup['shops_silent'];

        // The builder hands back the COMPLETE leaderboards; the email shows only the
        // first ROLLUP_LEADERBOARD_LIMIT rows and links out to the rest. Keep the
        // full counts so the "see all N" link can name real totals.
        $full_shops_week      = isset( $rollup['top_shops_week'] )      ? $rollup['top_shops_week']      : array();
        $full_lessons_week    = isset( $rollup['top_lessons_week'] )    ? $rollup['top_lessons_week']    : array();
        $full_shops_alltime   = isset( $rollup['top_shops_alltime'] )   ? $rollup['top_shops_alltime']   : array();
        $full_lessons_alltime = isset( $rollup['top_lessons_alltime'] ) ? $rollup['top_lessons_alltime'] : array();

        $limit = self::ROLLUP_LEADERBOARD_LIMIT;
        $top_shops_week      = array_slice( $full_shops_week,      0, $limit );
        $top_lessons_week    = array_slice( $full_lessons_week,    0, $limit );
        $top_shops_alltime   = array_slice( $full_shops_alltime,   0, $limit );
        $top_lessons_alltime = array_slice( $full_lessons_alltime, 0, $limit );

        $leaderboard_limit      = $limit;
        $leaderboard_shops_all  = count( $full_shops_alltime );
        $leaderboard_class_all  = count( $full_lessons_alltime );
        $leaderboard_truncated  = ( $leaderboard_shops_all > $limit )
            || ( $leaderboard_class_all > $limit )
            || ( count( $full_shops_week ) > $limit )
            || ( count( $full_lessons_week ) > $limit );

        $admin_url        = admin_url( 'admin.php?page=fmf-activity-report' );
        $leaderboards_url = self::leaderboards_url();
        $is_test          = ! empty( $override_to );

        ob_start();
        include $template;
        return ob_get_clean();
    }

    public static function settings() {
        $defaults = array(
            'from_name'  => get_option( 'blogname' ),
            'from_email' => get_option( 'admin_email' ),
            'cc_admin'   => 'tim@theprofitableflorist.com',
            'cc_office'  => 'office@theprofitableflorist.com',
        );
        $stored = get_option( 'fmf_settings', array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
    }

    public static function subject_for( array $report ) {
        return sprintf(
            'Your 15 Minute Florist team activity - week of %s',
            $report['week_start_label']
        );
    }

    public static function render_html( array $report, $override_to = '' ) {
        $template = FMF_PLUGIN_DIR . 'templates/emails/weekly-report.php';
        // Variables consumed by the template:
        $group                    = $report['group'];
        $leader                   = $report['leader'];
        $week_start_label         = $report['week_start_label'];
        $week_end_label           = $report['week_end_label'];
        $members_with_activity    = $report['members_with_activity'];
        $members_without_activity = $report['members_without_activity'];
        $total_completions        = $report['total_completions'];
        $total_members            = $report['total_members'];
        $has_activity             = $report['has_activity'];
        $course_url               = self::course_url();
        $unsubscribe_url          = self::unsubscribe_url( intval( $group['id'] ) );
        $is_test                  = ! empty( $override_to );

        ob_start();
        include $template;
        return ob_get_clean();
    }

    public static function course_url() {
        $settings = self::settings();
        $course_id = ! empty( $settings['course_id'] ) ? intval( $settings['course_id'] ) : FMF_DEFAULT_COURSE_ID;
        $url = get_permalink( $course_id );
        return $url ? $url : home_url( '/' );
    }

    /**
     * One-click unsubscribe link for a given group. HMAC-signed, no auth needed.
     */
    public static function unsubscribe_url( $group_id ) {
        $base = home_url( '/' );
        return add_query_arg(
            array(
                'fmf_unsub' => 1,
                'g'         => intval( $group_id ),
                'k'         => self::unsubscribe_key( $group_id ),
            ),
            $base
        );
    }

    public static function unsubscribe_key( $group_id ) {
        return substr( hash_hmac( 'sha256', 'fmf_unsub_' . intval( $group_id ), wp_salt( 'auth' ) ), 0, 16 );
    }

    /**
     * Public, no-login link to the full program leaderboards - the "see everything"
     * escape hatch from the top-10 lists in the roll-up email, for recipients (Tim)
     * who have no wp-admin account.
     *
     * Deliberately NOT the cron token from fmf_settings: that one authorises real
     * sends, and this URL sits in an inbox that gets forwarded. This key is
     * read-only - worst case someone sees the rankings - and is a distinct HMAC
     * string from unsubscribe_key() so a leaked link of one kind can never be
     * replayed as the other.
     */
    public static function leaderboards_url() {
        return add_query_arg(
            array(
                'fmf_leaderboard' => 1,
                'k'               => self::leaderboards_key(),
            ),
            home_url( '/' )
        );
    }

    /**
     * The secret in the public leaderboard link.
     *
     * Stored as its own rotatable option rather than derived from wp_salt(): a
     * salt-derived key cannot be revoked in isolation, because rotating
     * AUTH_KEY/AUTH_SALT to kill one leaked link would also invalidate every
     * unsubscribe link and log out every user on the site. This can be rotated
     * from the Leaderboards page with no other consequence.
     *
     * 32 URL-safe alphanumerics (~190 bits), compared with hash_equals.
     */
    public static function leaderboards_key() {
        $token = get_option( 'fmf_leaderboard_token' );
        if ( ! is_string( $token ) || strlen( $token ) < 24 ) {
            $token = self::rotate_leaderboards_key();
        }
        return $token;
    }

    /**
     * Issue a new link and invalidate the old one. Any link already sitting in an
     * inbox stops working immediately, which is the point.
     */
    public static function rotate_leaderboards_key() {
        $token = wp_generate_password( 32, false, false );
        update_option( 'fmf_leaderboard_token', $token, false );
        return $token;
    }

    private static function headers( $settings ) {
        $from_name  = ! empty( $settings['from_name'] )  ? $settings['from_name']  : get_option( 'blogname' );
        $from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : get_option( 'admin_email' );
        return array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        );
    }
}
