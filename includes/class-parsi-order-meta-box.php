<?php
/**
 * PARSI Order Meta Box Class
 *
 * Handles the shipment information meta box on WooCommerce Order Edit page
 *
 * @package PARSI
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_Order_Meta_Box {
    
    /**
     * Single instance of the class
     *
     * @var PARSI_Order_Meta_Box
     */
    private static $instance = null;
    
    /**
     * Get single instance of the class
     *
     * @return PARSI_Order_Meta_Box
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('add_meta_boxes', array($this, 'add_shipment_meta_box'));
        add_action('save_post', array($this, 'save_shipment_data'));
        add_action('wp_ajax_parsi_save_shipment_data', array($this, 'ajax_save_shipment_data'));
    }
    
    /**
     * Add shipment meta box to WooCommerce Order Edit page
     */
    public function add_shipment_meta_box() {
        add_meta_box(
            'parsi_shipment_info',
            __('اطلاعات مرسوله پارسی', 'parsi'),
            array($this, 'render_shipment_meta_box'),
            'shop_order',
            'normal',
            'high'
        );
    }
    
    /**
     * Render the shipment meta box HTML
     *
     * @param WP_Post $post The post object
     */
    public function render_shipment_meta_box($post) {
        // Get order object
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }
        
        // Get saved shipment data
        $shipment_data = $this->get_shipment_data($order->get_id());
        
        // Add nonce for security
        wp_nonce_field('parsi_save_shipment_data', 'parsi_shipment_nonce');
        
        // Include the meta box template
        include_once PARSI_PLUGIN_DIR . 'includes/templates/shipment-meta-box.php';
    }
    
    /**
     * Get shipment data from order meta
     *
     * @param int $order_id Order ID
     * @return array Shipment data
     */
    public function get_shipment_data($order_id) {
        return array(
            'shipment_type' => get_post_meta($order_id, '_parsi_shipment_type', true),
            'weight' => get_post_meta($order_id, '_parsi_weight', true),
            'length' => get_post_meta($order_id, '_parsi_length', true),
            'width' => get_post_meta($order_id, '_parsi_width', true),
            'height' => get_post_meta($order_id, '_parsi_height', true),
            'contents' => get_post_meta($order_id, '_parsi_contents', true),
            'declared_value' => get_post_meta($order_id, '_parsi_declared_value', true),
            'package_type' => get_post_meta($order_id, '_parsi_package_type', true),
            'packaging_cost' => get_post_meta($order_id, '_parsi_packaging_cost', true),
            'insurance_type' => get_post_meta($order_id, '_parsi_insurance_type', true),
        );
    }
    
    /**
     * Save shipment data when order is saved
     *
     * @param int $post_id Post ID
     */
    public function save_shipment_data($post_id) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check if this is a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }
        
        // Check if this is the correct post type
        if ('shop_order' !== get_post_type($post_id)) {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['parsi_shipment_nonce']) || 
            !wp_verify_nonce($_POST['parsi_shipment_nonce'], 'parsi_save_shipment_data')) {
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('edit_shop_orders', $post_id)) {
            return;
        }
        
        // Process and save shipment data
        $this->process_and_save_shipment_data($post_id, $_POST);
    }
    
    /**
     * AJAX handler for saving shipment data
     */
    public function ajax_save_shipment_data() {
        // Verify nonce
        if (!check_ajax_referer('parsi_save_shipment_data', 'nonce', false)) {
            wp_send_json_error(array('message' => __('خطا در تأیید درخواست.', 'parsi')));
        }
        
        // Check user capabilities
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('شما دسترسی کافی ندارید.', 'parsi')));
        }
        
        // Get order ID
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_send_json_error(array('message' => __('شناسه سفارش نامعتبر است.', 'parsi')));
        }
        
        // Process and save shipment data
        $result = $this->process_and_save_shipment_data($order_id, $_POST);
        
        if ($result) {
            wp_send_json_success(array('message' => __('اطلاعات مرسوله با موفقیت ذخیره شد.', 'parsi')));
        } else {
            wp_send_json_error(array('message' => __('خطا در ذخیره اطلاعات مرسوله.', 'parsi')));
        }
    }
    
    /**
     * Process and validate shipment data before saving
     *
     * @param int $order_id Order ID
     * @param array $data Shipment data
     * @return bool Success status
     */
    public function process_and_save_shipment_data($order_id, $data) {
        // Validate shipment type
        $shipment_type = isset($data['parsi_shipment_type']) ? sanitize_text_field($data['parsi_shipment_type']) : '';
        if (empty($shipment_type) || !in_array($shipment_type, array('packet', 'parcel'), true)) {
            return false;
        }
        
        // Validate weight (required for both types)
        $weight = isset($data['parsi_weight']) ? absint($data['parsi_weight']) : 0;
        if ($weight <= 0) {
            return false;
        }
        
        // Validate contents description (required for both types)
        $contents = isset($data['parsi_contents']) ? sanitize_text_field($data['parsi_contents']) : '';
        if (empty($contents)) {
            return false;
        }
        
        // Validate declared value
        $declared_value = isset($data['parsi_declared_value']) ? absint($data['parsi_declared_value']) : 0;
        
        // Validate package type (required for both types)
        $package_type = isset($data['parsi_package_type']) ? sanitize_text_field($data['parsi_package_type']) : '';
        if (empty($package_type)) {
            return false;
        }
        
        // Validate packaging cost
        $packaging_cost = isset($data['parsi_packaging_cost']) ? absint($data['parsi_packaging_cost']) : 0;
        
        // Apply insurance business rule
        $insurance_type = $this->determine_insurance_type($declared_value, isset($data['parsi_insurance_type']) ? sanitize_text_field($data['parsi_insurance_type']) : '');
        
        // Validate dimensions (required only for parcel)
        $length = $width = $height = 0;
        if ('parcel' === $shipment_type) {
            $length = isset($data['parsi_length']) ? absint($data['parsi_length']) : 0;
            $width = isset($data['parsi_width']) ? absint($data['parsi_width']) : 0;
            $height = isset($data['parsi_height']) ? absint($data['parsi_height']) : 0;
            
            if ($length <= 0 || $width <= 0 || $height <= 0) {
                return false;
            }
        }
        
        // Save all data
        update_post_meta($order_id, '_parsi_shipment_type', $shipment_type);
        update_post_meta($order_id, '_parsi_weight', $weight);
        update_post_meta($order_id, '_parsi_length', $length);
        update_post_meta($order_id, '_parsi_width', $width);
        update_post_meta($order_id, '_parsi_height', $height);
        update_post_meta($order_id, '_parsi_contents', $contents);
        update_post_meta($order_id, '_parsi_declared_value', $declared_value);
        update_post_meta($order_id, '_parsi_package_type', $package_type);
        update_post_meta($order_id, '_parsi_packaging_cost', $packaging_cost);
        update_post_meta($order_id, '_parsi_insurance_type', $insurance_type);
        
        return true;
    }
    
    /**
     * Determine insurance type based on declared value
     * Implements the business rule: if declared value is empty or less than 5,000,000 Rial,
     * insurance type must be "mandatory"
     *
     * @param int $declared_value Declared value in Rial
     * @param string $user_selected_type User selected insurance type
     * @return string Final insurance type
     */
    public function determine_insurance_type($declared_value, $user_selected_type) {
        // If declared value is empty or less than 5,000,000 Rial, force mandatory insurance
        if (empty($declared_value) || $declared_value < 5000000) {
            return 'mandatory';
        }
        
        // Validate user selected insurance type
        $valid_types = array('mandatory', 'cash_up_to_50m', 'goods_up_to_2b', 'documents_up_to_1b', 'bulk_up_to_1_5b');
        if (in_array($user_selected_type, $valid_types, true)) {
            return $user_selected_type;
        }
        
        // Default to mandatory if invalid type
        return 'mandatory';
    }
    
    /**
     * Get package type options for packet
     *
     * @return array Packet package type options
     */
    public function get_packet_package_options() {
        return array(
            'بدون نیاز به بسته بندی' => __('بدون نیاز به بسته بندی', 'parsi'),
            'پاکت حباب دار A3' => __('پاکت حباب دار A3', 'parsi'),
            'پاکت حباب دار A4' => __('پاکت حباب دار A4', 'parsi'),
            'پاکت حباب دار A5' => __('پاکت حباب دار A5', 'parsi'),
            'پاکت لمینت A3' => __('پاکت لمینت A3', 'parsi'),
            'پاکت لمینت A4' => __('پاکت لمینت A4', 'parsi'),
            'پاکت لمینت A5' => __('پاکت لمینت A5', 'parsi'),
            'کاور اسناد' => __('کاور اسناد', 'parsi'),
        );
    }
    
    /**
     * Get package type options for parcel
     *
     * @return array Parcel package type options
     */
    public function get_parcel_package_options() {
        return array(
            'کارتن 1' => __('کارتن 1', 'parsi'),
            'کارتن 2' => __('کارتن 2', 'parsi'),
            'کارتن 3' => __('کارتن 3', 'parsi'),
            'کارتن 4' => __('کارتن 4', 'parsi'),
            'کارتن 5' => __('کارتن 5', 'parsi'),
            'کارتن 6' => __('کارتن 6', 'parsi'),
            'کارتن 7' => __('کارتن 7', 'parsi'),
            'کارتن 8' => __('کارتن 8', 'parsi'),
            'کارتن 9' => __('کارتن 9', 'parsi'),
            'کارتن 10' => __('کارتن 10', 'parsi'),
        );
    }
    
    /**
     * Get insurance type options
     *
     * @return array Insurance type options
     */
    public function get_insurance_options() {
        return array(
            'mandatory' => __('اجباری', 'parsi'),
            'cash_up_to_50m' => __('نقدی تا ۵۰ میلیون', 'parsi'),
            'goods_up_to_2b' => __('کالا تا ۲ میلیارد', 'parsi'),
            'documents_up_to_1b' => __('اسناد تا ۱ میلیارد', 'parsi'),
            'bulk_up_to_1_5b' => __('حجمی تا ۱.۵ میلیارد', 'parsi'),
        );
    }
    
    /**
     * Enqueue admin scripts and styles for the meta box
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on order edit page
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }
        
        // Check if we're editing a shop order
        if (isset($_GET['post']) && 'shop_order' === get_post_type(intval($_GET['post']))) {
            // Enqueue CSS
            wp_enqueue_style(
                'parsi-order-meta-box',
                PARSI_PLUGIN_URL . 'assets/css/order-meta-box.css',
                array('woocommerce_admin_styles'),
                PARSI_VERSION
            );
            
            // Enqueue JavaScript
            wp_enqueue_script(
                'parsi-order-meta-box',
                PARSI_PLUGIN_URL . 'assets/js/order-meta-box.js',
                array('jquery'),
                PARSI_VERSION,
                true
            );
            
            // Localize script
            wp_localize_script('parsi-order-meta-box', 'parsiOrderMeta', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('parsi_save_shipment_data'),
                'strings' => array(
                    'confirmSave' => __('آیا از ذخیره اطلاعات مرسوله اطمینان دارید؟', 'parsi'),
                    'saving' => __('در حال ذخیره...', 'parsi'),
                    'saved' => __('اطلاعات با موفقیت ذخیره شد.', 'parsi'),
                    'error' => __('خطا در ذخیره اطلاعات.', 'parsi'),
                    'mandatoryInsuranceNotice' => __('با توجه به ارزش مرسوله، بیمه اجباری اعمال شد.', 'parsi'),
                ),
            ));
        }
    }
}