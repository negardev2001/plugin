<?php
defined('ABSPATH') || exit;

/**
 * Authorization gate for the Parsi shipping method.
 *
 * "Authorized" means the store is configured with credentials the real Parsi
 * Post API accepts. There is NO separate license server — authentication is the
 * `x-api-key` + `office` header pair, validated by the API itself. The result of
 * a real probe (GET GetApiOrderBasicInfo) is cached for a day so checkout does
 * not make a blocking call on every rate calculation.
 */
class PARSI_Auth {

    const CACHE_KEY = 'parsi_site_authorized';
    const CACHE_TTL = DAY_IN_SECONDS;

    public static function is_authorized(): bool {

        // Without both credentials the API returns 401/403, so don't even probe.
        $api_key   = (string) get_option('parsi_api_key', '');
        $office_id = (string) get_option('parsi_office_id', '');
        if ($api_key === '' || $office_id === '') {
            self::cache(false);
            return false;
        }

        // Cached result of the last real probe. Unknown/legacy values fall
        // through and trigger a fresh probe rather than returning a guess.
        $cached = get_transient(self::CACHE_KEY);
        if ($cached === 'yes') {
            return true;
        }
        if ($cached === 'no') {
            return false;
        }

        $result = self::probe_api($api_key, $office_id);

        // Network/transport error: don't lock out a previously-working store.
        if ($result === null) {
            return self::fail_safe();
        }

        self::cache($result);
        return $result;
    }

    /**
     * Probe the real API with a low-risk GET.
     *
     * @return bool|null true = accepted, false = rejected (bad creds),
     *                   null = could not reach the API (transport error).
     */
    private static function probe_api(string $api_key, string $office_id) {
        $url = class_exists('PARSI_API_Config')
            ? PARSI_API_Config::get_basic_info_url()
            : 'https://parsipost.com/api/Ordering/ClientOrder/GetApiOrderBasicInfo';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'x-api-key' => $api_key,
                'office'    => $office_id,
            ),
        ));

        if (is_wp_error($response)) {
            if (function_exists('parsi_log')) {
                parsi_log('Authorization probe transport error: ' . $response->get_error_message(), 'warning');
            }
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = ($code >= 200 && $code < 300);

        if (function_exists('parsi_log')) {
            parsi_log('Authorization probe result: HTTP ' . $code . ' (' . ($ok ? 'authorized' : 'rejected') . ')', 'info');
        }

        return $ok;
    }

    private static function cache(bool $value): void {
        // Transients can't store false reliably, so store an explicit token.
        set_transient(self::CACHE_KEY, $value ? 'yes' : 'no', self::CACHE_TTL);
        update_option(self::CACHE_KEY . '_last', $value);
    }

    /**
     * Fail-safe when the API can't be reached: keep a previously-authorized
     * store working; default to false for a store that has never succeeded.
     */
    private static function fail_safe(): bool {
        return (bool) get_option(self::CACHE_KEY . '_last', false);
    }
}
