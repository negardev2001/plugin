<?php
/**
 * PARSI API Request Client
 *
 * @package PARSI
 *
 * Talks to the Parsi Post API. Payloads mirror docs/Parsi-Post-API.postman_collection.json.
 * Authentication is custom headers: x-api-key + office.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_API_Request {

    /** @var string */
    private $api_key;

    /** @var string */
    private $office_id;

    public function __construct() {
        $this->api_key   = (string) get_option('parsi_api_key', '');
        $this->office_id = (string) get_option('parsi_office_id', '');
    }

    public function is_configured() {
        return $this->api_key !== '' && $this->office_id !== '';
    }

    // -----------------------------------------------------------------------
    // Public endpoint methods
    // -----------------------------------------------------------------------

    public function get_basic_info() {
        return $this->request('GET', PARSI_API_Config::get_basic_info_url());
    }

    public function get_price(array $args) {
        $body = $this->build_get_price_body($args);
        if (is_wp_error($body)) {
            return $body;
        }
        return $this->request('POST', PARSI_API_Config::get_price_url(), $body);
    }

    public function save_order(array $args) {
        $body = $this->build_save_order_body($args);
        if (is_wp_error($body)) {
            return $body;
        }
        return $this->request('POST', PARSI_API_Config::get_save_order_url(), $body);
    }

    public function test_connection() {
        $response = $this->get_basic_info();
        if (is_wp_error($response)) {
            return $response;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Backward-compat adapters for existing callers
    // -----------------------------------------------------------------------

    /**
     * Adapter for PARSI_Shipping_Method::calculate_shipping().
     */
    public function get_shipping_rates($package_data) {
        if (!$this->is_configured()) {
            parsi_log('API key or office ID not configured', 'error');
            return false;
        }
        if (!is_array($package_data) || empty($package_data['destination'])) {
            parsi_log('Invalid package data for rate calculation', 'error');
            return false;
        }

        $destination = $package_data['destination'];

        $sender_city_id = parsi_get_setting('parsi_sender_city_id');
        if (empty($sender_city_id)) {
            parsi_log('parsi_sender_city_id not configured', 'error');
            return false;
        }

        $receiver_city_id = '';
        if (!empty($destination['city_id'])) {
            $receiver_city_id = $destination['city_id'];
        } elseif (!empty($destination['city'])) {
            $receiver_city_id = parsi_get_city_id_by_name($destination['city']);
        }
        if (empty($receiver_city_id)) {
            parsi_log('Could not resolve receiver city ID from: ' . wp_json_encode($destination), 'warning');
            return false;
        }

        $weight_grams = parsi_validate_weight(
            isset($package_data['weight']) ? $package_data['weight'] : 0,
            'kg'
        );

        // Auto-select the parcel type from weight + whether the package has
        // dimensions (پاکت for light/dimensionless, otherwise بسته).
        $dimensions     = isset($package_data['dimensions']) && is_array($package_data['dimensions']) ? $package_data['dimensions'] : array();
        $has_dimensions = (float) (isset($dimensions['length']) ? $dimensions['length'] : 0) > 0
            || (float) (isset($dimensions['width']) ? $dimensions['width'] : 0) > 0
            || (float) (isset($dimensions['height']) ? $dimensions['height'] : 0) > 0;

        $parcel_type_id = '';
        if (!class_exists('PARSI_Locations')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
        }
        if (class_exists('PARSI_Locations')) {
            $parcel_type_id = PARSI_Locations::select_parcel_type($weight_grams, $has_dimensions);
        }

        $response = $this->get_price(array(
            'sender_city_id'       => $sender_city_id,
            'receiver_city_id'     => $receiver_city_id,
            'sender_postal_code'   => parsi_get_setting('parsi_sender_postal_code', get_option('woocommerce_store_postcode', '')),
            'sender_location'      => $this->get_sender_location($sender_city_id),
            'sender_national_code' => parsi_get_setting('parsi_sender_national_code', ''),
            'parcel_type_id'       => $parcel_type_id,
            'weight'               => $weight_grams,
        ));

        if (is_wp_error($response)) {
            parsi_handle_api_error($response->get_error_message(), array('endpoint' => 'GetPrice'));
            return false;
        }

        $cost = $this->extract_price_from_response($response);
        if ($cost === null) {
            parsi_log('GetPrice response did not contain a recognizable price field. Body: ' . wp_json_encode($response), 'warning');
            return false;
        }

        // API returns the price in IRR; convert to the store currency (IRT divides by 10).
        $cost = parsi_convert_api_price_to_store_currency($cost);

        return array(
            array(
                'id'        => 'parsi_post',
                'label'     => __('ارسال با پارسی پست', 'parsi'),
                'cost'      => (float) $cost,
                'meta_data' => array(),
            ),
        );
    }

    /**
     * Adapter for PARSI_Orders::register_shipment_on_order_creation().
     */
    public function register_shipment($shipment_data) {
        if (!$this->is_configured()) {
            parsi_log('API key or office ID not configured for shipment registration', 'error');
            return false;
        }

        $required = array('order_id', 'recipient_name', 'phone', 'address', 'postal_code', 'weight');
        foreach ($required as $field) {
            if (empty($shipment_data[$field])) {
                parsi_log('Missing required shipment field: ' . $field, 'error');
                return false;
            }
        }

        // Receiver national code is required by the API. Read from the order via
        // CRUD (HPOS-safe — get_post_meta() returns nothing when High-Performance
        // Order Storage is enabled). Falls back to the customer's saved billing
        // national code for legacy orders. If still missing, abort with a clear note.
        $order_id = (int) $shipment_data['order_id'];
        $order    = function_exists('wc_get_order') ? wc_get_order($order_id) : false;

        $national_raw = $order ? (string) $order->get_meta('_billing_national_code', true) : '';
        if ($national_raw === '' && $order && $order->get_customer_id()) {
            $national_raw = (string) get_user_meta($order->get_customer_id(), 'billing_national_code', true);
        }

        // Normalize to the canonical 10-digit form before sending to the API.
        $normalized             = parsi_validate_national_code($national_raw);
        $receiver_national_code = ($normalized !== false)
            ? $normalized
            : preg_replace('/[^0-9]/', '', parsi_normalize_digits($national_raw));

        if ($receiver_national_code === '') {
            return array(
                'success' => false,
                'error'   => __('کد ملی گیرنده در سفارش ثبت نشده است. لطفاً فیلد کد ملی را به فرم تسویه‌حساب اضافه کنید.', 'parsi'),
            );
        }

        $sender_city_id   = parsi_get_setting('parsi_sender_city_id');
        $receiver_city_id = !empty($shipment_data['city_id'])
            ? $shipment_data['city_id']
            : parsi_get_city_id_by_name($shipment_data['city'] ?? '');

        if (empty($sender_city_id)) {
            parsi_log('parsi_sender_city_id not configured', 'error');
            return false;
        }
        if (empty($receiver_city_id)) {
            return array(
                'success' => false,
                'error'   => __('شناسه شهر گیرنده مشخص نیست. لطفاً شهر سفارش را بررسی کنید.', 'parsi'),
            );
        }

        $name_parts = preg_split('/\s+/', trim((string) $shipment_data['recipient_name']), 2);
        $first_name = isset($name_parts[0]) ? $name_parts[0] : (string) $shipment_data['recipient_name'];
        $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';

        $weight_grams = parsi_validate_weight($shipment_data['weight'], 'kg');

        $response = $this->save_order(array(
            'sender_first_name'    => parsi_get_setting('parsi_sender_first_name', get_bloginfo('name')),
            'sender_last_name'     => parsi_get_setting('parsi_sender_last_name', ''),
            'sender_address'       => parsi_get_setting('parsi_sender_address', get_option('woocommerce_store_address', '')),
            'sender_city_id'       => $sender_city_id,
            'sender_postal_code'   => parsi_get_setting('parsi_sender_postal_code', get_option('woocommerce_store_postcode', '')),
            'sender_national_code' => parsi_get_setting('parsi_sender_national_code', ''),
            'sender_mobile'        => parsi_get_setting('parsi_sender_mobile', ''),
            'sender_location'      => $this->get_sender_location($sender_city_id),

            'receiver_first_name'    => $first_name,
            'receiver_last_name'     => $last_name,
            'receiver_address'       => $shipment_data['address'],
            'receiver_address_2'     => isset($shipment_data['address_2']) ? $shipment_data['address_2'] : '',
            'receiver_city_id'       => $receiver_city_id,
            'receiver_postal_code'   => $shipment_data['postal_code'],
            'receiver_national_code' => $receiver_national_code,
            'receiver_mobile'        => $shipment_data['phone'],

            'weight'  => $weight_grams,
            'content' => isset($shipment_data['description']) ? $shipment_data['description'] : '',
        ));

        if (is_wp_error($response)) {
            parsi_log('Save Order failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $tracking_number = $this->extract_tracking_from_response($response);
        $shipment_id     = $this->extract_shipment_id_from_response($response);
        $order_code      = $this->extract_order_code_from_response($response);

        return array(
            'success'         => $tracking_number !== '',
            'tracking_number' => $tracking_number,
            'shipment_id'     => $shipment_id,
            'order_code'      => $order_code,
            'raw_response'    => $response,
        );
    }

    // -----------------------------------------------------------------------
    // Payload builders
    // -----------------------------------------------------------------------

    private function build_get_price_body(array $args) {
        $required = array('sender_city_id', 'receiver_city_id', 'weight');
        foreach ($required as $key) {
            if (empty($args[$key])) {
                return new WP_Error('parsi_missing_field', 'Missing required GetPrice field: ' . $key);
            }
        }

        // Parcel type is auto-selected per call (by weight/dimensions); prefer
        // the per-call value, falling back to the configured/ default parcel type.
        $parcel_type_id = !empty($args['parcel_type_id'])
            ? $args['parcel_type_id']
            : parsi_get_setting('parsi_parcel_type_id', PARSI_API_Config::DEFAULT_PARCEL_TYPE_ID);

        return array(
            'contractId'          => parsi_get_setting('parsi_contract_id', $this->arg_or_default($args, 'contract_id', PARSI_API_Config::DEFAULT_CONTRACT_ID)),
            'isForeign'           => (bool) (isset($args['is_foreign']) ? $args['is_foreign'] : false),
            'serviceTypeId'       => parsi_get_setting('parsi_service_type_id', $this->arg_or_default($args, 'service_type_id', PARSI_API_Config::DEFAULT_SERVICE_TYPE_ID)),
            'insuranceTypeId'     => parsi_get_setting('parsi_insurance_type_id', $this->arg_or_default($args, 'insurance_type_id', PARSI_API_Config::DEFAULT_INSURANCE_TYPE_ID)),
            'deadLineId'          => parsi_get_setting('parsi_deadline_id', $this->arg_or_default($args, 'deadline_id', PARSI_API_Config::DEFAULT_DEADLINE_ID)),
            'senderCityId'        => $args['sender_city_id'],
            'senderPostalCode'    => isset($args['sender_postal_code']) ? (string) $args['sender_postal_code'] : '',
            'senderLocation'      => $this->format_location(isset($args['sender_location']) ? $args['sender_location'] : ''),
            'senderNationalCode'  => isset($args['sender_national_code']) ? (string) $args['sender_national_code'] : '',
            'receiverCityId'      => $args['receiver_city_id'],
            'parcelTypeId'        => $parcel_type_id,
            'boxTypeId'           => parsi_get_setting('parsi_box_type_id', $this->arg_or_default($args, 'box_type_id', PARSI_API_Config::DEFAULT_BOX_TYPE_ID)),
            'weight'              => (int) $args['weight'],
            'priceValue'          => isset($args['declared_value']) ? (int) $args['declared_value'] : 0,
            'packingPrice'        => isset($args['packing_price']) ? (int) $args['packing_price'] : 0,
            'parcelExtraServices' => isset($args['parcel_extra_services']) && is_array($args['parcel_extra_services'])
                ? $args['parcel_extra_services']
                : array(),
        );
    }

    private function build_save_order_body(array $args) {
        $required = array(
            'sender_first_name', 'sender_address', 'sender_city_id',
            'sender_postal_code', 'sender_national_code', 'sender_mobile',
            'receiver_first_name', 'receiver_address', 'receiver_city_id',
            'receiver_postal_code', 'receiver_national_code', 'receiver_mobile',
            'weight',
        );
        foreach ($required as $key) {
            if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
                return new WP_Error('parsi_missing_field', 'Missing required Save Order field: ' . $key);
            }
        }

        return array(
            'contractId'         => parsi_get_setting('parsi_contract_id', $this->arg_or_default($args, 'contract_id', PARSI_API_Config::DEFAULT_CONTRACT_ID)),
            'senderCityId'       => $args['sender_city_id'],
            'senderFirstName'    => (string) $args['sender_first_name'],
            'senderLastName'     => isset($args['sender_last_name']) ? (string) $args['sender_last_name'] : '',
            'senderAddress'      => (string) $args['sender_address'],
            'senderPostalCode'   => (string) $args['sender_postal_code'],
            'senderAddressTitle' => isset($args['sender_address_title']) ? (string) $args['sender_address_title'] : 'home',
            'senderLocation'     => $this->format_location(isset($args['sender_location']) ? $args['sender_location'] : ''),
            'senderNationalCode' => (string) $args['sender_national_code'],
            'senderMobile'       => (string) $args['sender_mobile'],
            'paymentTypeId'      => parsi_get_setting('parsi_payment_type_id', $this->arg_or_default($args, 'payment_type_id', PARSI_API_Config::DEFAULT_PAYMENT_TYPE_ID)),
            'parcels'            => array(
                array(
                    'ServiceTypeId'        => parsi_get_setting('parsi_service_type_id', $this->arg_or_default($args, 'service_type_id', PARSI_API_Config::DEFAULT_SERVICE_TYPE_ID)),
                    'DeadlineId'           => parsi_get_setting('parsi_deadline_id', $this->arg_or_default($args, 'deadline_id', PARSI_API_Config::DEFAULT_DEADLINE_ID)),
                    'receiverCityId'       => $args['receiver_city_id'],
                    'receiverFirstName'    => (string) $args['receiver_first_name'],
                    'receiverLastName'     => isset($args['receiver_last_name']) ? (string) $args['receiver_last_name'] : '',
                    'receiverPostalCode'   => (string) $args['receiver_postal_code'],
                    'receiverNationalCode' => (string) $args['receiver_national_code'],
                    'receiverMobile'       => (string) $args['receiver_mobile'],
                    'receiverAddress'      => (string) $args['receiver_address'],
                    'receiverLocation'     => $this->format_location(isset($args['receiver_location']) ? $args['receiver_location'] : ''),
                    'receiverAddress2'     => isset($args['receiver_address_2']) ? (string) $args['receiver_address_2'] : '',
                    'insuranceTypeId'      => parsi_get_setting('parsi_insurance_type_id', $this->arg_or_default($args, 'insurance_type_id', PARSI_API_Config::DEFAULT_INSURANCE_TYPE_ID)),
                    'parcelTypeId'         => parsi_get_setting('parsi_parcel_type_id', $this->arg_or_default($args, 'parcel_type_id', PARSI_API_Config::DEFAULT_PARCEL_TYPE_ID)),
                    'boxTypeId'            => parsi_get_setting('parsi_box_type_id', $this->arg_or_default($args, 'box_type_id', PARSI_API_Config::DEFAULT_BOX_TYPE_ID)),
                    'weight'               => (int) $args['weight'],
                    'content'              => isset($args['content']) ? (string) $args['content'] : '',
                    'parcelExtraServices'  => isset($args['parcel_extra_services']) && is_array($args['parcel_extra_services'])
                        ? $args['parcel_extra_services']
                        : array(),
                ),
            ),
        );
    }

    /**
     * Return a non-empty per-call arg if provided, otherwise the given default.
     * Used as the fallback value for parsi_get_setting() so the request never
     * carries an empty UUID for fields the API requires as a valid GUID.
     */
    private function arg_or_default(array $args, $key, $default) {
        return (isset($args[$key]) && $args[$key] !== '') ? $args[$key] : $default;
    }

    /**
     * Sender coordinates ("lat,long"). Uses the configured value, then falls
     * back to the center location of the configured sender city from the
     * bundled dataset (so it works even if the derive-on-save hook never ran).
     */
    private function get_sender_location($sender_city_id) {
        $location = parsi_get_setting('parsi_sender_location', '');
        if ($location !== '') {
            return $location;
        }
        if (!class_exists('PARSI_Locations')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
        }
        if (class_exists('PARSI_Locations')) {
            $city = PARSI_Locations::get_city($sender_city_id);
            if ($city && !empty($city['centerLocation'])) {
                return $city['centerLocation'];
            }
        }
        return '';
    }

    /**
     * Coerce a location into "lat,long" with no spaces.
     */
    private function format_location($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $parts = explode(',', $value);
        if (count($parts) !== 2) {
            return $value;
        }
        return floatval(trim($parts[0])) . ',' . floatval(trim($parts[1]));
    }

    // -----------------------------------------------------------------------
    // HTTP transport
    // -----------------------------------------------------------------------

    /**
     * @return array|WP_Error
     */
    private function request($method, $url, $body = null) {
        $args = array(
            'method'    => $method,
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type' => 'application/json',
                'x-api-key'    => $this->api_key,
                'office'       => $this->office_id,
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        parsi_log_api_request($url, $body !== null ? $body : array(), $method);

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        parsi_log_api_response($url, $raw, $code);

        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = $this->extract_error_message($decoded);
            if ($message === '') {
                $message = sprintf('HTTP %d: %s', $code, $raw);
            }
            return new WP_Error('parsi_http_' . $code, $message, array('status' => $code, 'body' => $decoded));
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('parsi_invalid_json', 'Failed to decode API response: ' . json_last_error_msg());
        }

        return is_array($decoded) ? $decoded : array();
    }

    // -----------------------------------------------------------------------
    // Response extractors (defensive — exact shape not in Postman collection)
    // -----------------------------------------------------------------------

    /**
     * Pull a human-readable error out of the API's documented envelope:
     * { "success": false, "messages": [ { "persian_message": "...", ... } ] }.
     * Falls back to a legacy top-level "message" key, then to ''.
     */
    private function extract_error_message($decoded) {
        if (!is_array($decoded)) {
            return '';
        }

        if (!empty($decoded['messages']) && is_array($decoded['messages'])) {
            $parts = array();
            foreach ($decoded['messages'] as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                if (!empty($msg['persian_message'])) {
                    $parts[] = (string) $msg['persian_message'];
                } elseif (!empty($msg['message'])) {
                    $parts[] = (string) $msg['message'];
                }
            }
            if (!empty($parts)) {
                return implode(' ', $parts);
            }
        }

        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return '';
    }

    private function extract_price_from_response($response) {
        if (!is_array($response)) {
            return null;
        }
        $candidates = array('totalPrice', 'price', 'finalPrice', 'amount', 'cost', 'totalAmount');
        foreach ($candidates as $key) {
            if (isset($response[$key]) && is_numeric($response[$key])) {
                return (float) $response[$key];
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return $this->extract_price_from_response($response['data']);
        }
        if (isset($response['result']) && is_array($response['result'])) {
            return $this->extract_price_from_response($response['result']);
        }
        return null;
    }

    private function extract_tracking_from_response($response) {
        if (!is_array($response)) {
            return '';
        }

        // Prefer the per-parcel postal barcode (e.g. "EP0225..."), the code
        // customers track on Iran Post. Drill through the data/result envelope
        // and the first parcel before falling back to top-level keys, so the
        // parcel barcode wins over the order-level "tracking" short code.
        foreach (array('data', 'result') as $envelope) {
            if (isset($response[$envelope]) && is_array($response[$envelope])) {
                $nested = $this->extract_tracking_from_response($response[$envelope]);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }
        if (isset($response['parcels'][0]) && is_array($response['parcels'][0])) {
            $parcel_tracking = $this->extract_tracking_from_response($response['parcels'][0]);
            if ($parcel_tracking !== '') {
                return $parcel_tracking;
            }
        }

        $candidates = array('barcode', 'trackingNumber', 'tracking_number', 'trackingCode', 'tracking');
        foreach ($candidates as $key) {
            if (!empty($response[$key]) && is_string($response[$key])) {
                return sanitize_text_field($response[$key]);
            }
        }
        return '';
    }

    /**
     * Extract the Parsi order-level tracking code (e.g. "OrhgNhJD") used on the
     * Parsi/Pishgaman portal. This is the data.tracking value, distinct from the
     * per-parcel Iran Post barcode returned by extract_tracking_from_response().
     */
    private function extract_order_code_from_response($response) {
        if (!is_array($response)) {
            return '';
        }
        $data = (isset($response['data']) && is_array($response['data']))
            ? $response['data']
            : $response;
        if (!empty($data['tracking']) && is_string($data['tracking'])) {
            return sanitize_text_field($data['tracking']);
        }
        return '';
    }

    private function extract_shipment_id_from_response($response) {
        if (!is_array($response)) {
            return '';
        }
        $candidates = array('shipmentId', 'shipment_id', 'orderId', 'id');
        foreach ($candidates as $key) {
            if (!empty($response[$key])) {
                return sanitize_text_field((string) $response[$key]);
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return $this->extract_shipment_id_from_response($response['data']);
        }
        if (isset($response['result']) && is_array($response['result'])) {
            return $this->extract_shipment_id_from_response($response['result']);
        }
        return '';
    }
}

/**
 * Read a plugin option, falling back to a non-empty default. Used by the API
 * client to merge per-call args with store-level configuration.
 */
if (!function_exists('parsi_get_setting')) {
    function parsi_get_setting($key, $default = '') {
        $value = get_option($key, '');
        if ($value === '' || $value === null) {
            return $default;
        }
        return $value;
    }
}
