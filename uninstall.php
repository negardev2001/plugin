<?php
/**
 * PARSI Plugin Uninstall Script
 *
 * @package PARSI
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('parsi_api_key');
delete_option('parsi_api_url');
delete_option('parsi_api_method');
delete_option('parsi_handling_fee');
delete_option('parsi_enable_logging');
delete_option('parsi_default_method');

// Delete site options (multisite)
if (is_multisite()) {
    delete_site_option('parsi_api_key');
    delete_site_option('parsi_api_url');
    delete_site_option('parsi_api_method');
    delete_site_option('parsi_handling_fee');
    delete_site_option('parsi_enable_logging');
    delete_site_option('parsi_default_method');
}

// Clear any cached data
wp_cache_flush();


