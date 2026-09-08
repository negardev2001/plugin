<?php
/**
 * PARSI Helper Functions
 *
 * @package PARSI
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert a price returned by the Parsi API into the store's currency.
 *
 * The Parsi API always returns prices in Iranian Rial (IRR). WooCommerce may
 * be configured in either Rial (IRR) or Toman (IRT). When the store uses Toman,
 * the value must be divided by 10; when it uses Rial, the value is used as-is.
 *
 * @param float|int|string $amount Price as returned by the API (in IRR).
 * @return float Price expressed in the store currency.
 */
function parsi_convert_api_price_to_store_currency($amount) {
    $amount = (float) $amount;

    if (function_exists('get_woocommerce_currency') && get_woocommerce_currency() === 'IRT') {
        return $amount / 10;
    }

    return $amount;
}

/**
 * Round a shipping amount to the nearest 1,000 Toman so the displayed price
 * always ends in three zeros (e.g. 127,352 → 127,000; 128,650 → 129,000).
 *
 * Standard half-up rounding. The step adapts to the store currency: 1,000 for
 * Toman (IRT) and 10,000 for Rial (IRR), since 1,000 Toman = 10,000 Rial.
 * Override the step with the `parsi_price_rounding_step` filter (return 0 to
 * disable rounding).
 *
 * @param float|int|string $amount Amount in the store currency.
 * @return float Rounded amount.
 */
function parsi_round_shipping_amount($amount) {
    $amount = (float) $amount;
    if ($amount <= 0) {
        return 0.0;
    }

    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
    $step     = ($currency === 'IRR') ? 10000 : 1000;
    $step     = (float) apply_filters('parsi_price_rounding_step', $step, $currency, $amount);

    if ($step <= 0) {
        return $amount;
    }

    return round($amount / $step) * $step;
}

/**
 * Convert a length value from a WooCommerce dimension unit to centimetres.
 *
 * @param float|int|string $value Length in the store's dimension unit.
 * @param string           $unit  WooCommerce dimension unit (cm, m, mm, in, yd).
 * @return float Length in centimetres.
 */
function parsi_dimension_to_cm($value, $unit = 'cm') {
    $value = (float) $value;
    switch ($unit) {
        case 'm':
            return $value * 100;
        case 'mm':
            return $value / 10;
        case 'in':
            return $value * 2.54;
        case 'yd':
            return $value * 91.44;
        case 'cm':
        default:
            return $value;
    }
}

/**
 * Compute an order's package dimensions (in cm) from its line items: the widest
 * length/width across items and the summed (stacked) height. Mirrors the cart
 * calculation in PARSI_Shipping_Method so GetPrice (checkout) and Save (order
 * registration) size the shipping box the same way.
 *
 * @param WC_Order $order The order.
 * @return array{length:float,width:float,height:float} Dimensions in cm.
 */
function parsi_get_order_dimensions_cm($order) {
    $dims = array('length' => 0.0, 'width' => 0.0, 'height' => 0.0);
    if (!is_object($order) || !method_exists($order, 'get_items')) {
        return $dims;
    }

    $unit = get_option('woocommerce_dimension_unit', 'cm');
    foreach ($order->get_items() as $item) {
        $product = (is_object($item) && method_exists($item, 'get_product')) ? $item->get_product() : null;
        if (!$product || !method_exists($product, 'has_dimensions') || !$product->has_dimensions()) {
            continue;
        }
        $qty = method_exists($item, 'get_quantity') ? max(1, (int) $item->get_quantity()) : 1;
        $l   = parsi_dimension_to_cm((float) $product->get_length(), $unit);
        $w   = parsi_dimension_to_cm((float) $product->get_width(), $unit);
        $h   = parsi_dimension_to_cm((float) $product->get_height(), $unit);

        $dims['length']  = max($dims['length'], $l);
        $dims['width']   = max($dims['width'], $w);
        $dims['height'] += $h * $qty;
    }

    return $dims;
}

/**
 * Log message to WooCommerce logger
 *
 * @param string $message Log message
 * @param string $level Log level (info, warning, error)
 * @return void
 */
function parsi_log($message, $level = 'info') {
    $enable_logging = get_option('parsi_enable_logging', true);
    $debug_mode = get_option('parsi_debug_mode', false);
    
    // Only log errors if logging is disabled
    if (!$enable_logging && 'error' !== $level) {
        return;
    }
    
    // In debug mode, log everything including warnings
    if (!$debug_mode && 'warning' === $level) {
        return;
    }
    
    if (!function_exists('wc_get_logger')) {
        return;
    }
    
    $logger = wc_get_logger();
    $context = array('source' => 'parsi');
    
    switch ($level) {
        case 'error':
            $logger->error($message, $context);
            break;
        case 'warning':
            $logger->warning($message, $context);
            break;
        case 'info':
        default:
            $logger->info($message, $context);
            break;
    }
}

/**
 * Log API request
 *
 * @param string $url API URL
 * @param array $data Request data
 * @param string $method HTTP method
 * @return void
 */
function parsi_log_api_request($url, $data = array(), $method = 'POST') {
    $debug_mode = get_option('parsi_debug_mode', false);
    
    if (!$debug_mode) {
        return;
    }
    
    // 🔐 SECURITY: Mask sensitive data before logging
    $sanitized_data = parsi_mask_sensitive_data($data);
    
    $log_message = sprintf(
        'API Request [%s] %s | Data: %s',
        $method,
        $url,
        wp_json_encode($sanitized_data)
    );
    
    parsi_log($log_message, 'info');
}

/**
 * Log API response
 *
 * @param string $url API URL
 * @param array $response Response data
 * @param int $code HTTP response code
 * @return void
 */
function parsi_log_api_response($url, $response, $code) {
    $debug_mode = get_option('parsi_debug_mode', false);
    
    if (!$debug_mode) {
        return;
    }
    
    // 🔐 SECURITY: Mask sensitive data before logging
    $sanitized_response = parsi_mask_sensitive_data($response);
    
    $log_message = sprintf(
        'API Response [%d] %s | Response: %s',
        $code,
        $url,
        wp_json_encode($sanitized_response)
    );
    
    parsi_log($log_message, 'info');
}

/**
 * 🔐 SECURITY: Mask sensitive data in logs
 * Masks API keys, phone numbers, national codes, and other sensitive information
 *
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function parsi_mask_sensitive_data($data) {
    if (is_array($data)) {
        $sanitized = array();
        foreach ($data as $key => $value) {
            // Mask sensitive keys
            $sensitive_keys = array(
                'api_key', 'apiKey', 'Api-Key', 'X-API-Key',
                'authorization', 'Authorization',
                'password', 'token',
                'receiverNationalCode', 'senderNationalCode', 'nationalCode',
                'receiverMobile', 'senderMobile', 'mobile', 'phone',
            );
            
            if (in_array($key, $sensitive_keys, true)) {
                // Mask the value (show only first 2 and last 2 characters)
                if (is_string($value) && strlen($value) > 4) {
                    $sanitized[$key] = substr($value, 0, 2) . str_repeat('*', strlen($value) - 4) . substr($value, -2);
                } else {
                    $sanitized[$key] = '***';
                }
            } elseif (is_array($value) || is_object($value)) {
                $sanitized[$key] = parsi_mask_sensitive_data($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    } elseif (is_object($data)) {
        $sanitized = new stdClass();
        foreach (get_object_vars($data) as $key => $value) {
            $sensitive_keys = array(
                'api_key', 'apiKey', 'Api-Key', 'X-API-Key',
                'authorization', 'Authorization',
                'password', 'token',
                'receiverNationalCode', 'senderNationalCode', 'nationalCode',
                'receiverMobile', 'senderMobile', 'mobile', 'phone',
            );
            
            if (in_array($key, $sensitive_keys, true)) {
                if (is_string($value) && strlen($value) > 4) {
                    $sanitized->$key = substr($value, 0, 2) . str_repeat('*', strlen($value) - 4) . substr($value, -2);
                } else {
                    $sanitized->$key = '***';
                }
            } elseif (is_array($value) || is_object($value)) {
                $sanitized->$key = parsi_mask_sensitive_data($value);
            } else {
                $sanitized->$key = $value;
            }
        }
        return $sanitized;
    }
    
    return $data;
}

/**
 * Handle API error with specific error types
 *
 * @param string $error_message Error message
 * @param array $context Additional context
 * @return void
 */
function parsi_handle_api_error($error_message, $context = array()) {
    $error_type = 'unknown';
    
    // Detect specific error types
    if (strpos($error_message, 'invalid city') !== false ||
        strpos($error_message, 'city not found') !== false ||
        strpos($error_message, 'شهر نامعتبر') !== false) {
        $error_type = 'invalid_city';
    } elseif (strpos($error_message, 'no tariff') !== false ||
              strpos($error_message, 'tariff not found') !== false ||
              strpos($error_message, 'تعرفه یافت نشد') !== false) {
        $error_type = 'missing_tariff';
    } elseif (strpos($error_message, 'unauthorized') !== false ||
              strpos($error_message, 'authentication failed') !== false ||
              strpos($error_message, 'احراز هویت') !== false) {
        $error_type = 'auth_error';
    } elseif (strpos($error_message, 'timeout') !== false ||
              strpos($error_message, 'connection') !== false) {
        $error_type = 'connection_error';
    }
    
    // Log with error type
    $log_message = sprintf(
        'API Error [%s]: %s | Context: %s',
        $error_type,
        $error_message,
        wp_json_encode($context)
    );
    
    parsi_log($log_message, 'error');
    
    // Allow custom error handling via filter
    do_action('parsi_api_error', $error_type, $error_message, $context);
    
    return $error_type;
}

/**
 * Get user-friendly error message (Persian)
 *
 * @param string $error_type Error type
 * @return string User-friendly message in Persian
 */
function parsi_get_error_message($error_type) {
    $messages = array(
        'invalid_city' => __('شهر وارد شده نامعتبر است. لطفاً آدرس ارسال خود را بررسی کنید.', 'parsi'),
        'missing_tariff' => __('تعرفه ارسال برای این مسیر در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.', 'parsi'),
        'auth_error' => __('احراز هویت API ناموفق بود. لطفاً کلید API خود را بررسی کنید.', 'parsi'),
        'connection_error' => __('اتصال به سرویس ارسال ممکن نیست. لطفاً دوباره تلاش کنید.', 'parsi'),
        'unknown' => __('خطایی در محاسبه هزینه ارسال رخ داد. لطفاً دوباره تلاش کنید.', 'parsi'),
        'api_not_configured' => __('تنظیمات API پیکربندی نشده است. لطفاً با مدیر فروشگاه تماس بگیرید.', 'parsi'),
        'live_calculation_disabled' => __('محاسبه زنده هزینه ارسال غیرفعال است.', 'parsi'),
        'empty_destination' => __('مقصد ارسال مشخص نشده است.', 'parsi'),
        'api_timeout' => __('زمان پاسخگویی API به پایان رسید. لطفاً دوباره تلاش کنید.', 'parsi'),
        'invalid_parcel_type' => __('نوع بسته نامعتبر است. نوع بسته باید «پاکت» یا «بسته» باشد.', 'parsi'),
        'invalid_weight' => __('وزن بسته نامعتبر است. لطفاً وزن محصولات را بررسی کنید.', 'parsi'),
    );
    
    return isset($messages[$error_type]) ? $messages[$error_type] : $messages['unknown'];
}

/**
 * 🔧 Get city ID by name from cached cities list
 * Used as fallback when _billing_city_id is not available
 *
 * @param string $city_name City name (e.g., "تهران")
 * @return string|null City GUID or null if not found
 */
function parsi_get_city_id_by_name($city_name) {
    if (empty($city_name)) {
        return null;
    }
    
    // 🔧 Normalize city name to handle Arabic/Persian variations
    $normalized_city_name = parsi_normalize_persian_text($city_name);

    // First, search the cached live cities list if one has been stored as an
    // option (legacy path; usually empty because the list is cached as a
    // transient, hence the dataset fallback below).
    $cities = get_option('parsi_cities_list', array());
    if (is_array($cities) && !empty($cities)) {
        foreach ($cities as $city) {
            if (isset($city['name'])) {
                $normalized_db_name = parsi_normalize_persian_text($city['name']);
                if (strtolower($normalized_db_name) === strtolower($normalized_city_name)) {
                    return $city['id'];
                }
            }
        }
    }

    // Fall back to the bundled Parsi dataset, which always has every city.
    if (!class_exists('PARSI_Locations')) {
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
    }
    if (class_exists('PARSI_Locations')) {
        $city_id = PARSI_Locations::get_city_id_by_name($city_name);
        if (!empty($city_id)) {
            return $city_id;
        }
    }

    parsi_log(sprintf('City not found by name: %s (normalized: %s)', $city_name, $normalized_city_name), 'warning');
    return null;
}

/**
 * 🔧 Get order city ID with fallback mechanism
 * Attempts to get city ID from order meta, then falls back to name matching
 *
 * @param int $order_id Order ID
 * @param string $city_name City name (for fallback)
 * @return string City GUID
 */
function parsi_get_order_city_id($order_id, $city_name = '') {
    // First, try to get from order meta (_billing_city_id)
    $city_id = get_post_meta($order_id, '_billing_city_id', true);
    
    if (!empty($city_id)) {
        parsi_log(sprintf('Using city ID from order meta: %s', $city_id), 'info');
        return $city_id;
    }
    
    // Fallback: try to find by city name
    if (!empty($city_name)) {
        $city_id = parsi_get_city_id_by_name($city_name);
        if (!empty($city_id)) {
            parsi_log(sprintf('Using city ID from name matching: %s -> %s', $city_name, $city_id), 'info');
            return $city_id;
        }
    }
    
    // Final fallback: use default city (Tehran)
    $default_city_id = '26e869d1-d9ce-4ad2-98f3-7dd1651a0b0c';
    parsi_log(sprintf('Using default city ID: %s', $default_city_id), 'warning');
    return $default_city_id;
}

/**
 * 🔧 Normalize Persian text to handle Arabic/Persian character variations
 * Converts Arabic ی (U+064A) to Persian ی (U+06CC)
 * Converts Arabic ک (U+0643) to Persian ک (U+06A9)
 *
 * @param string $text Text to normalize
 * @return string Normalized text
 */
function parsi_normalize_persian_text($text) {
    if (empty($text) || !is_string($text)) {
        return $text;
    }
    
    // Convert Arabic Ya (U+064A) and Kasra (U+0649) to Persian Ya (U+06CC)
    $normalized = str_replace(
        array("\u{064A}", "\u{0649}"),  // Arabic Ya and Kasra
        "\u{06CC}",                       // Persian Ya
        $text
    );
    
    // Convert Arabic Kaaf (U+0643) to Persian Kaaf (U+06A9)
    $normalized = str_replace(
        "\u{0643}",                       // Arabic Kaaf
        "\u{06A9}",                       // Persian Kaaf
        $normalized
    );
    
    return $normalized;
}

/**
 * Get all plugin options
 *
 * @return array
 */
function parsi_get_all_options() {
    return array(
        'api_key' => get_option('parsi_api_key', ''),
        'api_url' => get_option('parsi_api_url', ''),
        'api_method' => get_option('parsi_api_method', 'POST'),
        'handling_fee' => get_option('parsi_handling_fee', 0),
        'enable_logging' => get_option('parsi_enable_logging', true),
        'default_method' => get_option('parsi_default_method', ''),
    );
}

/**
 * Check if plugin is configured
 *
 * @return bool
 */
function parsi_is_configured() {
    $api_key = get_option('parsi_api_key', '');
    $api_url = get_option('parsi_api_url', '');
    
    return !empty($api_key) && !empty($api_url);
}

/**
 * Sanitize API response
 *
 * @param mixed $data Data to sanitize
 * @return mixed
 */
function parsi_sanitize_api_response($data) {
    if (is_array($data)) {
        return array_map('parsi_sanitize_api_response', $data);
    } elseif (is_string($data)) {
        return sanitize_text_field($data);
    } elseif (is_numeric($data)) {
        return floatval($data);
    } else {
        return $data;
    }
}

/**
 * Format shipping rate label
 *
 * @param string $label Original label
 * @param array $meta_data Meta data
 * @return string
 */
function parsi_format_rate_label($label, $meta_data = array()) {
    $formatted = $label;
    
    if (!empty($meta_data['estimated_delivery'])) {
        $formatted .= ' (' . $meta_data['estimated_delivery'] . ')';
    }
    
    return apply_filters('parsi_rate_label', $formatted, $label, $meta_data);
}

/**
 * Convert weight to grams (CRITICAL: Parsi API expects grams)
 *
 * @param float $weight Weight value
 * @param string $from_unit Source unit (kg, g, lbs, oz)
 * @return int Weight in grams
 */
function parsi_convert_weight_to_grams($weight, $from_unit = 'kg') {
    $weight = floatval($weight);
    
    // Defensive check for negative weight
    if ($weight < 0) {
        parsi_log('Negative weight detected, using 0: ' . $weight, 'warning');
        $weight = 0;
    }
    
    switch ($from_unit) {
        case 'kg':
            return intval($weight * 1000);
        case 'g':
            return intval($weight);
        case 'lbs':
            return intval($weight * 453.59237);
        case 'oz':
            return intval($weight * 28.34952);
        default:
            // Default to kg if unit not recognized
            parsi_log('Unknown weight unit: ' . $from_unit . ', defaulting to kg', 'warning');
            return intval($weight * 1000);
    }
}

/**
 * Get average product weight in grams (fallback for missing weights)
 *
 * @return int Average weight in grams
 */
function parsi_get_average_product_weight() {
    $average_weight = get_option('parsi_average_product_weight', 500); // Default 500g
    
    // Ensure it's a positive integer
    $average_weight = intval($average_weight);
    if ($average_weight <= 0) {
        $average_weight = 500; // Fallback to 500g
    }
    
    return $average_weight;
}

/**
 * Validate and normalize weight for API (always returns grams)
 *
 * @param float $weight Weight value
 * @param string $from_unit Source unit (kg, g, lbs, oz)
 * @return int Weight in grams (never zero or negative)
 */
function parsi_validate_weight($weight, $from_unit = 'kg') {
    $weight = floatval($weight);
    
    // Check if weight is missing or zero
    if (empty($weight) || $weight <= 0) {
        parsi_log('Weight is zero or missing, using average product weight', 'warning');
        return parsi_get_average_product_weight();
    }
    
    return parsi_convert_weight_to_grams($weight, $from_unit);
}

/**
 * Validate parcel type (must be "پاکت" or "بسته")
 *
 * @param string $parcel_type Parcel type to validate
 * @return bool True if valid, false otherwise
 */
function parsi_validate_parcel_type($parcel_type) {
    $valid_types = array('پاکت', 'بسته');
    
    return in_array($parcel_type, $valid_types, true);
}

/**
 * Get parcel type ID by name
 *
 * @param string $parcel_type_name Parcel type name ("پاکت" or "بسته")
 * @return string|null Parcel type ID or null if invalid
 */
function parsi_get_parcel_type_id($parcel_type_name) {
    if (!parsi_validate_parcel_type($parcel_type_name)) {
        return null;
    }
    
    // Get the configured parcel type IDs from options
    $packet_id = get_option('parsi_parcel_type_id_packet', '');
    $package_id = get_option('parsi_parcel_type_id_package', '');
    
    // Map names to IDs
    if ('پاکت' === $parcel_type_name) {
        return $packet_id;
    } elseif ('بسته' === $parcel_type_name) {
        return $package_id;
    }
    
    return null;
}

/**
 * Get Persian error message for invalid parcel type
 *
 * @return string Error message in Persian
 */
function parsi_get_parcel_type_error_message() {
    return __('نوع بسته نامعتبر است. نوع بسته باید «پاکت» یا «بسته» باشد.', 'parsi');
}

/**
 * 🔐 SECURITY: Validate weight as positive integer (grams)
 * Enforces strict numeric validation for weight values
 *
 * @param mixed $weight Weight value to validate
 * @param string $unit Unit (optional, for logging)
 * @return int Validated weight in grams, or 0 if invalid
 */
function parsi_validate_weight_strict($weight, $unit = '') {
    // Check if weight is numeric
    if (!is_numeric($weight)) {
        parsi_log(sprintf('Invalid weight provided (not numeric): %s', var_export($weight, true)), 'warning');
        return 0;
    }
    
    // Convert to float
    $weight = floatval($weight);
    
    // Check if weight is positive
    if ($weight <= 0) {
        parsi_log(sprintf('Invalid weight provided (not positive): %s %s', $weight, $unit), 'warning');
        return 0;
    }
    
    // Check if weight is reasonable (max 1000kg = 1,000,000g)
    if ($weight > 1000000) {
        parsi_log(sprintf('Weight exceeds maximum limit: %s %s', $weight, $unit), 'warning');
        return 0;
    }
    
    // Return as integer (grams)
    return intval($weight);
}

/**
 * 🔐 SECURITY: Validate dimension as positive integer (centimeters)
 * Enforces strict numeric validation for dimension values
 *
 * @param mixed $dimension Dimension value to validate
 * @param string $name Dimension name (length, width, height) for logging
 * @return int Validated dimension in cm, or 0 if invalid
 */
function parsi_validate_dimension_strict($dimension, $name = 'dimension') {
    // Check if dimension is numeric
    if (!is_numeric($dimension)) {
        parsi_log(sprintf('Invalid %s provided (not numeric): %s', $name, var_export($dimension, true)), 'warning');
        return 0;
    }
    
    // Convert to float
    $dimension = floatval($dimension);
    
    // Check if dimension is positive
    if ($dimension <= 0) {
        parsi_log(sprintf('Invalid %s provided (not positive): %s', $name, $dimension), 'warning');
        return 0;
    }
    
    // Check if dimension is reasonable (max 500cm)
    if ($dimension > 500) {
        parsi_log(sprintf('%s exceeds maximum limit: %s cm', $name, $dimension), 'warning');
        return 0;
    }
    
    // Return as integer
    return intval($dimension);
}

/**
 * 🔐 SECURITY: Validate phone number (Iranian format)
 * Validates phone numbers in Iranian format: 09xxxxxxxxx
 *
 * @param mixed $phone Phone number to validate
 * @return string|false Validated phone number or false if invalid
 */
function parsi_validate_phone_strict($phone) {
    // Remove any non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Validate Iranian mobile format
    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        parsi_log(sprintf('Invalid Iranian phone number: %s', $phone), 'warning');
        return false;
    }
    
    return $phone;
}

/**
 * 🔐 SECURITY: Validate postal code (Iranian format)
 * Validates postal codes in Iranian format: 10 digits
 *
 * @param mixed $postal_code Postal code to validate
 * @return string|false Validated postal code or false if invalid
 */
function parsi_validate_postal_code_strict($postal_code) {
    // Remove any non-digit characters
    $postal_code = preg_replace('/[^0-9]/', '', $postal_code);
    
    // Validate Iranian postal code format (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $postal_code)) {
        parsi_log(sprintf('Invalid Iranian postal code: %s', $postal_code), 'warning');
        return false;
    }
    
    return $postal_code;
}

/**
 * Convert Persian (۰-۹) and Arabic-Indic (٠-٩) digits to Latin (0-9).
 *
 * Users frequently type national codes / postal codes with localized digits;
 * normalizing first lets the numeric validators below work uniformly.
 *
 * @param mixed $value Input that may contain non-Latin digits
 * @return string
 */
function parsi_normalize_digits($value) {
    $value   = (string) $value;
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
    $latin   = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');

    $value = str_replace($persian, $latin, $value);
    $value = str_replace($arabic, $latin, $value);

    return $value;
}

/**
 * 🔐 SECURITY: Validate an Iranian national ID (کد ملی)
 *
 * Ports the standard checksum algorithm: 10 digits, not a single repeated
 * digit, with a trailing check digit. Persian/Arabic digits are normalized
 * first and shorter inputs are left-padded with zeros, because national IDs
 * may carry leading zeros that get dropped when typed.
 *
 * @param mixed $code Raw national ID
 * @return string|false The normalized 10-digit code, or false if invalid
 */
function parsi_validate_national_code($code) {
    $code = parsi_normalize_digits($code);
    $code = preg_replace('/[^0-9]/', '', (string) $code);

    if ($code === '') {
        return false;
    }

    // Leading zeros are commonly dropped — restore them before validating.
    if (strlen($code) < 10) {
        $code = str_pad($code, 10, '0', STR_PAD_LEFT);
    }

    if (!preg_match('/^[0-9]{10}$/', $code)) {
        return false;
    }

    // Reject all-identical sequences (0000000000, 1111111111, ...).
    if (preg_match('/^(\d)\1{9}$/', $code)) {
        return false;
    }

    $check = (int) $code[9];
    $sum   = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += ((int) $code[$i]) * (10 - $i);
    }
    $remainder = $sum % 11;

    $valid = ($remainder < 2)
        ? ($check === $remainder)
        : ($check === (11 - $remainder));

    return $valid ? $code : false;
}

/**
 * 🔐 SECURITY: Validate city ID
 * Validates city ID format (UUID or numeric)
 *
 * @param mixed $city_id City ID to validate
 * @return string|false Validated city ID or false if invalid
 */
function parsi_validate_city_id_strict($city_id) {
    // Check if empty
    if (empty($city_id)) {
        parsi_log('Empty city ID provided', 'warning');
        return false;
    }
    
    // Sanitize
    $city_id = sanitize_text_field($city_id);
    
    // Validate UUID format (most common for Parsi API)
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $city_id)) {
        return $city_id;
    }
    
    // Validate numeric format (fallback)
    if (is_numeric($city_id) && intval($city_id) > 0) {
        return $city_id;
    }
    
    parsi_log(sprintf('Invalid city ID format: %s', $city_id), 'warning');
    return false;
}

/**
 * 🔐 SECURITY: Check if order can make API request (flood prevention)
 * Prevents repeated API calls for the same order within short intervals
 *
 * @param int $order_id Order ID
 * @param string $action_type Type of action (register, sync, tracking)
 * @param int $min_interval Minimum interval in seconds (default: 30)
 * @return bool True if request is allowed, false otherwise
 */
function parsi_can_make_api_request($order_id, $action_type = 'default', $min_interval = 30) {
    // Get the last request time for this order and action
    $last_request_key = '_parsi_last_api_request_' . $action_type;
    $last_request_time = get_post_meta($order_id, $last_request_key, true);
    
    // If no previous request, allow
    if (empty($last_request_time)) {
        return true;
    }
    
    // Calculate time difference
    $time_diff = current_time('timestamp') - intval($last_request_time);
    
    // Check if minimum interval has passed
    if ($time_diff < $min_interval) {
        parsi_log(sprintf('API request blocked for order #%s (action: %s) - too soon (%d seconds elapsed, minimum: %d)',
            $order_id, $action_type, $time_diff, $min_interval), 'warning');
        return false;
    }
    
    return true;
}

/**
 * 🔐 SECURITY: Record API request time for an order (flood prevention)
 * Records the timestamp of the last API request for an order
 *
 * @param int $order_id Order ID
 * @param string $action_type Type of action (register, sync, tracking)
 * @return void
 */
function parsi_record_api_request_time($order_id, $action_type = 'default') {
    $last_request_key = '_parsi_last_api_request_' . $action_type;
    update_post_meta($order_id, $last_request_key, current_time('timestamp'));
}

/**
 * 🔐 SECURITY: Atomically check and set shipment registered flag
 * Prevents race conditions in shipment registration
 *
 * @param int $order_id Order ID
 * @return bool True if shipment was registered, false if already registered
 */
function parsi_atomic_register_shipment($order_id) {
    global $wpdb;
    
    // Use a transaction-like approach with UPDATE and check
    $table = $wpdb->postmeta;
    $post_id = intval($order_id);
    $meta_key = '_parsi_shipment_registered';
    
    // Try to update the flag atomically
    $result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $table SET meta_value = 'yes'
             WHERE post_id = %d AND meta_key = %s AND meta_value != 'yes'",
            $post_id,
            $meta_key
        )
    );
    
    // Check if any rows were updated (meaning it wasn't already 'yes')
    return $result > 0;
}

/**
 * Build the `parcelExtraServices` list sent with GetPrice / Save Order.
 *
 * Pickup from the sender's location ("جمع‌آوری از محل" / door-to-door) is an
 * API *extra service* added to the parcel's `parcelExtraServices` array (see
 * GetAllExtraService in the Postman collection), NOT a service-type swap. When
 * "جمع‌آوری از محل" (`parsi_enable_pickup`) is ON and a pickup extra-service is
 * selected (`parsi_pickup_extra_service_id`), its GUID is appended to whatever
 * extra services the caller already passed.
 *
 * The two endpoints use DIFFERENT shapes for this field (confirmed by live
 * testing), so the format is explicit per caller:
 *   - GetPrice → 'objects': List<ParcelExtraServiceModel>, i.e.
 *     `[{ "extraserviceId": "<guid>" }]`. Passing a bare string here fails with
 *     "Error converting value … to type …ParcelExtraServiceModel".
 *   - Save     → 'ids': List<Guid>, i.e. `["<guid>"]`. Passing an object here
 *     fails with "Cannot deserialize the current JSON object … into System.Guid".
 * The object key for 'objects' is filterable via `parsi_extra_service_id_key`
 * (default `extraserviceId`).
 *
 * With pickup OFF (and no caller-supplied services) this returns an empty
 * array in either format, so request payloads stay byte-identical to before
 * the feature existed.
 *
 * @param array  $existing Extra-service GUIDs the caller already resolved
 *                         (bare strings, or objects carrying the id).
 * @param string $format   'objects' (GetPrice) or 'ids' (Save).
 * @return array List formatted for the requested endpoint.
 */
function parsi_resolve_parcel_extra_services($existing = array(), $format = 'ids') {
    // Normalize any caller-supplied entries down to bare GUID strings.
    $ids = array();
    if (is_array($existing)) {
        foreach ($existing as $entry) {
            if (is_string($entry) && $entry !== '') {
                $ids[] = $entry;
            } elseif (is_array($entry)) {
                foreach (array('extraserviceId', 'id') as $k) {
                    if (!empty($entry[$k])) {
                        $ids[] = $entry[$k];
                        break;
                    }
                }
            }
        }
    }

    if (get_option('parsi_enable_pickup', false)) {
        $pickup_id = parsi_get_setting('parsi_pickup_extra_service_id', '');
        if ($pickup_id === '') {
            parsi_log('Pickup (door-to-door) is enabled but no pickup extra-service is selected in settings; skipping it.', 'warning');
        } elseif (!in_array($pickup_id, $ids, true)) {
            $ids[] = $pickup_id;
        }
    }

    if ($format === 'objects') {
        $id_key  = apply_filters('parsi_extra_service_id_key', 'extraserviceId');
        $objects = array();
        foreach ($ids as $id) {
            $objects[] = array($id_key => $id);
        }
        return $objects;
    }

    return array_values($ids);
}

/**
 * 🔐 SECURITY: Atomically update order meta with race condition protection
 * Prevents race conditions when updating order meta
 *
 * @param int $order_id Order ID
 * @param string $meta_key Meta key
 * @param mixed $meta_value Meta value
 * @return bool True if update was successful
 */
function parsi_atomic_update_order_meta($order_id, $meta_key, $meta_value) {
    global $wpdb;
    
    $table = $wpdb->postmeta;
    $post_id = intval($order_id);
    $sanitized_key = sanitize_key($meta_key);
    
    // Serialize value if it's an array or object
    if (is_array($meta_value) || is_object($meta_value)) {
        $meta_value = maybe_serialize($meta_value);
    }
    
    // Use REPLACE for atomic update
    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO $table (post_id, meta_key, meta_value)
             VALUES (%d, %s, %s)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            $post_id,
            $sanitized_key,
            $meta_value
        )
    );
    
    return $result !== false;
}

