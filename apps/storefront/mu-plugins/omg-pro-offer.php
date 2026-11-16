<?php
/**
 * Plugin Name: OMGGamer PRO offer and store rules
 * Description: OMG PRO costs 6.99 USD a month; a customer's first month is always 0.99 USD. When PRO is in the cart
 *              or the shopper is already a member, promo codes are switched off (PRO pricing replaces them). The checkout
 *              summary shows the PRO savings. Also: the shop lives at /collections/ (old /shop/ links redirect).
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

    private static $eligible = null;
    private static $member = null;

    public static function init(): void
    {
        foreach (['woocommerce_product_get_sale_price', 'woocommerce_product_get_price'] as $f) {
            add_filter($f, [__CLASS__, 'price'], 6, 2);
        }
        add_filter('woocommerce_product_get_regular_price', [__CLASS__, 'regular'], 6, 2);
        add_filter('woocommerce_product_is_on_sale', [__CLASS__, 'on_sale'], 20, 2);
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'coupon_product'], 20, 2);
        add_filter('get_post_metadata', [__CLASS__, 'live_pro_meta'], 20, 4);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'item_note'], 20, 2);
        add_action('template_redirect', [__CLASS__, 'shop_redirect'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'checkout_data'], 100);
        add_filter('woocommerce_coupons_enabled', [__CLASS__, 'coupons_enabled'], 20);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'drop_coupons'], 5);
        add_action('woocommerce_blocks_loaded', [__CLASS__, 'store_api']);
        add_action('wp_footer', [__CLASS__, 'summary_js'], 90);
        // The PRO product is never listed: only the PRO page, the PRO option on product pages and checkout add it.
        add_action('pre_get_posts', [__CLASS__, 'hide_in_queries'], 20);
        add_filter('woocommerce_related_products', [__CLASS__, 'hide_in_ids'], 20);
        add_filter('woocommerce_product_get_upsell_ids', [__CLASS__, 'hide_in_ids'], 20);
        add_filter('woocommerce_product_get_cross_sell_ids', [__CLASS__, 'hide_in_ids'], 20);
        add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'hide_in_sitemap'], 20, 2);
        add_filter('woocommerce_cart_item_permalink', [__CLASS__, 'cart_link'], 20, 2);
        add_action('template_redirect', [__CLASS__, 'product_page_redirect'], 2);
    }

    /* ---------- PRO members and coupons ---------- */

    /** Active PRO member: an active subscription, or a paid PRO order in the last 31 days. */
    public static function is_member(): bool
    {
        if (self::$member !== null) {
            return self::$member;
        }
        $uid = get_current_user_id();
        if (!$uid) {
            return self::$member = false;
        }
        if (function_exists('wcs_user_has_subscription') && wcs_user_has_subscription($uid, self::PRODUCT, 'active')) {
            return self::$member = true;
        }
        if (!function_exists('wc_get_orders')) {
            return self::$member = false;
        }
        $orders = wc_get_orders(['customer_id' => $uid, 'status' => ['processing', 'completed'], 'date_paid' => '>' . (time() - 31 * DAY_IN_SECONDS), 'limit' => 20]);
        foreach ($orders as $o) {
            foreach ($o->get_items() as $it) {
                if ((int) $it->get_product_id() === self::PRODUCT) {
                    return self::$member = true;
                }
            }
        }
        return self::$member = false;
    }

    public static function pro_in_cart(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) ($item['product_id'] ?? 0) === self::PRODUCT) {
                return true;
            }
        }
        return false;
    }

    /** PRO pricing is active for this cart. */
    public static function pro_active(): bool
    {
        return self::pro_in_cart() || self::is_member();
    }

    public static function coupons_enabled($on)
    {
        if (!$on || (is_admin() && !wp_doing_ajax()) || !did_action('woocommerce_cart_loaded_from_session')) {
            return $on;
        }
        return self::pro_active() ? false : $on;
    }

    public static function drop_coupons($cart): void
    {
        if ($cart && $cart->get_applied_coupons() && self::pro_active()) {
            $cart->set_applied_coupons([]);
        }
    }

    /* ---------- Checkout summary data (Store API: extensions.omg) ---------- */

    public static function store_api(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data') || !class_exists('Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => 'omg',
            'data_callback' => [__CLASS__, 'summary_data'],
            'schema_callback' => static fn () => [],
            'schema_type' => ARRAY_A,
        ]);
    }

    private static function own_sale(WC_Product $p): bool
    {
        $s = $p->get_sale_price('edit');
        if ($s === '' || $s === null) {
            return false;
        }
        $from = $p->get_date_on_sale_from('edit');
        $to = $p->get_date_on_sale_to('edit');
        return (!$from || $from->getTimestamp() <= time()) && (!$to || $to->getTimestamp() >= time());
    }

    /** What a PRO member pays for one unit (base currency): the cart's own rule, never below the cost floor. */
    public static function member_price(WC_Product $p): ?array
    {
        if (!class_exists('OMG_Pricing')) {
            return null;
        }
        $reg = $p->get_regular_price('edit');
        if (!is_numeric($reg) || (float) $reg <= 0) {
            return null;
        }
        $nm = self::own_sale($p) ? (float) $p->get_sale_price('edit') : OMG_Pricing::sale_of((float) $reg);
        if (class_exists('OMG_Flash')) {
            $nm = (float) OMG_Flash::apply($nm, $p);
        }
        $fm = get_post_custom($p->get_id())['_omg_floor_price'][0] ?? '';
        $floor = is_numeric($fm) && (float) $fm > 0 ? (float) $fm : OMG_Pricing::floor_of((float) $reg);
        return ['before' => $nm, 'pro' => max(OMG_Pricing::pro_of($nm), min($nm, round($floor, 2)))];
    }

    /** The checkout's "PRO members pay up to … less" reads omg_pro_price: give it the live member price. */
    public static function live_pro_meta($value, $object_id, $meta_key, $single)
    {
        if ($meta_key !== 'omg_pro_price' || is_admin() || self::$in_meta) {
            return $value;
        }
        self::$in_meta = true;
        try {
            $p = wc_get_product((int) $object_id);
            $m = $p instanceof WC_Product && !$p->is_type('variable') ? self::member_price($p) : null;
        } finally {
            self::$in_meta = false;
        }
        if (!$m) {
            return $value;
        }
        $v = (string) round($m['pro'], 2);
        return $single ? $v : [$v];
    }

    private static bool $in_meta = false;

    public static function summary_data(): array
    {
        $out = ['pro_active' => false, 'pro_in_cart' => false, 'member' => false, 'coupons' => function_exists('wc_coupons_enabled') ? wc_coupons_enabled() : true, 'items' => 0, 'discounted' => 0, 'pro_discount' => 0, 'subtotal_before_pro' => 0, 'pro_line' => 0];
        if (!function_exists('WC') || !WC()->cart) {
            return $out;
        }
        $active = self::pro_active();
        $dp = wc_get_price_decimals();
        $minor = static fn ($v) => (int) round((float) $v * (10 ** $dp));
        $conv = static fn ($v) => class_exists('OMG_Markets') && OMG_Markets::applies() ? (OMG_Markets::convert($v) ?? $v) : $v;
        $pct = class_exists('OMG_Pricing') ? (float) (OMG_Pricing::settings()['pro'] ?? 0) : 0;
        $disc = 0.0;
        $before = 0.0;
        foreach (WC()->cart->get_cart() as $item) {
            $qty = (int) $item['quantity'];
            if ((int) $item['product_id'] === self::PRODUCT) {
                $out['pro_in_cart'] = true;
                $out['pro_line'] += $minor($item['line_subtotal']);
                continue;
            }
            $out['items'] += $qty;
            $line = (float) $item['line_subtotal'];
            $p = $item['data'] ?? null;
            if ($active && $pct > 0 && $p instanceof WC_Product && class_exists('OMG_Pricing')) {
                $reg = $p->get_regular_price('edit');
                $m = is_numeric($reg) && (float) $reg > 0 ? self::member_price($p) : null;
                if ($m) {
                    // Same calculation as the cart: the real difference between the price before PRO and the
                    // member price (which never goes below the product's cost floor), not a flat percentage.
                    $d = max(0, round((float) $conv($m['before']), 2) - round((float) $conv($m['pro']), 2)) * $qty;
                    if ($d > 0) {
                        $disc += $d;
                        $out['discounted'] += $qty;
                        $line += $d;
                    }
                }
            }
            $before += $line;
        }
        $out['pro_active'] = $active;
        $out['member'] = self::is_member();
        $out['pro_discount'] = $minor($disc);
        $out['subtotal_before_pro'] = $minor($before);
        return $out;
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

    /** Front-end product lists, searches and blocks never include the PRO product. */
    public static function hide_in_queries($q): void
    {
        if (is_admin() && !wp_doing_ajax() || !$q instanceof WP_Query) {
            return;
        }
        $pt = $q->get('post_type');
        $types = is_array($pt) ? $pt : [$pt];
        if (!in_array('product', $types, true) && !($q->is_search() && ($pt === '' || $pt === 'any'))) {
            return;
        }
        $in = array_map('intval', (array) $q->get('post__in'));
        if ((int) $q->get('p') === self::PRODUCT || in_array(self::PRODUCT, $in, true) || ($q->is_main_query() && $q->is_singular())) {
            return;
        }
        $not = array_map('intval', (array) $q->get('post__not_in'));
        $not[] = self::PRODUCT;
        $q->set('post__not_in', array_values(array_unique($not)));
    }

    public static function hide_in_ids($ids)
    {
        return is_array($ids) ? array_values(array_filter($ids, static fn ($id) => (int) $id !== self::PRODUCT)) : $ids;
    }

    public static function hide_in_sitemap($args, $post_type)
    {
        if ($post_type === 'product') {
            $args['post__not_in'] = array_merge((array) ($args['post__not_in'] ?? []), [self::PRODUCT]);
        }
        return $args;
    }

    public static function cart_link($link, $item)
    {
        return (int) ($item['product_id'] ?? 0) === self::PRODUCT ? home_url('/pro/') : $link;
    }

    public static function product_page_redirect(): void
    {
        if (is_singular('product') && (int) get_queried_object_id() === self::PRODUCT && !current_user_can('edit_products')) {
            wp_safe_redirect(home_url('/pro/'), 301);
            exit;
        }
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

    /** Checkout summary: PRO savings banner, "applied on N items" note and a PRO breakdown in the totals. */
    public static function summary_js(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }
        ?>
<style id="omg-sum-css">
.omgx-card .omgx-save{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;padding:12px 14px;border-radius:14px;background:linear-gradient(120deg,rgba(124,58,237,.16),rgba(224,17,95,.12));border:1px solid rgba(139,92,246,.35);color:#d9d6e8;font:500 14px/1.35 Poppins,system-ui,sans-serif;text-align:center}
.omgx-card .omgx-save b{color:#f4f2fb;font-size:17px;font-weight:700}
.omgx-card .omgx-pill{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:999px;background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);color:#fff;font:700 11px/1 Poppins,system-ui,sans-serif;letter-spacing:.04em}
.omgx-card .omgx-pro-note{margin:2px 0 12px;color:#a29fb6;font:500 13px/1.4 Poppins,system-ui,sans-serif}
.omgx-card .omgx-pro-note .p{color:#c4b5fd;font-weight:700}
.omgx-card dl.omgx-tot .omgx-x-pro dt,.omgx-card dl.omgx-tot .omgx-x-disc dt{display:flex;align-items:center;gap:6px}
.omgx-card dl.omgx-tot .omgx-x-pro dt .p,.omgx-card dl.omgx-tot .omgx-x-disc dt .p{color:#c4b5fd;font-weight:700}
.omgx-card dl.omgx-tot .omgx-x-disc dd{color:#34d399!important;font-weight:600}
</style>
<script id="omg-sum-js">
(function(){
  if (!window.wp || !wp.data) return;
  var store = 'wc/store/cart', busy = false;
  function fmt(minor, t){
    var u = t.currency_minor_unit || 0, v = (minor / Math.pow(10, u)).toFixed(u).split('.');
    v[0] = v[0].replace(/\B(?=(\d{3})+(?!\d))/g, t.currency_thousand_separator || ',');
    return (t.currency_prefix || '') + v.join(t.currency_decimal_separator || '.') + (t.currency_suffix || '');
  }
  function el(tag, cls, html){ var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; e.setAttribute('data-omgx-x', '1'); return e; }
  function row(dl, cls, label, value, before){
    var r = dl.querySelector('.' + cls); if (!r) { r = el('div', cls, '<dt></dt><dd></dd>'); before && before.parentNode === dl ? dl.insertBefore(r, before) : dl.appendChild(r); }
    if (r.querySelector('dt').innerHTML !== label) r.querySelector('dt').innerHTML = label;
    if (r.querySelector('dd').textContent !== value) r.querySelector('dd').textContent = value;
  }
  function drop(root, sel){ root.querySelectorAll(sel).forEach(function(n){ n.remove(); }); }
  function paint(){
    var card = document.querySelector('.omgx-card'); if (!card || busy) return;
    var cart = wp.data.select(store).getCartData(); if (!cart || !cart.totals) return;
    var o = (cart.extensions || {}).omg || {}, t = cart.totals; busy = true;
    try {
      var coupon = card.querySelector('.omgx-coupon'); if (coupon) coupon.style.display = o.coupons === false ? 'none' : '';
      var ul = card.querySelector('.omgx-items'), dl = card.querySelector('dl.omgx-tot');
      if (o.pro_active && o.pro_discount > 0) {
        var msg = '<span class="omgx-pill">OMG PRO</span> You are saving <b>' + fmt(o.pro_discount, t) + '</b> on this order';
        var sv = card.querySelector('.omgx-save'); if (!sv) { sv = el('div', 'omgx-save'); card.insertBefore(sv, card.firstChild); } if (sv.innerHTML !== msg) sv.innerHTML = msg;
        var noteTxt = '<span class="p">PRO</span> discount is applied on ' + o.discounted + ' item' + (o.discounted === 1 ? '' : 's');
        var nt = card.querySelector('.omgx-pro-note'); if (!nt && ul) { nt = el('p', 'omgx-pro-note'); ul.parentNode.insertBefore(nt, ul.nextSibling); } if (nt && nt.innerHTML !== noteTxt) nt.innerHTML = noteTxt;
        if (dl) {
          var first = dl.firstElementChild;
          if (first && !first.hasAttribute('data-omgx-x')) {
            var dt = first.querySelector('dt'), dd = first.querySelector('dd');
            var lab = 'Subtotal (' + o.items + ' item' + (o.items === 1 ? '' : 's') + ')', val = fmt(o.subtotal_before_pro, t);
            if (dt && dt.textContent !== lab) dt.textContent = lab; if (dd && dd.textContent !== val) dd.textContent = val;
          }
          var after = first ? first.nextElementSibling : null;
          if (o.pro_in_cart) row(dl, 'omgx-x-pro', 'OMG <span class="p">PRO</span> membership', fmt(o.pro_line, t), after); else drop(dl, '.omgx-x-pro');
          row(dl, 'omgx-x-disc', '<span class="p">PRO</span> discount', '- ' + fmt(o.pro_discount, t), dl.querySelector('.prot'));
        }
      } else { drop(card, '.omgx-save,.omgx-pro-note,.omgx-x-pro,.omgx-x-disc'); }
    } finally { setTimeout(function(){ busy = false; }, 0); }
  }
  var t; function soon(){ clearTimeout(t); t = setTimeout(paint, 30); }
  wp.data.subscribe(soon);
  if (window.MutationObserver) new MutationObserver(function(){ if (!busy) soon(); }).observe(document.body, {childList: true, subtree: true});
  soon();
})();
</script>
        <?php
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
