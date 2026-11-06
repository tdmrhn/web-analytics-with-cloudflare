<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes everything it saved.
 *
 * Cached analytics are transients with short lifetimes and expire on their own.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cf_analytics_settings');          // encrypted token and zone / account / site IDs
delete_option('cf_analytics_cache_gen');
delete_option('cf_analytics_refresh_interval');
delete_option('cf_analytics_primary_color');
delete_option('cf_analytics_paths');  // accent color

delete_transient('cf_analytics_last_error');
delete_transient('eacfa_valid_paths');
delete_transient('eacfa_ctx_fail');

// Per-user theme choice (light / dark / system).
delete_metadata('user', 0, 'cf_analytics_theme', '', true);
