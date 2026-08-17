<?php
/**
 * Plugin Name: WooCommerce USDT TRC20 Direct Gateway
 * Description: Direct USDT TRC20 payment gateway for WooCommerce. Monitors a configured TRON wallet and automatically marks matching orders as paid.
 * Version: 0.2.12
 * Author: Custom
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: wc-usdt-trc20
 */

defined('ABSPATH') || exit;

final class WC_USDT_TRC20_Plugin {
    const CRON_HOOK = 'wc_usdt_trc20_check_payments';
    const TOKEN_DECIMALS = 6;
    const TOKEN_CONTRACT_MAINNET = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const TOKEN_CONTRACT_NILE = 'TXYZopYRdj2D9XRtbG411XZZ3kM5VkAeBf';
    const TOKEN_CONTRACT_SHASTA = 'TDZDd58a44n5Bvg7pfpcdWhZpv7XSt9PsU';
    const META_AMOUNT = '_usdt_trc20_amount';
    const META_ADDRESS = '_usdt_trc20_address';
    const META_TXID = '_usdt_trc20_txid';
    const META_CREATED = '_usdt_trc20_created';
    const META_MATCHED = '_usdt_trc20_matched';

    public static function load() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        require_once __DIR__ . '/includes/class-wc-gateway-usdt-trc20.php';

        // Register AJAX handlers at plugin level. The gateway constructor is not
        // guaranteed to run during admin-ajax.php requests.
        add_action('wp_ajax_wc_usdt_trc20_payment_status', ['WC_Gateway_USDT_TRC20', 'ajax_payment_status']);
        add_action('wp_ajax_nopriv_wc_usdt_trc20_payment_status', ['WC_Gateway_USDT_TRC20', 'ajax_payment_status']);
        add_action('wp_ajax_wc_usdt_trc20_verify_txid', ['WC_Gateway_USDT_TRC20', 'ajax_verify_txid']);
        add_action('wp_ajax_nopriv_wc_usdt_trc20_verify_txid', ['WC_Gateway_USDT_TRC20', 'ajax_verify_txid']);

        add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_gateway']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'check_payments']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'wc_usdt_1min', self::CRON_HOOK);
        }
    }

    public static function add_gateway($methods) {
        $methods[] = 'WC_Gateway_USDT_TRC20';
        return $methods;
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['wc_usdt_1min'])) {
            $schedules['wc_usdt_1min'] = [
                'interval' => 60,
                'display' => __('Every minute', 'wc-usdt-trc20'),
            ];
        }
        return $schedules;
    }

    public static function check_payments() {
        if (!class_exists('WC_Gateway_USDT_TRC20')) {
            return;
        }
        if (get_transient('wc_usdt_trc20_checker_lock')) {
            return;
        }
        set_transient('wc_usdt_trc20_checker_lock', 1, 50);
        try {
            $gateway = new WC_Gateway_USDT_TRC20();
            if ($gateway->is_configured()) {
                $gateway->scan_and_match();
            }
        } finally {
            delete_transient('wc_usdt_trc20_checker_lock');
        }
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'wc_usdt_1min', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
}

add_action('before_woocommerce_init', ['WC_USDT_TRC20_Plugin', 'declare_compatibility']);
add_action('plugins_loaded', ['WC_USDT_TRC20_Plugin', 'load'], 20);

register_activation_hook(__FILE__, ['WC_USDT_TRC20_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WC_USDT_TRC20_Plugin', 'deactivate']);
