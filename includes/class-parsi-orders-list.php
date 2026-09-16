<?php
/**
 * PARSI Orders List Panel
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

// Load WP_List_Table if not available
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * PARSI Orders List Table Class
 */
class PARSI_Orders_List_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'parsi_order',
            'plural'   => 'parsi_orders',
            'ajax'      => false
        ));
    }
    
    /**
     * Get table columns
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'order_id'       => __('شماره سفارش', 'parsi'),
            'customer_name'  => __('نام مشتری', 'parsi'),
            'products'       => __('محصولات', 'parsi'),
            'tracking_code'  => __('کد پیگیری', 'parsi'),
            'contract_code'  => __('کد قرارداد', 'parsi'),
            'status'         => __('وضعیت ارسال', 'parsi'),
            'actions'        => __('اقدامات', 'parsi'),
        );
    }
    
    /**
     * Get sortable columns
     *
     * @return array
     */
    protected function get_sortable_columns() {
        return array(
            'order_id' => array('order_id', false),
        );
    }
    
    /**
     * Return IDs of all orders that have _parsi_shipping_enabled = yes,
     * sorted newest-first. Queries the DB directly to support both HPOS
     * (wp_wc_orders / wp_wc_orders_meta) and legacy post-based storage
     * (wp_posts / wp_postmeta) — wc_get_orders() with meta_query is not
     * supported on the HPOS data store (WC 9.2+).
     *
     * @return int[]
     */
    private function get_all_parsi_order_ids() {
        global $wpdb;

        $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $use_hpos ) {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT m.order_id
                 FROM {$wpdb->prefix}wc_orders_meta AS m
                 INNER JOIN {$wpdb->prefix}wc_orders AS o ON o.id = m.order_id
                 WHERE m.meta_key = '_parsi_shipping_enabled'
                   AND m.meta_value = 'yes'
                   AND o.type = 'shop_order'
                   AND o.status != 'trash'
                 ORDER BY o.date_created_gmt DESC"
            );
        } else {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} AS p
                 INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_parsi_shipping_enabled'
                   AND pm.meta_value = 'yes'
                   AND p.post_type = 'shop_order'
                   AND p.post_status != 'trash'
                 ORDER BY p.post_date DESC"
            );
        }

        return array_map( 'intval', $ids ?: array() );
    }

    /**
     * Fetch display data for a page of order IDs directly from the DB.
     * Avoids wc_get_order() entirely — two batch queries regardless of page size.
     * Works with both HPOS and legacy storage.
     *
     * @param int[] $order_ids
     * @return array
     */
    private function fetch_order_rows( array $order_ids ) {
        global $wpdb;

        if ( empty( $order_ids ) ) {
            return array();
        }

        // All values already cast to int — safe to interpolate.
        $ids_in = implode( ',', $order_ids );

        $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $meta_table = $use_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $id_col     = $use_hpos ? 'order_id' : 'post_id';

        $needed_keys = array(
            '_billing_first_name',
            '_billing_last_name',
            '_billing_email',
            '_parsi_tracking_number',
            '_parsi_contract_id',
            '_parsi_shipping_status',
        );
        $keys_in = implode( ',', array_fill( 0, count( $needed_keys ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$id_col} AS order_id, meta_key, meta_value
                 FROM {$meta_table}
                 WHERE {$id_col} IN ({$ids_in})
                   AND meta_key IN ({$keys_in})",
                ...$needed_keys
            ),
            ARRAY_A
        );

        $product_rows = $wpdb->get_results(
            "SELECT oi.order_id, oi.order_item_name, oim.meta_value AS qty
             FROM {$wpdb->prefix}woocommerce_order_items AS oi
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                 ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_qty'
             WHERE oi.order_id IN ({$ids_in})
               AND oi.order_item_type = 'line_item'",
            ARRAY_A
        );
        // phpcs:enable

        // Index meta by order_id.
        $meta = array();
        foreach ( $meta_rows as $row ) {
            $meta[ (int) $row['order_id'] ][ $row['meta_key'] ] = $row['meta_value'];
        }

        // Index products by order_id.
        $products = array();
        foreach ( $product_rows as $row ) {
            $products[ (int) $row['order_id'] ][] = array(
                'name' => $row['order_item_name'],
                'qty'  => (int) $row['qty'],
            );
        }

        $data = array();
        foreach ( $order_ids as $order_id ) {
            $m = isset( $meta[ $order_id ] ) ? $meta[ $order_id ] : array();

            $first = isset( $m['_billing_first_name'] ) ? $m['_billing_first_name'] : '';
            $last  = isset( $m['_billing_last_name'] )  ? $m['_billing_last_name']  : '';
            $name  = trim( $first . ' ' . $last );
            if ( '' === $name ) {
                $name = isset( $m['_billing_email'] ) ? $m['_billing_email'] : '';
            }

            $prods     = isset( $products[ $order_id ] ) ? $products[ $order_id ] : array();
            $prod_strs = array();
            foreach ( array_slice( $prods, 0, 3 ) as $p ) {
                $prod_strs[] = $p['qty'] > 1
                    ? $p['name'] . ' (' . $p['qty'] . ')'
                    : $p['name'];
            }
            $prod_text = implode( ', ', $prod_strs );
            if ( count( $prods ) > 3 ) {
                $prod_text .= '...';
            }

            $tracking = isset( $m['_parsi_tracking_number'] ) ? $m['_parsi_tracking_number'] : '';
            $contract = ! empty( $m['_parsi_contract_id'] )
                ? $m['_parsi_contract_id']
                : get_option( 'parsi_contract_id', '' );
            $status   = ! empty( $m['_parsi_shipping_status'] )
                ? $m['_parsi_shipping_status']
                : __( 'ثبت نشده', 'parsi' );

            $is_delivered = false !== stripos( $status, 'تحویل' )
                || false !== stripos( $status, 'delivered' );

            $data[] = array(
                'order_id'      => $order_id,
                'customer_name' => $name,
                'products'      => $prod_text,
                'tracking_code' => $tracking,
                'contract_code' => $contract,
                'status'        => $status,
                'is_delivered'  => $is_delivered,
            );
        }

        return $data;
    }

    /**
     * Prepare items for display
     */
    public function prepare_items() {
        $per_page     = $this->get_items_per_page( 'parsi_orders_per_page', 20 );
        $current_page = $this->get_pagenum();

        $all_ids      = $this->get_all_parsi_order_ids();
        $total_orders = count( $all_ids );

        $order_dir = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_text_field( $_REQUEST['order'] ) )
            ? 'ASC'
            : 'DESC';

        if ( 'ASC' === $order_dir ) {
            $all_ids = array_reverse( $all_ids );
        }

        $page_ids = array_slice( $all_ids, ( $current_page - 1 ) * $per_page, $per_page );

        $this->_column_headers = $this->get_column_info();
        $this->items           = $this->fetch_order_rows( $page_ids );

        $this->set_pagination_args( array(
            'total_items' => $total_orders,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_orders / $per_page ),
        ) );
    }

    /**
     * Get total count of Parsi orders
     *
     * @return int
     */
    private function get_total_orders() {
        return count( $this->get_all_parsi_order_ids() );
    }
    
    /**
     * Prepare order data for table display
     *
     * @param array $orders Array of WC_Order objects
     * @return array
     */
    private function prepare_order_data($orders) {
        $data = array();
        
        foreach ($orders as $order) {
            if (!$order) {
                continue;
            }
            
            $order_id = $order->get_id();
            
            // Get customer name
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $customer_name = trim($first_name . ' ' . $last_name);
            if (empty($customer_name)) {
                $customer_name = $order->get_billing_email();
            }
            
            // Get products
            $products = array();
            foreach ($order->get_items() as $item) {
                $product_name = $item->get_name();
                $quantity = $item->get_quantity();
                if ($quantity > 1) {
                    $products[] = $product_name . ' (' . $quantity . ')';
                } else {
                    $products[] = $product_name;
                }
            }
            $products_text = implode(', ', array_slice($products, 0, 3));
            if (count($products) > 3) {
                $products_text .= '...';
            }
            
            // Get tracking code
            $tracking_code = $order->get_meta('_parsi_tracking_number');
            
            // Get contract code
            $contract_code = $order->get_meta('_parsi_contract_id');
            if (empty($contract_code)) {
                $contract_code = get_option('parsi_contract_id', '');
            }
            
            // Get status
            $status = $order->get_meta('_parsi_shipping_status');
            if (empty($status)) {
                $status = __('ثبت نشده', 'parsi');
            }
            
            // Check if shipment is delivered
            $is_delivered = (false !== stripos($status, 'تحویل') || false !== stripos($status, 'delivered'));
            
            $data[] = array(
                'order_id'      => $order_id,
                'customer_name' => $customer_name,
                'products'      => $products_text,
                'tracking_code'  => $tracking_code,
                'contract_code' => $contract_code,
                'status'        => $status,
                'is_delivered'  => $is_delivered,
            );
        }
        
        return $data;
    }
    
    /**
     * Default column renderer
     *
     * @param array $item Row data
     * @param string $column_name Column name
     * @return string
     */
    protected function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }
    
    /**
     * Render order ID column
     *
     * @param array $item Row data
     * @return string
     */
    protected function column_order_id($item) {
        $order_id = (int) $item['order_id'];

        $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $edit_url = $use_hpos
            ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
            : admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        return sprintf(
            '<a href="%s" class="row-title">#%s</a>',
            esc_url( $edit_url ),
            esc_html( $order_id )
        );
    }
    
    /**
     * Render status column
     *
     * @param array $item Row data
     * @return string
     */
    protected function column_status($item) {
        $status = $item['status'];
        
        // Determine status color
        $color = '#999';
        if (false !== stripos($status, 'ثبت') || false !== stripos($status, 'registered')) {
            $color = '#0073aa';
        } elseif (false !== stripos($status, 'ارسال') || false !== stripos($status, 'shipped') || false !== stripos($status, 'در حال')) {
            $color = '#ff9000';
        } elseif (false !== stripos($status, 'تحویل') || false !== stripos($status, 'delivered')) {
            $color = '#46b450';
        } elseif (false !== stripos($status, 'خطا') || false !== stripos($status, 'error') || false !== stripos($status, 'ناموفق')) {
            $color = '#dc3232';
        }
        
        return sprintf(
            '<span style="color: %s; font-weight: bold;">%s</span>',
            esc_attr($color),
            esc_html($status)
        );
    }
    
    /**
     * Render actions column
     *
     * @param array $item Row data
     * @return string
     */
    protected function column_actions($item) {
        $order_id = $item['order_id'];
        $tracking_code = $item['tracking_code'];
        $is_delivered = $item['is_delivered'];
        
        $actions = array();
        
        // Update status action
        $update_nonce = wp_create_nonce('parsi_update_status_' . $order_id);
        $actions[] = sprintf(
            '<button type="button" 
                    class="button button-small parsi-update-status-btn" 
                    data-order-id="%d" 
                    data-nonce="%s"
                    data-loading-text="%s">
                %s
            </button>',
            esc_attr($order_id),
            esc_attr($update_nonce),
            esc_attr__('در حال بروزرسانی...', 'parsi'),
            esc_html__('بروزرسانی وضعیت', 'parsi')
        );
        
        // Re-register action (only if not delivered)
        if (!$is_delivered) {
            $reregister_nonce = wp_create_nonce('parsi_reregister_' . $order_id);
            $actions[] = sprintf(
                '<button type="button" 
                        class="button button-small parsi-reregister-btn" 
                        data-order-id="%d" 
                        data-nonce="%s"
                        data-loading-text="%s">
                    %s
                </button>',
                esc_attr($order_id),
                esc_attr($reregister_nonce),
                esc_attr__('در حال ثبت...', 'parsi'),
                esc_html__('ثبت مجدد در پارسی پست', 'parsi')
            );
        }
        
        return implode(' ', $actions);
    }
    
    /**
     * No items found message
     */
    public function no_items() {
        esc_html_e('هیچ سفارشی با ارسال پارسی پست یافت نشد.', 'parsi');
    }
}

/**
 * PARSI Orders List Admin Class
 */
class PARSI_Orders_List {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add submenu page
        add_action('admin_menu', array($this, 'add_submenu_page'));

        // Redirect direct visits to admin.php?page=parsi-orders before output.
        add_action('admin_init', array($this, 'maybe_redirect'));

        // Handle AJAX actions
        add_action('wp_ajax_parsi_update_order_status', array($this, 'ajax_update_status'));
        add_action('wp_ajax_parsi_reregister_order', array($this, 'ajax_reregister'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Return the URL of the WooCommerce orders list pre-filtered for Parsi orders.
     * Handles both HPOS and legacy storage.
     *
     * @return string
     */
    private function wc_orders_url() {
        $use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return $use_hpos
            ? admin_url( 'admin.php?page=wc-orders&parsi_shipping_method=parsi' )
            : admin_url( 'edit.php?post_type=shop_order&parsi_shipping_method=parsi' );
    }

    /**
     * Redirect admin.php?page=parsi-orders to the filtered WooCommerce orders list.
     * Must run on admin_init (before any output is sent).
     */
    public function maybe_redirect() {
        if ( empty( $_GET['page'] ) || 'parsi-orders' !== $_GET['page'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        wp_redirect( $this->wc_orders_url() );
        exit;
    }
    
    /**
     * Add submenu page
     */
    public function add_submenu_page() {
        add_submenu_page(
            'parsi-settings',
            __('سفارش‌های پارسی پست', 'parsi'),
            __('سفارش‌های پارسی پست', 'parsi'),
            'manage_woocommerce',
            'parsi-orders',
            array($this, 'render_orders_page')
        );

        // Point the sidebar link directly to the filtered WooCommerce orders page
        // so clicking it skips the parsi-orders slug entirely.
        global $submenu;
        if ( ! empty( $submenu['parsi-settings'] ) ) {
            foreach ( $submenu['parsi-settings'] as &$item ) {
                if ( isset( $item[2] ) && 'parsi-orders' === $item[2] ) {
                    $item[2] = $this->wc_orders_url();
                    break;
                }
            }
            unset( $item );
        }
    }
    
    /**
     * Enqueue scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook) {
        if ('parsi-settings_page_parsi-orders' !== $hook) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'parsi-admin-orders-list',
            PARSI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PARSI_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'parsi-orders-list',
            PARSI_PLUGIN_URL . 'assets/js/orders-list.js',
            array('jquery'),
            PARSI_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('parsi-orders-list', 'parsiOrdersList', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => array(
                'updateSuccess'   => __('وضعیت با موفقیت بروزرسانی شد.', 'parsi'),
                'updateError'     => __('خطا در بروزرسانی وضعیت.', 'parsi'),
                'reregisterSuccess' => __('سفارش با موفقیت مجدداً ثبت شد.', 'parsi'),
                'reregisterError'   => __('خطا در ثبت مجدد سفارش.', 'parsi'),
                'confirmReregister' => __('آیا مطمئن هستید که می‌خواهید این سفارش را مجدداً ثبت کنید؟', 'parsi'),
            ),
        ));
    }
    
    /**
     * Render orders page
     */
    public function render_orders_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('شما دسترسی کافی برای مشاهده این صفحه را ندارید.', 'parsi'));
        }
        
        // Create table instance
        $table = new PARSI_Orders_List_Table();
        
        // Process bulk actions if any
        $this->process_bulk_actions($table);
        
        // Prepare items
        $table->prepare_items();
        
        ?>
        <div class="wrap parsi-dashboard-wrap">
            <div class="parsi-page-header">
                <div class="parsi-logo">
                    <img src="<?php echo esc_url(PARSI_PLUGIN_URL . 'assets/logo.svg'); ?>" alt="Parsi Post Logo" />
                </div>
                <h1 class="parsi-page-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
            </div>
            
            <?php
            // Show notices if any
            if (isset($_GET['parsi_notice']) && isset($_GET['parsi_message'])) {
                $notice_type = sanitize_text_field($_GET['parsi_notice']);
                $message = sanitize_text_field($_GET['parsi_message']);
                $class = 'success' === $notice_type ? 'parsi-notice-success' : 'parsi-notice-error';
                printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
            }
            ?>
            
            <div class="parsi-orders-list-wrapper">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                    <?php
                    $table->search_box(__('جستجو در سفارشات', 'parsi'), 'parsi-order-search');
                    $table->display();
                    ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Process bulk actions (placeholder for future use)
     *
     * @param PARSI_Orders_List_Table $table Table instance
     */
    private function process_bulk_actions($table) {
        // Bulk actions can be implemented here in the future
    }
    
    /**
     * AJAX handler for updating order status
     */
    public function ajax_update_status() {
        // Verify nonce
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        if (!wp_verify_nonce($nonce, 'parsi_update_status_' . $order_id)) {
            wp_send_json_error(array('message' => __('خطای امنیتی: لطفاً صفحه را رفرش کنید.', 'parsi')));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی کافی برای انجام این عملیات را ندارید.', 'parsi')));
        }
        
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('سفارش یافت نشد.', 'parsi')));
        }
        
        // Get tracking number
        $tracking_number = $order->get_meta('_parsi_tracking_number');
        if (empty($tracking_number)) {
            wp_send_json_error(array('message' => __('کد پیگیری برای این سفارش ثبت نشده است.', 'parsi')));
        }
        
        // Sync status using PARSI_Status_Sync
        if (class_exists('PARSI_Status_Sync')) {
            $status_sync = new PARSI_Status_Sync();
            $result = $status_sync->sync_shipment_status($order, $tracking_number);
            
            if ($result) {
                $new_status = $order->get_meta('_parsi_shipping_status');
                wp_send_json_success(array(
                    'status' => $new_status,
                    'message' => __('وضعیت با موفقیت بروزرسانی شد.', 'parsi')
                ));
            } else {
                wp_send_json_error(array('message' => __('خطا در دریافت وضعیت از API.', 'parsi')));
            }
        } else {
            // The Parsi Post API does not expose a tracking status endpoint, so
            // there is no automated fallback. Status must be updated manually
            // via the order meta box.
            wp_send_json_error(array(
                'message' => __('همگام‌سازی خودکار وضعیت در این نسخه API در دسترس نیست. لطفاً وضعیت را به‌صورت دستی به‌روزرسانی کنید.', 'parsi'),
            ));
        }
    }
    
    /**
     * AJAX handler for re-registering order
     */
    public function ajax_reregister() {
        // Verify nonce
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        if (!wp_verify_nonce($nonce, 'parsi_reregister_' . $order_id)) {
            wp_send_json_error(array('message' => __('خطای امنیتی: لطفاً صفحه را رفرش کنید.', 'parsi')));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی کافی برای انجام این عملیات را ندارید.', 'parsi')));
        }
        
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('سفارش یافت نشد.', 'parsi')));
        }
        
        // Check if shipment is already delivered
        $current_status = $order->get_meta('_parsi_shipping_status');
        if ($current_status && (false !== stripos($current_status, 'تحویل') || false !== stripos($current_status, 'delivered'))) {
            wp_send_json_error(array('message' => __('سفارش تحویل شده است و نمی‌تواند مجدداً ثبت شود.', 'parsi')));
        }
        
        // Prepare shipment data
        $shipping_address = $order->get_address('shipping');
        $billing_address = $order->get_address('billing');
        $recipient_address = !empty($shipping_address['address_1']) ? $shipping_address : $billing_address;
        
        // Calculate total weight
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->has_weight()) {
                $weight += $product->get_weight() * $item->get_quantity();
            }
        }
        
        // Convert weight to kg if needed
        $weight_unit = get_option('woocommerce_weight_unit');
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
        }
        
        // City ID — prefer custom checkout field if present (the API client falls
        // back to a name lookup against the cached cities list otherwise).
        $billing_city_id = (string) get_post_meta($order_id, '_billing_city_id', true);

        // Prepare shipment data. Receiver national code is read directly from
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
            'weight'         => $weight,
            'description'    => sprintf(__('سفارش #%s', 'parsi'), $order_id),
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
                $order->update_meta_data('_parsi_shipment_registered', 'yes');
                $order->update_meta_data('_parsi_shipment_id', $shipment_id);
                if ($order_code !== '') {
                    $order->update_meta_data('_parsi_order_code', $order_code);
                }
                $order->update_meta_data('_parsi_registration_time', current_time('mysql'));
                $order->save();

                // Add order note
                $note = sprintf(
                    __('سفارش مجدداً در پارسی پست ثبت شد. کد پیگیری: %s', 'parsi'),
                    $tracking_number
                );
                if ($order_code !== '') {
                    $note .= ' ' . sprintf(__('(کد سفارش پارسی: %s)', 'parsi'), $order_code);
                }
                $order->add_order_note($note);
                
                if (function_exists('parsi_log')) {
                    parsi_log(sprintf('Order #%s re-registered successfully. Tracking: %s', $order_id, $tracking_number), 'info');
                }
                
                wp_send_json_success(array(
                    'tracking_number' => $tracking_number,
                    'message' => __('سفارش با موفقیت مجدداً ثبت شد.', 'parsi')
                ));
            } else {
                wp_send_json_error(array('message' => __('ثبت انجام شد اما کد پیگیری دریافت نشد.', 'parsi')));
            }
        } else {
            $error_message = isset($response['error']) ? $response['error'] : __('خطا در ثبت مجدد سفارش.', 'parsi');
            wp_send_json_error(array('message' => $error_message));
        }
    }
}
