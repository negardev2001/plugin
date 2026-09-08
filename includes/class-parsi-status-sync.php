<?php
/**
 * PARSI Shipment Status Synchronization
 *
 * @package PARSI
 *
 * Live status synchronization is disabled: the Parsi Post API documented in
 * docs/Parsi-Post-API.postman_collection.json has no tracking-status endpoint. This class is
 * kept as a stub so existing callers (order meta box "Sync" button, scheduled
 * cron) don't fatal — every operation returns false with a user-facing notice.
 *
 * When Parsi Post publishes a tracking endpoint, restore sync_shipment_status()
 * to call PARSI_API_Request and parse the response.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_Status_Sync {

    public function __construct() {
        // Ensure any previously-scheduled cron from earlier plugin versions is
        // removed so we don't accumulate dead jobs.
        add_action('init', array($this, 'unschedule_status_sync'));
    }

    /**
     * No-op. Kept as a public method for backward compatibility with the
     * (currently-skipped) install hook.
     */
    public function schedule_status_sync() {
        // Intentional no-op.
    }

    /**
     * Remove any previously-scheduled status-sync cron event.
     */
    public function unschedule_status_sync() {
        $timestamp = wp_next_scheduled('parsi_status_sync_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'parsi_status_sync_event');
        }
    }

    /**
     * No-op. Returns the same shape the previous implementation did so callers
     * that read $result['synced'] / $result['failed'] continue to work.
     *
     * @return array{synced:int,failed:int}
     */
    public function sync_all_shipments() {
        parsi_log('Status sync requested, but no tracking endpoint is exposed by the API. Skipping.', 'warning');
        return array('synced' => 0, 'failed' => 0);
    }

    /**
     * No-op. Returns false so AJAX handlers surface the unavailability to the
     * user instead of silently appearing to succeed.
     *
     * @return false
     */
    public function sync_shipment_status($order, $tracking_number) {
        parsi_log('sync_shipment_status called but disabled: no API endpoint available.', 'warning');
        return false;
    }

    /**
     * Build the manual-sync URL. Kept for compatibility with settings page links.
     */
    public static function get_manual_sync_url() {
        return add_query_arg(array(
            'parsi_manual_sync' => '1',
            '_wpnonce'          => wp_create_nonce('parsi_manual_sync'),
        ), admin_url('admin.php?page=parsi-settings'));
    }
}
