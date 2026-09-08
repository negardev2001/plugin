<?php
/**
 * PARSI Tracking Display
 *
 * @package PARSI
 *
 * Shows the tracking number stored on the order in admin + customer views.
 * Live status lookup is intentionally NOT implemented: the Parsi Post API
 * documented in docs/Parsi-Post-API.postman_collection.json has no tracking endpoint. Status
 * is updated manually via the order meta box (PARSI_Orders::render_order_meta_box).
 */

if (!defined('ABSPATH')) {
    exit;
}

class PARSI_Tracking {

    public function __construct() {
        // Display in admin order edit screen (legacy + HPOS).
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_tracking_info_admin'), 10, 1);

        // Display in customer-facing order view.
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_tracking_info_customer'), 10, 1);

        // Tracking lookup shortcode.
        add_shortcode('parsi_tracking', array($this, 'tracking_shortcode'));
    }

    /**
     * Admin: tracking number block under the shipping address.
     */
    public function display_tracking_info_admin($order) {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        $parsi_shipping  = $order->get_meta('_parsi_shipping_enabled');
        $tracking_number = $order->get_meta('_parsi_tracking_number');

        if ('yes' !== $parsi_shipping || empty($tracking_number)) {
            return;
        }
        ?>
        <div class="parsi-tracking-admin" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('کد رهگیری پارسی پست', 'parsi'); ?></h3>
            <p>
                <strong><?php esc_html_e('کد پیگیری:', 'parsi'); ?></strong>
                <code style="background: #fff; padding: 5px 10px; border-radius: 3px;"><?php echo esc_html($tracking_number); ?></code>
            </p>
        </div>
        <?php
    }

    /**
     * Customer: tracking number block on the order details page.
     */
    public function display_tracking_info_customer($order) {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        $tracking_number = $order->get_meta('_parsi_tracking_number');
        $parsi_status    = $order->get_meta('_parsi_shipping_status');

        if (empty($tracking_number)) {
            return;
        }
        ?>
        <section class="woocommerce-order-parsi-tracking" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 4px;">
            <h2 style="margin-top: 0;"><?php esc_html_e('رهگیری بسته پستی', 'parsi'); ?></h2>
            <p>
                <strong><?php esc_html_e('کد پیگیری:', 'parsi'); ?></strong>
                <code style="background: #fff; padding: 8px 12px; border-radius: 3px; font-size: 16px; font-weight: bold;"><?php echo esc_html($tracking_number); ?></code>
            </p>
            <?php if ($parsi_status): ?>
                <p>
                    <strong><?php esc_html_e('وضعیت:', 'parsi'); ?></strong>
                    <?php echo esc_html($parsi_status); ?>
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * [parsi_tracking] — shortcode rendering an informational tracking page.
     * Live lookup is not available; we point the user to the official site.
     */
    public function tracking_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => __('رهگیری بسته پارسی پست', 'parsi'),
        ), $atts);

        ob_start();
        ?>
        <div class="parsi-tracking-page" style="max-width: 800px; margin: 20px auto; padding: 20px;">
            <h2 style="text-align: center;"><?php echo esc_html($atts['title']); ?></h2>
            <p style="text-align: center; color: #555;">
                <?php esc_html_e('برای رهگیری بسته خود، کد پیگیری را از فاکتور سفارش خود بردارید و در سایت پارسی پست وارد کنید.', 'parsi'); ?>
            </p>
            <p style="text-align: center;">
                <a href="https://parsipost.com" target="_blank" rel="noopener" class="button button-primary">
                    <?php esc_html_e('رفتن به سایت پارسی پست', 'parsi'); ?>
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
