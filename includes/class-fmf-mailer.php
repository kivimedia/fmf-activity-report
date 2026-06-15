<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Mailer {

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

        // CC the admin on real weekly reports (but not on test/preview sends,
        // and never if the admin is already the recipient).
        if ( ! $override_to && ! empty( $settings['cc_admin'] ) && is_email( $settings['cc_admin'] ) ) {
            if ( ! in_array( strtolower( $settings['cc_admin'] ), array_map( 'strtolower', $recipients ), true ) ) {
                $headers[] = 'Cc: ' . $settings['cc_admin'];
            }
        }

        $ok = wp_mail( $recipients, $subject, $body, $headers );

        return array(
            'sent'       => (bool) $ok,
            'recipients' => $recipients,
            'error'      => $ok ? '' : 'wp_mail returned false',
        );
    }

    public static function settings() {
        $defaults = array(
            'from_name'  => get_option( 'blogname' ),
            'from_email' => get_option( 'admin_email' ),
            'cc_admin'   => 'tim@theprofitableflorist.com',
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

    private static function headers( $settings ) {
        $from_name  = ! empty( $settings['from_name'] )  ? $settings['from_name']  : get_option( 'blogname' );
        $from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : get_option( 'admin_email' );
        return array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_email ),
        );
    }
}
