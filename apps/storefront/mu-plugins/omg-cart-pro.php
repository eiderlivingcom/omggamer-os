<?php
/**
 * Plugin Name: OMGGamer cart PRO choice and express payment placement
 * Description: Cart page: the same "No PRO / PRO" choice as checkout, above the totals, and "Price not final" under
 *              the total. It follows the cart: PRO in
 *              the cart selects "PRO", otherwise "No PRO"; choosing adds or removes the PRO membership. Express
 *              payment buttons (Apple Pay, Google Pay, Amazon Pay, Link) show only on the checkout page.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Cart_Pro
{
    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'cart_js'], 98);
        add_action('wp_head', [__CLASS__, 'express_css'], 99);
        // Stripe: express buttons only on checkout (product page and cart off), whatever is saved in its settings.
        add_filter('option_woocommerce_stripe_settings', [__CLASS__, 'stripe_locations']);
    }

    public static function stripe_locations($s)
    {
        if (is_array($s)) {
            foreach ($s as $k => $v) {
                if (is_array($v) && preg_match('/button_locations$/', (string) $k)) {
                    $s[$k] = in_array('checkout', $v, true) || !$v ? ['checkout'] : array_values(array_intersect($v, ['checkout']));
                }
            }
        }
        return $s;
    }

    public static function express_css(): void
    {
        if (function_exists('is_checkout') && is_checkout()) {
            return;
        }
        ?>
<style id="omg-express-only-checkout">
/* Express payments (Apple Pay, Google Pay, Amazon Pay, Link) are offered at checkout only. */
.wc-block-cart .wc-block-components-express-payment,.wc-block-cart .wc-block-components-express-payment-continue-rule,
.wc-block-mini-cart__footer .wc-block-components-express-payment,#wc-stripe-express-checkout-element,#wc-stripe-express-checkout-button-separator,
.wc-stripe-product-checkout-container,#wc-stripe-payment-request-wrapper,#wc-stripe-payment-request-button-separator,
.wc-stripe-express-checkout-element,#wc-stripe-amazon-pay-element,.wc-block-components-express-payment__event-buttons{display:none!important}
</style>
        <?php
    }

    public static function cart_js(): void
    {
        if (!function_exists('is_cart') || !is_cart() || !class_exists('OMG_Pro_Offer') || OMG_Pro_Offer::is_member()) {
            return;
        }
        $pro = wc_get_product(OMG_Pro_Offer::PRODUCT);
        if (!$pro) {
            return;
        }
        $conv = static function (float $v): float {
            return class_exists('OMG_Markets') && OMG_Markets::applies() ? (float) (OMG_Markets::convert($v) ?? $v) : $v;
        };
        $monthly = wp_strip_all_tags(wc_price($conv((float) OMG_Pro_Offer::MONTHLY)));
        $first = OMG_Pro_Offer::eligible() ? wp_strip_all_tags(wc_price($conv((float) OMG_Pro_Offer::FIRST))) : '';
        $cfg = [
            'id' => OMG_Pro_Offer::PRODUCT,
            'monthly' => html_entity_decode($monthly),
            'first' => html_entity_decode($first),
            'url' => home_url('/pro/'),
        ];
        ?>
<script id="omg-cart-pro-js">
(function(){
  var P = <?php echo wp_json_encode($cfg); ?>, busy = false;
  function store(){ return window.wp && wp.data && wp.data.select('wc/store/cart'); }
  function items(){ try { var c = store() && store().getCartData(); return (c && c.items) || []; } catch (e) { return []; } }
  function proItem(){ return items().filter(function(i){ return Number(i.id) === Number(P.id); })[0]; }
  function html(has){
    var price = (P.first ? 'First month ' + P.first + ', then ' : '') + P.monthly + '/month, cancel anytime.';
    return '<div class="hd"><b>Choose your <span class="pro">PRO</span> benefit</b><span class="tag' + (has ? '' : ' hot') + '">' + (has ? 'Added' : 'Best value') + '</span></div>' +
      '<div class="seg" role="radiogroup" aria-label="PRO membership">' +
      '<button type="button" role="radio" aria-checked="' + !has + '" class="' + (has ? '' : 'is-on') + '" data-omg-cpro="off">No <span class="pro">PRO</span></button>' +
      '<button type="button" role="radio" aria-checked="' + has + '" class="' + (has ? 'is-on' : '') + '" data-omg-cpro="on"><span class="pro">PRO</span></button></div>' +
      '<p class="sv">' + (has ? 'OMG PRO is in your order: member prices on eligible games. ' : 'PRO members get member prices on eligible games. ') + price + ' <a href="' + P.url + '" target="_blank" rel="noopener">How PRO works</a></p>';
  }
  function paint(){
    var side = document.querySelector('.wc-block-cart__sidebar, .wp-block-woocommerce-cart-totals-block');
    if (!side || !items().length) return;
    var has = !!proItem(), box = document.querySelector('.omg-cart-pro');
    if (!box) { box = document.createElement('div'); box.className = 'omgx-pro omg-cart-pro'; side.insertBefore(box, side.firstChild); box.dataset.has = ''; }
    if (box.dataset.has !== String(has)) { box.dataset.has = String(has); box.classList.toggle('is-on', has); box.innerHTML = html(has); }
  }
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('[data-omg-cpro]'); if (!b || busy) return;
    var want = b.getAttribute('data-omg-cpro') === 'on', it = proItem(), d = wp.data.dispatch('wc/store/cart');
    if (want === !!it) return;
    busy = true;
    Promise.resolve(want ? d.addItemToCart(P.id, 1) : d.removeItemFromCart(it.key)).catch(function(err){ if (err && err.message) window.alert(err.message); }).then(function(){ busy = false; paint(); });
  });
  // Under the cart total: the price can still change at checkout (discount codes, PRO, service fee).
  function notFinal(){
    var tot = document.querySelector('.wc-block-cart .wc-block-components-totals-footer-item');
    if (!tot || tot.parentNode.querySelector('.omg-not-final')) return;
    var n = document.createElement('div'); n.className = 'omg-not-final';
    n.innerHTML = 'Price not final <span class="i" tabindex="0" aria-label="The final price is shown at checkout, after discount codes, PRO and the service fee.">i<span class="tip">The final price is shown at checkout, after discount codes, PRO and the service fee.</span></span>';
    tot.parentNode.insertBefore(n, tot.nextSibling);
  }
  var _paint = paint; paint = function(){ _paint(); notFinal(); };
  function start(){ if (!window.wp || !wp.data) return; wp.data.subscribe(function(){ clearTimeout(start.t); start.t = setTimeout(paint, 40); }); paint(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
<style>.omg-cart-pro{margin:0 0 16px}
.omg-not-final{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin:4px 0 0;color:#8d84a8;font:600 11px/1.2 Poppins,system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase}
.omg-not-final .i{position:relative;display:inline-grid;place-items:center;width:16px;height:16px;border:1.5px solid currentColor;border-radius:50%;font-size:10px;letter-spacing:0;text-transform:none;cursor:help}
.omg-not-final .tip{display:none;position:absolute;right:0;bottom:calc(100% + 8px);width:240px;padding:10px 12px;border-radius:10px;background:#1d1930;border:1px solid #2a2540;color:#d9d6e8;font:400 12px/1.45 Poppins,system-ui,sans-serif;text-transform:none;letter-spacing:0;z-index:5}
.omg-not-final .i:hover .tip,.omg-not-final .i:focus .tip{display:block}</style>
        <?php
    }
}

OMG_Cart_Pro::init();
