<?php
/**
 * Plugin Name: OMGGamer pricing and discounts
 * Description: Storewide sale (X% off the regular price OMGGamer OS sends), an automatic PRO price (a further Y% off),
 *              coupons that stack with PRO, and a floor so no sale goes below supplier cost + the minimum profit.
 *              Settings: WooCommerce → OMG pricing.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Pricing
{
    public const OPTION = 'omg_pricing';

    /** Products whose cart price has already been set to the PRO price (the sale must not be applied twice). */
    private static ?SplObjectStorage $pro_set = null;
    private static bool $reading = false;

    public static function settings(): array
    {
        $s = get_option(self::OPTION, []);
        return wp_parse_args(is_array($s) ? $s : [], [
            'sale' => 10,         // storewide % off the regular price
            'pro' => 10,          // PRO members: further % off the sale price
            'markup' => 57.8,     // the OMGGamer OS pricing rule's markup, used to work out supplier cost
            'min_profit' => 15,   // never sell below supplier cost + this %, whatever the discounts
        ]);
    }

    public static function init(): void
    {
        self::$pro_set = new SplObjectStorage();
        foreach (['woocommerce_product_get_price', 'woocommerce_product_variation_get_price'] as $h) {
            add_filter($h, [__CLASS__, 'price'], 5, 2);
        }
        foreach (['woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price'] as $h) {
            add_filter($h, [__CLASS__, 'sale_price'], 5, 2);
        }
        add_filter('woocommerce_variation_prices_price', [__CLASS__, 'price'], 5, 2);
        add_filter('woocommerce_variation_prices_sale_price', [__CLASS__, 'sale_price'], 5, 2);
        add_filter('woocommerce_product_is_on_sale', [__CLASS__, 'on_sale'], 20, 2);
        add_filter('woocommerce_get_variation_prices_hash', [__CLASS__, 'hash'], 20);
        add_filter('get_post_metadata', [__CLASS__, 'pro_meta'], 10, 4);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'member_prices'], 15);
        add_filter('woocommerce_coupon_get_discount_amount', [__CLASS__, 'floor'], 50, 5);
        add_action('wp_footer', [__CLASS__, 'buybox_js'], 85);
        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'menu'], 70);
            add_action('admin_post_omg_pricing_save', [__CLASS__, 'save']);
        }
    }

    /* ---------------- rules ---------------- */

    private static function pro_id(): int
    {
        return class_exists('OMG_PS_Checkout') ? (int) OMG_PS_Checkout::PRO_PRODUCT : 1906;
    }

    /** Back-office and integration requests see the plain prices OMGGamer OS sent. */
    private static function live(): bool
    {
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ((strpos($uri, '/wp-json/') !== false || strpos($uri, 'rest_route=') !== false)
            && preg_match('~(wp-json/|rest_route=/?)(omggamer|wc/v\d|wc-analytics|wc-admin|wp/v2|dokan|mcp)~', $uri)) {
            return false;
        }
        return true;
    }

    private static function eligible($product): bool
    {
        if (!$product instanceof WC_Product || !self::live()) {
            return false;
        }
        $id = $product->get_parent_id() ?: $product->get_id();
        if ($id === self::pro_id() || $product->is_type(['subscription', 'variable-subscription', 'subscription_variation'])) {
            return false;
        }
        return (float) self::settings()['sale'] > 0;
    }

    private static function own_sale($product): bool
    {
        $s = $product->get_sale_price('edit');
        if ($s === '' || $s === null) {
            return false;
        }
        $from = $product->get_date_on_sale_from('edit');
        $to = $product->get_date_on_sale_to('edit');
        $now = time();
        return (!$from || $from->getTimestamp() <= $now) && (!$to || $to->getTimestamp() >= $now);
    }

    public static function sale_of(float $regular): float
    {
        return round($regular * (1 - (float) self::settings()['sale'] / 100), 2);
    }

    public static function pro_of(float $price): float
    {
        return round($price * (1 - (float) self::settings()['pro'] / 100), 2);
    }

    /** Supplier cost + minimum profit, worked out from the regular price and the OS markup. */
    public static function floor_of(float $regular): float
    {
        $s = self::settings();
        return round($regular * (1 + (float) $s['min_profit'] / 100) / (1 + (float) $s['markup'] / 100), 2);
    }

    /** The sale price in the store's base currency, or null when the storewide sale does not apply. */
    private static function storewide($product): ?float
    {
        if (!self::eligible($product) || self::own_sale($product)) {
            return null;
        }
        $reg = $product->get_regular_price('edit');
        return is_numeric($reg) && (float) $reg > 0 ? self::sale_of((float) $reg) : null;
    }

    public static function price($price, $product)
    {
        if (self::$pro_set && $product instanceof WC_Product && self::$pro_set->contains($product)) {
            return $price;
        }
        $sale = self::storewide($product);
        return $sale === null ? $price : (string) $sale;
    }

    public static function sale_price($price, $product)
    {
        $sale = self::storewide($product);
        return $sale === null ? $price : (string) $sale;
    }

    public static function on_sale($on, $product)
    {
        if ($on || !$product instanceof WC_Product || $product->is_type('variable')) {
            return $on;
        }
        return self::storewide($product) !== null ? true : $on;
    }

    public static function hash($hash)
    {
        $hash[] = 'omg-pricing-' . md5(wp_json_encode(self::settings()) . (self::live() ? '1' : '0'));
        return $hash;
    }

    /* ---------------- PRO ---------------- */

    /** PRO price in base currency for a product or variation: sale price less the PRO %. */
    public static function pro_base(int $id): ?float
    {
        if ((float) self::settings()['pro'] <= 0) {
            return null;
        }
        $type = get_post_type($id);
        $ids = [$id];
        if ($type === 'product' && ($p = wc_get_product($id)) && $p->is_type('variable')) {
            $ids = $p->get_children();
        }
        $best = null;
        foreach ($ids as $cid) {
            $c = get_post_custom($cid);
            $reg = $c['_regular_price'][0] ?? '';
            if (!is_numeric($reg) || (float) $reg <= 0) {
                continue;
            }
            $sale = $c['_sale_price'][0] ?? '';
            $price = is_numeric($sale) && (float) $sale > 0 && (float) $sale < (float) $reg ? (float) $sale : self::sale_of((float) $reg);
            $pro = self::pro_of($price);
            $best = $best === null ? $pro : min($best, $pro);
        }
        return $best;
    }

    /** "omg_pro_price" (cards, buy box) follows the automatic PRO price. */
    public static function pro_meta($value, $object_id, $meta_key, $single)
    {
        if ($meta_key !== 'omg_pro_price' || self::$reading || !self::live() || (float) self::settings()['pro'] <= 0) {
            return $value;
        }
        if ((int) $object_id === self::pro_id()) {
            return $value;
        }
        self::$reading = true;
        $pro = self::pro_base((int) $object_id);
        self::$reading = false;
        return $pro === null ? $value : [(string) $pro];
    }

    /** In the cart: PRO price while the membership is in the cart or the shopper is a member. */
    public static function member_prices($cart): void
    {
        if ((is_admin() && !wp_doing_ajax()) || !$cart || (float) self::settings()['pro'] <= 0) {
            return;
        }
        $pro_id = self::pro_id();
        $has = function_exists('wcs_user_has_subscription') && is_user_logged_in() && wcs_user_has_subscription(get_current_user_id(), $pro_id, 'active');
        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] === $pro_id) {
                $has = true;
            }
        }
        if (!$has) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof WC_Product || (int) $item['product_id'] === $pro_id || self::$pro_set->contains($product)) {
                continue;
            }
            $reg = $product->get_regular_price('edit');
            if (!is_numeric($reg) || (float) $reg <= 0) {
                continue;
            }
            $base = self::own_sale($product) ? (float) $product->get_sale_price('edit') : self::sale_of((float) $reg);
            $product->set_price((string) self::pro_of($base));
            self::$pro_set->attach($product);
        }
    }

    /* ---------------- floor ---------------- */

    /** A coupon never takes a line below supplier cost + minimum profit. */
    public static function floor($discount, $discounting_amount, $cart_item, $single, $coupon)
    {
        if (!is_array($cart_item) || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
            return $discount;
        }
        $reg = $cart_item['data']->get_regular_price('edit');
        if (!is_numeric($reg) || (float) $reg <= 0) {
            return $discount;
        }
        $floor = self::floor_of((float) $reg);
        if (class_exists('OMG_Markets') && OMG_Markets::applies()) {
            $c = OMG_Markets::convert($floor);
            $floor = $c ?? $floor;
        }
        $qty = $single ? 1 : max(1, (int) ($cart_item['quantity'] ?? 1));
        $max = max(0, (float) $discounting_amount - $floor * $qty);
        return min((float) $discount, $max);
    }

    /* ---------------- product page: PRO line follows the chosen edition ---------------- */

    public static function buybox_js(): void
    {
        if (!function_exists('is_product') || !is_product() || (float) self::settings()['pro'] <= 0) {
            return;
        }
        $pct = (float) self::settings()['pro'];
        ?>
<script id="omg-pricing-js">
(function(){
  var K = 1 - <?php echo wp_json_encode($pct); ?> / 100;
  function num(t){ var m = String(t || '').replace(/[^\d.,]/g, ''); if (!m) return NaN; if (m.indexOf(',') > -1 && m.indexOf('.') > -1) m = m.replace(/,/g, ''); else m = m.replace(',', '.'); return parseFloat(m); }
  function fmt(like, v){ var s = String(like || ''); var dec = /[.,]\d{2}\b/.test(s) ? 2 : 0; var n = v.toFixed(dec); return /\d/.test(s) ? s.replace(/[\d][\d.,\s]*[\d]|\d/, n) : n; }
  function current(){
    var f = document.querySelector('form.variations_form'), vid = f && f.querySelector('[name=variation_id]');
    var list = []; try { list = JSON.parse(f.getAttribute('data-product_variations') || '[]') || []; } catch (e) {}
    var v = vid && list.filter(function(x){ return String(x.variation_id) === String(vid.value); })[0];
    if (v) return { price: +v.display_price, reg: +v.display_regular_price };
    var big = document.querySelector('.omg-bb-opt:not(.omg-bb-opt-pro) [data-omg-price]'); var r = document.querySelector('.omg-bb-opt:not(.omg-bb-opt-pro) [data-omg-regular]');
    return big ? { price: num(big.textContent), reg: num(r && r.textContent) || num(big.textContent) } : null;
  }
  var busy = false;
  function paint(){
    if (busy) return; var bb = document.querySelector('.omg-bb'); if (!bb) return;
    var c = current(); if (!c || !(c.price > 0)) return;
    var pro = Math.round(c.price * K * 100) / 100, opt = bb.querySelector('.omg-bb-opt-pro'); if (!opt) return;
    var ref = bb.querySelector('[data-omg-price]'); var like = ref ? ref.textContent : '';
    busy = true;
    try {
      if (opt.hidden) opt.hidden = false;
      var set = function(sel, txt){ bb.querySelectorAll(sel).forEach(function(e){ if (e.textContent !== txt) e.textContent = txt; }); };
      set('[data-omg-pro]', fmt(like, pro));
      set('[data-omg-pro-save]', fmt(like, Math.round((c.price - pro) * 100) / 100));
      if (c.reg > pro) set('[data-omg-pro-off]', '−' + Math.round((1 - pro / c.reg) * 100) + '%');
      var fp = document.getElementById('omg-dg-of-pro'); if (fp) { var h = '<strong>' + fmt(like, pro) + '</strong> with PRO'; if (fp.innerHTML !== h) fp.innerHTML = h; }
    } finally { busy = false; }
  }
  function start(){ paint(); var bb = document.querySelector('.omg-bb'); if (bb && window.MutationObserver) new MutationObserver(function(){ clearTimeout(paint.t); paint.t = setTimeout(paint, 40); }).observe(bb, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'class'] });
    document.addEventListener('change', function(e){ if (e.target && e.target.closest && e.target.closest('form.variations_form, #omg-dg-ed')) setTimeout(paint, 60); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }

    /* ---------------- settings page ---------------- */

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'OMG pricing', 'OMG pricing', 'manage_woocommerce', 'omg-pricing', [__CLASS__, 'page']);
    }

    public static function save(): void
    {
        check_admin_referer('omg_pricing_save');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        $in = wp_unslash($_POST);
        $clamp = static fn ($v, $lo, $hi) => max($lo, min($hi, round((float) $v, 3)));
        update_option(self::OPTION, [
            'sale' => $clamp($in['sale'] ?? 10, 0, 90),
            'pro' => $clamp($in['pro'] ?? 10, 0, 90),
            'markup' => $clamp($in['markup'] ?? 57.8, 0, 1000),
            'min_profit' => $clamp($in['min_profit'] ?? 15, 0, 500),
        ]);
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::get_transient_version('product', true);
        }
        wp_safe_redirect(admin_url('admin.php?page=omg-pricing&saved=1'));
        exit;
    }

    public static function page(): void
    {
        $s = self::settings();
        $ex = 100.0;
        $reg = $ex * (1 + $s['markup'] / 100);
        $sale = self::sale_of($reg);
        $pro = self::pro_of($sale);
        $both = round($pro * 0.9, 2);
        ?>
<div class="wrap">
  <h1>OMG pricing &amp; discounts</h1>
  <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('omg_pricing_save'); ?><input type="hidden" name="action" value="omg_pricing_save">
    <table class="form-table">
      <tr><th>Storewide sale %</th><td><input type="number" step="0.1" min="0" max="90" name="sale" value="<?php echo esc_attr($s['sale']); ?>"> <p class="description">Every product is shown with its regular price crossed out and this % off. Products with their own sale price keep it. 0 = off.</p></td></tr>
      <tr><th>PRO discount %</th><td><input type="number" step="0.1" min="0" max="90" name="pro" value="<?php echo esc_attr($s['pro']); ?>"> <p class="description">PRO price = sale price less this %. Applied in the cart while the PRO membership is in the cart or the shopper is a member. Coupons apply on top.</p></td></tr>
      <tr><th>OS markup %</th><td><input type="number" step="0.1" min="0" name="markup" value="<?php echo esc_attr($s['markup']); ?>"> <p class="description">The same Markup % as the pricing rule in OMGGamer OS, so the store can work out the supplier cost from the regular price.</p></td></tr>
      <tr><th>Minimum profit %</th><td><input type="number" step="0.1" min="0" name="min_profit" value="<?php echo esc_attr($s['min_profit']); ?>"> <p class="description">No coupon can take a line below supplier cost + this %.</p></td></tr>
    </table>
    <?php submit_button('Save'); ?>
  </form>
  <h2>Example: supplier cost <?php echo esc_html(number_format($ex, 2)); ?></h2>
  <table class="widefat striped" style="max-width:640px">
    <tr><td>Regular price (shown crossed out)</td><td><?php echo esc_html(number_format(round($reg, 2), 2)); ?></td></tr>
    <tr><td>Storewide sale price</td><td><?php echo esc_html(number_format($sale, 2)); ?> (profit <?php echo esc_html(round(($sale / $ex - 1) * 100, 1)); ?>%)</td></tr>
    <tr><td>With PRO <em>or</em> a 10% coupon</td><td><?php echo esc_html(number_format($pro, 2)); ?> (profit <?php echo esc_html(round(($pro / $ex - 1) * 100, 1)); ?>%)</td></tr>
    <tr><td>With PRO <em>and</em> a 10% coupon</td><td><?php echo esc_html(number_format($both, 2)); ?> (profit <?php echo esc_html(round(($both / $ex - 1) * 100, 1)); ?>%)</td></tr>
    <tr><td>Floor (never below)</td><td><?php echo esc_html(number_format(self::floor_of($reg), 2)); ?></td></tr>
  </table>
  <p class="description">Profit is before payment fees.</p>
</div>
        <?php
    }
}

OMG_Pricing::init();
