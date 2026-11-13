<?php
/**
 * Show and charge prices in the visitor's market currency.
 * Stored prices stay in the base currency; only what is read for display, cart and checkout is converted.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets_Prices
{
    private static bool $reading_meta = false;

    public static function init(): void
    {
        add_filter('woocommerce_currency', [__CLASS__, 'currency'], 99);
        add_filter('wc_get_price_decimals', [__CLASS__, 'decimals'], 99);

        foreach (['price', 'regular_price', 'sale_price'] as $p) {
            add_filter("woocommerce_product_get_{$p}", [__CLASS__, 'price'], 99, 2);
            add_filter("woocommerce_product_variation_get_{$p}", [__CLASS__, 'price'], 99, 2);
            add_filter("woocommerce_variation_prices_{$p}", [__CLASS__, 'price'], 99, 2);
        }
        add_filter('woocommerce_get_variation_prices_hash', [__CLASS__, 'variation_hash'], 99);

        add_filter('woocommerce_coupon_get_amount', [__CLASS__, 'coupon_amount'], 99, 2);
        add_filter('woocommerce_coupon_get_minimum_amount', [__CLASS__, 'amount'], 99);
        add_filter('woocommerce_coupon_get_maximum_amount', [__CLASS__, 'amount'], 99);
        add_filter('woocommerce_package_rates', [__CLASS__, 'shipping'], 99);

        // OMGGamer extras that store a base-currency amount: PRO member price and the free-game target.
        add_filter('get_post_metadata', [__CLASS__, 'pro_price'], 99, 4);
        add_action('wp_enqueue_scripts', [__CLASS__, 'free_game_target'], 999);
        add_action('wp_footer', [__CLASS__, 'chart_symbol'], 5);
        add_filter('rest_post_dispatch', [__CLASS__, 'header'], 99);
        // Card templates print "from <strong>${PRO price}</strong> with PRO" with a typed "$".
        add_filter('bricks/frontend/render_data', [__CLASS__, 'pro_symbol'], 99);
        add_action('send_headers', [__CLASS__, 'header']);
    }

    public static function pro_symbol($html)
    {
        if (!is_string($html) || strpos($html, 'omg-ac-pro-t') === false || !self::active()) {
            return $html;
        }
        $sym = get_woocommerce_currency_symbol(OMG_Markets::currency());
        return preg_replace('~(class="[^"]*omg-ac-pro-t[^"]*"[^>]*>\s*from\s*<strong>)\$~', '${1}' . $sym, $html);
    }

    /** X-OMG-Market: the market used for this response, or "base (reason)". */
    public static function header($response = null)
    {
        $why = OMG_Markets::why_not();
        $v = $why === '' ? OMG_Markets::current() . ' ' . OMG_Markets::currency() : 'base (' . $why . ')';
        if ($response instanceof WP_HTTP_Response) {
            $response->header('X-OMG-Market', $v);
            return $response;
        }
        if (!headers_sent()) {
            header('X-OMG-Market: ' . $v);
        }
        return $response;
    }

    private static function active(): bool
    {
        if (!OMG_Markets::applies()) {
            return false;
        }
        $code = OMG_Markets::current();
        $m = OMG_Markets::market($code);
        return $m['currency'] !== OMG_Markets::base_currency() || (float) ($m['markup'] ?? 0) !== 0.0 || ($m['rounding'] ?? 'none') !== 'none';
    }

    public static function currency($currency)
    {
        return OMG_Markets::applies() ? OMG_Markets::currency() : $currency;
    }

    public static function decimals($dp)
    {
        return OMG_Markets::applies() ? OMG_Markets::decimals(OMG_Markets::currency()) : $dp;
    }

    public static function amount($value)
    {
        if (!self::active()) {
            return $value;
        }
        $c = OMG_Markets::convert($value);
        return $c === null ? $value : $c;
    }

    public static function price($value, $product = null)
    {
        if ($value === '' || $value === null || !self::active()) {
            return $value;
        }
        $c = OMG_Markets::convert($value);
        return $c === null ? $value : (string) $c;
    }

    public static function variation_hash($hash)
    {
        $hash[] = 'omg-market-' . (OMG_Markets::applies() ? OMG_Markets::current() : 'base') . '-' . (OMG_Markets_Rates::data()['date'] ?? '');
        return $hash;
    }

    public static function coupon_amount($amount, $coupon)
    {
        // Percentage coupons stay as they are; fixed amounts are converted.
        if (!$coupon || $coupon->is_type('percent') || $coupon->is_type('recurring_percent') || $coupon->is_type('sign_up_fee_percent')) {
            return $amount;
        }
        return self::amount($amount);
    }

    public static function shipping($rates)
    {
        if (!self::active()) {
            return $rates;
        }
        foreach ($rates as $rate) {
            $rate->set_cost((string) self::amount($rate->get_cost()));
            $rate->set_taxes(array_map([__CLASS__, 'amount'], $rate->get_taxes()));
        }
        return $rates;
    }

    /** Base-currency amounts kept in post meta by OMGGamer: PRO member price and the monthly price history. */
    public static function pro_price($value, $object_id, $meta_key, $single)
    {
        if (!in_array($meta_key, ['omg_pro_price', 'omg_price_history'], true) || self::$reading_meta || !self::active()) {
            return $value;
        }
        self::$reading_meta = true;
        $raw = get_post_meta($object_id, $meta_key, true);
        self::$reading_meta = false;
        if ($meta_key === 'omg_price_history') {
            $h = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (!is_array($h) || !$h) {
                return $value;
            }
            foreach ($h as $k => $v) {
                $c = OMG_Markets::convert($v);
                if ($c === null) {
                    return $value;
                }
                $h[$k] = $c;
            }
            $out = is_array($raw) ? $h : wp_json_encode($h);
            return [$out];
        }
        if ($raw === '' || !is_numeric($raw)) {
            return $value;
        }
        $c = OMG_Markets::convert($raw);
        return $c === null ? $value : [(string) $c];
    }

    /** The price-history chart in the product templates prints a typed "$"; show the market's symbol instead. */
    public static function chart_symbol(): void
    {
        if (!self::active() || !is_singular('product')) {
            return;
        }
        $sym = html_entity_decode(get_woocommerce_currency_symbol(OMG_Markets::currency()));
        ?>
<script id="omg-markets-chart">
(function(){
  var sym = <?php echo wp_json_encode($sym); ?>;
  function fix(root){
    root.querySelectorAll('.omg-ph-stat, .omg-ph-pt b, .omg-ph-tip, .omg-ph-stats *').forEach(function(el){
      el.childNodes.forEach(function(n){ if (n.nodeType === 3 && /\$\s*\d/.test(n.nodeValue)) n.nodeValue = n.nodeValue.replace(/\$(\s*\d)/g, sym + '$1'); });
    });
  }
  function start(){
    var host = document.getElementById('omg-ph'); if (!host) return;
    fix(host);
    if (window.MutationObserver) new MutationObserver(function(){ fix(host); }).observe(host, {childList:true, subtree:true, characterData:true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }

    public static function free_game_target(): void
    {
        if (!self::active() || !wp_script_is('omgps-drawer', 'enqueued') || !class_exists('OMG_PS_Drawer')) {
            return;
        }
        $base = defined('OMG_PS_Drawer::FREE_GAME_TARGET') ? constant('OMG_PS_Drawer::FREE_GAME_TARGET') : null;
        if ($base === null) {
            return;
        }
        $t = OMG_Markets::convert($base);
        if ($t !== null) {
            wp_add_inline_script('omgps-drawer', 'window.OMGDRAWER&&(OMGDRAWER.target=' . wp_json_encode($t) . ');', 'before');
        }
    }
}
