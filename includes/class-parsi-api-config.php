<?php
/**
 * PARSI API Configuration
 *
 * @package PARSI
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint URLs and request defaults for the Parsi Post API.
 *
 * Endpoints mirror the official Postman collection (docs/Parsi-Post-API.postman_collection.json).
 * Authentication is via `x-api-key` and `office` headers, set per-request in
 * PARSI_API_Request, not here.
 */
class PARSI_API_Config {

    const API_BASE_URL = 'https://parsipost.com/api';

    /**
     * Canonical default UUIDs for this Parsi Post integration, taken from the
     * official Postman collection (docs/Parsi-Post-API.postman_collection.json).
     *
     * GetPrice rejects empty strings for contractId/serviceTypeId/deadLineId
     * ("Error converting value \"\" to type 'System.Guid'"), so the payload
     * builders fall back to these when a merchant hasn't entered their own.
     * A merchant can override any of them in the plugin settings.
     */
    const DEFAULT_CONTRACT_ID       = 'EEA5ADC0-758C-4BF5-9247-131136EEB6AA';
    const DEFAULT_SERVICE_TYPE_ID   = 'D8BD3783-86B4-4CE9-900B-468C2069FD18';
    const DEFAULT_DEADLINE_ID       = 'F865EADF-E65A-438A-9618-311283C82B15';
    const DEFAULT_INSURANCE_TYPE_ID = '8c9e6753-d1dd-46dc-9901-3523b02b8a93';
    const DEFAULT_PARCEL_TYPE_ID    = '976c51bd-cc4a-40fb-897c-4f8b905f489a';
    const DEFAULT_BOX_TYPE_ID       = '7d0a1a9e-0ec3-458c-a733-1aa04c33bcc8';
    const DEFAULT_PAYMENT_TYPE_ID   = '119A8743-BE66-44B3-B03D-7BDE4C1FE0CB';

    const API_ENDPOINTS = array(
        'basic_info'    => '/Ordering/ClientOrder/GetApiOrderBasicInfo',
        'get_price'     => '/Ordering/ClientOrder/GetPrice',
        'save_order'    => '/Ordering/ClientOrder/Save',
        'get_receivers' => '/Ordering/ClientOrder/GetAllReceivers',
    );

    /**
     * Get the API base URL. Filterable via `parsi_api_base_url` for staging.
     */
    public static function get_base_url() {
        $override = apply_filters('parsi_api_base_url', null);
        if (is_string($override) && !empty($override)) {
            return rtrim($override, '/');
        }
        return self::API_BASE_URL;
    }

    public static function get_endpoint($key) {
        $path = isset(self::API_ENDPOINTS[$key]) ? self::API_ENDPOINTS[$key] : '';
        return self::get_base_url() . $path;
    }

    public static function get_basic_info_url() {
        return self::get_endpoint('basic_info');
    }

    public static function get_price_url() {
        return self::get_endpoint('get_price');
    }

    public static function get_save_order_url() {
        return self::get_endpoint('save_order');
    }

    public static function get_receivers_url() {
        return self::get_endpoint('get_receivers');
    }

    /**
     * Cities and deadlines both come from GetApiOrderBasicInfo.
     * These aliases exist so callers can read intent.
     */
    public static function get_cities_url() {
        return self::get_basic_info_url();
    }

    public static function get_deadlines_url() {
        return self::get_basic_info_url();
    }

    /**
     * Validate an Iranian mobile number (09xxxxxxxxx).
     */
    public static function validate_iranian_mobile($phone) {
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);
        return (bool) preg_match('/^09[0-9]{9}$/', $phone);
    }
}
