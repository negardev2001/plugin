<?php
/**
 * Test script to verify PARSI_Shipping_Method compatibility with WooCommerce
 * 
 * This script checks that the get_rate_id() method signature matches the parent class
 */

// Load WordPress
$wp_load_path = __DIR__ . '/../../../wp-load.php';
if (file_exists($wp_load_path)) {
    require_once $wp_load_path;
} else {
    echo "WordPress not found. Please run this test within a WordPress installation.\n";
    exit(1);
}

// Check if WooCommerce is active
if (!class_exists('WC_Shipping_Method')) {
    echo "WooCommerce is not active. Please activate WooCommerce first.\n";
    exit(1);
}

// Load the PARSI shipping method class
require_once __DIR__ . '/includes/class-parsi-shipping-method.php';

// Use Reflection to check method signatures
echo "=== Testing PARSI_Shipping_Method Compatibility ===\n\n";

// Get reflection classes
$parsi_reflection = new ReflectionClass('PARSI_Shipping_Method');
$wc_reflection = new ReflectionClass('WC_Shipping_Method');

// Check get_rate_id method
if ($parsi_reflection->hasMethod('get_rate_id') && $wc_reflection->hasMethod('get_rate_id')) {
    $parsi_method = $parsi_reflection->getMethod('get_rate_id');
    $wc_method = $wc_reflection->getMethod('get_rate_id');
    
    echo "Checking get_rate_id() method signature:\n";
    echo "- PARSI_Shipping_Method::get_rate_id() parameters: ";
    $params = $parsi_method->getParameters();
    foreach ($params as $param) {
        echo '$' . $param->getName();
        if ($param->isDefaultValueAvailable()) {
            echo ' = ' . var_export($param->getDefaultValue(), true);
        }
        echo ' ';
    }
    echo "\n";
    
    echo "- WC_Shipping_Method::get_rate_id() parameters: ";
    $params = $wc_method->getParameters();
    foreach ($params as $param) {
        echo '$' . $param->getName();
        if ($param->isDefaultValueAvailable()) {
            echo ' = ' . var_export($param->getDefaultValue(), true);
        }
        echo ' ';
    }
    echo "\n";
    
    // Compare signatures
    $parsi_params = $parsi_method->getParameters();
    $wc_params = $wc_method->getParameters();
    
    $compatible = true;
    if (count($parsi_params) !== count($wc_params)) {
        $compatible = false;
    } else {
        for ($i = 0; $i < count($parsi_params); $i++) {
            if ($parsi_params[$i]->getName() !== $wc_params[$i]->getName()) {
                $compatible = false;
                break;
            }
            if ($parsi_params[$i]->isDefaultValueAvailable() !== $wc_params[$i]->isDefaultValueAvailable()) {
                $compatible = false;
                break;
            }
            if ($parsi_params[$i]->isDefaultValueAvailable() && $wc_params[$i]->isDefaultValueAvailable()) {
                if ($parsi_params[$i]->getDefaultValue() !== $wc_params[$i]->getDefaultValue()) {
                    $compatible = false;
                    break;
                }
            }
        }
    }
    
    if ($compatible) {
        echo "✓ get_rate_id() signature is compatible\n";
    } else {
        echo "✗ get_rate_id() signature is NOT compatible\n";
    }
} else {
    echo "✗ get_rate_id() method not found in one of the classes\n";
}

// Test other overridden methods
echo "\nChecking other overridden methods:\n";

$methods_to_check = ['init', 'init_form_fields', 'calculate_shipping'];

foreach ($methods_to_check as $method_name) {
    if ($parsi_reflection->hasMethod($method_name) && $wc_reflection->hasMethod($method_name)) {
        $parsi_method = $parsi_reflection->getMethod($method_name);
        $wc_method = $wc_reflection->getMethod($method_name);
        
        echo "- $method_name(): ";
        
        // Check if the number of required parameters is compatible
        $parsi_required = $parsi_method->getNumberOfRequiredParameters();
        $wc_required = $wc_method->getNumberOfRequiredParameters();
        
        if ($parsi_required <= $wc_required) {
            echo "✓ Compatible (required params: $parsi_required vs $wc_required)\n";
        } else {
            echo "✗ NOT compatible (required params: $parsi_required vs $wc_required)\n";
        }
    }
}

// Test actual instantiation and method call
echo "\nTesting actual method execution:\n";

try {
    // Create an instance
    $shipping_method = new PARSI_Shipping_Method(1);
    
    // Test get_rate_id with no parameters
    $rate_id1 = $shipping_method->get_rate_id();
    echo "- get_rate_id() without parameter: $rate_id1\n";
    
    // Test get_rate_id with a suffix
    $rate_id2 = $shipping_method->get_rate_id('test_suffix');
    echo "- get_rate_id('test_suffix'): $rate_id2\n";
    
    // Verify the format
    if (preg_match('/^parsi:\d+:\d+$/', $rate_id1)) {
        echo "✓ Rate ID format is correct for default case\n";
    } else {
        echo "✗ Rate ID format is incorrect for default case\n";
    }
    
    if (preg_match('/^parsi:\d+:test_suffix$/', $rate_id2)) {
        echo "✓ Rate ID format is correct for custom suffix\n";
    } else {
        echo "✗ Rate ID format is incorrect for custom suffix\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error testing method execution: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";