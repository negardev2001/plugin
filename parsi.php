<?php
/**
 * Plugin Name: PARSI - WooCommerce Shipping Integration
 * Plugin URI: https://parsipost.ir/
 * Description: A WooCommerce shipping plugin that calculates shipping rates using an external shipping API.
 * Version: 1.2.3
 * Author: Negar Hassani
 * Author URI: mailto:Negarhassani1380@gmail.com
 * Text Domain: parsi
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PARSI_VERSION', '1.2.3');
define('PARSI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PARSI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PARSI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('PARSI_PLUGIN_FILE', __FILE__);

/**
 * Main PARSI Plugin Class
 */
class PARSI_Plugin {
    
    /**
     * Single instance of the class
     *
     * @var PARSI_Plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance of the class
     *
     * @return PARSI_Plugin
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
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Load plugin textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Declare High-Performance Order Storage (HPOS) compatibility. Must be
        // hooked on before_woocommerce_init; registering it here (at plugin load)
        // is early enough since that action fires later during WooCommerce init.
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Localize the plugin name and author on the Plugins screen when the
        // admin panel language is Persian.
        add_filter('all_plugins', array($this, 'localize_plugin_list_meta'));

        // Warn admins/shop managers when the Parsi account runs out of credit.
        add_action('admin_notices', array($this, 'credit_exhausted_notice'));

        // Check if WooCommerce is active and initialize (run after WooCommerce is loaded)
        add_action('woocommerce_init', array($this, 'check_woocommerce'));
        // Also check on plugins_loaded with low priority as fallback
        add_action('plugins_loaded', array($this, 'check_woocommerce_fallback'), 20);
        
        // Admin settings (can work without WooCommerce)
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // Plugin update checker (for automatic updates)
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        
        // AJAX handlers
        add_action('wp_ajax_parsi_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_parsi_search_cities', 'parsi_ajax_search_cities');
        add_action('wp_ajax_nopriv_parsi_search_cities', 'parsi_ajax_search_cities');
        add_action('wp_ajax_parsi_clear_cache', 'parsi_ajax_clear_cache');
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        // Check if WooCommerce is active using multiple methods
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // WooCommerce is active, include helper files
        $this->load_woocommerce_integrations();
    }
    
    /**
     * Fallback check for WooCommerce (runs on plugins_loaded)
     */
    public function check_woocommerce_fallback() {
        // Only run if woocommerce_init didn't run (WooCommerce not installed)
        if (!did_action('woocommerce_init') && !$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }
    }

    /**
     * Declare compatibility with WooCommerce High-Performance Order Storage
     * (custom order tables). The plugin already reads and writes order data via
     * CRUD (wc_get_order / $order->get_meta / update_meta_data), so it is
     * HPOS-safe; this declaration removes the "Incompatible plugin detected"
     * warning under WooCommerce → Settings → Advanced → Features and lets the
     * store switch to HPOS.
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                PARSI_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Show the plugin's name and author in Persian on the Plugins list when the
     * admin panel language is Persian; keep the English header otherwise.
     *
     * Hooked on `all_plugins` — the exact filter WP_Plugins_List_Table applies
     * to the get_plugins() data it renders. The author link (mailto) is kept
     * because AuthorURI is left untouched.
     *
     * @param array $plugins Installed plugins keyed by plugin file.
     * @return array
     */
    public function localize_plugin_list_meta($plugins) {
        if (!is_array($plugins) || !isset($plugins[PARSI_PLUGIN_BASENAME])) {
            return $plugins;
        }

        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        if (strpos($locale, 'fa') !== 0) {
            return $plugins;
        }

        $plugins[PARSI_PLUGIN_BASENAME]['Name']       = 'پارسی پست - افزونه حمل و نقل آنلاین';
        $plugins[PARSI_PLUGIN_BASENAME]['Author']     = 'نگار حسنی';
        $plugins[PARSI_PLUGIN_BASENAME]['AuthorName'] = 'نگار حسنی';

        return $plugins;
    }

    /**
     * Show an admin notice, to administrators and shop managers only, when the
     * Parsi account is out of credit (a Save Order failed with "عدم موجودی
     * اعتبار"). The flag is set in PARSI_API_Request::register_shipment() and
     * cleared automatically the next time a shipment registers successfully.
     * Customers never see this — it requires the manage_woocommerce capability.
     */
    public function credit_exhausted_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!get_option('parsi_credit_exhausted', 0)) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('پارسی پست:', 'parsi'); ?></strong>
                <?php esc_html_e('اعتبار حساب پارسی پست شما به پایان رسیده است. تا زمانی که حساب خود را شارژ نکنید، ثبت مرسوله جدید برای سفارش‌ها ناموفق خواهد بود.', 'parsi'); ?>
                <a href="https://parsipost.com" target="_blank" rel="noopener"><?php esc_html_e('شارژ حساب', 'parsi'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Check if WooCommerce is active using multiple methods
     *
     * @return bool
     */
    private function is_woocommerce_active() {
        // Check if WC class exists (most reliable)
        if (class_exists('WC')) {
            return true;
        }
        
        // Check if WooCommerce plugin is in active plugins list
        $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
        if (in_array('woocommerce/woocommerce.php', $active_plugins)) {
            return true;
        }
        
        // Check for multisite active plugins
        if (is_multisite()) {
            $active_sitewide_plugins = get_site_option('active_sitewide_plugins');
            if (isset($active_sitewide_plugins['woocommerce/woocommerce.php'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Load WooCommerce integrations
     */
    private function load_woocommerce_integrations() {
        // Prevent double loading
        if (did_action('parsi_woocommerce_loaded')) {
            return;
        }
        
        // WooCommerce is active, include helper files
        require_once PARSI_PLUGIN_DIR . 'includes/helpers.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-config.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-request.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-checkout.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-orders.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-tracking.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-auth.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-status-sync.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-orders-list.php';
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-order-meta-box.php';
        
        // Initialize orders management
        new PARSI_Orders();
        
        // Initialize tracking service
        new PARSI_Tracking();
        
        // Initialize status synchronization
        new PARSI_Status_Sync();
        
        // Initialize orders list panel
        new PARSI_Orders_List();
        
        // Initialize order meta box
        PARSI_Order_Meta_Box::get_instance();

        // Initialize classic-checkout Province/City dropdowns
        new PARSI_Checkout();

        // 🔧 Hook to save billing city ID from checkout
        add_action('woocommerce_checkout_update_order_meta', 'parsi_save_billing_city_id', 10, 1);
        
        // Initialize shipping method (load shipping method class only when needed)
        add_action('woocommerce_shipping_init', array($this, 'init_shipping_method'));
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
        
        // Also register the shipping method on plugins_loaded to ensure it's registered
        // even if WooCommerce is already loaded when the plugin is activated
        add_action('plugins_loaded', array($this, 'register_shipping_method_on_load'), 30);
        
        // Mark as loaded
        do_action('parsi_woocommerce_loaded');
    }
    
    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>
                <strong><?php esc_html_e('ارسال با پارسی پست', 'parsi'); ?></strong>
                <?php esc_html_e('نیاز به نصب و فعال بودن ووکامرس دارد.', 'parsi'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('parsi', false, dirname(PARSI_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Initialize shipping method
     */
    public function init_shipping_method() {
        // Load helpers first (required by shipping method)
        if (!function_exists('parsi_log')) {
            require_once PARSI_PLUGIN_DIR . 'includes/helpers.php';
        }
        
        // Load shipping method class only when WooCommerce shipping is initialized
        if (!class_exists('PARSI_Shipping_Method')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-shipping-method.php';
        }
    }
    
    /**
     * Add shipping method to WooCommerce
     *
     * @param array $methods Existing shipping methods
     * @return array
     */
    public function add_shipping_method($methods) {
        // Ensure the class is loaded
        if (!class_exists('PARSI_Shipping_Method')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-shipping-method.php';
        }
        
        // Only add if class exists and WC_Shipping_Method is available
        if (class_exists('PARSI_Shipping_Method') && class_exists('WC_Shipping_Method')) {
            $methods['parsi'] = 'PARSI_Shipping_Method';
        }
        
        return $methods;
    }
    
    /**
     * Register shipping method on plugins_loaded
     * This ensures the shipping method is registered even if WooCommerce is already loaded
     */
    public function register_shipping_method_on_load() {
        // Only proceed if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return;
        }
        
        // Load the shipping method class
        if (!class_exists('PARSI_Shipping_Method')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-shipping-method.php';
        }
        
        // Register the shipping method
        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Only add menu if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            return;
        }
        
        // Add as a top-level independent menu
        add_menu_page(
            __('تنظیمات پارسی پست', 'parsi'),
            __('ارسال با پارسی پست', 'parsi'),
            'manage_woocommerce',
            'parsi-settings',
            array($this, 'render_settings_page'),
            PARSI_PLUGIN_URL . 'assets/logo.svg',
            30
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Settings
        register_setting('parsi_settings', 'parsi_api_key', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_key'),
            'default' => ''
        ));
        
        register_setting('parsi_settings', 'parsi_office_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('parsi_settings', 'parsi_contract_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'EEA5ADC0-758C-4BF5-9247-131136EEB6AA'
        ));
        
        register_setting('parsi_settings', 'parsi_store_phone', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_iranian_phone'),
            'default' => ''
        ));
        
        register_setting('parsi_settings', 'parsi_handling_fee', array(
            'type' => 'float',
            'sanitize_callback' => 'floatval',
            'default' => 0
        ));
        
        register_setting('parsi_settings', 'parsi_enable_logging', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true
        ));
        
        register_setting('parsi_settings', 'parsi_default_method', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        // Additional Settings for Live Calculation
        register_setting('parsi_settings', 'parsi_origin_city', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'تهران'
        ));

        // Sender province UUID, paired with the sender city dropdown.
        register_setting('parsi_settings', 'parsi_sender_province_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('parsi_settings', 'parsi_enable_live_calculation', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => true
        ));
        
        register_setting('parsi_settings', 'parsi_debug_mode', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false
        ));
        
        // Additional Shipping Settings
        register_setting('parsi_settings', 'parsi_free_shipping_threshold', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 0
        ));
        
        register_setting('parsi_settings', 'parsi_max_shipping_cost', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 0
        ));
        
        register_setting('parsi_settings', 'parsi_delivery_time_estimate', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '3-5 روز کاری'
        ));
        
        register_setting('parsi_settings', 'parsi_enable_shipping_insurance', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false
        ));

        register_setting('parsi_settings', 'parsi_enable_pickup', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => false
        ));

        register_setting('parsi_settings', 'parsi_pickup_extra_service_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('parsi_settings', 'parsi_cod_fee', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 0
        ));
        
        register_setting('parsi_settings', 'parsi_packaging_type', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'standard'
        ));
        
        // New Settings for Product Dimensions
        register_setting('parsi_settings', 'parsi_product_weight', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_int_positive'),
            'default' => 500
        ));
        
        register_setting('parsi_settings', 'parsi_product_length', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 20
        ));
        
        register_setting('parsi_settings', 'parsi_product_width', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 15
        ));
        
        register_setting('parsi_settings', 'parsi_product_height', array(
            'type' => 'float',
            'sanitize_callback' => array($this, 'sanitize_float_positive'),
            'default' => 10
        ));
        
        register_setting('parsi_settings', 'parsi_shipment_type', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'packet'
        ));
        
        register_setting('parsi_settings', 'parsi_pickup_time', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'morning'
        ));
        
        // Settings sections
        add_settings_section(
            'parsi_connection_section',
            __('تنظیمات اتصال', 'parsi'),
            array($this, 'render_connection_section'),
            'parsi-settings'
        );
        
        add_settings_section(
            'parsi_sender_section',
            __('اطلاعات فرستنده', 'parsi'),
            array($this, 'render_sender_section'),
            'parsi-settings'
        );
        
        add_settings_section(
            'parsi_shipping_defaults_section',
            __('تنظیمات پیش‌فرض ارسال', 'parsi'),
            array($this, 'render_shipping_defaults_section'),
            'parsi-settings'
        );
        
        add_settings_section(
            'parsi_parcel_section',
            __('تنظیمات بسته و جمع‌آوری', 'parsi'),
            array($this, 'render_parcel_section'),
            'parsi-settings'
        );
        
        add_settings_section(
            'parsi_dimensions_section',
            __('ابعاد متوسط محصول', 'parsi'),
            array($this, 'render_dimensions_section'),
            'parsi-settings'
        );
        
        // Connection Settings Fields
        add_settings_field(
            'parsi_api_key',
            __('کلید API', 'parsi'),
            array($this, 'render_api_key_field'),
            'parsi-settings',
            'parsi_connection_section'
        );
        
        add_settings_field(
            'parsi_office_id',
            __('شناسه دفتر (Office ID)', 'parsi'),
            array($this, 'render_office_id_field'),
            'parsi-settings',
            'parsi_connection_section'
        );
        
        add_settings_field(
            'parsi_contract_id',
            __('شناسه قرارداد (Contract ID)', 'parsi'),
            array($this, 'render_contract_id_field'),
            'parsi-settings',
            'parsi_connection_section'
        );
        
        add_settings_field(
            'parsi_store_phone',
            __('شماره تماس فروشگاه', 'parsi'),
            array($this, 'render_store_phone_field'),
            'parsi-settings',
            'parsi_connection_section'
        );
        
        // Sender Information Fields
        add_settings_field(
            'parsi_origin_city',
            __('استان و شهر مبدا', 'parsi'),
            array($this, 'render_origin_location_field'),
            'parsi-settings',
            'parsi_sender_section'
        );
        
        // Shipping Defaults Fields
        add_settings_field(
            'parsi_enable_live_calculation',
            __('محاسبه زنده هزینه', 'parsi'),
            array($this, 'render_live_calculation_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_handling_fee',
            __('کارمزد بسته‌بندی', 'parsi'),
            array($this, 'render_handling_fee_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_free_shipping_threshold',
            __('آستانه ارسال رایگان', 'parsi'),
            array($this, 'render_free_shipping_threshold_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_max_shipping_cost',
            __('بیشینه هزینه ارسال', 'parsi'),
            array($this, 'render_max_shipping_cost_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_delivery_time_estimate',
            __('برآورد زمان تحویل', 'parsi'),
            array($this, 'render_delivery_time_estimate_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_cod_fee',
            __('کارمزد پرداخت در محل', 'parsi'),
            array($this, 'render_cod_fee_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        add_settings_field(
            'parsi_default_method',
            __('روش ارسال پیش‌فرض', 'parsi'),
            array($this, 'render_default_method_field'),
            'parsi-settings',
            'parsi_shipping_defaults_section'
        );
        
        // Parcel & Collection Settings Fields
        add_settings_field(
            'parsi_enable_shipping_insurance',
            __('بیمه ارسال', 'parsi'),
            array($this, 'render_shipping_insurance_field'),
            'parsi-settings',
            'parsi_parcel_section'
        );

        add_settings_field(
            'parsi_enable_pickup',
            __('جمع‌آوری از محل (درب به درب)', 'parsi'),
            array($this, 'render_pickup_field'),
            'parsi-settings',
            'parsi_parcel_section'
        );

        add_settings_field(
            'parsi_pickup_extra_service_id',
            __('سرویس جمع‌آوری', 'parsi'),
            array($this, 'render_pickup_extra_service_field'),
            'parsi-settings',
            'parsi_parcel_section'
        );

        add_settings_field(
            'parsi_enable_logging',
            __('فعال‌سازی ثبت وقایع', 'parsi'),
            array($this, 'render_logging_field'),
            'parsi-settings',
            'parsi_parcel_section'
        );
        
        add_settings_field(
            'parsi_debug_mode',
            __('حالت عیب‌یابی', 'parsi'),
            array($this, 'render_debug_mode_field'),
            'parsi-settings',
            'parsi_parcel_section'
        );
        
        // Dimensions Fields
        add_settings_field(
            'parsi_product_weight',
            __('وزن متوسط محصول (گرم)', 'parsi'),
            array($this, 'render_product_weight_field'),
            'parsi-settings',
            'parsi_dimensions_section'
        );
        
        add_settings_field(
            'parsi_product_dimensions',
            __('ابعاد متوسط محصول (سانتی‌متر)', 'parsi'),
            array($this, 'render_product_dimensions_field'),
            'parsi-settings',
            'parsi_dimensions_section'
        );
        
        add_settings_field(
            'parsi_shipment_type',
            __('نوع ارسال', 'parsi'),
            array($this, 'render_shipment_type_field'),
            'parsi-settings',
            'parsi_dimensions_section'
        );
        
        add_settings_field(
            'parsi_pickup_time',
            __('زمان جمع‌آوری', 'parsi'),
            array($this, 'render_pickup_time_field'),
            'parsi-settings',
            'parsi_dimensions_section'
        );

        $this->register_api_payload_settings();
    }

    /**
     * Register the fields the API payload needs but that weren't previously
     * exposed in the admin UI: sender contact info and the Parsi UUID set
     * (service/deadline/parcel/box/insurance/payment type IDs).
     *
     * Defaults are empty — the API client refuses to send if a required value
     * is missing, which is safer than shipping hardcoded test UUIDs.
     */
    private function register_api_payload_settings() {
        $sender_text_fields = array(
            'parsi_sender_first_name'    => __('نام فرستنده', 'parsi'),
            'parsi_sender_last_name'     => __('نام خانوادگی فرستنده', 'parsi'),
            'parsi_sender_address'       => __('آدرس فرستنده', 'parsi'),
            'parsi_sender_postal_code'   => __('کد پستی فرستنده', 'parsi'),
            'parsi_sender_national_code' => __('کد ملی فرستنده', 'parsi'),
            'parsi_sender_mobile'        => __('موبایل فرستنده', 'parsi'),
        );
        // The national code and postal code carry extra validation so a
        // misconfigured sender identity is rejected at save time rather than
        // surfacing later as an opaque "خطا در ثبت سفارش" from the API.
        $sender_sanitizers = array(
            'parsi_sender_national_code' => array($this, 'sanitize_sender_national_code'),
            'parsi_sender_postal_code'   => array($this, 'sanitize_sender_postal_code'),
            'parsi_sender_mobile'        => array($this, 'sanitize_sender_mobile'),
        );
        foreach ($sender_text_fields as $key => $label) {
            register_setting('parsi_settings', $key, array(
                'type'              => 'string',
                'sanitize_callback' => isset($sender_sanitizers[$key]) ? $sender_sanitizers[$key] : 'sanitize_text_field',
                'default'           => '',
            ));
        }

        // Sender geo-coordinates are derived automatically from the chosen
        // sender city's center location (see render_origin_location_field and
        // the update_option_parsi_sender_city_id hook), so this is registered
        // for persistence but is no longer a hand-typed field.
        register_setting('parsi_settings', 'parsi_sender_location', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        $uuid_fields = array(
            'parsi_sender_city_id'     => __('شناسه شهر فرستنده', 'parsi'),
            'parsi_payment_type_id'    => __('شناسه نوع پرداخت', 'parsi'),
            'parsi_service_type_id'    => __('شناسه نوع سرویس', 'parsi'),
            'parsi_deadline_id'        => __('شناسه مهلت', 'parsi'),
            'parsi_insurance_type_id'  => __('شناسه نوع بیمه', 'parsi'),
            'parsi_parcel_type_id'     => __('شناسه نوع بسته', 'parsi'),
            'parsi_box_type_id'        => __('شناسه نوع جعبه', 'parsi'),
        );
        foreach ($uuid_fields as $key => $label) {
            register_setting('parsi_settings', $key, array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ));
        }

        add_settings_section(
            'parsi_api_sender_section',
            __('اطلاعات فرستنده در API', 'parsi'),
            array($this, 'render_api_sender_section'),
            'parsi-settings'
        );
        foreach ($sender_text_fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                array($this, 'render_api_payload_field'),
                'parsi-settings',
                'parsi_api_sender_section',
                array('key' => $key, 'placeholder' => '')
            );
        }
        // Note: the sender city (parsi_sender_city_id) is now chosen via the
        // Province/City dropdowns in the "اطلاعات فرستنده" section, so it is no
        // longer rendered as a manual UUID field here.

        // The "شناسه‌های پیش‌فرض API" (default API IDs) section is hidden by
        // default: these UUIDs already fall back to working defaults in
        // PARSI_API_Config, so merchants don't normally need to touch them. The
        // register_setting() calls above still run, so any previously saved
        // values persist and continue to be used. Re-enable the UI by returning
        // true from the `parsi_show_api_uuid_section` filter.
        if (apply_filters('parsi_show_api_uuid_section', false)) {
            add_settings_section(
                'parsi_api_uuid_section',
                __('شناسه‌های پیش‌فرض API', 'parsi'),
                array($this, 'render_api_uuid_section'),
                'parsi-settings'
            );
            foreach ($uuid_fields as $key => $label) {
                if ($key === 'parsi_sender_city_id') {
                    continue; // Chosen via the Province/City dropdowns in the sender section.
                }
                if ($key === 'parsi_insurance_type_id') {
                    add_settings_field(
                        $key,
                        __('نوع بیمه', 'parsi'),
                        array($this, 'render_insurance_type_field'),
                        'parsi-settings',
                        'parsi_api_uuid_section'
                    );
                    continue;
                }
                if ($key === 'parsi_parcel_type_id') {
                    add_settings_field(
                        $key,
                        __('نوع بسته', 'parsi'),
                        array($this, 'render_parcel_type_field'),
                        'parsi-settings',
                        'parsi_api_uuid_section'
                    );
                    continue;
                }
                // Show the canonical default GUID as the placeholder so merchants
                // know what value is used when the field is left blank. These match
                // PARSI_API_Config defaults used by the payload builders.
                $uuid_placeholders = array(
                    'parsi_service_type_id' => 'D8BD3783-86B4-4CE9-900B-468C2069FD18',
                    'parsi_deadline_id'     => 'F865EADF-E65A-438A-9618-311283C82B15',
                    'parsi_box_type_id'     => '7d0a1a9e-0ec3-458c-a733-1aa04c33bcc8',
                    'parsi_payment_type_id' => '119A8743-BE66-44B3-B03D-7BDE4C1FE0CB',
                );
                $placeholder = isset($uuid_placeholders[$key]) ? $uuid_placeholders[$key] : __('UUID', 'parsi');
                add_settings_field(
                    $key,
                    $label,
                    array($this, 'render_api_payload_field'),
                    'parsi-settings',
                    'parsi_api_uuid_section',
                    array('key' => $key, 'placeholder' => $placeholder)
                );
            }
        }
    }

    public function render_api_sender_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">📤</div>
            <h3 class="parsi-section-title">' . esc_html__('اطلاعات فرستنده در API', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('این مقادیر در درخواست ثبت مرسوله به‌عنوان فرستنده ارسال می‌شوند.', 'parsi') . '</p>';
    }

    public function render_api_uuid_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">🆔</div>
            <h3 class="parsi-section-title">' . esc_html__('شناسه‌های پیش‌فرض API', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('شناسه‌های UUID که از طرف پارسی پست به شما اعلام شده است. در صورت خالی بودن، مقدار پیش‌فرض (نمایش‌داده‌شده در هر فیلد) استفاده می‌شود.', 'parsi') . '</p>';
    }

    /**
     * Sanitize/validate the sender national code (کد ملی فرستنده) the same way
     * the buyer's National ID is validated. On invalid input we keep the
     * previously stored value and surface an admin error instead of saving a
     * malformed code that the Parsi API would later reject.
     *
     * @param mixed $input Raw submitted value
     * @return string Normalized 10-digit code, or the previous valid value
     */
    public function sanitize_sender_national_code($input) {
        $raw = trim((string) $input);
        if ($raw === '') {
            return '';
        }

        $normalized = function_exists('parsi_validate_national_code')
            ? parsi_validate_national_code($raw)
            : false;

        if ($normalized === false) {
            add_settings_error(
                'parsi_sender_national_code',
                'parsi_sender_national_code_invalid',
                __('کد ملی فرستنده نامعتبر است. لطفاً یک کد ملی ۱۰ رقمی معتبر وارد کنید.', 'parsi'),
                'error'
            );
            return (string) get_option('parsi_sender_national_code', '');
        }

        return $normalized;
    }

    /**
     * Sanitize/validate the sender postal code (کد پستی فرستنده): it must be a
     * 10-digit Iranian postal code. Invalid input is rejected (the previous
     * value is kept) with an admin error notice.
     *
     * @param mixed $input Raw submitted value
     * @return string Normalized 10-digit postal code, or the previous value
     */
    public function sanitize_sender_postal_code($input) {
        $raw = trim((string) $input);
        if ($raw === '') {
            return '';
        }

        $normalized = function_exists('parsi_normalize_digits')
            ? parsi_normalize_digits($raw)
            : $raw;
        $validated  = function_exists('parsi_validate_postal_code_strict')
            ? parsi_validate_postal_code_strict($normalized)
            : (preg_match('/^[0-9]{10}$/', preg_replace('/[^0-9]/', '', $normalized)) ? preg_replace('/[^0-9]/', '', $normalized) : false);

        if ($validated === false) {
            add_settings_error(
                'parsi_sender_postal_code',
                'parsi_sender_postal_code_invalid',
                __('کد پستی فرستنده باید یک عدد ۱۰ رقمی باشد.', 'parsi'),
                'error'
            );
            return (string) get_option('parsi_sender_postal_code', '');
        }

        return $validated;
    }

    /**
     * Sanitize/validate the sender mobile (موبایل فرستنده): it must be an
     * 11-digit Iranian mobile number starting with 09. Invalid input is
     * rejected (the previous value is kept) with an admin error notice.
     *
     * @param mixed $input Raw submitted value
     * @return string Normalized 11-digit mobile, or the previous value
     */
    public function sanitize_sender_mobile($input) {
        $raw = trim((string) $input);
        if ($raw === '') {
            return '';
        }

        $normalized = function_exists('parsi_normalize_digits')
            ? parsi_normalize_digits($raw)
            : $raw;
        $validated  = function_exists('parsi_validate_phone_strict')
            ? parsi_validate_phone_strict($normalized)
            : (preg_match('/^09[0-9]{9}$/', preg_replace('/[^0-9]/', '', $normalized)) ? preg_replace('/[^0-9]/', '', $normalized) : false);

        if ($validated === false) {
            add_settings_error(
                'parsi_sender_mobile',
                'parsi_sender_mobile_invalid',
                __('موبایل فرستنده باید یک شماره ۱۱ رقمی باشد و با ۰۹ شروع شود.', 'parsi'),
                'error'
            );
            return (string) get_option('parsi_sender_mobile', '');
        }

        return $validated;
    }

    /**
     * Generic text input renderer for settings registered through
     * register_api_payload_settings().
     */
    public function render_api_payload_field($args) {
        $key         = isset($args['key']) ? $args['key'] : '';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        if ($key === '') {
            return;
        }
        $value = get_option($key, '');
        ?>
        <div class="parsi-form-row">
            <input type="text"
                   name="<?php echo esc_attr($key); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   class="parsi-form-input"
                   placeholder="<?php echo esc_attr($placeholder); ?>"
                   dir="ltr" />
        </div>
        <?php
    }
    
    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_styles($hook) {
        // Load styles on our settings page
        if ('toplevel_page_parsi-settings' === $hook || 'settings_page_parsi-settings' === $hook) {
            // Ensure WooCommerce admin styles are loaded first to prevent conflicts
            wp_enqueue_style('woocommerce_admin_styles');
            
            wp_enqueue_style(
                'parsi-admin-styles',
                PARSI_PLUGIN_URL . 'assets/css/admin.css',
                array('woocommerce_admin_styles'),
                PARSI_VERSION
            );
            
            // Enqueue JavaScript for settings page
            wp_enqueue_script(
                'parsi-admin-scripts',
                PARSI_PLUGIN_URL . 'assets/js/parsi-translations.js',
                array('jquery'),
                PARSI_VERSION,
                true
            );
            
            // Localize script with AJAX URL and nonce
            wp_localize_script('parsi-admin-scripts', 'parsiAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('parsi_admin_nonce'),
                'strings' => array(
                    'saving' => __('در حال ذخیره...', 'parsi'),
                    'saved' => __('تنظیمات با موفقیت ذخیره شد.', 'parsi'),
                    'error' => __('خطا در ذخیره تنظیمات.', 'parsi'),
                    'testing' => __('در حال تست...', 'parsi'),
                    'connected' => __('اتصال موفقیت‌آمیز!', 'parsi'),
                    'failed' => __('اتصال ناموفق بود.', 'parsi'),
                    'clearingCache' => __('در حال پاک کردن cache...', 'parsi'),
                    'cacheCleared' => __('Cache با موفقیت پاک شد.', 'parsi'),
                    'cacheError' => __('خطا در پاک کردن cache.', 'parsi'),
                )
            ));

            // Province → city cascading dropdowns for the sender settings.
            if (!class_exists('PARSI_Locations')) {
                require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
            }
            wp_enqueue_script(
                'parsi-admin-locations',
                PARSI_PLUGIN_URL . 'assets/js/admin-locations.js',
                array(),
                PARSI_VERSION,
                true
            );
            wp_localize_script('parsi-admin-locations', 'parsiLocations', array(
                'provinces' => PARSI_Locations::get_province_cities_map(),
            ));
        }

        // Load order meta box styles on order edit page
        if ('post.php' === $hook || 'post-new.php' === $hook) {
            if (isset($_GET['post']) && 'shop_order' === get_post_type(intval($_GET['post']))) {
                // Ensure WooCommerce admin styles are loaded first to prevent conflicts
                wp_enqueue_style('woocommerce_admin_styles');
                
                wp_enqueue_style(
                    'parsi-order-meta-box',
                    PARSI_PLUGIN_URL . 'assets/css/order-meta-box.css',
                    array('woocommerce_admin_styles'),
                    PARSI_VERSION
                );
                
                // Enqueue JavaScript for order meta box
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
        
        // Ensure WooCommerce admin styles are loaded first to prevent conflicts
        wp_enqueue_style('woocommerce_admin_styles');
        
        wp_enqueue_style(
            'parsi-admin-styles',
            PARSI_PLUGIN_URL . 'assets/css/admin.css',
            array('woocommerce_admin_styles'),
            PARSI_VERSION
        );
        
        // Enqueue JavaScript for settings page
        wp_enqueue_script(
            'parsi-admin-scripts',
            PARSI_PLUGIN_URL . 'assets/js/parsi-translations.js',
            array('jquery'),
            PARSI_VERSION,
            true
        );
        
        // Localize script with AJAX URL and nonce
        wp_localize_script('parsi-admin-scripts', 'parsiAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('parsi_admin_nonce'),
            'strings' => array(
                'saving' => __('در حال ذخیره...', 'parsi'),
                'saved' => __('تنظیمات با موفقیت ذخیره شد.', 'parsi'),
                'error' => __('خطا در ذخیره تنظیمات.', 'parsi'),
                'testing' => __('در حال تست...', 'parsi'),
                'connected' => __('اتصال موفقیت‌آمیز!', 'parsi'),
                'failed' => __('اتصال ناموفق بود.', 'parsi'),
            )
        ));
    }
    
    /**
     * Render connection section description
     */
    public function render_connection_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">🔑</div>
            <h3 class="parsi-section-title">' . esc_html__('تنظیمات اتصال', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('اطلاعات اتصال به سرویس پارسی پست را وارد کنید.', 'parsi') . '</p>';
    }
    
    /**
     * Render sender section description
     */
    public function render_sender_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">📍</div>
            <h3 class="parsi-section-title">' . esc_html__('اطلاعات فرستنده', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('اطلاعات مبدا ارسال مرسولات را مشخص کنید.', 'parsi') . '</p>';
    }
    
    /**
     * Render shipping defaults section description
     */
    public function render_shipping_defaults_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">🚚</div>
            <h3 class="parsi-section-title">' . esc_html__('تنظیمات پیش‌فرض ارسال', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('تنظیمات پیش‌فرض برای محاسبه هزینه ارسال.', 'parsi') . '</p>';
    }
    
    /**
     * Render parcel section description
     */
    public function render_parcel_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">📦</div>
            <h3 class="parsi-section-title">' . esc_html__('تنظیمات بسته و جمع‌آوری', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('تنظیمات مربوط به بسته‌بندی و جمع‌آوری مرسولات.', 'parsi') . '</p>';
    }
    
    /**
     * Render dimensions section description
     */
    public function render_dimensions_section() {
        echo '<div class="parsi-section-header">
            <div class="parsi-section-icon">📏</div>
            <h3 class="parsi-section-title">' . esc_html__('ابعاد متوسط محصول', 'parsi') . '</h3>
        </div>
        <p class="parsi-section-description">' . esc_html__('ابعاد پیش‌فرض برای محاسبه هزینه ارسال در صورت عدم تعریف ابعاد برای محصولات.', 'parsi') . '</p>';
    }
    
    /**
     * Render API key field
     */
    public function render_api_key_field() {
        $value = get_option('parsi_api_key', '');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('کلید API', 'parsi'); ?> <span class="required">*</span></label>
            <input type="text" name="parsi_api_key" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="<?php esc_attr_e('کلید API خود را وارد کنید', 'parsi'); ?>" dir="ltr" />
            <p class="parsi-field-description"><?php esc_html_e('کلید API خود را برای اتصال به سرویس پارسی پست وارد کنید.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Sanitize API key
     *
     * @param string $api_key API key
     * @return string
     */
    public function sanitize_api_key($api_key) {
        $api_key = sanitize_text_field($api_key);
        
        if (empty($api_key)) {
            add_settings_error(
                'parsi_api_key',
                'empty_api_key',
                __('کلید API نمی‌تواند خالی باشد.', 'parsi')
            );
        }
        
        return $api_key;
    }
    
    /**
     * Render office ID field
     */
    public function render_office_id_field() {
        $value = get_option('parsi_office_id', '');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('شناسه دفتر (Office ID)', 'parsi'); ?> <span class="required">*</span></label>
            <input type="text" name="parsi_office_id" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="<?php esc_attr_e('شناسه دفتر خود را وارد کنید', 'parsi'); ?>" dir="ltr" />
            <p class="parsi-field-description"><?php esc_html_e('شناسه دفتر برای احراز هویت با API.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render contract ID field
     */
    public function render_contract_id_field() {
        $value = get_option('parsi_contract_id', 'EEA5ADC0-758C-4BF5-9247-131136EEB6AA');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('شناسه قرارداد (Contract ID)', 'parsi'); ?></label>
            <input type="text" name="parsi_contract_id" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="EEA5ADC0-758C-4BF5-9247-131136EEB6AA" dir="ltr" />
            <p class="parsi-field-description"><?php esc_html_e('شناسه قرارداد کسب‌وکار شما.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render store phone field
     */
    public function render_store_phone_field() {
        $value = get_option('parsi_store_phone', '');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('شماره تماس فروشگاه', 'parsi'); ?> <span class="required">*</span></label>
            <input type="tel" name="parsi_store_phone" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="09123456789" dir="ltr" />
            <p class="parsi-field-description"><?php esc_html_e('شماره موبایل تماس فروشگاه (09xxxxxxxxx)', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Sanitize Iranian phone number
     *
     * @param string $phone Phone number
     * @return string
     */
    public function sanitize_iranian_phone($phone) {
        // Remove any non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Validate Iranian mobile format
        if (!empty($phone) && !preg_match('/^09[0-9]{9}$/', $phone)) {
            add_settings_error(
                'parsi_store_phone',
                'invalid_phone',
                __('شماره موبایل نامعتبر است. فرمت صحیح: 09xxxxxxxxx', 'parsi')
            );
            return '';
        }
        
        return $phone;
    }
    
    /**
     * Render handling fee field
     */
    public function render_handling_fee_field() {
        $value = get_option('parsi_handling_fee', 0);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('کارمزد بسته‌بندی (درصد)', 'parsi'); ?></label>
            <input type="number" name="parsi_handling_fee" value="<?php echo esc_attr($value); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="0" />
            <p class="parsi-field-description"><?php esc_html_e('درصدی از هزینه ارسال که به‌عنوان کارمزد بسته‌بندی اضافه می‌شود و در صفحه سبد خرید و تسویه‌حساب به مشتری نمایش داده می‌شود. مثال: عدد ۱۰ یعنی هزینه ارسال + ۱۰٪. برای غیرفعال کردن، ۰ وارد کنید.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render logging field
     */
    public function render_logging_field() {
        $value = get_option('parsi_enable_logging', true);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('ثبت وقایع', 'parsi'); ?></label>
            <div class="parsi-form-checkbox">
                <input type="checkbox" name="parsi_enable_logging" value="1" <?php checked($value, true); ?> />
                <label><?php esc_html_e('ثبت وقایع برای درخواست‌ها و خطاهای API فعال شود', 'parsi'); ?></label>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render default method field
     */
    public function render_default_method_field() {
        $value = get_option('parsi_default_method', '');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('روش ارسال پیش‌فرض', 'parsi'); ?></label>
            <input type="text" name="parsi_default_method" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="<?php esc_attr_e('شناسه روش پیش‌فرض را وارد کنید', 'parsi'); ?>" />
            <p class="parsi-field-description"><?php esc_html_e('شناسه روش ارسال پیش‌فرض در صورت بازگشت چندین گزینه از API (اختیاری).', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render origin (sender) Province + City cascading dropdowns.
     *
     * The province select drives the city select client-side (see
     * assets/js/admin-locations.js). Selecting a city stores its UUID in
     * parsi_sender_city_id; the city name (parsi_origin_city), province UUID
     * (parsi_sender_province_id) and coordinates (parsi_sender_location) are
     * derived and persisted server-side by the update_option hook.
     */
    public function render_origin_location_field() {
        if (!class_exists('PARSI_Locations')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
        }

        $city_name   = get_option('parsi_origin_city', 'تهران');
        $city_id     = get_option('parsi_sender_city_id', '');
        $province_id = get_option('parsi_sender_province_id', '');

        // Derive the province from the saved city if it isn't stored yet.
        if ($province_id === '' && $city_id !== '') {
            $city = PARSI_Locations::get_city($city_id);
            if ($city) {
                $province_id = $city['province_id'];
            }
        }

        $provinces = PARSI_Locations::get_provinces();
        $cities    = $province_id !== '' ? PARSI_Locations::get_cities($province_id) : array();
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label" for="parsi_sender_province_id"><?php esc_html_e('استان مبدا', 'parsi'); ?></label>
            <select name="parsi_sender_province_id" id="parsi_sender_province_id" class="parsi-form-input">
                <option value=""><?php esc_html_e('انتخاب استان...', 'parsi'); ?></option>
                <?php foreach ($provinces as $province): ?>
                    <option value="<?php echo esc_attr($province['id']); ?>" <?php selected($province_id, $province['id']); ?>>
                        <?php echo esc_html($province['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="parsi-form-row">
            <label class="parsi-form-label" for="parsi_sender_city_id"><?php esc_html_e('شهر مبدا', 'parsi'); ?></label>
            <select name="parsi_sender_city_id" id="parsi_sender_city_id" class="parsi-form-input" data-selected="<?php echo esc_attr($city_id); ?>">
                <option value=""><?php esc_html_e('انتخاب شهر...', 'parsi'); ?></option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?php echo esc_attr($city['id']); ?>" <?php selected($city_id, $city['id']); ?>>
                        <?php echo esc_html($city['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="parsi_origin_city" id="parsi_origin_city" value="<?php echo esc_attr($city_name); ?>" />
            <p class="parsi-field-description"><?php esc_html_e('استان و شهری که ارسال‌ها از آن انجام می‌شود (موقعیت فروشگاه). مختصات جغرافیایی به‌صورت خودکار از روی شهر انتخابی تعیین می‌شود.', 'parsi'); ?></p>
        </div>
        <?php
    }

    /**
     * Render insurance type dropdown (from the bundled Parsi dataset).
     */
    public function render_insurance_type_field() {
        if (!class_exists('PARSI_Locations')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
        }

        $value = get_option('parsi_insurance_type_id', '');
        $types = PARSI_Locations::get_insurance_types();
        ?>
        <div class="parsi-form-row">
            <select name="parsi_insurance_type_id" id="parsi_insurance_type_id" class="parsi-form-input">
                <option value=""><?php esc_html_e('انتخاب نوع بیمه...', 'parsi'); ?></option>
                <?php foreach ($types as $type): ?>
                    <option value="<?php echo esc_attr($type['id']); ?>" <?php selected($value, $type['id']); ?>>
                        <?php echo esc_html($type['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="parsi-field-description"><?php esc_html_e('نوع بیمه‌ای که در محاسبه هزینه و ثبت مرسوله استفاده می‌شود.', 'parsi'); ?></p>
        </div>
        <?php
    }

    /**
     * Render the parcel-type field as a read-only note: parcel type is selected
     * automatically from the package weight and dimensions at calculation time.
     */
    public function render_parcel_type_field() {
        ?>
        <div class="parsi-form-row">
            <p class="parsi-field-description">
                <?php esc_html_e('نوع بسته به‌صورت خودکار بر اساس وزن و ابعاد مرسوله انتخاب می‌شود: «پاکت» برای مرسولات سبک تا ۲ کیلوگرم بدون ابعاد، و «بسته» برای سایر موارد.', 'parsi'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render live calculation field
     */
    public function render_live_calculation_field() {
        $value = get_option('parsi_enable_live_calculation', true);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('محاسبه زنده', 'parsi'); ?></label>
            <div class="parsi-form-checkbox">
                <input type="checkbox" name="parsi_enable_live_calculation" value="1" <?php checked($value, true); ?> />
                <label><?php esc_html_e('محاسبه هزینه ارسال به صورت زنده از طریق API در صفحه پرداخت.', 'parsi'); ?></label>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render debug mode field
     */
    public function render_debug_mode_field() {
        $value = get_option('parsi_debug_mode', false);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('حالت عیب‌یابی', 'parsi'); ?></label>
            <div class="parsi-form-checkbox">
                <input type="checkbox" name="parsi_debug_mode" value="1" <?php checked($value, true); ?> />
                <label><?php esc_html_e('فعال‌سازی حالت عیب‌یابی برای ثبت وقایع دقیق API (نیاز به فعال بودن ثبت وقایع).', 'parsi'); ?></label>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render free shipping threshold field
     */
    public function render_free_shipping_threshold_field() {
        $value = get_option('parsi_free_shipping_threshold', 0);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('آستانه ارسال رایگان', 'parsi'); ?></label>
            <input type="number" name="parsi_free_shipping_threshold" value="<?php echo esc_attr($value); ?>" step="0.01" min="0" class="parsi-form-input" placeholder="0.00" />
            <p class="parsi-field-description"><?php esc_html_e('حداقل مبلغ سفارش برای ارسال رایگان. اگر مبلغ سفارش از این مقدار بیشتر باشد، هزینه ارسال رایگان خواهد بود. 0 به معنای غیرفعال بودن این قابلیت است.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render max shipping cost field
     */
    public function render_max_shipping_cost_field() {
        $value = get_option('parsi_max_shipping_cost', 0);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('بیشینه هزینه ارسال', 'parsi'); ?></label>
            <input type="number" name="parsi_max_shipping_cost" value="<?php echo esc_attr($value); ?>" step="0.01" min="0" class="parsi-form-input" placeholder="0.00" />
            <p class="parsi-field-description"><?php esc_html_e('حداکثر هزینه ارسال قابل نمایش برای مشتری. اگر هزینه محاسبه شده از API بیشتر از این مقدار باشد، این مبلغ نمایش داده می‌شود. 0 به معنای بدون محدودیت است.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render delivery time estimate field
     */
    public function render_delivery_time_estimate_field() {
        $value = get_option('parsi_delivery_time_estimate', '3-5 روز کاری');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('برآورد زمان تحویل', 'parsi'); ?></label>
            <input type="text" name="parsi_delivery_time_estimate" value="<?php echo esc_attr($value); ?>" class="parsi-form-input" placeholder="<?php esc_attr_e('3-5 روز کاری', 'parsi'); ?>" />
            <p class="parsi-field-description"><?php esc_html_e('متن نمایش زمان تحویل در صفحه پرداخت برای مشتری. مثال: 3-5 روز کاری', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render shipping insurance field
     */
    public function render_shipping_insurance_field() {
        $value = get_option('parsi_enable_shipping_insurance', false);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('بیمه ارسال', 'parsi'); ?></label>
            <div class="parsi-form-checkbox">
                <input type="checkbox" name="parsi_enable_shipping_insurance" value="1" <?php checked($value, true); ?> />
                <label><?php esc_html_e('فعال‌سازی بیمه ارسال برای تمام مرسولات. هزینه بیمه به صورت خودکار به هزینه ارسال اضافه می‌شود.', 'parsi'); ?></label>
            </div>
        </div>
        <?php
    }

    /**
     * Render pickup-from-sender-location (door-to-door) checkbox.
     *
     * Pickup is delivered as an API extra service: when enabled, the extra
     * service chosen in render_pickup_extra_service_field() is added to each
     * parcel's parcelExtraServices.
     */
    public function render_pickup_field() {
        $value = get_option('parsi_enable_pickup', false);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('جمع‌آوری از محل (درب به درب)', 'parsi'); ?></label>
            <div class="parsi-form-checkbox">
                <input type="checkbox" name="parsi_enable_pickup" value="1" <?php checked($value, true); ?> />
                <label><?php esc_html_e('در صورت فعال بودن، مأمور پارسی پست برای دریافت مرسوله به آدرس فرستنده مراجعه می‌کند. این قابلیت باید در قرارداد شما با پارسی پست فعال باشد.', 'parsi'); ?></label>
            </div>
        </div>
        <?php
    }

    /**
     * Render the pickup extra-service dropdown, populated from the live
     * GetAllExtraService list so the merchant selects exactly which extra
     * service represents "جمع‌آوری از محل" for their contract. Its GUID is
     * added to parcelExtraServices when pickup is enabled.
     */
    public function render_pickup_extra_service_field() {
        if (!class_exists('PARSI_API_Data')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-data.php';
        }

        $value          = get_option('parsi_pickup_extra_service_id', '');
        $extra_services = PARSI_API_Data::get_extra_services();
        ?>
        <div class="parsi-form-row">
            <?php if (false === $extra_services): ?>
                <p class="parsi-field-description"><?php esc_html_e('دریافت لیست سرویس‌های اضافه از API ناموفق بود. ابتدا کلید API و شناسه دفتر را بررسی و ذخیره کنید.', 'parsi'); ?></p>
                <?php if ($value !== ''): ?>
                    <input type="hidden" name="parsi_pickup_extra_service_id" value="<?php echo esc_attr($value); ?>" />
                <?php endif; ?>
            <?php else: ?>
                <select name="parsi_pickup_extra_service_id" class="parsi-form-input">
                    <option value=""><?php esc_html_e('انتخاب سرویس جمع‌آوری...', 'parsi'); ?></option>
                    <?php foreach ($extra_services as $service): ?>
                        <option value="<?php echo esc_attr($service['id']); ?>" <?php selected($value, $service['id']); ?>>
                            <?php echo esc_html($service['title'] !== '' ? $service['title'] : $service['id']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="parsi-field-description"><?php esc_html_e('سرویس اضافه‌ای که نقش «جمع‌آوری از محل» را دارد. این مقدار زمانی استفاده می‌شود که گزینه جمع‌آوری از محل فعال باشد.', 'parsi'); ?></p>
            <?php endif; ?>
            <?php $this->render_pickup_debug_dump(); ?>
        </div>
        <?php
    }

    /**
     * Admin-only diagnostic (shown only in debug mode): dumps the raw
     * GetAllExtraService list for the configured contract so a
     * merchant/developer can identify which extra service is the pickup /
     * door-to-door option before selecting it above.
     */
    private function render_pickup_debug_dump() {
        if (!get_option('parsi_debug_mode', false) || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!class_exists('PARSI_API_Data')) {
            require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-data.php';
        }

        $extra_services = PARSI_API_Data::get_extra_services();
        ?>
        <p class="parsi-field-description"><?php esc_html_e('خروجی خام GetAllExtraService برای این قرارداد (حالت عیب‌یابی):', 'parsi'); ?></p>
        <?php if (false === $extra_services): ?>
            <pre style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;"><?php esc_html_e('دریافت لیست سرویس‌های اضافه از API ناموفق بود.', 'parsi'); ?></pre>
        <?php else: ?>
            <pre dir="ltr" style="max-height:300px;overflow:auto;background:#f6f7f7;padding:10px;border:1px solid #ccd0d4;"><?php echo esc_html(wp_json_encode($extra_services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        <?php endif; ?>
        <?php
    }

    /**
     * Render COD fee field
     */
    public function render_cod_fee_field() {
        $value = get_option('parsi_cod_fee', 0);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('کارمزد پرداخت در محل', 'parsi'); ?></label>
            <input type="number" name="parsi_cod_fee" value="<?php echo esc_attr($value); ?>" step="0.01" min="0" class="parsi-form-input" placeholder="0.00" />
            <p class="parsi-field-description"><?php esc_html_e('کارمزد اضافی برای پرداخت در محل (COD). این مبلغ به هزینه ارسال اضافه می‌شود. 0 به معنای بدون کارمزد است.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render packaging type field
     */
    public function render_packaging_type_field() {
        $value = get_option('parsi_packaging_type', 'standard');
        $options = array(
            'standard' => __('استاندارد', 'parsi'),
            'express' => __('اکسپرس', 'parsi'),
            'fragile' => __('قابل‌شکن', 'parsi'),
            'bulk' => __('حجمی', 'parsi'),
        );
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('نوع بسته‌بندی', 'parsi'); ?></label>
            <select name="parsi_packaging_type" class="parsi-form-input">
                <?php foreach ($options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="parsi-field-description"><?php esc_html_e('نوع بسته‌بندی پیش‌فرض برای محاسبه هزینه ارسال.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render API status
     */
    public function render_api_status() {
        $api_key = get_option('parsi_api_key', '');
        $is_connected = !empty($api_key);
        ?>
        <div class="parsi-api-status">
            <div class="parsi-status-indicator <?php echo $is_connected ? 'connected' : 'disconnected'; ?>"></div>
            <span class="parsi-status-text"><?php echo $is_connected ? __('متصل شده', 'parsi') : __('متصل نشده', 'parsi'); ?></span>
        </div>
        <?php
    }
    
    /**
     * Render test connection button
     */
    public function render_test_connection_button() {
        ?>
        <button type="button" class="parsi-test-button" onclick="parsiTestConnection()">
            <?php esc_html_e('تست اتصال به API', 'parsi'); ?>
        </button>
        <div id="parsi-test-result" class="parsi-test-result"></div>
        <script>
        function parsiTestConnection() {
            const apiKeyEl = document.querySelector('input[name="parsi_api_key"]');
            const officeEl = document.querySelector('input[name="parsi_office_id"]');
            const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';
            const officeId = officeEl ? officeEl.value.trim() : '';
            const resultDiv = document.getElementById('parsi-test-result');

            if (!apiKey || !officeId) {
                resultDiv.innerHTML = '<span class="parsi-error"><?php esc_html_e('لطفاً ابتدا کلید API و شناسه دفتر را وارد کنید.', 'parsi'); ?></span>';
                return;
            }

            resultDiv.innerHTML = '<span class="parsi-loading"><?php esc_html_e('در حال تست...', 'parsi'); ?></span>';

            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'parsi_test_connection',
                    api_key: apiKey,
                    office_id: officeId,
                    nonce: '<?php echo wp_create_nonce('parsi_test_connection'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<span class="parsi-success"><?php esc_html_e('اتصال موفقیت‌آمیز!', 'parsi'); ?></span>';
                } else {
                    resultDiv.innerHTML = '<span class="parsi-error">' + (data.message || '<?php esc_html_e('اتصال ناموفق بود.', 'parsi'); ?>') + '</span>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<span class="parsi-error"><?php esc_html_e('خطا در ارتباط با سرور.', 'parsi'); ?></span>';
            });
        }
        </script>
        <script>
        function parsiClearCache() {
            const resultDiv = document.getElementById('parsi-cache-result');
            resultDiv.innerHTML = '<span class="parsi-loading">' + parsiAdmin.strings.clearingCache + '</span>';
            
            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'parsi_clear_cache',
                    nonce: parsiAdmin.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<span class="parsi-success">' + parsiAdmin.strings.cacheCleared + '</span>';
                } else {
                    resultDiv.innerHTML = '<span class="parsi-error">' + (data.message || parsiAdmin.strings.cacheError) + '</span>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<span class="parsi-error">' + parsiAdmin.strings.cacheError + '</span>';
            });
        }
        </script>
        <?php
    }
    
    /**
     * Render product weight field
     */
    public function render_product_weight_field() {
        $value = get_option('parsi_product_weight', '');
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('وزن متوسط محصول (گرم)', 'parsi'); ?></label>
            <input type="number" name="parsi_product_weight" value="<?php echo esc_attr($value); ?>" step="1" min="0" class="parsi-form-input" placeholder="500" dir="ltr" />
            <p class="parsi-field-description"><?php esc_html_e('وزن متوسط محصولات برای محاسبه ابعاد بسته.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render product length field
     */
    public function render_product_length_field() {
        $value = get_option('parsi_product_length', '');
        ?>
        <div class="parsi-dimension-field">
            <label class="parsi-form-label"><?php esc_html_e('طول (سانتی‌متر)', 'parsi'); ?></label>
            <input type="number" name="parsi_product_length" value="<?php echo esc_attr($value); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="20" dir="ltr" />
        </div>
        <?php
    }
    
    /**
     * Render product width field
     */
    public function render_product_width_field() {
        $value = get_option('parsi_product_width', '');
        ?>
        <div class="parsi-dimension-field">
            <label class="parsi-form-label"><?php esc_html_e('عرض (سانتی‌متر)', 'parsi'); ?></label>
            <input type="number" name="parsi_product_width" value="<?php echo esc_attr($value); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="15" dir="ltr" />
        </div>
        <?php
    }
    
    /**
     * Render product height field
     */
    public function render_product_height_field() {
        $value = get_option('parsi_product_height', '');
        ?>
        <div class="parsi-dimension-field">
            <label class="parsi-form-label"><?php esc_html_e('ارتفاع (سانتی‌متر)', 'parsi'); ?></label>
            <input type="number" name="parsi_product_height" value="<?php echo esc_attr($value); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="10" dir="ltr" />
        </div>
        <?php
    }
    
    /**
     * Render product dimensions field (combined)
     */
    public function render_product_dimensions_field() {
        $length = get_option('parsi_product_length', 20);
        $width = get_option('parsi_product_width', 15);
        $height = get_option('parsi_product_height', 10);
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('ابعاد متوسط محصول', 'parsi'); ?></label>
            <div class="parsi-dimensions-fields">
                <div class="parsi-dimension-field">
                    <label class="parsi-form-label"><?php esc_html_e('طول (سانتی‌متر)', 'parsi'); ?></label>
                    <input type="number" name="parsi_product_length" value="<?php echo esc_attr($length); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="20" dir="ltr" />
                </div>
                <div class="parsi-dimension-field">
                    <label class="parsi-form-label"><?php esc_html_e('عرض (سانتی‌متر)', 'parsi'); ?></label>
                    <input type="number" name="parsi_product_width" value="<?php echo esc_attr($width); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="15" dir="ltr" />
                </div>
                <div class="parsi-dimension-field">
                    <label class="parsi-form-label"><?php esc_html_e('ارتفاع (سانتی‌متر)', 'parsi'); ?></label>
                    <input type="number" name="parsi_product_height" value="<?php echo esc_attr($height); ?>" step="0.1" min="0" class="parsi-form-input" placeholder="10" dir="ltr" />
                </div>
            </div>
            <p class="parsi-field-description"><?php esc_html_e('ابعاد متوسط محصولات برای محاسبه ابعاد بسته.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render shipment type field
     */
    public function render_shipment_type_field() {
        $value = get_option('parsi_shipment_type', 'packet');
        $options = array(
            'packet' => __('پاکت', 'parsi'),
            'parcel' => __('بسته', 'parsi'),
        );
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('نوع ارسال', 'parsi'); ?></label>
            <select name="parsi_shipment_type" class="parsi-form-input">
                <?php foreach ($options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="parsi-field-description"><?php esc_html_e('نوع بسته برای ارسال مرسولات.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render pickup time field
     */
    public function render_pickup_time_field() {
        $value = get_option('parsi_pickup_time', 'morning');
        $options = array(
            'morning' => __('صبح', 'parsi'),
            'afternoon' => __('بعدازظهر', 'parsi'),
            'evening' => __('عصر', 'parsi'),
        );
        ?>
        <div class="parsi-form-row">
            <label class="parsi-form-label"><?php esc_html_e('زمان جمع‌آوری', 'parsi'); ?></label>
            <select name="parsi_pickup_time" class="parsi-form-input">
                <?php foreach ($options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="parsi-field-description"><?php esc_html_e('زمان جمع‌آوری مرسولات از فروشگاه.', 'parsi'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Sanitize integer positive value
     *
     * @param mixed $value Integer value
     * @return int
     */
    public function sanitize_int_positive($value) {
        $int_value = intval($value);
        
        if ($int_value < 0) {
            add_settings_error(
                'parsi_int_value',
                'negative_value',
                __('مقدار نمی‌تواند منفی باشد.', 'parsi')
            );
            return 0;
        }
        
        return $int_value;
    }
    
    /**
     * Sanitize float positive value for dimensions
     *
     * @param mixed $value Float value
     * @return float
     */
    public function sanitize_dim_float_positive($value) {
        $float_value = floatval($value);
        
        if ($float_value < 0) {
            add_settings_error(
                'parsi_dim_float_value',
                'negative_value',
                __('مقدار نمی‌تواند منفی باشد.', 'parsi')
            );
            return 0;
        }
        
        return $float_value;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('شما دسترسی کافی برای مشاهده این صفحه را ندارید.', 'parsi'));
        }
        
        // Check if WooCommerce is active before rendering
        if (!$this->is_woocommerce_active()) {
            ?>
            <div class="wrap">
                <div class="notice notice-error">
                    <p><?php esc_html_e('این افزونه به ووکامرس نیاز دارد.', 'parsi'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        
        ?>
        <div class="wrap woocommerce parsi-dashboard-wrap">
            <div class="parsi-page-header">
                <div class="parsi-logo">
                    <img src="<?php echo esc_url(PARSI_PLUGIN_URL . 'assets/logo.svg'); ?>" alt="Parsi Post Logo" />
                </div>
                <h1 class="parsi-page-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
                <span class="parsi-badge">v<?php echo esc_html(PARSI_VERSION); ?></span>
            </div>
            
            <?php
            // Show validation errors (e.g. invalid sender national/postal code)
            // or the success message. add_settings_error() entries are stored in
            // a transient during the save redirect; this top-level menu page is
            // not under "Settings", so WordPress does not auto-render them here.
            if (isset($_GET['settings-updated'])) {
                if (count(get_settings_errors()) > 0) {
                    settings_errors();
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('تنظیمات با موفقیت ذخیره شد.', 'parsi') . '</p></div>';
                }
            }
            ?>
            
            <form method="post" action="options.php" class="parsi-settings-form">
                <?php
                settings_fields('parsi_settings');
                do_settings_sections('parsi-settings');
                ?>
                
                <div class="parsi-form-row">
                    <button type="submit" class="parsi-submit-button"><?php esc_html_e('ذخیره تنظیمات', 'parsi'); ?></button>
                </div>
            </form>
            
            <div class="parsi-divider"></div>
            
            <!-- Status Sync Section -->
            <div class="parsi-logs-section">
                <div class="parsi-logs-header">
                    <div class="parsi-section-icon">🔄</div>
                    <h2 class="parsi-logs-title"><?php esc_html_e('همگام‌سازی وضعیت ارسال', 'parsi'); ?></h2>
                </div>
                <p class="parsi-logs-description"><?php esc_html_e('همگام‌سازی دستی وضعیت ارسال از API پارسی پست.', 'parsi'); ?></p>
                <?php if (class_exists('PARSI_Status_Sync')): ?>
                    <a href="<?php echo esc_url(PARSI_Status_Sync::get_manual_sync_url()); ?>" class="parsi-button-secondary">
                        <?php esc_html_e('همگام‌سازی اکنون', 'parsi'); ?>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="parsi-divider"></div>
            
            <!-- Cache Management Section -->
            <div class="parsi-logs-section">
                <div class="parsi-logs-header">
                    <div class="parsi-section-icon">🗑️</div>
                    <h2 class="parsi-logs-title"><?php esc_html_e('مدیریت Cache', 'parsi'); ?></h2>
                </div>
                <p class="parsi-logs-description"><?php esc_html_e('پاک کردن cache شهرها و deadlines برای دریافت لیست به‌روز از API.', 'parsi'); ?></p>
                <button type="button" class="parsi-button-secondary" onclick="parsiClearCache()">
                    <?php esc_html_e('پاک کردن Cache', 'parsi'); ?>
                </button>
                <div id="parsi-cache-result" class="parsi-cache-result"></div>
            </div>
            
            <div class="parsi-divider"></div>
            
            <div class="parsi-logs-section">
                <div class="parsi-logs-header">
                    <div class="parsi-section-icon">📋</div>
                    <h2 class="parsi-logs-title"><?php esc_html_e('وقایع', 'parsi'); ?></h2>
                </div>
                <p class="parsi-logs-description"><?php esc_html_e('مشاهده وقایع اخیر درخواست‌ها و خطاهای API.', 'parsi'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" class="parsi-button-secondary">
                    <?php esc_html_e('مشاهده وقایع ووکامرس', 'parsi'); ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sanitize checkbox value
     *
     * @param mixed $value Checkbox value
     * @return bool
     */
    public function sanitize_checkbox($value) {
        return isset($value) && '1' === $value;
    }
    
    /**
     * Sanitize float positive value
     *
     * @param mixed $value Float value
     * @return float
     */
    public function sanitize_float_positive($value) {
        $float_value = floatval($value);
        
        if ($float_value < 0) {
            add_settings_error(
                'parsi_float_value',
                'negative_value',
                __('مقدار نمی‌تواند منفی باشد.', 'parsi')
            );
            return 0;
        }
        
        return $float_value;
    }
    
    /**
     * Check for plugin updates
     *
     * @param object $transient Update transient
     * @return object
     */
    public function check_for_updates($transient) {
        // This is a placeholder for automatic update functionality
        // You would implement your update server logic here
        // For now, we'll return the transient as-is
        
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Example update check (replace with your update server)
        // $remote_version = $this->get_remote_version();
        // if (version_compare(PARSI_VERSION, $remote_version, '<')) {
        //     $transient->response[PARSI_PLUGIN_BASENAME] = (object) array(
        //         'slug' => 'parsi',
        //         'new_version' => $remote_version,
        //         'url' => 'https://your-website.com/parsi',
        //         'package' => 'https://your-website.com/parsi/parsi.zip'
        //     );
        // }
        
        return $transient;
    }
    
    /**
     * AJAX handler for test connection
     */
    public function ajax_test_connection() {
        // Verify nonce
        if (!check_ajax_referer('parsi_test_connection', 'nonce', false)) {
            wp_send_json_error(array('message' => __('خطا در تأیید درخواست.', 'parsi')));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی کافی ندارید.', 'parsi')));
        }
        
        // Both the API key and office ID are required headers — test them together.
        $api_key   = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $office_id = isset($_POST['office_id']) ? sanitize_text_field(wp_unslash($_POST['office_id'])) : '';

        if (empty($api_key) || empty($office_id)) {
            wp_send_json_error(array('message' => __('کلید API و شناسه دفتر نمی‌توانند خالی باشند.', 'parsi')));
        }

        // Probe the API with a low-risk call (GetApiOrderBasicInfo) using the
        // submitted credentials, then restore whatever was saved before.
        try {
            if (!class_exists('PARSI_API_Request')) {
                require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-request.php';
            }

            $old_api_key   = get_option('parsi_api_key', '');
            $old_office_id = get_option('parsi_office_id', '');
            update_option('parsi_api_key', $api_key);
            update_option('parsi_office_id', $office_id);

            $api_request = new PARSI_API_Request();
            $result      = $api_request->test_connection();

            update_option('parsi_api_key', $old_api_key);
            update_option('parsi_office_id', $old_office_id);

            // A successful probe means the saved credentials may have changed;
            // drop the cached authorization so the gate re-checks immediately.
            delete_transient(PARSI_Auth::CACHE_KEY);

            if ($result === true) {
                wp_send_json_success(array('message' => __('اتصال موفقیت‌آمیز!', 'parsi')));
            }

            $message = is_wp_error($result)
                ? $result->get_error_message()
                : __('اتصال ناموفق بود. لطفاً کلید API و شناسه دفتر را بررسی کنید.', 'parsi');
            wp_send_json_error(array('message' => $message));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('خطا در اتصال: ', 'parsi') . $e->getMessage()));
        }
    }
}

/**
 * Activation: nothing to schedule. Status sync was previously a cron job, but
 * the Parsi Post API has no tracking endpoint, so live sync is disabled.
 */
function parsi_activate() {
    // Intentional no-op — kept for parsi_clear_api_cache hook compatibility.
}

/**
 * Deactivation: clear any leftover sync cron from earlier plugin versions.
 */
function parsi_deactivate() {
    $timestamp = wp_next_scheduled('parsi_status_sync_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'parsi_status_sync_event');
    }
}

// Register activation/deactivation hooks
register_activation_hook(__FILE__, 'parsi_activate');
register_deactivation_hook(__FILE__, 'parsi_deactivate');

// 🔧 Clear API cache on plugin activation/deactivation
add_action('parsi_activate', 'parsi_clear_api_cache');
add_action('parsi_deactivate', 'parsi_clear_api_cache');

/**
 * Clear API cache (cities, deadlines, etc.)
 * Called on plugin activation/deactivation
 */
function parsi_clear_api_cache() {
    if (class_exists('PARSI_API_Data')) {
        PARSI_API_Data::clear_cache();
    }
    // Drop the cached authorization probe so credential changes take effect now.
    if (class_exists('PARSI_Auth')) {
        delete_transient(PARSI_Auth::CACHE_KEY);
    }
}

// Re-check authorization immediately when the API credentials change, rather
// than waiting for the cached probe to expire.
add_action('update_option_parsi_api_key', 'parsi_clear_api_cache');
add_action('update_option_parsi_office_id', 'parsi_clear_api_cache');
add_action('add_option_parsi_api_key', 'parsi_clear_api_cache');
add_action('add_option_parsi_office_id', 'parsi_clear_api_cache');

/**
 * When the sender city UUID is saved from the settings dropdown, derive and
 * persist the values the rest of the plugin reads: the city name
 * (parsi_origin_city), the province UUID (parsi_sender_province_id) and the
 * city's center coordinates (parsi_sender_location). The city UUID is the
 * single source of truth; everything else is looked up from the bundled
 * dataset so it can never drift.
 *
 * @param mixed $value The newly-saved city UUID (add_option/update_option arg shape).
 */
function parsi_sync_sender_location_from_city($value) {
    if (!class_exists('PARSI_Locations')) {
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-locations.php';
    }

    $city_id = is_string($value) ? sanitize_text_field($value) : '';
    if ($city_id === '') {
        return;
    }

    $city = PARSI_Locations::get_city($city_id);
    if (!$city) {
        // Unknown id — leave the derived values untouched rather than corrupt them.
        return;
    }

    update_option('parsi_origin_city', $city['name']);
    update_option('parsi_sender_province_id', $city['province_id']);
    update_option('parsi_sender_location', $city['centerLocation']);
}

// update_option passes ($old_value, $value); add_option passes ($option, $value).
// Normalize both to just the new value.
add_action('update_option_parsi_sender_city_id', function ($old_value, $value) {
    parsi_sync_sender_location_from_city($value);
}, 10, 2);
add_action('add_option_parsi_sender_city_id', function ($option, $value) {
    parsi_sync_sender_location_from_city($value);
}, 10, 2);

/**
 * Save billing city ID from checkout form
 * Hook: woocommerce_checkout_update_order_meta
 *
 * @param int $order_id Order ID
 */
function parsi_save_billing_city_id($order_id) {
    // Check if billing_city_id is in POST data
    if (!empty($_POST['billing_city_id'])) {
        $city_id = sanitize_text_field($_POST['billing_city_id']);
        update_post_meta($order_id, '_billing_city_id', $city_id);
        
        // Log for debugging
        if (function_exists('parsi_log')) {
            parsi_log(sprintf('Saved billing city ID for order #%s: %s', $order_id, $city_id), 'info');
        }
    }
}

/**
 * AJAX handler for searching cities (Select2)
 * Used in checkout page for city dropdown
 */
function parsi_ajax_search_cities() {
    // 🔒 SECURITY: Verify nonce for CSRF protection
    if (!check_ajax_referer('parsi_search_cities', 'nonce', false)) {
        wp_send_json_error(array('message' => __('خطا در تأیید درخواست.', 'parsi')));
    }
    
    // Check user capabilities
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('شما دسترسی کافی ندارید.', 'parsi')));
    }
    
    // Get search query
    $search_query = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
    
    if (empty($search_query)) {
        wp_send_json(array('results' => array()));
    }
    
    // Get cities from cache
    if (!class_exists('PARSI_API_Data')) {
        require_once PARSI_PLUGIN_DIR . 'includes/class-parsi-api-data.php';
    }
    
    $cities = PARSI_API_Data::get_cities();
    
    if (false === $cities) {
        wp_send_json_error(array('message' => __('خطا در دریافت لیست شهرها.', 'parsi')));
    }
    
    // Filter cities by search query
    $results = array();
    $search_lower = strtolower($search_query);
    
    foreach ($cities as $city) {
        // Normalize city name for comparison
        $city_name = isset($city['name']) ? $city['name'] : '';
        if (function_exists('parsi_normalize_persian_text')) {
            $city_name_normalized = parsi_normalize_persian_text($city_name);
        } else {
            $city_name_normalized = strtolower($city_name);
        }
        
        // Check if city name contains search query
        if (strpos($city_name_normalized, $search_lower) !== false) {
            $results[] = array(
                'id' => isset($city['id']) ? $city['id'] : '',
                'text' => $city_name,
                'province' => isset($city['province']) ? $city['province'] : '',
            );
        }
    }
    
    // Limit results to 50 for performance
    $results = array_slice($results, 0, 50);
    
    wp_send_json(array('results' => $results));
}

/**
 * AJAX handler for clearing cache
 */
function parsi_ajax_clear_cache() {
    // 🔒 SECURITY: Verify nonce for CSRF protection
    if (!check_ajax_referer('parsi_admin_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('خطا در تأیید درخواست.', 'parsi')));
    }
    
    // Check user capabilities
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('شما دسترسی کافی ندارید.', 'parsi')));
    }
    
    // Clear cache
    if (class_exists('PARSI_API_Data')) {
        PARSI_API_Data::clear_cache();
        wp_send_json_success(array('message' => __('Cache با موفقیت پاک شد.', 'parsi')));
    } else {
        wp_send_json_error(array('message' => __('خطا در پاک کردن cache.', 'parsi')));
    }
}

/**
 * Initialize plugin
 */
function parsi_init() {
    return PARSI_Plugin::get_instance();
}

// Initialize plugin
parsi_init();

