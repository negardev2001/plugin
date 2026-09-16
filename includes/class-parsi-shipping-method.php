<?php
/**
 * PARSI Shipping Method Class
 *
 * @package PARSI
 */

defined('ABSPATH') || exit;

// Ensure WC_Shipping_Method is available
if (!class_exists('WC_Shipping_Method')) {
    return;
}

// Fallback function if parsi_log is not defined
if (!function_exists('parsi_log')) {
    function parsi_log($message, $level = 'info') {
        // Silently fail if logging is not available
        return;
    }
}

/**
 * PARSI Shipping Method
 */
class PARSI_Shipping_Method extends WC_Shipping_Method {

    /**
     * Guards against registering the cart-label filter more than once (the
     * shipping method is instantiated per zone instance).
     *
     * @var bool
     */
    private static $pending_label_filter_added = false;

    /**
     * Constructor
     *
     * @param int $instance_id Instance ID
     */
    public function __construct($instance_id = 0) {

        $this->id          = 'parsi';
        $this->instance_id = absint($instance_id);
        $this->method_title       = __('ارسال با پارسی پست', 'parsi');
        $this->method_description = __('محاسبه هزینه ارسال با استفاده از API پارسی پست', 'parsi');

        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();
    }

    /**
     * Initialize shipping method
     */
    public function init() {

        // Load the settings. Instance settings are per shipping-zone, which is
        // what makes the Edit → title rename actually save and apply.
        $this->init_form_fields();
        $this->init_settings();
        $this->init_instance_settings();

        // Define user set variables. get_option() reads the per-zone instance
        // value for fields declared in instance_form_fields (title, tax_status).
        $this->title      = $this->get_option('title', __('ارسال با پارسی پست', 'parsi'));
        $this->enabled    = $this->get_option('enabled', 'yes');
        $this->tax_status = $this->get_option('tax_status', 'taxable');

        // Save settings in admin
        add_action(
            'woocommerce_update_options_shipping_' . $this->id,
            array($this, 'process_admin_options')
        );

        // Render the title-only placeholder rate without a (misleading) price.
        // Registered once regardless of how many zone instances exist.
        if (!self::$pending_label_filter_added) {
            add_filter('woocommerce_cart_shipping_method_full_label', array(__CLASS__, 'render_pending_label'), 10, 2);
            self::$pending_label_filter_added = true;
        }
    }

    /**
     * Render the placeholder rate's label without a price.
     *
     * When no destination city is set yet, calculate_shipping() adds a rate
     * flagged `parsi_pending` with a zero cost so the option is visible. Here we
     * strip the "Free"/price WooCommerce would otherwise show and add a short
     * hint that the cost appears after the address is entered.
     *
     * @param string           $label  The full rate label (name + price).
     * @param WC_Shipping_Rate $method The shipping rate object.
     * @return string
     */
    public static function render_pending_label($label, $method) {
        if (!is_object($method) || !method_exists($method, 'get_meta_data')) {
            return $label;
        }

        $meta = $method->get_meta_data();
        if (empty($meta['parsi_pending'])) {
            return $label;
        }

        return esc_html($method->get_label())
            . ' <small>(' . esc_html__('هزینه پس از ورود آدرس محاسبه می‌شود', 'parsi') . ')</small>';
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {

        // Instance (per-zone) fields so the store owner can rename the method
        // per shipping zone and have it persist and display on checkout.
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __('عنوان روش ارسال', 'parsi'),
                'type'        => 'text',
                'description' => __('این عنوان در صفحه پرداخت برای مشتری نمایش داده می‌شود.', 'parsi'),
                'default'     => __('ارسال با پارسی پست', 'parsi'),
                'desc_tip'    => true,
            ),
            'tax_status' => array(
                'title'   => __('وضعیت مالیات', 'parsi'),
                'type'    => 'select',
                'class'   => 'wc-enhanced-select',
                'default' => 'taxable',
                'options' => array(
                    'taxable' => __('مشمول مالیات', 'parsi'),
                    'none'    => __('بدون مالیات', 'parsi'),
                ),
            ),
        );
    }

    /**
     * Calculate shipping
     *
     * @param array $package Package data
     */
    public function calculate_shipping($package = array()) {

        /**
         * 🔐 Fail-safe authorization check
         */
        if (!class_exists('PARSI_Auth') || !PARSI_Auth::is_authorized()) {
            return;
        }

        // Check if live calculation is enabled
        $enable_live_calculation = get_option('parsi_enable_live_calculation', true);
        if (!$enable_live_calculation) {
            // Live calculation is disabled, return without showing rates
            if (function_exists('parsi_log')) {
                parsi_log('Live calculation is disabled in settings', 'info');
            }
            return;
        }

        // Check if API is configured
        $api_key = get_option('parsi_api_key', '');

        if (empty($api_key)) {
            if (function_exists('parsi_log')) {
                parsi_log('API key not configured', 'error');
            }
            return;
        }

        // Validate package structure to prevent errors
        if (!is_array($package) || !isset($package['destination']) || !isset($package['contents'])) {
            if (function_exists('parsi_log')) {
                parsi_log('Invalid package structure provided', 'warning');
            }
            return;
        }

        // Get package details with safe defaults
        $destination = isset($package['destination']) ? $package['destination'] : array();
        $weight      = $this->get_package_weight($package);
        $dimensions  = $this->get_package_dimensions($package);
        
        // Get origin city from settings
        $origin_city = get_option('parsi_origin_city', 'تهران');
        
        // Get packaging type from settings
        $packaging_type = get_option('parsi_packaging_type', 'standard');
        
        // Get delivery time estimate from settings
        $delivery_time = get_option('parsi_delivery_time_estimate', '3-5 روز کاری');
        
        // No destination city yet: show the method with its title only (no
        // price) so customers can see the option exists. WooCommerce
        // recalculates shipping once the address/city is entered, which
        // replaces this placeholder with the real, priced rate. The placeholder
        // shares the real rate's ID so the customer's selection carries over.
        if (empty($destination['city']) && empty($destination['city_id'])) {
            if (function_exists('parsi_log')) {
                parsi_log('Destination city is empty — showing Parsi shipping as a title-only placeholder', 'info');
            }
            $this->add_rate(array(
                'id'        => $this->get_rate_id('parsi_post'),
                'label'     => $this->title,
                'cost'      => 0,
                'meta_data' => array('parsi_pending' => true),
            ));
            return;
        }

        // Prepare API request with error handling
        try {
            if (!class_exists('PARSI_API_Request')) {
                if (function_exists('parsi_log')) {
                    parsi_log('PARSI_API_Request class not found', 'error');
                }
                return;
            }
            
            $api_request = new PARSI_API_Request();
            $rates = $api_request->get_shipping_rates(array(
                'origin_city'   => $origin_city,
                'destination'    => $destination,
                'weight'         => $weight,
                'dimensions'     => $dimensions,
                'packaging_type' => $packaging_type,
            ));
        } catch (Exception $e) {
            if (function_exists('parsi_log')) {
                parsi_log('Error creating API request: ' . $e->getMessage(), 'error');
            }
            return;
        }

        // Add rates if available
        if (!empty($rates) && is_array($rates)) {
            $fixed_shipping_cost = (float) get_option('parsi_fixed_shipping_cost', 0);
            if ($fixed_shipping_cost > 0) {
                foreach ($rates as &$rate) {
                    $rate['cost'] = $fixed_shipping_cost;
                }
                unset($rate);
            }
            
            foreach ($rates as $rate) {
                if (isset($rate['id'], $rate['label'], $rate['cost'])) {
                    // Calculate final cost with all fees
                    $final_cost = $this->calculate_final_cost($rate['cost']);
                    
                    // Check for free shipping threshold
                    $free_shipping_threshold = (float) get_option('parsi_free_shipping_threshold', 0);
                    if ($free_shipping_threshold > 0 && $this->get_cart_total() >= $free_shipping_threshold) {
                        $final_cost = 0;
                        if (function_exists('parsi_log')) {
                            parsi_log('Free shipping threshold met - shipping cost set to 0', 'info');
                        }
                    }
                    
                    // Build rate label from the store owner's custom method
                    // title (the per-zone "Title" setting) so renaming the
                    // method actually changes what the customer sees, then
                    // append the delivery-time estimate.
                    $rate_label = $this->title;
                    if (!empty($delivery_time)) {
                        $rate_label .= ' (' . $delivery_time . ')';
                    }
                    
                    // Prepare meta data
                    $meta_data = $rate['meta_data'] ?? array();
                    $meta_data['delivery_time'] = $delivery_time;
                    $meta_data['packaging_type'] = $packaging_type;
                    
                    $this->add_rate(array(
                        'id'        => $this->get_rate_id($rate['id']),
                        'label'     => $rate_label,
                        'cost'      => $final_cost,
                        'meta_data' => $meta_data,
                    ));
                }
            }
        } else {
            // Graceful failure: log warning but don't crash checkout
            if (function_exists('parsi_log')) {
                parsi_log('No shipping rates returned from API - shipping method may not be available', 'warning');
            }
            
            // Optionally add a fallback rate with error message
            $show_fallback = apply_filters('parsi_show_fallback_rate', false);
            if ($show_fallback) {
                $this->add_rate(array(
                    'id'        => $this->get_rate_id('fallback'),
                    'label'     => __('ارسال (تماس برای استعلام قیمت)', 'parsi'),
                    'cost'      => 0,
                    'meta_data' => array(
                        'fallback' => true,
                        'message' => __('در حال حاضر امکان محاسبه هزینه ارسال وجود ندارد. لطفاً برای استعلام قیمت به سایت پارسی‌پست مراجعه کنید.', 'parsi'),
                    ),
                ));
            }
        }
    }
    
    /**
     * Get cart total for free shipping threshold check
     *
     * @return float Cart total
     */
    private function get_cart_total() {
        $total = 0;
        
        if (function_exists('WC')) {
            $cart = WC()->cart;
            if ($cart) {
                $total = $cart->get_displayed_subtotal();
            }
        }
        
        return (float) $total;
    }

    /**
     * Get package weight (CRITICAL: Returns weight in kg for API, with validation)
     */
    private function get_package_weight($package) {

        $weight = 0;
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');

        // Safely check if package has contents
        if (!isset($package['contents']) || !is_array($package['contents'])) {
            // Return default weight if no contents
            return function_exists('parsi_get_average_product_weight')
                ? parsi_get_average_product_weight() / 1000 // Convert grams to kg
                : 0.5; // Fallback to 0.5kg
        }

        foreach ($package['contents'] as $item) {
            // Validate item structure
            if (!isset($item['data']) || !is_object($item['data'])) {
                continue;
            }
            
            if (method_exists($item['data'], 'has_weight') && $item['data']->has_weight()) {
                $weight += $item['data']->get_weight() * (isset($item['quantity']) ? $item['quantity'] : 1);
            }
        }

        // CRITICAL: Validate and normalize weight
        // Convert to kg first (the API expects kg which will be converted to grams)
        switch ($weight_unit) {
            case 'g':
                $weight /= 1000;
                break;
            case 'lbs':
                $weight *= 0.453592;
                break;
            case 'oz':
                $weight *= 0.0283495;
                break;
            // kg is already in correct unit
        }

        // CRITICAL: Defensive check for zero or negative weight
        if ($weight <= 0) {
            if (function_exists('parsi_log')) {
                parsi_log('Package weight is zero or negative, using average product weight', 'warning');
            }
            $weight = function_exists('parsi_get_average_product_weight')
                ? parsi_get_average_product_weight() / 1000 // Convert grams to kg
                : 0.5; // Fallback to 0.5kg
        }

        return apply_filters('parsi_package_weight', $weight, $package);
    }

    /**
     * Get package dimensions
     */
    private function get_package_dimensions($package) {

        $dimensions = array(
            'length' => 0,
            'width'  => 0,
            'height' => 0,
        );

        // Safely check if package has contents
        if (!isset($package['contents']) || !is_array($package['contents'])) {
            return $dimensions;
        }

        foreach ($package['contents'] as $item) {
            // Validate item structure
            if (!isset($item['data']) || !is_object($item['data'])) {
                continue;
            }
            
            if (method_exists($item['data'], 'has_dimensions') && $item['data']->has_dimensions()) {
                $length = $item['data']->get_length();
                $width  = $item['data']->get_width();
                $height = $item['data']->get_height();

                $unit = get_option('woocommerce_dimension_unit');
                switch ($unit) {
                    case 'm':
                        $length *= 100;
                        $width  *= 100;
                        $height *= 100;
                        break;
                    case 'mm':
                        $length /= 10;
                        $width  /= 10;
                        $height /= 10;
                        break;
                    case 'in':
                        $length *= 2.54;
                        $width  *= 2.54;
                        $height *= 2.54;
                        break;
                    case 'yd':
                        $length *= 91.44;
                        $width  *= 91.44;
                        $height *= 91.44;
                        break;
                }

                // 🔐 SECURITY: Use strict validation for dimensions
                if (function_exists('parsi_validate_dimension_strict')) {
                    $length = parsi_validate_dimension_strict($length, 'length');
                    $width = parsi_validate_dimension_strict($width, 'width');
                    $height = parsi_validate_dimension_strict($height, 'height');
                } else {
                    // Fallback to basic validation
                    $length = max(0, floatval($length));
                    $width = max(0, floatval($width));
                    $height = max(0, floatval($height));
                }

                $dimensions['length'] = max($dimensions['length'], $length);
                $dimensions['width']  = max($dimensions['width'], $width);
                $dimensions['height'] += $height * $item['quantity'];
            }
        }

        return apply_filters('parsi_package_dimensions', $dimensions, $package);
    }

    /**
     * Get rate ID
     *
     * @param string $suffix Optional suffix for the rate ID
     * @return string The formatted rate ID
     */
    public function get_rate_id($suffix = '') {
        // If no suffix provided, use the instance_id to maintain backward compatibility
        $rate_id = empty($suffix) ? $this->instance_id : $suffix;
        return $this->id . ':' . $this->instance_id . ':' . sanitize_key($rate_id);
    }

    /**
     * Calculate final cost
     *
     * @param float $base_cost Base shipping cost from API
     * @return float Final shipping cost with all fees
     */
    private function calculate_final_cost($base_cost) {

        // Handling fee (کارمزد بسته‌بندی) is a PERCENTAGE of the base shipping
        // cost, e.g. 10 → base + 10%. It is included in the price the customer
        // sees at cart/checkout (calculate_shipping adds it to the rate).
        $handling_fee_percent = (float) get_option('parsi_handling_fee', 0);
        $handling_amount      = $base_cost * ($handling_fee_percent / 100);
        $cod_fee = (float) get_option('parsi_cod_fee', 0);
        $enable_insurance = (bool) get_option('parsi_enable_shipping_insurance', false);
        
        // Only add the COD fee if the customer selected COD as their payment
        // method. The chosen payment method is stored on the session as a single
        // string (WC_Cart has no get_chosen_payment_methods() method, despite
        // the previous code; calling it fatals during cart calculation).
        $is_cod = false;
        if (function_exists('WC') && WC()->session) {
            $chosen = (string) WC()->session->get('chosen_payment_method', '');
            $is_cod = $chosen === 'cod';
        }
        
        // Calculate insurance fee (1% of base cost if enabled)
        $insurance_fee = 0;
        if ($enable_insurance && $base_cost > 0) {
            $insurance_fee = $base_cost * 0.01; // 1% insurance fee
        }
        
        // Calculate final cost (only add COD fee if COD is chosen)
        $final_cost = (float) $base_cost + $handling_amount;
        if ($is_cod) {
            $final_cost += $cod_fee;
        }
        $final_cost += $insurance_fee;

        $final_cost = apply_filters(
            'parsi_shipping_cost',
            $final_cost,
            $base_cost,
            $handling_amount,
            $is_cod ? $cod_fee : 0,
            $insurance_fee
        );

        // Round the final amount to the nearest 1,000 Toman so the price the
        // customer sees always ends in 000 (e.g. 127,352 → 127,000). Done last,
        // after the filter, so the displayed total is guaranteed to be rounded.
        if (function_exists('parsi_round_shipping_amount')) {
            $final_cost = parsi_round_shipping_amount($final_cost);
        }

        // Apply max shipping cost limit if set
        $max_shipping_cost = (float) get_option('parsi_max_shipping_cost', 0);
        if ($max_shipping_cost > 0 && $final_cost > $max_shipping_cost) {
            $final_cost = $max_shipping_cost;
            if (function_exists('parsi_log')) {
                parsi_log(sprintf('Max shipping cost limit applied: final cost capped at %s', $max_shipping_cost), 'info');
            }
        }

        return $final_cost;
    }
}