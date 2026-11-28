<?php
/**
 * Account → Orders: digital and physical orders listed separately, and the order details page.
 *
 * Keys come from OMGGamer OS (order item meta "_omg_keys": [{type, serial}], as delivered by the supplier, e.g.
 * Kinguin). They are shown by kind:
 *   - image/* (the supplier sends a picture of the code)  → the image, with a download button
 *   - a single URL                                          → a gift / activation link, with an "Open" button
 *   - "Login: … / Password: …" lines                        → account credentials, one copy button per field
 *   - anything else                                         → a key code with a copy button
 * Keys stay hidden until the customer clicks "Show key"; the first reveal is recorded on the order.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Account_Orders
{
    public static function init(): void
    {
        add_action('wp_ajax_omg_acc_keys', [__CLASS__, 'ajax_keys']);
        add_action('template_redirect', [__CLASS__, 'downloads'], 5);
        // Plain "Key: …" lines under items in emails / thank-you page stay, but in the account the new view is used.
    }

    /* ----------------------------------------------------------- item facts */

    public static function is_physical_item(WC_Order_Item $item): bool
    {
        $pid = (int) $item->get_product_id();
        return $pid && class_exists('OMG_SEO') && OMG_SEO::physical($pid) !== '';
    }

    /** Badge: GAME, DLC, ACCOUNT, GIFT, GIFT CARD, TOP-UP, SOFTWARE, HARDWARE, ACCESSORY, MEMBERSHIP. */
    public static function badge(WC_Order_Item $item): string
    {
        $pid = (int) $item->get_product_id();
        if (class_exists('OMG_Pro_Offer') && $pid === OMG_Pro_Offer::PRODUCT) {
            return 'Membership';
        }
        if ($k = (class_exists('OMG_SEO') ? OMG_SEO::physical($pid) : '')) {
            return $k === 'hardware' ? 'Hardware' : 'Accessory';
        }
        if (strcasecmp((string) get_post_meta($pid, '_omg_listing_type', true), 'Gift') === 0) {
            return 'Gift';
        }
        if (function_exists('omg_is_currency') && omg_is_currency($pid)) {
            return 'Top-up';
        }
        return [
            'game_account' => 'Account', 'gift_card' => 'Gift card', 'game_currency' => 'Top-up', 'dlc' => 'DLC',
            'software' => 'Software', 'subscription' => 'Subscription',
        ][(string) get_post_meta($pid, '_omg_product_type', true)] ?? 'Game';
    }

    public static function region(WC_Order_Item $item): string
    {
        foreach ($item->get_meta_data() as $m) {
            $d = $m->get_data();
            if (in_array($d['key'], ['pa_region', 'attribute_pa_region', 'Region', 'region'], true) && $d['value'] !== '') {
                $t = get_term_by('slug', (string) $d['value'], 'pa_region');
                return $t ? html_entity_decode($t->name, ENT_QUOTES) : ucwords(str_replace('-', ' ', (string) $d['value']));
            }
        }
        $pid = (int) $item->get_product_id();
        $terms = $pid ? wp_get_post_terms($pid, 'pa_region', ['fields' => 'names']) : [];
        return !is_wp_error($terms) && count($terms) === 1 ? html_entity_decode($terms[0], ENT_QUOTES) : '';
    }

    public static function image(WC_Order_Item $item, string $size = 'woocommerce_thumbnail'): string
    {
        $p = $item->get_product();
        $id = $p ? ($p->get_image_id() ?: ($p->get_parent_id() ? (int) get_post_thumbnail_id($p->get_parent_id()) : 0)) : 0;
        return $id ? (string) wp_get_attachment_image_url($id, $size) : wc_placeholder_img_src($size);
    }

    public static function raw_keys(WC_Order_Item $item): array
    {
        $raw = $item->get_meta('_omg_keys', true);
        return array_values(array_filter(is_array($raw) ? $raw : [], static fn ($k) => is_array($k) && isset($k['serial']) && $k['serial'] !== ''));
    }

    /** Turn a supplier key into something the page can show well. */
    public static function parse_key(array $k): array
    {
        $type = strtolower((string) ($k['type'] ?? 'text/plain'));
        $serial = (string) $k['serial'];
        if (str_starts_with($type, 'image/')) {
            $data = preg_match('#^[A-Za-z0-9+/=\s]+$#', $serial) ? preg_replace('/\s+/', '', $serial) : '';
            if (filter_var($serial, FILTER_VALIDATE_URL)) {
                return ['kind' => 'image', 'src' => $serial];
            }
            return ['kind' => 'image', 'src' => $data !== '' ? 'data:' . $type . ';base64,' . $data : ''];
        }
        $text = trim(str_replace("\r", '', $serial));
        if (preg_match('#^https?://\S+$#i', $text)) {
            return ['kind' => 'link', 'url' => $text];
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
        $looks_account = preg_match('/\b(login|user ?name|user|password|pass|e-?mail|account|pin|security answer|recovery)\b\s*[:=\-]/i', $text);
        if ($looks_account || count($lines) > 1) {
            $rows = [];
            $notes = [];
            foreach ($lines as $l) {
                if (preg_match('/^([^:=]{1,40})\s*[:=]\s*(.+)$/u', $l, $m) && !preg_match('#^https?$#i', trim($m[1]))) {
                    $rows[] = [trim($m[1]), trim($m[2])];
                } else {
                    $notes[] = $l;
                }
            }
            if ($rows) {
                return ['kind' => 'account', 'rows' => $rows, 'note' => implode("\n", $notes)];
            }
            return ['kind' => 'code', 'value' => $text];
        }
        return ['kind' => 'code', 'value' => $text];
    }

    /* ----------------------------------------------------------- ownership */

    private static function own_order(int $order_id): ?WC_Order
    {
        $o = wc_get_order($order_id);
        return $o && (int) $o->get_customer_id() === get_current_user_id() && get_current_user_id() ? $o : null;
    }

    private static function keys_ready(WC_Order $o): bool
    {
        return $o->has_status(['completed', 'processing']);
    }

    private static function mark_revealed(WC_Order $o, WC_Order_Item $item, string $how): void
    {
        if ($item->get_meta('_omg_keys_revealed_at')) {
            return;
        }
        $item->update_meta_data('_omg_keys_revealed_at', gmdate('c'));
        $item->save();
        $o->add_order_note(sprintf('Customer %s the key(s) for "%s" in their account.', $how, $item->get_name()));
    }

    /** AJAX: the keys of one order item, for its owner. */
    public static function ajax_keys(): void
    {
        check_ajax_referer('omg_acc_keys');
        $o = self::own_order((int) ($_POST['order'] ?? 0));
        $item = $o ? $o->get_item((int) ($_POST['item'] ?? 0)) : null;
        if (!$o || !$item || !self::keys_ready($o)) {
            wp_send_json_error(['message' => 'Not available.'], 403);
        }
        $keys = array_map([__CLASS__, 'parse_key'], self::raw_keys($item));
        if (!$keys) {
            wp_send_json_error(['message' => 'Your key is on its way. This page updates when it arrives.'], 404);
        }
        self::mark_revealed($o, $item, 'revealed');
        wp_send_json_success(['keys' => $keys]);
    }

    /** ?omg_keys=ORDER (text file of every key) and ?omg_receipt=ORDER (printable receipt). */
    public static function downloads(): void
    {
        if (!is_user_logged_in() || (!isset($_GET['omg_keys']) && !isset($_GET['omg_receipt']))) {
            return;
        }
        $id = (int) ($_GET['omg_keys'] ?? $_GET['omg_receipt']);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'omg_acc_order_' . $id)) {
            wp_die('This link has expired. Open the order again in your account.', 403);
        }
        $o = self::own_order($id);
        if (!$o) {
            wp_die('Order not found.', 404);
        }
        if (isset($_GET['omg_keys'])) {
            if (!self::keys_ready($o)) {
                wp_die('Keys are available once the order is paid.', 403);
            }
            $out = 'OMGGamer order #' . $o->get_order_number() . ' — ' . wc_format_datetime($o->get_date_created()) . "\n";
            $out .= str_repeat('=', 60) . "\n\n";
            foreach ($o->get_items() as $item) {
                $keys = self::raw_keys($item);
                if (!$keys) {
                    continue;
                }
                $rg = self::region($item);
                $out .= $item->get_name() . ($rg !== '' ? ' (' . $rg . ')' : '') . "\n";
                foreach ($keys as $k) {
                    $p = self::parse_key($k);
                    if ($p['kind'] === 'image') {
                        $out .= "  [Image key: download it from the order page in your account]\n";
                    } elseif ($p['kind'] === 'link') {
                        $out .= '  Link: ' . $p['url'] . "\n";
                    } elseif ($p['kind'] === 'account') {
                        foreach ($p['rows'] as [$l, $v]) {
                            $out .= '  ' . $l . ': ' . $v . "\n";
                        }
                        if ($p['note'] !== '') {
                            $out .= '  ' . str_replace("\n", "\n  ", $p['note']) . "\n";
                        }
                    } else {
                        $out .= '  Key: ' . $p['value'] . "\n";
                    }
                }
                $out .= "\n";
                self::mark_revealed($o, $item, 'downloaded');
            }
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="omggamer-order-' . $o->get_order_number() . '-keys.txt"');
            echo $out;
            exit;
        }
        self::receipt($o);
        exit;
    }

    public static function url(WC_Order $o, string $what): string
    {
        return wp_nonce_url(add_query_arg($what, $o->get_id(), wc_get_page_permalink('myaccount')), 'omg_acc_order_' . $o->get_id());
    }

    /* -------------------------------------------------------------- statuses */

    public static function status(WC_Order $o, bool $physical = false): array
    {
        $s = $o->get_status();
        $map = [
            'completed' => [$physical ? 'Delivered' : 'Success', 'ok'],
            'processing' => [$physical ? 'Processing' : 'Delivering', 'wait'],
            'on-hold' => ['On hold', 'wait'],
            'pending' => ['Awaiting payment', 'wait'],
            'failed' => ['Failed', 'bad'],
            'cancelled' => ['Cancelled', 'bad'],
            'refunded' => ['Refunded', 'mute'],
            'checkout-draft' => ['Draft', 'mute'],
        ];
        return $map[$s] ?? [wc_get_order_status_name($s), 'mute'];
    }

    /* ----------------------------------------------------------- orders list */

    public static function customer_orders(int $uid, int $limit = 200): array
    {
        return $uid ? wc_get_orders(['customer_id' => $uid, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_diff(array_keys(wc_get_order_statuses()), ['wc-checkout-draft'])]) : [];
    }

    /** @return array{digital: array, physical: array} each [order, items[]] */
    public static function split(array $orders): array
    {
        $out = ['digital' => [], 'physical' => []];
        foreach ($orders as $o) {
            $d = $p = [];
            foreach ($o->get_items() as $item) {
                if (self::is_physical_item($item)) {
                    $p[] = $item;
                } else {
                    $d[] = $item;
                }
            }
            if ($d) {
                $out['digital'][] = [$o, $d];
            }
            if ($p) {
                $out['physical'][] = [$o, $p];
            }
        }
        return $out;
    }

    public static function render_list(): void
    {
        $uid = get_current_user_id();
        $split = self::split(self::customer_orders($uid));
        $tab = ($_GET['type'] ?? '') === 'physical' ? 'physical' : 'digital';
        if (empty($_GET['type']) && !$split['digital'] && $split['physical']) {
            $tab = 'physical';
        }
        $base = wc_get_account_endpoint_url('orders');
        echo '<div class="oa-head"><h1>Orders</h1><p>Your keys, accounts and gift links are in each digital order; physical orders show delivery details.</p></div>';
        echo '<div class="oa-tabs" role="tablist">';
        foreach (['digital' => 'Digital products', 'physical' => 'Physical products'] as $k => $l) {
            echo '<a role="tab" aria-selected="' . ($tab === $k ? 'true' : 'false') . '" class="' . ($tab === $k ? 'on' : '') . '" href="' . esc_url(add_query_arg('type', $k, $base)) . '">' . esc_html($l) . ' <span>' . count($split[$k]) . '</span></a>';
        }
        echo '</div>';
        $rows = $split[$tab];
        $page = max(1, (int) ($_GET['pg'] ?? 1));
        $per = 10;
        $pages = max(1, (int) ceil(count($rows) / $per));
        $rows = array_slice($rows, ($page - 1) * $per, $per);
        if (!$rows) {
            echo '<div class="oa-empty"><span class="ic">!</span><p>' . ($tab === 'digital' ? 'No digital orders yet. Keys, accounts and gift cards you buy will appear here.' : 'No physical orders yet. Hardware and accessories you buy will appear here.') . '</p><a class="oa-btn pri" href="' . esc_url(wc_get_page_permalink('shop')) . '">Browse the store</a></div>';
            return;
        }
        foreach ($rows as [$o, $items]) {
            self::order_card($o, $items, $tab === 'physical');
        }
        if ($pages > 1) {
            echo '<nav class="oa-pager">';
            for ($i = 1; $i <= $pages; $i++) {
                echo '<a class="' . ($i === $page ? 'on' : '') . '" href="' . esc_url(add_query_arg(['type' => $tab, 'pg' => $i], $base)) . '">' . $i . '</a>';
            }
            echo '</nav>';
        }
    }

    public static function order_card(WC_Order $o, array $items, bool $physical): void
    {
        [$label, $cls] = self::status($o, $physical);
        echo '<article class="oa-order"><header class="oa-order-hd"><dl>'
            . '<div><dt>Order placed on</dt><dd>' . esc_html(wc_format_datetime($o->get_date_created(), 'M j, Y')) . '</dd></div>'
            . '<div><dt>Order amount</dt><dd>' . wp_kses_post(wc_price($o->get_total(), ['currency' => $o->get_currency()])) . '</dd></div>'
            . '<div><dt>Order ID</dt><dd>#' . esc_html($o->get_order_number()) . '</dd></div></dl>'
            . '<div class="oa-order-act"><span class="oa-pill ' . esc_attr($cls) . '">' . esc_html($label) . '</span>'
            . '<a class="oa-btn pri" href="' . esc_url($o->get_view_order_url()) . '">View order details</a></div></header><div class="oa-order-items">';
        foreach (array_slice($items, 0, 4) as $item) {
            $region = self::region($item);
            echo '<div class="oa-mini"><img src="' . esc_url(self::image($item)) . '" alt="" loading="lazy"><div><b>' . esc_html($item->get_name()) . '</b>'
                . ($region ? '<span class="oa-region">' . esc_html($region) . '</span>' : '<span class="oa-region mute">' . esc_html(self::badge($item)) . '</span>') . '</div></div>';
        }
        if (count($items) > 4) {
            echo '<div class="oa-more">+' . (count($items) - 4) . ' more</div>';
        }
        echo '</div></article>';
    }

    /* ---------------------------------------------------------- order details */

    public static function render_details(int $order_id): void
    {
        $o = self::own_order($order_id);
        if (!$o) {
            echo '<div class="oa-empty"><p>This order is not in your account.</p><a class="oa-btn" href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">Back to orders</a></div>';
            return;
        }
        $items = $o->get_items();
        $has_digital = $has_physical = false;
        foreach ($items as $it) {
            self::is_physical_item($it) ? $has_physical = true : $has_digital = true;
        }
        $ready = self::keys_ready($o);
        $any_keys = false;
        foreach ($items as $it) {
            if (self::raw_keys($it)) {
                $any_keys = true;
            }
        }
        $orders = wc_get_account_endpoint_url('orders');
        echo '<nav class="oa-crumbs"><a href="' . esc_url(add_query_arg('type', $has_digital ? 'digital' : 'physical', $orders)) . '">My orders</a><span>›</span><b>Order details</b></nav>';
        echo '<section class="oa-card oa-sum"><dl>'
            . '<div><dt>Order placed on</dt><dd>' . esc_html(wc_format_datetime($o->get_date_created(), 'M j, Y')) . '</dd></div>'
            . '<div><dt>Order amount</dt><dd>' . wp_kses_post(wc_price($o->get_total(), ['currency' => $o->get_currency()])) . '</dd></div>'
            . '<div><dt>Order ID</dt><dd>#' . esc_html($o->get_order_number()) . '</dd></div></dl><div class="oa-order-act">';
        if ($ready && $any_keys) {
            echo '<a class="oa-btn" href="' . esc_url(self::url($o, 'omg_keys')) . '"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 3v12m0 0l-5-5m5 5l5-5M4 19h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Download all</a>';
        }
        echo '<a class="oa-btn" href="' . esc_url(self::url($o, 'omg_receipt')) . '" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 3v12m0 0l-5-5m5 5l5-5M4 19h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Download receipt</a></div></section>';

        [$label, $cls] = self::status($o, !$has_digital);
        echo '<section class="oa-card oa-items"><div class="oa-items-hd"><span>Sold by <b>OMGGamer</b></span><span class="oa-pill ' . esc_attr($cls) . '">' . esc_html($label) . '</span></div>';
        $nonce = wp_create_nonce('omg_acc_keys');
        foreach ($items as $item_id => $item) {
            $physical = self::is_physical_item($item);
            $product = $item->get_product();
            $link = $product ? get_permalink($product->get_parent_id() ?: $product->get_id()) : '';
            $region = self::region($item);
            $keys = self::raw_keys($item);
            echo '<div class="oa-item"><img class="oa-item-im" src="' . esc_url(self::image($item)) . '" alt="" loading="lazy"><div class="oa-item-main">'
                . '<div class="oa-item-top"><div><b class="oa-item-name">' . esc_html($item->get_name()) . ($item->get_quantity() > 1 ? ' <span class="oa-qty">×' . (int) $item->get_quantity() . '</span>' : '') . '</b>'
                . ($region ? '<span class="oa-region">' . esc_html($region) . '</span>' : '') . '</div><span class="oa-badge">' . esc_html(self::badge($item)) . '</span></div>';
            if (!$physical) {
                if (!$ready) {
                    echo '<div class="oa-key-wait">' . ($o->has_status(['pending', 'on-hold']) ? 'Your key appears here once the payment is confirmed.' : 'No key: this order is ' . esc_html(strtolower(wc_get_order_status_name($o->get_status()))) . '.') . '</div>';
                } elseif (!$keys) {
                    echo '<div class="oa-key-wait"><span class="oa-spin" aria-hidden="true"></span>We are getting your key from the seller. It usually takes a few minutes; we also email you when it is ready.</div>';
                } else {
                    $revealed = (bool) $item->get_meta('_omg_keys_revealed_at');
                    echo '<div class="oa-keys" data-order="' . (int) $o->get_id() . '" data-item="' . (int) $item_id . '" data-nonce="' . esc_attr($nonce) . '">'
                        . '<button type="button" class="oa-btn pri oa-reveal">' . ($revealed ? 'Show key' : 'Reveal key') . (count($keys) > 1 ? 's (' . count($keys) . ')' : '') . '</button>'
                        . '<p class="oa-key-hint">' . self::key_hint(self::badge($item)) . '</p><div class="oa-key-list" hidden></div></div>';
                }
            } else {
                echo '<div class="oa-ship-line">' . esc_html(self::ship_line($o)) . '</div>';
            }
            echo '<div class="oa-item-act">';
            if ($link) {
                echo '<a class="oa-btn sm" href="' . esc_url($link) . '"><svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>View product</a>';
                if ($o->has_status('completed')) {
                    echo '<a class="oa-btn sm" href="' . esc_url($link . '#reviews') . '">Rate product</a>';
                }
            }
            echo '</div></div></div>';
        }
        echo '</section>';

        if ($has_physical) {
            self::render_shipping($o);
        }

        // Transaction details and totals.
        $method = $o->get_payment_method_title() ?: ($o->get_total() > 0 ? '–' : 'No payment needed');
        $txn = $o->get_transaction_id();
        echo '<h2 class="oa-h2">Transaction details</h2><section class="oa-card oa-txn"><div><dt>Transaction number</dt><dd>' . esc_html($txn ?: '–') . '</dd></div><div class="r"><dt>Payment mode</dt><dd>' . esc_html($method) . '</dd></div></section>';
        echo '<section class="oa-card oa-totals"><div class="oa-total"><span>Total</span><b>' . wp_kses_post(wc_price($o->get_total(), ['currency' => $o->get_currency()])) . '</b></div>';
        $cur = ['currency' => $o->get_currency()];
        $rows = [['Order amount', wc_price((float) $o->get_subtotal(), $cur), '']];
        foreach ($o->get_fees() as $fee) {
            $rows[] = [$fee->get_name(), ((float) $fee->get_total() >= 0 ? '+ ' : '− ') . wc_price(abs((float) $fee->get_total()), $cur), (float) $fee->get_total() < 0 ? 'good' : ''];
        }
        if ((float) $o->get_shipping_total() > 0) {
            $rows[] = ['Delivery', '+ ' . wc_price((float) $o->get_shipping_total(), $cur), ''];
        }
        if ((float) $o->get_total_discount() > 0) {
            $codes = $o->get_coupon_codes();
            $rows[] = ['Promo' . ($codes ? ' (' . strtoupper(implode(', ', $codes)) . ')' : ''), '− ' . wc_price((float) $o->get_total_discount(), $cur), 'good'];
        }
        if ((float) $o->get_total_tax() > 0) {
            $rows[] = ['Tax', '+ ' . wc_price((float) $o->get_total_tax(), $cur), ''];
        }
        if ((float) $o->get_meta('_omg_balance_base') > 0) {
            $rows[] = ['Paid from account balance', wc_price((float) $o->get_total(), $cur), ''];
        }
        foreach ($o->get_refunds() as $ref) {
            $rows[] = ['Refunded', '− ' . wc_price(abs((float) $ref->get_amount()), $cur), 'good'];
        }
        foreach ($rows as [$l, $v, $c]) {
            echo '<div class="oa-trow"><span>' . esc_html($l) . '</span><span class="' . esc_attr($c) . '">' . wp_kses_post($v) . '</span></div>';
        }
        echo '</section>';
        echo '<p class="oa-help">Problem with this order? <a href="' . esc_url(home_url('/contact/')) . '">Contact support</a> with order #' . esc_html($o->get_order_number()) . '. Every order has <a href="' . esc_url(home_url('/buyer-protection/')) . '">buyer protection</a>.</p>';
    }

    private static function key_hint(string $badge): string
    {
        switch ($badge) {
            case 'Account':
                return 'Log in with these details, then change the password and add your own email.';
            case 'Gift':
                return 'Open the gift link while logged in to the account you want the game on.';
            case 'Top-up':
            case 'Gift card':
                return 'Redeem the code on the platform shown in the product name.';
            default:
                return 'Activate the key on the platform shown in the product name. <a href="' . esc_url(home_url('/activate/')) . '">How to activate</a>';
        }
    }

    private static function ship_line(WC_Order $o): string
    {
        if ($t = self::tracking($o)) {
            return 'Shipped' . ($t['carrier'] ? ' with ' . $t['carrier'] : '') . '. Tracking number ' . $t['number'] . '.';
        }
        if ($o->has_status('completed')) {
            return 'Delivered.';
        }
        if ($o->has_status('processing')) {
            return 'We are preparing your order for shipping.';
        }
        return 'Shipping starts once the payment is confirmed.';
    }

    /** Tracking from the Shipment Tracking plugin or simple meta, if any. */
    private static function tracking(WC_Order $o): ?array
    {
        $st = $o->get_meta('_wc_shipment_tracking_items');
        if (is_array($st) && $st) {
            $t = end($st);
            return ['number' => (string) ($t['tracking_number'] ?? ''), 'carrier' => (string) ($t['tracking_provider'] ?? $t['custom_tracking_provider'] ?? ''), 'url' => (string) ($t['custom_tracking_link'] ?? '')];
        }
        $n = (string) $o->get_meta('_tracking_number');
        return $n !== '' ? ['number' => $n, 'carrier' => (string) $o->get_meta('_tracking_provider'), 'url' => (string) $o->get_meta('_tracking_url')] : null;
    }

    private static function render_shipping(WC_Order $o): void
    {
        $steps = ['Ordered' => true, 'Processing' => $o->has_status(['processing', 'completed']), 'Shipped' => (bool) self::tracking($o) || $o->has_status('completed'), 'Delivered' => $o->has_status('completed')];
        echo '<h2 class="oa-h2">Delivery</h2><section class="oa-card oa-ship"><ol class="oa-steps">';
        foreach ($steps as $l => $done) {
            echo '<li class="' . ($done ? 'done' : '') . '"><span></span>' . esc_html($l) . '</li>';
        }
        echo '</ol><div class="oa-ship-grid"><div><dt>Delivery address</dt><dd>' . wp_kses_post($o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address() ?: '–') . '</dd></div>'
            . '<div><dt>Delivery method</dt><dd>' . esc_html($o->get_shipping_method() ?: '–') . '</dd></div>';
        if ($t = self::tracking($o)) {
            echo '<div><dt>Tracking</dt><dd>' . esc_html(trim($t['carrier'] . ' ' . $t['number'])) . ($t['url'] ? ' · <a href="' . esc_url($t['url']) . '" target="_blank" rel="noopener">Track parcel</a>' : '') . '</dd></div>';
        }
        echo '</div></section>';
    }

    /* ------------------------------------------------------------- receipt */

    private static function legal(string $field, string $fallback): string
    {
        $v = shortcode_exists('omg_legal') ? trim(wp_strip_all_tags(do_shortcode('[omg_legal field="' . $field . '"]'))) : '';
        return $v !== '' ? $v : $fallback;
    }

    private static function receipt(WC_Order $o): void
    {
        $uid = get_current_user_id();
        $company = trim((string) get_user_meta($uid, 'omg_company_name', true));
        $vat = trim((string) get_user_meta($uid, 'omg_company_vat', true));
        $caddr = array_filter([get_user_meta($uid, 'omg_company_address_1', true), get_user_meta($uid, 'omg_company_address_2', true), trim(get_user_meta($uid, 'omg_company_postcode', true) . ' ' . get_user_meta($uid, 'omg_company_city', true)), get_user_meta($uid, 'omg_company_region', true)]);
        $cur = ['currency' => $o->get_currency()];
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Receipt – OMGGamer order #<?php echo esc_html($o->get_order_number()); ?></title>
<style>body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;margin:0;background:#f4f3f8}.r{max-width:760px;margin:24px auto;background:#fff;padding:40px;border-radius:12px}h1{margin:0;font-size:22px}.top{display:flex;justify-content:space-between;gap:24px;margin-bottom:28px}.muted{color:#666}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eee;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#666}td.n,th.n{text-align:right;white-space:nowrap}.tot td{border:0;padding:4px 8px}.tot .g td{font-weight:700;font-size:16px;border-top:2px solid #111;padding-top:10px}.cols{display:flex;gap:40px;flex-wrap:wrap}.cols>div{flex:1;min-width:200px}.btn{display:inline-block;margin:0 auto 24px;padding:10px 18px;background:#7c3aed;color:#fff;border-radius:8px;text-decoration:none;border:0;font:600 14px sans-serif;cursor:pointer}@media print{body{background:#fff}.r{margin:0;padding:0}.np{display:none}}</style></head><body>
<div class="np" style="text-align:center;padding-top:20px"><button class="btn" onclick="window.print()">Print / save as PDF</button></div>
<div class="r"><div class="top"><div><h1>Receipt</h1><div class="muted">Order #<?php echo esc_html($o->get_order_number()); ?> · <?php echo esc_html(wc_format_datetime($o->get_date_created(), 'j M Y')); ?></div></div>
<div style="text-align:right"><strong>OMGGamer</strong><br><span class="muted"><?php echo esc_html(self::legal('name', 'XGamer Labs')); ?><br><?php echo esc_html(self::legal('email', 'hello@omggamer.com')); ?></span></div></div>
<div class="cols"><div><strong>Billed to</strong><br><?php echo wp_kses_post($o->get_formatted_billing_address() ?: esc_html($o->get_billing_email())); ?><br><span class="muted"><?php echo esc_html($o->get_billing_email()); ?></span></div>
<?php if ($company !== '') : ?><div><strong>Company</strong><br><?php echo esc_html($company); ?><br><?php echo esc_html(implode(', ', $caddr)); ?><?php if ($vat !== '') : ?><br>VAT number: <?php echo esc_html($vat); ?><?php endif; ?></div><?php endif; ?>
<div><strong>Payment</strong><br><?php echo esc_html($o->get_payment_method_title() ?: '–'); ?><br><span class="muted"><?php echo esc_html($o->get_transaction_id() ? 'Ref. ' . $o->get_transaction_id() : ''); ?></span><br>Status: <?php echo esc_html(wc_get_order_status_name($o->get_status())); ?></div></div>
<table><thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Amount</th></tr></thead><tbody>
<?php foreach ($o->get_items() as $item) : ?><tr><td><?php echo esc_html($item->get_name()); ?><?php $rg = self::region($item); echo $rg ? '<br><span class="muted">' . esc_html($rg) . '</span>' : ''; ?></td><td class="n"><?php echo (int) $item->get_quantity(); ?></td><td class="n"><?php echo wp_kses_post(wc_price((float) $item->get_total() + (float) $item->get_total_tax(), $cur)); ?></td></tr><?php endforeach; ?>
</tbody></table>
<table class="tot"><tbody>
<?php foreach ($o->get_order_item_totals() as $k => $row) : if ($k === 'payment_method') { continue; } ?><tr class="<?php echo $k === 'order_total' ? 'g' : ''; ?>"><td><?php echo esc_html(rtrim(wp_strip_all_tags($row['label']), ':')); ?></td><td class="n"><?php echo wp_kses_post($row['value']); ?></td></tr><?php endforeach; ?>
</tbody></table>
<p class="muted" style="font-size:12px">Digital goods are delivered electronically. Questions: <?php echo esc_html(self::legal('email', 'hello@omggamer.com')); ?>. This receipt was generated from your OMGGamer account.</p></div></body></html><?php
    }
}
