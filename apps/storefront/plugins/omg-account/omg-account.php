<?php
/**
 * Plugin Name: OMGGamer Account
 * Description: The customer account at /account/: orders (digital and physical separately) with keys, accounts,
 *              gift links and image keys shown properly, order details and receipts; settings, linked accounts,
 *              communication preferences, password and security; payment settings with an account balance
 *              (top-ups issued by OMGGamer and redeemable top-up codes, payable at checkout), transactions and
 *              the PRO membership.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: OMGGamer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OMG_ACCOUNT_VERSION', '1.0.0');
define('OMG_ACCOUNT_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/class-omg-wallet.php';
require_once __DIR__ . '/includes/class-omg-account-orders.php';
require_once __DIR__ . '/includes/class-omg-account.php';

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    OMG_Wallet::init();
    OMG_Account_Orders::init();
    OMG_Account::init();
}, 15);

register_activation_hook(__FILE__, static function () {
    update_option('omg_account_flush', 1);
});

add_action('init', static function () {
    if (get_option('omg_account_flush')) {
        delete_option('omg_account_flush');
        flush_rewrite_rules(false);
    }
}, 99);
