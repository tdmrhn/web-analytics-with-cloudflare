<?php
/**
 * Plugin Name: Web Analytics with Cloudflare RUM
 * Plugin URI: https://github.com/tdmrhn/web-analytics-with-cloudflare
 * Description: See your Cloudflare Web Analytics in WordPress: visits, page views, page load time and Core Web Vitals (LCP, INP, CLS), read live from Cloudflare with nothing stored in your database.
 * Version: 0.1
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: dmrhn
 * Author URI: https://taha.tr
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: web-analytics-with-cloudflare
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CF_ANALYTICS_VERSION', '0.1');
define('CF_ANALYTICS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF_ANALYTICS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once CF_ANALYTICS_PLUGIN_DIR . 'includes/class-cf-encryption.php';
require_once CF_ANALYTICS_PLUGIN_DIR . 'includes/class-cf-api.php';
require_once CF_ANALYTICS_PLUGIN_DIR . 'includes/class-cf-live.php';

// Admin pages
if (is_admin()) {
    require_once CF_ANALYTICS_PLUGIN_DIR . 'admin/settings.php';
    require_once CF_ANALYTICS_PLUGIN_DIR . 'admin/analytics.php';
    require_once CF_ANALYTICS_PLUGIN_DIR . 'admin/widget.php';
}

// Admin bar (both frontend and backend)
add_action('init', function() {
    if (is_admin_bar_showing()) {
        require_once CF_ANALYTICS_PLUGIN_DIR . 'admin/admin-bar.php';
    }
});

/**
 * Add settings link on plugins page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . esc_url(CF_Live::dashboard_url(array('section' => 'settings'))) . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});
