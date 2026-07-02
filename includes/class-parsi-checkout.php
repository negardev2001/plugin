<?php
/**
 * PARSI Classic Checkout / Cart Integration
 *
 * @package PARSI
 *
 * WooCommerce already exposes Iran's provinces through its native State field,
 * so rather than add a second province dropdown we progressively enhance the
 * existing City field into a dropdown driven by the selected State. This runs
 * on both the classic checkout and the cart-page shipping calculator
 * (see assets/js/checkout-locations.js).
 *
 * The chosen city NAME is written into the native city field (so the order
 * keeps a human-readable city); the city UUID rides in a hidden field
 * (billing_city_id on checkout, calc_shipping_city_id on the cart). Those are
 * captured into the session and injected into the shipping package destination
 * so PARSI_API_Request::get_shipping_rates() uses the exact city ID. If a
 * province can't be matched, the City field stays a normal text input and the
 * name is resolved via the bundled dataset (parsi_get_city_id_by_name).
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_Checkout {

    /** Session key holding the selected receiver city UUID. */
    const SESSION_KEY = 'parsi_receiver_city_id';

    /** Billing checkout/account field key for the receiver national ID (کد ملی). */
    const NATIONAL_CODE_FIELD = 'billing_national_code';

    /** PARSI shipping method id (rate ids look like "parsi:3"). */
    const SHIPPING_METHOD_ID = 'parsi';

    public function __construct() {
        // Hidden field that carries the chosen city UUID on the checkout form.
        add_action('woocommerce_after_checkout_billing_form', array($this, 'render_city_id_field'));

        // Cascading City dropdown assets (checkout + cart).
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Capture the chosen city UUID into the session so the live rate
        // calculation can use the exact ID instead of resolving by name.
        add_action('woocommerce_checkout_update_order_review', array($this, 'capture_city_id_from_review'));
        add_action('woocommerce_checkout_process', array($this, 'capture_city_id_from_post'));
        add_action('woocommerce_calculated_shipping', array($this, 'capture_calc_shipping_city_id'));
        add_filter('woocommerce_cart_shipping_packages', array($this, 'inject_destination_city_id'));

        // National ID (کد ملی) field — required by the PARSI API as the receiver
        // national code. Shown on the checkout and the My Account billing address;
        // WooCommerce auto-saves billing_* custom fields to the _billing_national_code
        // order meta that PARSI_API_Request::register_shipment() reads.
        add_filter('woocommerce_billing_fields', array($this, 'add_national_code_field'));
        add_filter('woocommerce_admin_billing_fields', array($this, 'add_admin_national_code_field'));
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_national_code'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_national_code'), 10, 2);
    }

    /**
     * Hidden field carrying the selected city UUID on the checkout form.
     *
     * @param WC_Checkout $checkout Checkout object.
     */
    public function render_city_id_field($checkout) {
        $value = '';
        if (is_object($checkout) && method_exists($checkout, 'get_value')) {
            $value = (string) $checkout->get_value('billing_city_id');
        }
        echo '<input type="hidden" name="billing_city_id" id="billing_city_id" value="' . esc_attr($value) . '" />';
    }

    /**
     * Enqueue the cascading dropdown script on the checkout and cart pages.
     */
    public function enqueue_assets() {
        $on_checkout = function_exists('is_checkout') && is_checkout();
        $on_cart     = function_exists('is_cart') && is_cart();
        if (!$on_checkout && !$on_cart) {
            return;
        }
        if (!class_exists('PARSI_Locations')) {
            return;
        }

        wp_enqueue_script(
            'parsi-checkout-locations',
            PARSI_PLUGIN_URL . 'assets/js/checkout-locations.js',
            array('jquery'),
            PARSI_VERSION,
            true
        );
        wp_localize_script('parsi-checkout-locations', 'parsiCheckoutLocations', array(
            'provinces' => PARSI_Locations::get_provinces_with_cities(),
            'i18n'      => array(
                'chooseCity'          => __('انتخاب شهر...', 'parsi'),
                'selectProvinceFirst' => __('ابتدا استان را انتخاب کنید', 'parsi'),
            ),
            // Drives the conditional "required" indicator on the national ID
            // field when the PARSI Post shipping method is selected.
            'nationalCode' => array(
                'fieldId'       => self::NATIONAL_CODE_FIELD,
                'methodPrefix'  => self::SHIPPING_METHOD_ID,
            ),
        ));
    }

    /**
     * Validate a posted city UUID against the dataset and store it in session.
     *
     * @param string $city_id Raw city UUID.
     */
    private function remember_city_id($city_id) {
        $city_id = sanitize_text_field((string) $city_id);
        if ($city_id === '' || !class_exists('PARSI_Locations')) {
            return;
        }
        if (!PARSI_Locations::get_city($city_id)) {
            return; // Unknown id — ignore.
        }
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, $city_id);
        }
    }

    /**
     * Capture city UUID from the serialized checkout order-review POST data.
     *
     * @param string $post_data URL-encoded checkout form data.
     */
    public function capture_city_id_from_review($post_data) {
        $parsed = array();
        parse_str((string) $post_data, $parsed);
        if (!empty($parsed['billing_city_id'])) {
            $this->remember_city_id($parsed['billing_city_id']);
        }
    }

    /**
     * Capture city UUID from the final checkout POST (fallback).
     */
    public function capture_city_id_from_post() {
        if (!empty($_POST['billing_city_id'])) {
            $this->remember_city_id(wp_unslash($_POST['billing_city_id']));
        }
    }

    /**
     * Capture city UUID from the cart-page shipping calculator submission.
     */
    public function capture_calc_shipping_city_id() {
        if (!empty($_POST['calc_shipping_city_id'])) {
            $this->remember_city_id(wp_unslash($_POST['calc_shipping_city_id']));
        }
    }

    /**
     * Inject the selected receiver city UUID into each shipping package's
     * destination so PARSI_API_Request::get_shipping_rates() can use the exact
     * city ID instead of resolving by name.
     *
     * @param array $packages Shipping packages.
     * @return array
     */
    public function inject_destination_city_id($packages) {
        if (!function_exists('WC') || !WC()->session) {
            return $packages;
        }
        $city_id = (string) WC()->session->get(self::SESSION_KEY, '');
        if ($city_id === '') {
            return $packages;
        }
        foreach ($packages as $key => $package) {
            if (isset($packages[$key]['destination']) && is_array($packages[$key]['destination'])) {
                $packages[$key]['destination']['city_id'] = $city_id;
            }
        }
        return $packages;
    }

    /**
     * Add the National ID (کد ملی) field to the billing fields.
     *
     * Registered on the checkout and the My Account billing address. It is not
     * marked required at the WooCommerce level because it is only mandatory when
     * the PARSI Post shipping method is chosen — that rule is enforced in
     * validate_national_code(). WooCommerce auto-saves billing_* custom fields to
     * the order's _billing_national_code meta and to the customer's user meta.
     *
     * @param array $fields Billing fields.
     * @return array
     */
    public function add_national_code_field($fields) {
        $fields[self::NATIONAL_CODE_FIELD] = array(
            'type'              => 'text',
            'label'             => __('کد ملی', 'parsi'),
            'placeholder'       => __('کد ملی ۱۰ رقمی', 'parsi'),
            'required'          => false,
            'class'             => array('form-row-wide'),
            'priority'          => 105,
            'maxlength'         => 10,
            'custom_attributes' => array('inputmode' => 'numeric'),
        );
        return $fields;
    }

    /**
     * Surface the National ID in the admin order "Billing" panel so staff can
     * view and edit it. The key omits the billing_ prefix; WooCommerce maps it to
     * the _billing_national_code meta.
     *
     * @param array $fields Admin billing fields.
     * @return array
     */
    public function add_admin_national_code_field($fields) {
        $fields['national_code'] = array(
            'label' => __('کد ملی', 'parsi'),
            'show'  => true,
        );
        return $fields;
    }

    /**
     * Whether any chosen shipping method for the order is PARSI Post.
     *
     * @return bool
     */
    private function checkout_uses_parsi() {
        if (!function_exists('WC') || !WC()->session) {
            return false;
        }
        $chosen = WC()->session->get('chosen_shipping_methods', array());
        if (!is_array($chosen)) {
            return false;
        }
        foreach ($chosen as $method) {
            // Rate ids look like "parsi:3" (method id : instance id).
            if (strpos((string) $method, self::SHIPPING_METHOD_ID) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate the National ID on checkout: required when PARSI Post is selected,
     * and always format-checked when a value is provided.
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Error collector.
     */
    public function validate_national_code($data, $errors) {
        $raw = isset($_POST[self::NATIONAL_CODE_FIELD])
            ? trim((string) wp_unslash($_POST[self::NATIONAL_CODE_FIELD]))
            : '';

        if ($raw === '') {
            if ($this->checkout_uses_parsi()) {
                $errors->add(
                    'billing_national_code_required',
                    __('برای ارسال با پارسی پست، وارد کردن کد ملی الزامی است.', 'parsi')
                );
            }
            return;
        }

        if (parsi_validate_national_code($raw) === false) {
            $errors->add(
                'billing_national_code_invalid',
                __('کد ملی وارد شده معتبر نیست. لطفاً یک کد ملی ۱۰ رقمی صحیح وارد کنید.', 'parsi')
            );
        }
    }

    /**
     * Persist the normalized National ID to the order's _billing_national_code
     * meta (the key PARSI_API_Request::register_shipment() reads).
     *
     * Uses the order object's update_meta_data() so it is HPOS-safe and overrides
     * the raw value WooCommerce auto-saves for the billing_ custom field. The meta
     * is written before $order->save() runs, so no extra save is needed.
     *
     * @param WC_Order $order Order being created at checkout.
     * @param array    $data  Posted checkout data.
     */
    public function save_national_code($order, $data) {
        if (!is_object($order) || !isset($_POST[self::NATIONAL_CODE_FIELD])) {
            return;
        }
        $raw = trim((string) wp_unslash($_POST[self::NATIONAL_CODE_FIELD]));
        if ($raw === '') {
            return;
        }
        $normalized = parsi_validate_national_code($raw);
        $value      = ($normalized !== false) ? $normalized : sanitize_text_field($raw);
        $order->update_meta_data('_billing_national_code', $value);
    }
}
