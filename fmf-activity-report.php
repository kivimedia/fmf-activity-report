<?php
/**
 * Plugin Name: 15 Minute Florist - Weekly Activity Report
 * Plugin URI:  https://github.com/kivimedia/fmf-activity-report
 * Description: Weekly Monday-morning email to LifterLMS group leaders showing which staff watched which workshop in The 15 Minute Florist last week.
 * Version:     1.0.0
 * Author:      Kivi Media
 * Author URI:  https://kivimedia.co
 * License:     GPL-2.0+
 * Text Domain: fmf-activity-report
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FMF_VERSION',     '1.0.9' );
define( 'FMF_PLUGIN_FILE', __FILE__ );
define( 'FMF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'FMF_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Default course id for The 15 Minute Florist. Overridable via fmf_settings option.
define( 'FMF_DEFAULT_COURSE_ID', 5685 );

// Weekly cron hook name.
define( 'FMF_CRON_HOOK', 'fmf_send_weekly_reports' );

require_once FMF_PLUGIN_DIR . 'includes/class-fmf-activator.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-deactivator.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-lifterlms-reader.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-report-builder.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-mailer.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-cron.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-admin.php';
require_once FMF_PLUGIN_DIR . 'includes/class-fmf-rest.php';

register_activation_hook(   __FILE__, array( 'FMF_Activator',   'activate' ) );
register_deactivation_hook( __FILE__, array( 'FMF_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FMF_Cron',  'register' ) );
add_action( 'plugins_loaded', array( 'FMF_Admin', 'register' ) );
add_action( 'rest_api_init',  array( 'FMF_REST', 'register_routes' ) );

// One-click unsubscribe handler (works without authentication; uses HMAC).
add_action( 'init', array( 'FMF_REST', 'maybe_handle_unsubscribe_query' ) );
