<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMF_Deactivator {

    public static function deactivate() {
        $timestamp = wp_next_scheduled( FMF_CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, FMF_CRON_HOOK );
        }
        wp_clear_scheduled_hook( FMF_CRON_HOOK );
    }
}
