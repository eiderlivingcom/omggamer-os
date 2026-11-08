<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orders:
 * - When an order is paid, its OMGGamer items are sent to OMGGamer OS (signed). Failures retry via WP-Cron.
 * - OMGGamer OS delivers keys to /orders/deliver; they are saved on the order items (shown in My Account and
 *   in the "Completed order" email) and the order is completed when every OMGGamer item has its keys.
 * - OMGGamer OS adds private notes (and may put an order on hold) through /orders/note.
 * Existing orders and order statuses are never changed except by these explicit calls.
 */
class OMG_Connector_Orders
{
    const KEY_META = '_omg_keys';

    public static function init()
    {
        add_action('woocommerce_payment_complete', [__CLASS__, 'on_paid']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_paid']);
        add_action('omg_connector_send_order', [__CLASS__, 'send']);
        add_action('woocommerce_order_item_meta_end', [__CLASS__, 'show_keys'], 10, 3);
    }

    public static function on_paid($order_id)
    {
        if (get_option('omg_connector_storefront', '') === '') {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_omg_sent_at')) {
            return;
        }
        self::send($order_id);
    }

    private static function payload($order)
    {
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $variant = (string) get_post_meta($product_id, '_omg_variant_id', true);
            if ($variant === '') {
                continue;
            }
            $items[] = [
                'item_id' => (string) $item_id,
                'name' => $item->get_name(),
                'variant_id' => $variant,
                'product_external_id' => (string) get_post_meta($item->get_product_id(), '_omg_external_id', true),
                'quantity' => (int) $item->get_quantity(),
                'line_total' => wc_format_decimal($item->get_total(), 2),
            ];
        }
        return [
            'order_id' => (string) $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => wc_format_decimal($order->get_total(), 2),
            'items' => $items,
        ];
    }

    public static function send($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $payload = self::payload($order);
        if (!$payload['items']) {
            return; // no OMGGamer items in this order
        }
        $body = wp_json_encode($payload);
        $base = rtrim((string) get_option('omg_connector_os_url', 'https://os.omggamer.com'), '/');
        $code = rawurlencode((string) get_option('omg_connector_storefront', ''));
        $ts = (string) time();
        $res = wp_remote_post("{$base}/api/v1/webhooks/storefronts/{$code}/orders", [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-OMG-Timestamp' => $ts,
                'X-OMG-Signature' => hash_hmac('sha256', $ts . '.' . $body, (string) get_option('omg_connector_secret', '')),
            ],
            'body' => $body,
        ]);
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $order->update_meta_data('_omg_sent_at', gmdate('c'));
            $order->save();
            $order->add_order_note('Sent to OMGGamer OS for fulfilment.');
            return;
        }
        $tries = (int) $order->get_meta('_omg_send_attempts') + 1;
        $order->update_meta_data('_omg_send_attempts', $tries);
        $order->save();
        if ($tries <= 10) {
            wp_schedule_single_event(time() + min(3600, 60 * $tries * $tries), 'omg_connector_send_order', [$order_id]);
        } else {
            $order->add_order_note('Could not reach OMGGamer OS after 10 attempts. Fulfil this order by hand or resend it.');
        }
    }

    /** POST /orders/deliver {order_id, items:[{item_id, keys:[{type, serial}]}]} */
    public static function deliver(array $data)
    {
        $order = wc_get_order((int) ($data['order_id'] ?? 0));
        if (!$order) {
            return new WP_Error('omg_not_found', 'Order not found.', ['status' => 404]);
        }
        $added = 0;
        foreach ((array) ($data['items'] ?? []) as $row) {
            $item = $order->get_item((int) ($row['item_id'] ?? 0));
            if (!$item) {
                continue;
            }
            $stored = (array) $item->get_meta(self::KEY_META, true);
            $seen = array_flip(array_map(function ($k) { return md5($k['serial'] ?? ''); }, $stored));
            foreach ((array) ($row['keys'] ?? []) as $key) {
                $serial = (string) ($key['serial'] ?? '');
                if ($serial === '' || isset($seen[md5($serial)])) {
                    continue; // already delivered (idempotent)
                }
                $stored[] = ['type' => sanitize_text_field($key['type'] ?? 'text/plain'), 'serial' => $serial];
                $seen[md5($serial)] = true;
                $added++;
            }
            $item->update_meta_data(self::KEY_META, $stored);
            $item->save();
        }
        $complete = true;
        foreach ($order->get_items() as $item) {
            $pid = $item->get_variation_id() ?: $item->get_product_id();
            if (get_post_meta($pid, '_omg_variant_id', true) === '') {
                continue;
            }
            if (count((array) $item->get_meta(self::KEY_META, true)) < (int) $item->get_quantity()) {
                $complete = false;
            }
        }
        if ($added) {
            $order->add_order_note(sprintf('OMGGamer OS delivered %d key(s).', $added));
        }
        if ($complete && !$order->has_status('completed')) {
            $order->update_status('completed', 'All keys delivered by OMGGamer OS.');
        }
        return ['ok' => true, 'added' => $added, 'complete' => $complete];
    }

    /** POST /orders/note {order_id, note, status?} — status limited to on-hold / processing / cancelled. */
    public static function note(array $data)
    {
        $order = wc_get_order((int) ($data['order_id'] ?? 0));
        if (!$order) {
            return new WP_Error('omg_not_found', 'Order not found.', ['status' => 404]);
        }
        $note = sanitize_textarea_field((string) ($data['note'] ?? ''));
        if ($note !== '') {
            $order->add_order_note($note);
        }
        $status = (string) ($data['status'] ?? '');
        if (in_array($status, ['on-hold', 'processing', 'cancelled'], true) && !$order->has_status(['completed', 'refunded'])) {
            $order->update_status($status);
        }
        return ['ok' => true];
    }

    /** Show keys under each item (order received page, My Account, emails). */
    public static function show_keys($item_id, $item, $order)
    {
        $keys = (array) $item->get_meta(self::KEY_META, true);
        if (!$keys || !$order || !$order->has_status(['completed', 'processing'])) {
            return;
        }
        echo '<div class="omg-keys" style="margin-top:6px">';
        foreach ($keys as $key) {
            $type = (string) ($key['type'] ?? 'text/plain');
            if (strpos($type, 'image/') === 0) {
                echo '<img alt="' . esc_attr__('Key', 'omggamer-connector') . '" style="max-width:320px;display:block;margin:4px 0" src="data:' . esc_attr($type) . ';base64,' . esc_attr($key['serial']) . '">';
            } else {
                echo '<div><strong>' . esc_html__('Key:', 'omggamer-connector') . '</strong> <code style="user-select:all">' . esc_html($key['serial']) . '</code></div>';
            }
        }
        echo '</div>';
    }
}
