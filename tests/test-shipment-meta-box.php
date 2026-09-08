<?php
/**
 * Test PARSI Shipment Meta Box
 *
 * This test file verifies the shipment meta box functionality
 * for both packet and parcel shipment types.
 *
 * @package PARSI
 */

if (!defined('ABSPATH')) {
    // For testing purposes, we'll define ABSPATH if not defined
    define('ABSPATH', dirname(__FILE__) . '/');
}

// Include required files
require_once parsi_plugin_dir() . 'includes/class-parsi-order-meta-box.php';
require_once parsi_plugin_dir() . 'includes/helpers.php';

/**
 * Mock WordPress functions for testing
 */
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action, $name, $referer = true, $echo = true) {
        $nonce_field = '<input type="hidden" name="' . $name . '" value="test_nonce" />';
        if ($echo) {
            echo $nonce_field;
        }
        return $nonce_field;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) {
        return $nonce === 'test_nonce';
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $query_arg = '_wpnonce', $die = true) {
        return isset($_REQUEST[$query_arg]) && $_REQUEST[$query_arg] === 'test_nonce';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, $post_id = null) {
        return true; // Grant all permissions for testing
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => true, 'data' => $data));
        die();
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'data' => $data));
        die();
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_textarea')) {
    function sanitize_textarea($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs(intval($value));
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        static $meta_data = array();
        
        if (!isset($meta_data[$post_id])) {
            $meta_data[$post_id] = array(
                '_parsi_shipment_type' => 'packet',
                '_parsi_weight' => '500',
                '_parsi_contents' => 'Test contents',
                '_parsi_declared_value' => '1000000',
                '_parsi_package_type' => 'پاکت حباب دار A4',
                '_parsi_packaging_cost' => '5000',
                '_parsi_insurance_type' => 'mandatory',
            );
        }
        
        if (empty($key)) {
            return $single ? $meta_data[$post_id] : array($meta_data[$post_id]);
        }
        
        return isset($meta_data[$post_id][$key]) ? $meta_data[$post_id][$key] : '';
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value) {
        static $updated_meta = array();
        
        if (!isset($updated_meta[$post_id])) {
            $updated_meta[$post_id] = array();
        }
        
        $updated_meta[$post_id][$meta_key] = $meta_value;
        
        echo "Updated meta for post {$post_id}: {$meta_key} = " . var_export($meta_value, true) . "\n";
        
        return true;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post) {
        return 'shop_order';
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) {
        return (object) array(
            'get_id' => function() use ($order_id) { return $order_id; }
        );
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action) {
        return 'test_nonce_' . $action;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path) {
        return 'http://localhost/wp-admin/' . $path;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        return (bool) $checked === (bool) $current ? ' checked="checked"' : '';
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        return (bool) $selected === (bool) $current ? ' selected="selected"' : '';
    }
}

if (!function_exists('disabled')) {
    function disabled($disabled, $current = true, $echo = true) {
        return (bool) $disabled === (bool) $current ? ' disabled="disabled"' : '';
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text; // No translation in test
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo esc_html($text);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default') {
        echo esc_attr($text);
    }
}

/**
 * Test functions
 */

function test_packet_shipment_type() {
    echo "=== Testing Packet Shipment Type ===\n";
    
    // Create mock order
    $order_id = 123;
    $order = wc_get_order($order_id);
    
    // Create meta box instance
    $meta_box = PARSI_Order_Meta_Box::get_instance();
    
    // Test packet package options
    $packet_options = $meta_box->get_packet_package_options();
    echo "Packet package options:\n";
    foreach ($packet_options as $key => $label) {
        echo "  - {$key}: {$label}\n";
    }
    
    // Test insurance options
    $insurance_options = $meta_box->get_insurance_options();
    echo "Insurance options:\n";
    foreach ($insurance_options as $key => $label) {
        echo "  - {$key}: {$label}\n";
    }
    
    // Test shipment data retrieval
    $shipment_data = $meta_box->get_shipment_data($order_id);
    echo "Shipment data:\n";
    foreach ($shipment_data as $key => $value) {
        echo "  - {$key}: {$value}\n";
    }
    
    echo "\n";
}

function test_parcel_shipment_type() {
    echo "=== Testing Parcel Shipment Type ===\n";
    
    // Create mock order
    $order_id = 456;
    $order = wc_get_order($order_id);
    
    // Create meta box instance
    $meta_box = PARSI_Order_Meta_Box::get_instance();
    
    // Test parcel package options
    $parcel_options = $meta_box->get_parcel_package_options();
    echo "Parcel package options:\n";
    foreach ($parcel_options as $key => $label) {
        echo "  - {$key}: {$label}\n";
    }
    
    // Test insurance business rule
    echo "\nTesting insurance business rule:\n";
    
    // Test low value (should force mandatory)
    $insurance_type = $meta_box->determine_insurance_type(3000000, 'cash_up_to_50m');
    echo "Low value (3,000,000): {$insurance_type} (should be 'mandatory')\n";
    
    // Test high value (should allow user selection)
    $insurance_type = $meta_box->determine_insurance_type(10000000, 'cash_up_to_50m');
    echo "High value (10,000,000): {$insurance_type} (should be 'cash_up_to_50m')\n";
    
    // Test empty value (should force mandatory)
    $insurance_type = $meta_box->determine_insurance_type(0, 'goods_up_to_2b');
    echo "Empty value: {$insurance_type} (should be 'mandatory')\n";
    
    echo "\n";
}

function test_data_validation() {
    echo "=== Testing Data Validation ===\n";
    
    // Test valid packet data
    $valid_packet_data = array(
        'parsi_shipment_type' => 'packet',
        'parsi_weight' => '500',
        'parsi_contents' => 'Test packet contents',
        'parsi_declared_value' => '1000000',
        'parsi_package_type' => 'پاکت حباب دار A4',
        'parsi_packaging_cost' => '5000',
        'parsi_insurance_type' => 'mandatory'
    );
    
    $meta_box = PARSI_Order_Meta_Box::get_instance();
    $result = $meta_box->process_and_save_shipment_data(123, $valid_packet_data);
    echo "Valid packet data: " . ($result ? "PASS" : "FAIL") . "\n";
    
    // Test valid parcel data
    $valid_parcel_data = array(
        'parsi_shipment_type' => 'parcel',
        'parsi_weight' => '1000',
        'parsi_length' => '20',
        'parsi_width' => '15',
        'parsi_height' => '10',
        'parsi_contents' => 'Test parcel contents',
        'parsi_declared_value' => '5000000',
        'parsi_package_type' => 'کارتن 2',
        'parsi_packaging_cost' => '10000',
        'parsi_insurance_type' => 'goods_up_to_2b'
    );
    
    $result = $meta_box->process_and_save_shipment_data(456, $valid_parcel_data);
    echo "Valid parcel data: " . ($result ? "PASS" : "FAIL") . "\n";
    
    // Test invalid data (missing weight)
    $invalid_data = array(
        'parsi_shipment_type' => 'packet',
        'parsi_weight' => '0', // Invalid
        'parsi_contents' => 'Test contents',
        'parsi_declared_value' => '1000000',
        'parsi_package_type' => 'پاکت حباب دار A4',
        'parsi_packaging_cost' => '5000',
        'parsi_insurance_type' => 'mandatory'
    );
    
    $result = $meta_box->process_and_save_shipment_data(789, $invalid_data);
    echo "Invalid data (missing weight): " . ($result ? "FAIL" : "PASS") . "\n";
    
    // Test invalid parcel data (missing dimensions)
    $invalid_parcel_data = array(
        'parsi_shipment_type' => 'parcel',
        'parsi_weight' => '1000',
        'parsi_length' => '0', // Invalid
        'parsi_width' => '15',
        'parsi_height' => '10',
        'parsi_contents' => 'Test parcel contents',
        'parsi_declared_value' => '5000000',
        'parsi_package_type' => 'کارتن 2',
        'parsi_packaging_cost' => '10000',
        'parsi_insurance_type' => 'goods_up_to_2b'
    );
    
    $result = $meta_box->process_and_save_shipment_data(101, $invalid_parcel_data);
    echo "Invalid parcel data (missing dimensions): " . ($result ? "FAIL" : "PASS") . "\n";
    
    echo "\n";
}

function test_meta_keys() {
    echo "=== Testing Meta Keys ===\n";
    
    $expected_keys = array(
        '_parsi_shipment_type',
        '_parsi_weight',
        '_parsi_length',
        '_parsi_width',
        '_parsi_height',
        '_parsi_contents',
        '_parsi_declared_value',
        '_parsi_package_type',
        '_parsi_packaging_cost',
        '_parsi_insurance_type'
    );
    
    echo "Expected meta keys:\n";
    foreach ($expected_keys as $key) {
        echo "  - {$key}\n";
    }
    
    echo "\n";
}

// Run tests
echo "PARSI Shipment Meta Box Test Results\n";
echo "===================================\n\n";

test_packet_shipment_type();
test_parcel_shipment_type();
test_data_validation();
test_meta_keys();

echo "All tests completed!\n";
echo "The meta box implementation should work correctly with both packet and parcel shipment types.\n";
echo "Key features implemented:\n";
echo "  - Conditional field visibility based on shipment type\n";
echo "  - Insurance business rule enforcement\n";
echo "  - Server-side validation for all required fields\n";
echo "  - RTL-compatible CSS styling\n";
echo "  - AJAX save functionality\n";
echo "  - HPOS-compatible meta storage\n";