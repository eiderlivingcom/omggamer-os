<?php
/**
 * Plugin Name: OMGGamer PRO offer and store rules
 * Description: OMG PRO costs 6.99 USD a month; a customer's first month is always 0.99 USD. Promo codes never apply
 *              to the PRO membership (they still apply to the other products in the same cart). Also: gift cards use
 *              the digital key page, and the shop lives at /collections/.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Pro_Offer
{
    public const PRODUCT = 1906;
    public const MONTHLY = 6.99;
    public const FIRST = 0.99;

    /** Bricks templates: gift card / currency (106) => game key (104). */
    private const TEMPLATE_SWAP = [106 => 104];

    private static $eligible = null;

    public static function init(): void
    {
        foreach (['woocommerce_product_get_sale_price', 'woocommerce_product_get_price'] as $f) {
            add_filter($f, [__CLASS__, 'price'], 6, 2);
        }
        add_filter('woocommerce_product_get_regular_price', [__CLASS__, 'regular'], 6, 2);
        add_filter('woocommerce_product_is_on_sale', [__CLASS__, 'on_sale'], 20, 2);
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'coupon_product'], 20, 2);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'item_note'], 20, 2);
        add_filter('bricks/active_templates', [__CLASS__, 'templates'], 30, 3);
        add_filter('get_post_metadata', [__CLASS__, 'giftcard_layout'], 10, 4);
        add_action('template_redirect', [__CLASS__, 'shop_redirect'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'checkout_data'], 100);
    }

    /** Give the checkout's PRO box the real offer (in the visitor's currency). */
    public static function checkout_data(): void
    {
        if (!wp_script_is('omgx', 'enqueued') && !wp_script_is('omgx', 'registered')) {
            return;
        }
        $c = static fn ($v) => class_exists('OMG_Markets') && OMG_Markets::applies() ? (OMG_Markets::convert($v) ?? $v) : $v;
        $d = ['proMonthly' => $c(self::MONTHLY), 'proFirst' => self::eligible() ? $c(self::FIRST) : 0];
        wp_add_inline_script('omgx', 'window.OMGX=window.OMGX||{};Object.assign(window.OMGX,' . wp_json_encode($d) . ');', 'before');
    }

    /** First month at 0.99 unless this customer has bought PRO before. */
    public static function eligible(): bool
    {
        if (self::$eligible !== null) {
            return self::$eligible;
        }
        if (!function_exists('wc_customer_bought_product')) {
            return self::$eligible = true;
        }
        $uid = get_current_user_id();
        $email = '';
        if (function_exists('WC') && WC()->customer) {
            $email = (string) WC()->customer->get_billing_email();
        }
        if (!$email && $uid) {
            $u = get_userdata($uid);
            $email = $u ? $u->user_email : '';
        }
        if (!$uid && !$email) {
            return self::$eligible = true;
        }
        return self::$eligible = !wc_customer_bought_product($email, $uid, self::PRODUCT);
    }

    private static function is_pro($product): bool
    {
        return $product instanceof WC_Product && $product->get_id() === self::PRODUCT;
    }

    public static function regular($price, $product)
    {
        return self::is_pro($product) ? (string) self::MONTHLY : $price;
    }

    public static function price($price, $product)
    {
        if (!self::is_pro($product)) {
            return $price;
        }
        if (current_filter() === 'woocommerce_product_get_sale_price') {
            return self::eligible() ? (string) self::FIRST : '';
        }
        return (string) (self::eligible() ? self::FIRST : self::MONTHLY);
    }

    public static function on_sale($on, $product)
    {
        return self::is_pro($product) ? self::eligible() : $on;
    }

    /** Promo codes skip the PRO membership; they still discount every other product in the cart. */
    public static function coupon_product($valid, $product)
    {
        if ($product instanceof WC_Product && ($product->get_id() === self::PRODUCT || $product->get_parent_id() === self::PRODUCT)) {
            return false;
        }
        return $valid;
    }

    public static function item_note($data, $item)
    {
        if ((int) ($item['product_id'] ?? 0) !== self::PRODUCT) {
            return $data;
        }
        $m = static fn ($v) => wp_strip_all_tags(wc_price(class_exists('OMG_Markets') && OMG_Markets::applies() ? (OMG_Markets::convert($v) ?? $v) : $v));
        $data[] = [
            'key' => 'Billing',
            'value' => self::eligible() ? sprintf('%s for the first month, then %s/month. Cancel anytime.', $m(self::FIRST), $m(self::MONTHLY)) : sprintf('%s/month. Cancel anytime.', $m(self::MONTHLY)),
        ];
        $data[] = ['key' => 'Promo codes', 'value' => 'Not applicable to PRO'];
        return $data;
    }

    /** Gift cards use the digital key page (OMG Product Studio's "digital" layout) unless one is set by hand. */
    public static function giftcard_layout($value, $object_id, $meta_key, $single)
    {
        static $busy = false;
        if ($meta_key !== '_omg_layout' || $busy || get_post_type($object_id) !== 'product') {
            return $value;
        }
        $busy = true;
        $own = get_post_meta($object_id, '_omg_layout', true);
        $gift = has_term('gift-cards', 'product_cat', $object_id);
        $busy = false;
        if ($gift && (!$own || $own === 'auto' || $own === 'giftcard')) {
            return $single ? ['digital'] : [['digital']];
        }
        return $value;
    }

    public static function templates($active, $post_id = 0, $content_type = '')
    {
        if (is_array($active) && !empty($active['content']) && isset(self::TEMPLATE_SWAP[(int) $active['content']])
            && function_exists('is_product') && is_product()) {
            $to = self::TEMPLATE_SWAP[(int) $active['content']];
            if (get_post_status($to) === 'publish') {
                $active['content'] = $to;
            }
        }
        return $active;
    }

    /** Old /shop/ links go to /collections/ (the shop page's new address). */
    public static function shop_redirect(): void
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!preg_match('~^/shop(/.*)?$~', $path, $m) || !function_exists('wc_get_page_permalink')) {
            return;
        }
        $shop = wc_get_page_permalink('shop');
        if (strpos($shop, '/shop/') !== false) {
            return; // shop page not moved yet
        }
        $rest = isset($m[1]) ? ltrim($m[1], '/') : '';
        $to = trailingslashit($shop) . $rest;
        if (!empty($_SERVER['QUERY_STRING'])) {
            $to .= '?' . $_SERVER['QUERY_STRING'];
        }
        wp_safe_redirect($to, 301);
        exit;
    }
}

OMG_Pro_Offer::init();
