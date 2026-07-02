<?php
/**
 * PARSI Orders Management
 *
 * @package PARSI
 */

if (!defined('ABSPATH')) {
    exit;
}

// Fallback function if parsi_log is not defined
if (!function_exists('parsi_log')) {
    function parsi_log($message, $level = 'info') {
        // Silently fail if logging is not available
        return;
    }
}

/**
 * PARSI Orders Class
 */
class PARSI_Orders {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Automatic shipment registration (works on both admin and frontend)
        add_action('woocommerce_checkout_order_created', array($this, 'register_shipment_on_order_creation'), 10, 1);
        add_action('woocommerce_new_order', array($this, 'register_shipment_on_order_creation'), 10, 1);

        // Auto-detect PARSI shipping when an order is created. MUST be registered
        // before the is_admin() guard below — customer checkouts run on the
        // frontend, so registering these only in admin would leave the
        // _parsi_shipping_enabled flag unset for real buyer orders.
        add_action('woocommerce_checkout_order_processed', array($this, 'auto_detect_parsi_shipping'), 10, 1);
        add_action('woocommerce_checkout_order_created', array($this, 'auto_detect_parsi_shipping'), 10, 1);
        add_action('woocommerce_new_order', array($this, 'auto_detect_parsi_shipping'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'auto_detect_parsi_shipping_on_status_change'), 10, 3);

        // 🔐 SECURITY: AJAX handler for manual order sync (admin only)
        add_action('wp_ajax_parsi_manual_sync_order', array($this, 'ajax_manual_sync_order'));
        // Note: wp_ajax_nopriv_parsi_manual_sync_order removed - admin function only
        
        // 🔐 SECURITY: AJAX handler for quick toggle in orders list (admin only)
        add_action('wp_ajax_parsi_toggle_shipping', array($this, 'ajax_toggle_shipping'));
        
        // Only load admin-specific features
        if (!is_admin()) {
            return;
        }
        
        // Check if using HPOS (High-Performance Order Storage)
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            // HPOS support
            add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_column'), 20);
            add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_order_column_hpos'), 10, 2);
            
            // Add bulk actions for HPOS
            add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_bulk_actions'));
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_actions'), 10, 3);
        } else {
            // Legacy post-based orders
            add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'), 20);
            add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column'), 10, 2);

            // Add bulk actions for legacy
            add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));
            add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
        }

        // Shipping method filter — registered for BOTH backends. The HPOS class
        // can exist while the store still uses the legacy edit.php orders page
        // (HPOS available but not enabled), so we cannot rely on the branch above
        // to pick the right page. Each renderer/query filter guards on the active
        // screen, so only the matching hook actually fires.
        // HPOS orders screen (admin.php?page=wc-orders):
        add_action('woocommerce_order_list_table_restrict_manage_orders', array($this, 'render_shipping_method_filter'), 10, 2);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', array($this, 'filter_orders_by_shipping_method_hpos'));
        // Legacy orders screen (edit.php?post_type=shop_order):
        add_action('restrict_manage_posts', array($this, 'render_shipping_method_filter_legacy'));
        add_filter('request', array($this, 'filter_orders_by_shipping_method_legacy'));
        
        // Add meta box to order edit page (works for both HPOS and legacy)
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        
        // Save meta box data
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order_meta_box'), 10, 2);

        // Add admin notice for bulk actions
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        
        // Enqueue admin styles for orders list
        add_action('admin_enqueue_scripts', array($this, 'enqueue_orders_list_styles'));
    }
    
    /**
     * Enqueue admin styles for orders list
     */
    public function enqueue_orders_list_styles($hook) {
        // Only load on orders list pages
        if ('edit.php' !== $hook) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if we're on the shop order list page (both legacy and HPOS)
        if ('shop_order' !== $screen->post_type && 'woocommerce_page_wc-orders' !== $screen->id) {
            return;
        }
        
        wp_enqueue_style(
            'parsi-admin-orders',
            PARSI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PARSI_VERSION
        );
    }
    
    /**
     * Add custom column to orders list
     *
     * @param array $columns Existing columns
     * @return array
     */
    public function add_order_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add our column after order status
            if ('order_status' === $key) {
                $new_columns['parsi_shipping'] = __('ارسال با پارسی پست', 'parsi');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render custom column content (Legacy)
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function render_order_column($column, $post_id) {
        if ('parsi_shipping' !== $column) {
            return;
        }
        
        $this->render_column_content($post_id);
    }
    
    /**
     * Render custom column content (HPOS)
     *
     * @param string $column Column name
     * @param object $order Order object
     */
    public function render_order_column_hpos($column, $order) {
        if ('parsi_shipping' !== $column) {
            return;
        }
        
        $this->render_column_content($order->get_id());
    }
    
    /**
     * Render column content
     *
     * @param int $order_id Order ID
     */
    private function render_column_content($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $parsi_shipping = $order->get_meta('_parsi_shipping_enabled');
        $parsi_tracking = $order->get_meta('_parsi_tracking_number');
        $parsi_status = $order->get_meta('_parsi_shipping_status');
        
        // If meta is not set, check if order uses PARSI shipping method (for existing orders)
        if ('yes' !== $parsi_shipping && 'no' !== $parsi_shipping) {
            $shipping_methods = $order->get_shipping_methods();
            $has_parsi = false;
            
            foreach ($shipping_methods as $shipping_method) {
                $method_id = $shipping_method->get_method_id();
                if (false !== strpos($method_id, 'parsi')) {
                    $has_parsi = true;
                    // Auto-enable for display and save (for backward compatibility)
                    $order->update_meta_data('_parsi_shipping_enabled', 'yes');
                    $order->update_meta_data('_parsi_auto_detected', 'yes');
                    $order->save();
                    $parsi_shipping = 'yes';
                    break;
                }
            }
        }
        
        $is_enabled = ('yes' === $parsi_shipping);
        
        // Output checkbox with label
        echo '<div class="parsi-shipping-toggle">';
        echo '<label class="parsi-toggle-label">';
        echo '<input type="checkbox"
                  class="parsi-shipping-checkbox"
                  data-order-id="' . esc_attr($order_id) . '"
                  data-nonce="' . esc_attr(wp_create_nonce('parsi_toggle_shipping_' . $order_id)) . '"
                  ' . checked($is_enabled, true, false) . ' />';
        echo '<span class="parsi-toggle-text">';
        
        if ($is_enabled) {
            $status_text = __('فعال', 'parsi');
            if (!empty($parsi_status)) {
                $status_text = esc_html($parsi_status);
            }
            echo '<span class="parsi-status parsi-active" style="color: #46b450; font-weight: bold;">✓ ' . esc_html($status_text) . '</span>';
        } else {
            echo '<span class="parsi-status parsi-inactive" style="color: #999;">' . esc_html__('غیرفعال', 'parsi') . '</span>';
        }
        
        echo '</span>';
        echo '</label>';
        
        // Show tracking number if available
        if ($is_enabled && $parsi_tracking) {
            echo '<div class="parsi-tracking-info"><small style="color: #666;">' . esc_html__('کد پیگیری:', 'parsi') . ' ' . esc_html($parsi_tracking) . '</small></div>';
        }
        
        echo '</div>';
        
        // Enqueue admin script for handling checkbox toggles
        $this->enqueue_admin_script();
    }
    
    /**
     * Query var name used by the shipping method filter dropdown.
     */
    const SHIPPING_FILTER_VAR = 'parsi_shipping_method';

    /**
     * Render the "شیوه ارسال" (shipping method) filter dropdown markup.
     *
     * Shared by both the HPOS and legacy renderers so the markup stays in sync.
     */
    private function shipping_method_filter_dropdown() {
        $selected = isset($_GET[self::SHIPPING_FILTER_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::SHIPPING_FILTER_VAR])) : '';

        echo '<select name="' . esc_attr(self::SHIPPING_FILTER_VAR) . '" id="' . esc_attr(self::SHIPPING_FILTER_VAR) . '">';
        echo '<option value="">' . esc_html__('همه شیوه‌های ارسال', 'parsi') . '</option>';
        echo '<option value="parsi" ' . selected($selected, 'parsi', false) . '>' . esc_html__('ارسال با پارسی پست', 'parsi') . '</option>';
        echo '</select>';
    }

    /**
     * Render the shipping method filter on the HPOS orders screen.
     *
     * @param string $order_type Current order type (e.g. shop_order).
     * @param string $which      Position of the filter row (top/bottom).
     */
    public function render_shipping_method_filter($order_type = 'shop_order', $which = '') {
        if ('shop_order' !== $order_type) {
            return;
        }

        $this->shipping_method_filter_dropdown();
    }

    /**
     * Render the shipping method filter on the legacy (post-based) orders screen.
     */
    public function render_shipping_method_filter_legacy() {
        global $typenow;

        if ('shop_order' !== $typenow) {
            return;
        }

        $this->shipping_method_filter_dropdown();
    }

    /**
     * Apply the shipping method filter to the HPOS order query.
     *
     * @param array $query_args Query args passed to wc_get_orders().
     * @return array
     */
    public function filter_orders_by_shipping_method_hpos($query_args) {
        $value = isset($_GET[self::SHIPPING_FILTER_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::SHIPPING_FILTER_VAR])) : '';

        if ('parsi' !== $value) {
            return $query_args;
        }

        if (!isset($query_args['meta_query']) || !is_array($query_args['meta_query'])) {
            $query_args['meta_query'] = array();
        }

        $query_args['meta_query'][] = array(
            'key'   => '_parsi_shipping_enabled',
            'value' => 'yes',
        );

        return $query_args;
    }

    /**
     * Apply the shipping method filter to the legacy (post-based) order query.
     *
     * @param array $query_vars WP_Query vars.
     * @return array
     */
    public function filter_orders_by_shipping_method_legacy($query_vars) {
        global $typenow;

        if ('shop_order' !== $typenow) {
            return $query_vars;
        }

        $value = isset($_GET[self::SHIPPING_FILTER_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::SHIPPING_FILTER_VAR])) : '';

        if ('parsi' !== $value) {
            return $query_vars;
        }

        if (!isset($query_vars['meta_query']) || !is_array($query_vars['meta_query'])) {
            $query_vars['meta_query'] = array();
        }

        $query_vars['meta_query'][] = array(
            'key'   => '_parsi_shipping_enabled',
            'value' => 'yes',
        );

        return $query_vars;
    }

    /**
     * Enqueue admin script for checkbox toggles
     */
    private function enqueue_admin_script() {
        static $enqueued = false;
        
        if ($enqueued) {
            return;
        }
        
        $enqueued = true;
        
        wp_enqueue_script('parsi-admin-orders', PARSI_PLUGIN_URL . 'assets/js/admin-orders.js', array('jquery'), PARSI_VERSION, true);
        wp_localize_script('parsi-admin-orders', 'parsiAdminOrders', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => array(
                'enabling' => __('در حال فعال‌سازی...', 'parsi'),
                'disabling' => __('در حال غیرفعال‌سازی...', 'parsi'),
                'error' => __('خطا در ذخیره تنظیمات', 'parsi'),
                'tracking' => __('کد پیگیری:', 'parsi'),
                'inactive' => __('غیرفعال', 'parsi'),
            )
        ));
    }
    
    /**
     * Auto-detect if order uses PARSI shipping method
     *
     * @param int $order_id Order ID
     */
    public function auto_detect_parsi_shipping($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if already manually set by admin
        $parsi_shipping = $order->get_meta('_parsi_shipping_enabled');
        if ('no' === $parsi_shipping) {
            return; // Admin explicitly disabled it
        }
        
        // If already enabled, don't change
        if ('yes' === $parsi_shipping) {
            return;
        }
        
        // Check if order uses PARSI shipping method
        $shipping_methods = $order->get_shipping_methods();
        $has_parsi = false;
        
        foreach ($shipping_methods as $shipping_method) {
            $method_id = $shipping_method->get_method_id();
            if (false !== strpos($method_id, 'parsi')) {
                $has_parsi = true;
                break;
            }
        }
        
        // If PARSI shipping is used, enable it automatically
        if ($has_parsi) {
            $order->update_meta_data('_parsi_shipping_enabled', 'yes');
            $order->update_meta_data('_parsi_auto_detected', 'yes'); // Mark as auto-detected
            $order->save();
        }
    }
    
    /**
     * Auto-detect PARSI shipping when order status changes
     *
     * @param int $order_id Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function auto_detect_parsi_shipping_on_status_change($order_id, $old_status, $new_status) {
        // Only check when order is being processed
        if (in_array($new_status, array('processing', 'on-hold', 'completed'))) {
            $this->auto_detect_parsi_shipping($order_id);
        }
    }
    
    /**
     * Register shipment with Parsi API on order creation
     *
     * @param int $order_id Order ID
     */
    public function register_shipment_on_order_creation($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // This handler is hooked to both woocommerce_checkout_order_created
        // (passes a WC_Order object) and woocommerce_new_order (passes an int
        // ID). Normalize to the real integer ID so downstream meta lookups and
        // the (int) cast in register_shipment() resolve the correct order
        // instead of casting an object to 1.
        $order_id = $order->get_id();

        // Check if order uses PARSI shipping method
        $shipping_methods = $order->get_shipping_methods();
        $has_parsi = false;
        
        foreach ($shipping_methods as $shipping_method) {
            $method_id = $shipping_method->get_method_id();
            if (false !== strpos($method_id, 'parsi')) {
                $has_parsi = true;
                break;
            }
        }
        
        // Only register if PARSI shipping is used
        if (!$has_parsi) {
            return;
        }
        
        // 🔐 SECURITY: Check if shipment is already registered (atomic check)
        $already_registered = $order->get_meta('_parsi_shipment_registered');
        if ('yes' === $already_registered) {
            return;
        }
        
        // 🔐 SECURITY: Check for request flooding before registering
        if (function_exists('parsi_can_make_api_request') && !parsi_can_make_api_request($order_id, 'register', 60)) {
            parsi_log(sprintf('Shipment registration blocked for order #%s - too soon', $order_id), 'warning');
            return;
        }
        
        // Get order data for shipment registration
        $shipping_address = $order->get_address('shipping');
        $billing_address = $order->get_address('billing');
        
        // Use shipping address if available, otherwise billing
        $recipient_address = !empty($shipping_address['address_1']) ? $shipping_address : $billing_address;
        
        // CRITICAL: Calculate total weight with proper unit handling
        $weight = 0;
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $weight += $product->get_weight() * $item->get_quantity();
            }
        }
        
        // CRITICAL: Validate weight and get in grams
        // The API expects weight in grams, so we validate and convert here
        if (function_exists('parsi_validate_weight')) {
            $weight_grams = parsi_validate_weight($weight, $weight_unit);
        } else {
            // Fallback conversion if helper function is not available
            switch ($weight_unit) {
                case 'g':
                    $weight_grams = intval($weight);
                    break;
                case 'lbs':
                    $weight_grams = intval($weight * 453.59237);
                    break;
                case 'oz':
                    $weight_grams = intval($weight * 28.34952);
                    break;
                case 'kg':
                default:
                    $weight_grams = intval($weight * 1000);
                    break;
            }
            // Defensive check for zero weight
            if ($weight_grams <= 0) {
                $weight_grams = 500; // Fallback to 500g
            }
        }
        
        // Convert grams back to kg for shipment_data (API request will convert to grams)
        $weight_kg = $weight_grams / 1000;
        
        // CRITICAL: Get parcel type from order meta or settings
        $parcel_type = $order->get_meta('_parsi_parcel_type');
        if (empty($parcel_type)) {
            // Get default from settings
            $parcel_type = get_option('parsi_default_parcel_type', 'بسته');
        }
        
        // Validate parcel type
        if (function_exists('parsi_validate_parcel_type') && !parsi_validate_parcel_type($parcel_type)) {
            // Log error and use default
            parsi_log('Invalid parcel type: ' . $parcel_type . ', using default "بسته"', 'warning');
            $parcel_type = 'بسته';
        }
        
        // City ID — prefer the custom checkout field if present, else fall back
        // to a name lookup against the cached cities list inside the API client.
        $billing_city_id = (string) get_post_meta($order_id, '_billing_city_id', true);

        // Prepare shipment data. National code is read directly from
        // _billing_national_code by PARSI_API_Request::register_shipment().
        $shipment_data = array(
            'order_id'       => $order_id,
            'recipient_name' => trim($recipient_address['first_name'] . ' ' . $recipient_address['last_name']),
            'phone'          => $billing_address['phone'],
            'address'        => $recipient_address['address_1'],
            'address_2'      => $recipient_address['address_2'],
            'city'           => $recipient_address['city'],
            'city_id'        => $billing_city_id,
            'state'          => $recipient_address['state'],
            'postal_code'    => $recipient_address['postcode'],
            'country'        => $recipient_address['country'],
            'weight'         => $weight_kg, // kg; API client converts to grams
            'parcel_type'    => $parcel_type,
            'description'    => sprintf(__('Order #%s', 'parsi'), $order_id),
        );
        
        // Register shipment via API
        $api_request = new PARSI_API_Request();
        $response = $api_request->register_shipment($shipment_data);
        
        // Process response
        if ($response && isset($response['success']) && $response['success']) {
            // Store tracking code and shipment ID
            $tracking_number = isset($response['tracking_number']) ? sanitize_text_field($response['tracking_number']) : '';
            $shipment_id = isset($response['shipment_id']) ? sanitize_text_field($response['shipment_id']) : '';
            $order_code = isset($response['order_code']) ? sanitize_text_field($response['order_code']) : '';

            if (!empty($tracking_number)) {
                $order->update_meta_data('_parsi_tracking_number', $tracking_number);
                $order->update_meta_data('_parsi_shipment_id', $shipment_id);
                if ($order_code !== '') {
                    $order->update_meta_data('_parsi_order_code', $order_code);
                }
                $order->update_meta_data('_parsi_registration_time', current_time('mysql'));
                $order->update_meta_data('_parsi_shipping_enabled', 'yes');
                $order->update_meta_data('_parsi_shipment_registered', 'yes');
                $order->save();

                if (function_exists('parsi_record_api_request_time')) {
                    parsi_record_api_request_time($order_id, 'register');
                }

                $note = sprintf(
                    __('Parsi shipment registered successfully. Tracking number: %s', 'parsi'),
                    $tracking_number
                );
                if ($order_code !== '') {
                    $note .= ' ' . sprintf(__('(Parsi order code: %s)', 'parsi'), $order_code);
                }
                $order->add_order_note($note);

                if (function_exists('parsi_log')) {
                    parsi_log(sprintf('Shipment registered for order #%s. Tracking: %s', $order_id, $tracking_number), 'info');
                }
            } else {
                // Log error if tracking number is missing
                $order->add_order_note(
                    __('Shipment registered but tracking number not returned. Please check logs.', 'parsi')
                );
                if (function_exists('parsi_log')) {
                    parsi_log(sprintf('Shipment registered for order #%s but no tracking number returned', $order_id), 'warning');
                }
            }
        } else {
            // Log error but don't fail order creation
            $error_message = isset($response['error']) ? $response['error'] : __('Failed to register shipment with Parsi API. Please check logs for details.', 'parsi');
            $order->add_order_note($error_message);
            if (function_exists('parsi_log')) {
                parsi_log(sprintf('Failed to register shipment for order #%s: %s', $order_id, $error_message), 'error');
            }
        }
    }
    
    /**
     * Add meta box to order edit page
     */
    public function add_order_meta_box() {
        add_meta_box(
            'parsi-shipping-meta-box',
            __('ارسال با پارسی پست', 'parsi'),
            array($this, 'render_order_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }
    
    /**
     * Render order meta box
     *
     * @param WP_Post $post Post object
     */
    public function render_order_meta_box($post) {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }
        
        $parsi_shipping = $order->get_meta('_parsi_shipping_enabled');
        $parsi_tracking = $order->get_meta('_parsi_tracking_number');
        $parsi_status = $order->get_meta('_parsi_shipping_status');
        $parsi_notes = $order->get_meta('_parsi_shipping_notes');
        
        wp_nonce_field('parsi_order_meta_box', 'parsi_order_meta_box_nonce');
        
        ?>
        <div class="parsi-order-meta-box">
            <p>
                <label>
                    <input type="checkbox" name="parsi_shipping_enabled" value="yes" <?php checked($parsi_shipping, 'yes'); ?> />
                    <?php esc_html_e('فعال‌سازی ارسال با پارسی پست', 'parsi'); ?>
                </label>
            </p>
            
            <p>
                <label for="parsi_tracking_number">
                    <strong><?php esc_html_e('کد پیگیری:', 'parsi'); ?></strong>
                </label>
                <input type="text" 
                       id="parsi_tracking_number" 
                       name="parsi_tracking_number" 
                       value="<?php echo esc_attr($parsi_tracking); ?>" 
                       class="widefat" 
                       placeholder="<?php esc_attr_e('مثال: 1234567890', 'parsi'); ?>" />
                <?php // Live tracking/sync buttons removed: the Parsi Post API has no
                      // status endpoint. Status is updated manually below. ?>
            </p>
            
            <p>
                <label for="parsi_shipping_status">
                    <strong><?php esc_html_e('وضعیت ارسال:', 'parsi'); ?></strong>
                </label>
                <select id="parsi_shipping_status" name="parsi_shipping_status" class="widefat">
                    <option value=""><?php esc_html_e('-- انتخاب کنید --', 'parsi'); ?></option>
                    <option value="<?php esc_attr_e('در انتظار ارسال', 'parsi'); ?>" <?php selected($parsi_status, __('در انتظار ارسال', 'parsi')); ?>>
                        <?php esc_html_e('در انتظار ارسال', 'parsi'); ?>
                    </option>
                    <option value="<?php esc_attr_e('ارسال شده', 'parsi'); ?>" <?php selected($parsi_status, __('ارسال شده', 'parsi')); ?>>
                        <?php esc_html_e('ارسال شده', 'parsi'); ?>
                    </option>
                    <option value="<?php esc_attr_e('در حال انتقال', 'parsi'); ?>" <?php selected($parsi_status, __('در حال انتقال', 'parsi')); ?>>
                        <?php esc_html_e('در حال انتقال', 'parsi'); ?>
                    </option>
                    <option value="<?php esc_attr_e('تحویل شده', 'parsi'); ?>" <?php selected($parsi_status, __('تحویل شده', 'parsi')); ?>>
                        <?php esc_html_e('تحویل شده', 'parsi'); ?>
                    </option>
                    <option value="<?php esc_attr_e('برگشت خورده', 'parsi'); ?>" <?php selected($parsi_status, __('برگشت خورده', 'parsi')); ?>>
                        <?php esc_html_e('برگشت خورده', 'parsi'); ?>
                    </option>
                </select>
            </p>
            
            <p>
                <label for="parsi_shipping_notes">
                    <strong><?php esc_html_e('یادداشت:', 'parsi'); ?></strong>
                </label>
                <textarea id="parsi_shipping_notes" 
                          name="parsi_shipping_notes" 
                          class="widefat" 
                          rows="3" 
                          placeholder="<?php esc_attr_e('یادداشت‌های اضافی...', 'parsi'); ?>"><?php echo esc_textarea($parsi_notes); ?></textarea>
            </p>
            
            <?php
            // Show shipping method info if PARSI was used
            $shipping_methods = $order->get_shipping_methods();
            $has_parsi = false;
            foreach ($shipping_methods as $shipping_method) {
                if (false !== strpos($shipping_method->get_method_id(), 'parsi')) {
                    $has_parsi = true;
                    ?>
                    <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <strong><?php esc_html_e('روش ارسال:', 'parsi'); ?></strong><br>
                        <?php echo esc_html($shipping_method->get_name()); ?><br>
                        <small style="color: #666;">
                            <?php echo wc_price($shipping_method->get_total()); ?>
                        </small>
                    </p>
                    <?php
                    break;
                }
            }
            
            if (!$has_parsi) {
                ?>
                <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; color: #999; font-style: italic;">
                    <?php esc_html_e('این سفارش با روش ارسال پارسی پست ثبت نشده است.', 'parsi'); ?>
                </p>
                <?php
            }
            ?>
        </div>
        
        <style>
            .parsi-order-meta-box p {
                margin: 10px 0;
            }
            .parsi-order-meta-box label {
                display: block;
                margin-bottom: 5px;
            }
            .parsi-order-meta-box input[type="text"],
            .parsi-order-meta-box select,
            .parsi-order-meta-box textarea {
                margin-top: 5px;
            }
        </style>
        
        <?php if (!empty($parsi_tracking)): ?>
        <script>
        jQuery(document).ready(function($) {
            $('.parsi-track-button-meta').on('click', function() {
                var button = $(this);
                var trackingNumber = button.data('tracking');
                var resultDiv = button.siblings('.parsi-tracking-result-meta');
                
                button.prop('disabled', true).text('<?php esc_html_e('در حال بررسی...', 'parsi'); ?>');
                resultDiv.hide().html('');
                
                $.ajax({
                    url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    data: {
                        action: 'parsi_get_tracking',
                        tracking_number: trackingNumber,
                        nonce: '<?php echo wp_create_nonce('parsi_tracking_nonce'); ?>'
                    },
                    success: function(response) {
                        button.prop('disabled', false).text('<?php esc_html_e('بررسی وضعیت رهگیری', 'parsi'); ?>');
                        
                        if (response.success && response.data) {
                            var html = '<div style="background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px;">';
                            
                            if (response.data.status) {
                                html += '<p style="margin: 5px 0;"><strong><?php esc_html_e('وضعیت:', 'parsi'); ?></strong> ' + response.data.status + '</p>';
                            }
                            
                            if (response.data.location) {
                                html += '<p style="margin: 5px 0;"><strong><?php esc_html_e('مکان:', 'parsi'); ?></strong> ' + response.data.location + '</p>';
                            }
                            
                            if (response.data.timeline && response.data.timeline.length > 0) {
                                html += '<p style="margin: 5px 0;"><strong><?php esc_html_e('آخرین رویداد:', 'parsi'); ?></strong><br>';
                                var lastEvent = response.data.timeline[0];
                                html += '<small>' + (lastEvent.date || '') + ' - ' + (lastEvent.description || '') + '</small>';
                                html += '</p>';
                            }
                            
                            html += '</div>';
                            resultDiv.html(html).show();
                        } else {
                            resultDiv.html('<p style="color: #d63638; font-size: 11px; margin: 0;"><?php esc_html_e('خطا در دریافت اطلاعات', 'parsi'); ?></p>').show();
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('<?php esc_html_e('بررسی وضعیت رهگیری', 'parsi'); ?>');
                        resultDiv.html('<p style="color: #d63638; font-size: 11px; margin: 0;"><?php esc_html_e('خطا در ارتباط', 'parsi'); ?></p>').show();
                    }
                });
            });
            
            // Manual order sync function
            window.parsiManualSyncOrder = function(orderId) {
                if (!confirm('<?php esc_html_e('آیا می‌خواهید وضعیت این سفارش را همگام‌سازی کنید؟', 'parsi'); ?>')) {
                    return;
                }
                
                var button = jQuery('button[onclick*="parsiManualSyncOrder(' + orderId + ')"]');
                button.prop('disabled', true).text('<?php esc_html_e('در حال همگام‌سازی...', 'parsi'); ?>');
                
                jQuery.ajax({
                    url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    data: {
                        action: 'parsi_manual_sync_order',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('parsi_manual_sync_order'); ?>'
                    },
                    success: function(response) {
                        button.prop('disabled', false).text('<?php esc_html_e('همگام‌سازی وضعیت', 'parsi'); ?>');
                        
                        if (response.success) {
                            alert('<?php esc_html_e('وضعیت سفارش با موفقیت همگام‌سازی شد.', 'parsi'); ?>');
                            location.reload();
                        } else {
                            alert(response.data || '<?php esc_html_e('خطا در همگام‌سازی وضعیت.', 'parsi'); ?>');
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('<?php esc_html_e('همگام‌سازی وضعیت', 'parsi'); ?>');
                        alert('<?php esc_html_e('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', 'parsi'); ?>');
                    }
                });
            };
        });
        </script>
        <?php endif; ?>
        <?php
    }
    
    /**
     * 🔐 SECURITY: Validate order ownership
     * Checks if the current user has permission to access the order
     *
     * @param int $order_id Order ID
     * @return bool True if user has access, false otherwise
     */
    private function validate_order_ownership($order_id) {
        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Check if user has permission to edit shop orders
        if (!current_user_can('edit_shop_orders')) {
            return false;
        }
        
        // For shop managers and admins, they have access to all orders
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        
        // For other users with edit_shop_orders capability,
        // verify they have access to this specific order
        // (This is a basic check; additional checks may be needed based on user roles)
        return true;
    }
    
    /**
     * Save order meta box data
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function save_order_meta_box($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['parsi_order_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['parsi_order_meta_box_nonce'], 'parsi_order_meta_box')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        // Save PARSI shipping enabled
        $parsi_shipping = isset($_POST['parsi_shipping_enabled']) ? 'yes' : 'no';
        $order->update_meta_data('_parsi_shipping_enabled', $parsi_shipping);
        
        // Save tracking number
        if (isset($_POST['parsi_tracking_number'])) {
            $tracking = sanitize_text_field($_POST['parsi_tracking_number']);
            $order->update_meta_data('_parsi_tracking_number', $tracking);
        }
        
        // Save shipping status
        if (isset($_POST['parsi_shipping_status'])) {
            $status = sanitize_text_field($_POST['parsi_shipping_status']);
            $order->update_meta_data('_parsi_shipping_status', $status);
        }
        
        // Save notes
        if (isset($_POST['parsi_shipping_notes'])) {
            $notes = sanitize_textarea_field($_POST['parsi_shipping_notes']);
            $order->update_meta_data('_parsi_shipping_notes', $notes);
        }
        
        $order->save();
        
        // Add order note if status changed
        if (isset($_POST['parsi_shipping_status']) && !empty($_POST['parsi_shipping_status'])) {
            $status_text = sanitize_text_field($_POST['parsi_shipping_status']);
            $order->add_order_note(sprintf(__('وضعیت ارسال پارسی پست تغییر کرد به: %s', 'parsi'), $status_text));
        }
    }
    
    /**
     * AJAX handler for manual order sync
     */
    public function ajax_manual_sync_order() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'parsi_manual_sync_order')) {
            wp_send_json_error(array('success' => false, 'data' => __('توکن امنیتی نامعتبر است.', 'parsi')));
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('success' => false, 'data' => __('شما اجازه انجام این عملیات را ندارید.', 'parsi')));
            return;
        }
        
        // Get order ID
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('success' => false, 'data' => __('شناسه سفارش نامعتبر است.', 'parsi')));
            return;
        }
        
        // 🔐 SECURITY: Validate order ownership
        if (!$this->validate_order_ownership($order_id)) {
            wp_send_json_error(array('success' => false, 'data' => __('سفارش یافت نشد یا شما اجازه دسترسی به آن را ندارید.', 'parsi')));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('success' => false, 'data' => __('سفارش یافت نشد.', 'parsi')));
            return;
        }
        
        // 🔐 SECURITY: Check for request flooding
        if (function_exists('parsi_can_make_api_request') && !parsi_can_make_api_request($order_id, 'sync', 30)) {
            wp_send_json_error(array('success' => false, 'data' => __('لطفاً چند لحظه دیگر تلاش کنید.', 'parsi')));
            return;
        }
        
        // Get tracking number
        $tracking_number = $order->get_meta('_parsi_tracking_number');
        if (empty($tracking_number)) {
            wp_send_json_error(array('success' => false, 'data' => __('کد پیگیری برای این سفارش یافت نشد.', 'parsi')));
            return;
        }
        
        // Sync status using PARSI_Status_Sync
        if (class_exists('PARSI_Status_Sync')) {
            $status_sync = new PARSI_Status_Sync();
            $result = $status_sync->sync_shipment_status($order, $tracking_number);
            
            // 🔐 SECURITY: Record API request time
            if (function_exists('parsi_record_api_request_time')) {
                parsi_record_api_request_time($order_id, 'sync');
            }
            
            if ($result) {
                wp_send_json_success(array('success' => true));
            } else {
                wp_send_json_error(array('success' => false, 'data' => __('خطا در همگام‌سازی وضعیت ارسال.', 'parsi')));
            }
        } else {
            wp_send_json_error(array('success' => false, 'data' => __('سرویس همگام‌سازی در دسترس نیست.', 'parsi')));
        }
    }
    
    /**
     * AJAX handler for toggling Parsi shipping from orders list
     */
    public function ajax_toggle_shipping() {
        // Get order ID
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => __('شناسه سفارش نامعتبر است.', 'parsi')));
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'parsi_toggle_shipping_' . $order_id)) {
            wp_send_json_error(array('message' => __('بررسی امنیتی ناموفق بود.', 'parsi')));
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('شما اجازه انجام این عملیات را ندارید.', 'parsi')));
            return;
        }
        
        // 🔐 SECURITY: Validate order ownership
        if (!$this->validate_order_ownership($order_id)) {
            wp_send_json_error(array('message' => __('سفارش یافت نشد یا شما اجازه دسترسی به آن را ندارید.', 'parsi')));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('سفارش یافت نشد.', 'parsi')));
            return;
        }
        
        // Get current status and toggle it
        $current_status = $order->get_meta('_parsi_shipping_enabled');
        $new_status = ('yes' === $current_status) ? 'no' : 'yes';
        
        // Update the meta
        $order->update_meta_data('_parsi_shipping_enabled', $new_status);
        
        // If enabling, clear auto-detected flag to indicate manual override
        if ('yes' === $new_status) {
            $order->update_meta_data('_parsi_auto_detected', 'no');
        }
        
        $order->save();
        
        // Add order note
        $note_text = ('yes' === $new_status)
            ? __('ارسال با پارسی پست فعال شد.', 'parsi')
            : __('ارسال با پارسی پست غیرفعال شد.', 'parsi');
        $order->add_order_note($note_text);
        
        // Get tracking number for response
        $tracking_number = $order->get_meta('_parsi_tracking_number');
        $shipping_status = $order->get_meta('_parsi_shipping_status');
        
        wp_send_json_success(array(
            'enabled' => ('yes' === $new_status),
            'tracking_number' => $tracking_number,
            'shipping_status' => $shipping_status,
            'status_text' => ('yes' === $new_status) ? ($shipping_status ?: __('فعال', 'parsi')) : __('غیرفعال', 'parsi')
        ));
    }
    
    /**
     * Add bulk actions
     *
     * @param array $actions Existing actions
     * @return array
     */
    public function add_bulk_actions($actions) {
        $actions['parsi_enable'] = __('فعال‌سازی ارسال با پارسی پست', 'parsi');
        $actions['parsi_disable'] = __('غیرفعال‌سازی ارسال با پارسی پست', 'parsi');
        return $actions;
    }
    
    /**
     * Handle bulk actions
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $post_ids Post IDs
     * @return string
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if (!in_array($action, array('parsi_enable', 'parsi_disable'))) {
            return $redirect_to;
        }
        
        $updated = 0;
        $value = ('parsi_enable' === $action) ? 'yes' : 'no';
        
        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if ($order) {
                $order->update_meta_data('_parsi_shipping_enabled', $value);
                $order->save();
                $updated++;
            }
        }
        
        $redirect_to = add_query_arg(array(
            'parsi_bulk_action' => $action,
            'parsi_updated' => $updated,
        ), $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * Show admin notice for bulk actions
     */
    public function bulk_action_admin_notice() {
        if (!isset($_GET['parsi_bulk_action']) || !isset($_GET['parsi_updated'])) {
            return;
        }
        
        $action = sanitize_text_field($_GET['parsi_bulk_action']);
        $updated = intval($_GET['parsi_updated']);
        
        $message = '';
        if ('parsi_enable' === $action) {
            $message = sprintf(__('%d سفارش با موفقیت برای ارسال با پارسی پست فعال شد.', 'parsi'), $updated);
        } elseif ('parsi_disable' === $action) {
            $message = sprintf(__('%d سفارش با موفقیت از ارسال با پارسی پست غیرفعال شد.', 'parsi'), $updated);
        }
        
        if ($message) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        }
    }
}

