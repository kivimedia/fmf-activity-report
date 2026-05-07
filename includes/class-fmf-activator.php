<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Activator {

    public static function activate() {
        if ( false === get_option( 'fmf_settings' ) ) {
            add_option( 'fmf_settings', array(
                'course_id'          => FMF_DEFAULT_COURSE_ID,
                'send_day_of_week'   => 1, // Monday (0=Sunday, 1=Monday, ...)
                'send_hour_local'    => 8, // 8am Tim's timezone
                'timezone'           => 'America/New_York',
                'min_team_size'      => 2,
                'enable_send'        => 1,
                'test_recipient'     => 'ziv@kivimedia.co',
                'from_name'          => get_option( 'blogname' ),
                'from_email'         => get_option( 'admin_email' ),
                'cron_token'         => wp_generate_password( 32, false, false ),
            ) );
        } else {
            $existing = get_option( 'fmf_settings' );
            if ( empty( $existing['cron_token'] ) ) {
                $existing['cron_token'] = wp_generate_password( 32, false, false );
                update_option( 'fmf_settings', $existing );
            }
        }

        if ( false === get_option( 'fmf_group_overrides' ) ) {
            // Per-group toggle: array<group_id => bool>. Missing = default ON.
            add_option( 'fmf_group_overrides', array() );
        }

        if ( false === get_option( 'fmf_group_recipients' ) ) {
            // Per-group shop-owner email override: array<group_id => email>.
            // When set, takes precedence over auto-detected leaders.
            add_option( 'fmf_group_recipients', array() );
        }

        if ( false === get_option( 'fmf_sent_log' ) ) {
            // array<group_id => array<week_start_iso => unix_ts>>
            add_option( 'fmf_sent_log', array() );
        }

        FMF_Cron::schedule();
    }
}
