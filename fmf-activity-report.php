<?php
/**
 * Plugin Name: 15 Minute Florist - Weekly Activity Report
 * Plugin URI:  https://github.com/kivimedia/fmf-activity-report
 * Description: Weekly Monday-morning email to LifterLMS group leaders showing which staff watched which workshop in The 15 Minute Florist last week.
 * Version:     1.4.2
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

/**
 * Bail if another copy of this plugin is already loaded.
 *
 * WordPress identifies plugins by FOLDER NAME, so two directories holding this
 * same plugin (e.g. `fmf-activity-report` alongside `fmf-activity-report-main`,
 * which is what GitHub's "Download ZIP" produces) install as two unrelated
 * plugins and can both be activated. The second one to load then re-runs every
 * define() in this file, emitting a PHP warning per constant on EVERY request -
 * output sent before WordPress sends its headers, which can leave wp-admin
 * blank. Its code never even runs: FMF_PLUGIN_DIR still points at the first
 * copy, so the require_once calls below resolve to already-loaded files.
 *
 * Returning here makes the duplicate silent and inert instead. This is a guard,
 * not a fix: the real fix is to keep exactly one plugin directory on the server
 * and delete the other. Check via /wp-json/wp/v2/plugins (which one is active).
 */
if ( defined( 'FMF_VERSION' ) ) {
    return;
}

define( 'FMF_VERSION',     '1.4.2' );
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

// Public full-leaderboard page linked from the roll-up email (HMAC, no login).
add_action( 'init', array( 'FMF_REST', 'maybe_handle_leaderboard_query' ) );
