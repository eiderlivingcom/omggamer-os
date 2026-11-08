<?php
/**
 * Plugin Name:       OMGGamer Catalogue Connector
 * Description:       Secure receiving endpoint for approved product data from OMGGamer OS. Creates and updates WooCommerce products by their OMGGamer OS ID, never duplicates them and never touches orders.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            OMGGamer
 * License:           GPL-2.0-or-later
 * Text Domain:       omggamer-connector
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OMG_CONNECTOR_VERSION', '1.0.0');
define('OMG_CONNECTOR_FILE', __FILE__);
define('OMG_CONNECTOR_NS', 'omggamer-connector/v1');

require_once __DIR__ . '/includes/class-auth.php';
require_once __DIR__ . '/includes/class-products.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-admin.php';

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('rest_api_init', ['OMG_Connector_REST', 'register']);
add_action('admin_menu', ['OMG_Connector_Admin', 'menu']);
add_action('admin_init', ['OMG_Connector_Admin', 'register_settings']);

register_activation_hook(__FILE__, function () {
    if (!get_option('omg_connector_secret')) {
        add_option('omg_connector_secret', '', '', false); // set by an administrator; never autoloaded
    }
    add_option('omg_connector_vendor_id', 0);
});
