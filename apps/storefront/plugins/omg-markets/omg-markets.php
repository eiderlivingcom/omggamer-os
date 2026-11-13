<?php
/**
 * Plugin Name: OMGGamer Markets
 * Description: Country markets with local currencies (European Central Bank daily reference rates) and a Google Merchant Center product feed per country.
 * Version: 1.0.0
 * Author: OMGGamer
 * Requires Plugins: woocommerce
 * Text Domain: omg-markets
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OMG_MARKETS_VERSION', '1.0.0');
define('OMG_MARKETS_FILE', __FILE__);

require_once __DIR__ . '/includes/class-markets.php';
require_once __DIR__ . '/includes/class-rates.php';
require_once __DIR__ . '/includes/class-prices.php';
require_once __DIR__ . '/includes/class-switcher.php';
require_once __DIR__ . '/includes/class-feed.php';
require_once __DIR__ . '/includes/class-admin.php';

register_activation_hook(__FILE__, static function (): void {
    OMG_Markets_Feed::add_rewrite();
    flush_rewrite_rules(false);
    OMG_Markets_Rates::schedule();
    OMG_Markets_Rates::refresh();
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(OMG_Markets_Rates::CRON);
    flush_rewrite_rules(false);
});

add_action('plugins_loaded', static function (): void {
    if (!function_exists('WC')) {
        return;
    }
    OMG_Markets_Rates::init();
    OMG_Markets_Prices::init();
    OMG_Markets_Switcher::init();
    OMG_Markets_Feed::init();
    if (is_admin()) {
        OMG_Markets_Admin::init();
    }
}, 20);
