<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Before checkout, ask OMGGamer OS whether every OMGGamer item in the cart still has a purchasable
 * supplier offer (OMGGamer OS re-reads the supplier live). Unavailable items block checkout; a changed
 * price is applied to the product and the customer is asked to review the cart.
 * Answers are cached for 60 seconds per cart so the supplier is not asked on every page view.
 */
class OMG_Connector_Checkout
{
    public static function init()
    {
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check']);
    }

    private static function configured()
    {
        return get_option('omg_connector_storefront', '') !== '' && strlen((string) get_option('omg_connector_secret', '')) >= 32;
    }

    public static function check()
    {
        if (!self::configured() || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        if (!is_checkout() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            return; // only at checkout (classic page or Store API / block checkout)
        }
        $items = [];
        $names = [];
        foreach (WC()->cart->get_cart() as $line) {
            $pid = !empty($line['variation_id']) ? (int) $line['variation_id'] : (int) $line['product_id'];
            $vid = (string) get_post_meta($pid, '_omg_variant_id', true);
            if ($vid === '') {
                continue; // not an OMGGamer OS product
            }
            $items[] = ['variant_id' => $vid, 'quantity' => (int) $line['quantity']];
            $names[$vid] = ['name' => $line['data'] ? $line['data']->get_name() : '', 'product_id' => $pid];
        }
        if (!$items) {
            return;
        }
        $body = wp_json_encode(['items' => $items]);
        $cache = 'omg_avail_' . md5($body);
        $result = get_transient($cache);
        if ($result === false) {
            $result = self::ask($body);
            if (is_array($result)) {
                set_transient($cache, $result, 60);
            }
        }
        if (!is_array($result)) {
            if (get_option('omg_connector_strict', 0)) {
                wc_add_notice(__('We could not confirm stock for your order right now. Please try again in a minute.', 'omggamer-connector'), 'error');
            }
            return;
        }
        foreach ((array) ($result['items'] ?? []) as $row) {
            $vid = (string) ($row['variant_id'] ?? '');
            if (!isset($names[$vid])) {
                continue;
            }
            $label = $names[$vid]['name'];
            if (empty($row['available'])) {
                wc_add_notice(sprintf(__('%s is no longer available. Please remove it from your cart.', 'omggamer-connector'), esc_html($label)), 'error');
                continue;
            }
            if (!empty($row['price_changed']) && isset($row['price'])) {
                $product = wc_get_product($names[$vid]['product_id']);
                if ($product && wc_format_decimal($product->get_regular_price()) !== wc_format_decimal($row['price'])) {
                    $product->set_regular_price(wc_format_decimal($row['price']));
                    $product->save();
                    delete_transient($cache);
                    wc_add_notice(sprintf(__('The price of %s has changed. Please review your cart before paying.', 'omggamer-connector'), esc_html($label)), 'error');
                }
            }
        }
    }

    private static function ask($body)
    {
        $base = rtrim((string) get_option('omg_connector_os_url', 'https://os.omggamer.com'), '/');
        $code = rawurlencode((string) get_option('omg_connector_storefront', ''));
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, (string) get_option('omg_connector_secret', ''));
        $res = wp_remote_post("{$base}/api/v1/webhooks/storefronts/{$code}/availability", [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json', 'X-OMG-Timestamp' => $ts, 'X-OMG-Signature' => $sig],
            'body' => $body,
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('Availability check failed: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)), ['source' => 'omggamer-connector']);
            }
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : null;
    }
}
