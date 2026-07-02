<?php
/**
 * Compatibility Test Script for PARSI Shipping Plugin
 * This script tests the plugin's compatibility with WooCommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function is_woocommerce_active() {
    // Check if WC class exists (most reliable)
    if (class_exists('WC')) {
        return true;
    }
    
    // Check if WooCommerce plugin is in active plugins list
    $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
    if (in_array('woocommerce/woocommerce.php', $active_plugins)) {
        return true;
    }
    
    // Check for multisite active plugins
    if (is_multisite()) {
        $active_sitewide_plugins = get_site_option('active_sitewide_plugins');
        if (isset($active_sitewide_plugins['woocommerce/woocommerce.php'])) {
            return true;
        }
    }
    
    return false;
}

// Test plugin initialization
function test_parsi_plugin_init() {
    echo "<h2>Plugin Initialization Test</h2>";
    
    // Check if constants are defined
    if (defined('PARSI_VERSION')) {
        echo "<p>✓ PARSI_VERSION defined: " . PARSI_VERSION . "</p>";
    } else {
        echo "<p>✗ PARSI_VERSION not defined</p>";
    }
    
    if (defined('PARSI_PLUGIN_DIR')) {
        echo "<p>✓ PARSI_PLUGIN_DIR defined: " . PARSI_PLUGIN_DIR . "</p>";
    } else {
        echo "<p>✗ PARSI_PLUGIN_DIR not defined</p>";
    }
    
    if (defined('PARSI_PLUGIN_URL')) {
        echo "<p>✓ PARSI_PLUGIN_URL defined: " . PARSI_PLUGIN_URL . "</p>";
    } else {
        echo "<p>✗ PARSI_PLUGIN_URL not defined</p>";
    }
    
    // Check if main plugin class exists
    if (class_exists('PARSI_Plugin')) {
        echo "<p>✓ PARSI_Plugin class exists</p>";
        
        // Test getting instance
        $instance = PARSI_Plugin::get_instance();
        if ($instance) {
            echo "<p>✓ Plugin instance created successfully</p>";
        } else {
            echo "<p>✗ Failed to create plugin instance</p>";
        }
    } else {
        echo "<p>✗ PARSI_Plugin class not found</p>";
    }
}

// Test WooCommerce compatibility
function test_woocommerce_compatibility() {
    echo "<h2>WooCommerce Compatibility Test</h2>";
    
    if (is_woocommerce_active()) {
        echo "<p>✓ WooCommerce is active</p>";
        
        // Check WC version
        if (defined('WC_VERSION')) {
            echo "<p>✓ WooCommerce version: " . WC_VERSION . "</p>";
        } else {
            echo "<p>✗ WooCommerce version not defined</p>";
        }
        
        // Check if shipping methods are loaded
        if (class_exists('WC_Shipping_Method')) {
            echo "<p>✓ WC_Shipping_Method class available</p>";
        } else {
            echo "<p>✗ WC_Shipping_Method class not available</p>";
        }
        
        // Check if PARSI shipping method is registered
        $shipping_methods = WC()->shipping->get_shipping_methods();
        if (isset($shipping_methods['parsi'])) {
            echo "<p>✓ PARSI shipping method registered</p>";
        } else {
            echo "<p>✗ PARSI shipping method not registered</p>";
        }
    } else {
        echo "<p>✗ WooCommerce is not active</p>";
    }
}

// Test settings page
function test_settings_page() {
    echo "<h2>Settings Page Test</h2>";
    
    // Check if settings are registered
    $registered_settings = get_registered_settings();
    if (isset($registered_settings['parsi_settings'])) {
        echo "<p>✓ PARSI settings registered</p>";
    } else {
        echo "<p>✗ PARSI settings not registered</p>";
    }
    
    // Check if settings sections exist
    global $wp_settings_sections;
    if (isset($wp_settings_sections['parsi-settings'])) {
        echo "<p>✓ PARSI settings sections exist</p>";
        
        $sections = $wp_settings_sections['parsi-settings'];
        foreach ($sections as $section) {
            echo "<p>  - Section: " . $section['title'] . "</p>";
        }
    } else {
        echo "<p>✗ PARSI settings sections not found</p>";
    }
    
    // Check if settings fields exist
    global $wp_settings_fields;
    if (isset($wp_settings_fields['parsi-settings'])) {
        echo "<p>✓ PARSI settings fields exist</p>";
        
        $fields = $wp_settings_fields['parsi-settings'];
        foreach ($fields as $section => $section_fields) {
            echo "<p>  - Section '$section' has " . count($section_fields) . " fields</p>";
        }
    } else {
        echo "<p>✗ PARSI settings fields not found</p>";
    }
}

// Test CSS and JS enqueuing
function test_assets() {
    echo "<h2>Assets Test</h2>";
    
    // Check if CSS file exists
    $css_file = PARSI_PLUGIN_DIR . 'assets/css/admin.css';
    if (file_exists($css_file)) {
        echo "<p>✓ Admin CSS file exists</p>";
    } else {
        echo "<p>✗ Admin CSS file not found</p>";
    }
    
    // Check if JS file exists
    $js_file = PARSI_PLUGIN_DIR . 'assets/js/parsi-translations.js';
    if (file_exists($js_file)) {
        echo "<p>✓ Admin JS file exists</p>";
    } else {
        echo "<p>✗ Admin JS file not found</p>";
    }
    
    // Check if assets are properly enqueued
    global $wp_styles, $wp_scripts;
    
    // This would need to be checked on the actual admin page
    echo "<p>ℹ Asset enqueuing should be checked on the admin page</p>";
}

// Run all tests
echo "<div class='wrap'>";
echo "<h1>PARSI Shipping Plugin Compatibility Test</h1>";

test_parsi_plugin_init();
echo "<hr>";
test_woocommerce_compatibility();
echo "<hr>";
test_settings_page();
echo "<hr>";
test_assets();

echo "</div>";
?>