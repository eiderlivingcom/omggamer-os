<?php
/**
 * Plugin Name: OMGGamer storefront fixes
 * Description: Product page (buy box, section order, specs, seller card), PRO membership in the cart and its
 *              member prices, the cart page's focused header, and line-item images in the product-page ratio.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Storefront_Fixes
{
    private static function pro_id(): int
    {
        return class_exists('OMG_PS_Checkout') ? (int) OMG_PS_Checkout::PRO_PRODUCT : 1906;
    }

    public static function init(): void
    {
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'member_prices'], 20);
        add_filter('bricks/active_templates', [__CLASS__, 'cart_no_header_footer'], 31);
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_body_open', [__CLASS__, 'cart_topbar'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'cart_assets'], 41);
        add_action('wp_head', [__CLASS__, 'css'], 100);
        add_action('wp_footer', [__CLASS__, 'js'], 80);
    }

    /* ---------------- PRO ---------------- */

    public static function is_member(): bool
    {
        return function_exists('wcs_user_has_subscription') && is_user_logged_in()
            && wcs_user_has_subscription(get_current_user_id(), self::pro_id(), 'active');
    }

    /** The stored PRO price in the store's base currency (read past any display filter). */
    private static function raw_pro(int $id): ?float
    {
        if (!$id) {
            return null;
        }
        $all = get_post_custom($id);
        $v = $all['omg_pro_price'][0] ?? '';
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /**
     * Member prices: while the PRO membership is in the cart (or the shopper is an active member), an item with a
     * PRO price lower than its normal price is charged the PRO price. A variation's own PRO price wins over the
     * product's.
     */
    public static function member_prices($cart): void
    {
        if ((is_admin() && !wp_doing_ajax()) || !$cart) {
            return;
        }
        // The automatic PRO price (omg-pricing.php) takes over when it is switched on.
        if (class_exists('OMG_Pricing') && (float) OMG_Pricing::settings()['pro'] > 0) {
            return;
        }
        $pro_id = self::pro_id();
        $has = false;
        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] === $pro_id) {
                $has = true;
                break;
            }
        }
        if (!$has && !self::is_member()) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] === $pro_id || empty($item['data'])) {
                continue;
            }
            $product = $item['data'];
            $pro = self::raw_pro((int) ($item['variation_id'] ?? 0)) ?? self::raw_pro((int) $item['product_id']);
            $now = (float) $product->get_price('edit');
            if ($pro !== null && $pro < $now) {
                $product->set_price($pro);
            }
        }
    }

    /* ---------------- Cart page: same focused bar as checkout ---------------- */

    private static function on_cart(): bool
    {
        return function_exists('is_cart') && is_cart() && !(function_exists('bricks_is_builder') && bricks_is_builder());
    }

    public static function cart_no_header_footer($active)
    {
        if (is_array($active) && self::on_cart()) {
            $active['header'] = 0;
            $active['footer'] = 0;
        }
        return $active;
    }

    public static function body_class($c)
    {
        if (self::on_cart()) {
            $c[] = 'omgx-on';
            $c[] = 'omgx-cart';
        }
        return $c;
    }

    public static function cart_assets(): void
    {
        if (self::on_cart() && defined('OMG_PS_URL')) {
            wp_enqueue_style('omgx', OMG_PS_URL . 'assets/checkout.css', [], defined('OMG_PS_VERSION') ? OMG_PS_VERSION : null);
        }
    }

    public static function cart_topbar(): void
    {
        if (!self::on_cart()) {
            return;
        }
        $steps = [['Cart', 'now'], ['Checkout', 'next'], ['Delivery', 'next']];
        echo '<header class="omgx-bar"><div class="omgx-bar-in">';
        echo '<a class="omgx-logo" href="' . esc_url(home_url('/')) . '" aria-label="OMGGamer home"><span class="mk">OMG</span><span class="wm">OMG<b>Gamer</b></span></a>';
        echo '<ol class="omgx-steps" aria-label="Checkout progress">';
        foreach ($steps as $i => $st) {
            $in = '<span class="n">' . ($i + 1) . '</span><span class="l">' . esc_html($st[0]) . '</span>';
            if ($st[0] === 'Checkout' && WC()->cart && !WC()->cart->is_empty()) {
                $in = '<a href="' . esc_url(wc_get_checkout_url()) . '">' . $in . '</a>';
            }
            echo '<li class="is-' . esc_attr($st[1]) . '"' . ($st[1] === 'now' ? ' aria-current="step"' : '') . '>' . $in . '</li>';
            if ($i < 2) {
                echo '<li class="ln" aria-hidden="true"></li>';
            }
        }
        echo '</ol><div class="omgx-loc"><span class="flag" aria-hidden="true"></span>' . esc_html(get_woocommerce_currency()) . ' · English</div></div></header>';
    }

    /* ---------------- CSS ---------------- */

    public static function css(): void
    {
        ?>
<style id="omg-storefront-fixes-css">
/* Product page: sections in the main column. Offers come right after the region/edition picker,
   or before the trailer when a product has no picker. */
html body #omg-dg #omg-dg-main{display:flex;flex-direction:column}
html body #omg-dg #omg-dg-main>*{order:20}
html body #omg-dg #omg-dg-main>#omg-dg-hero{order:1}
html body #omg-dg #omg-dg-main>#omg-dg-warning{order:2}
html body #omg-dg #omg-dg-main>#omg-dg-ed{order:3}
html body #omg-dg #omg-dg-main>#omg-dg-offers{order:4}
html body #omg-dg #omg-dg-main>#omg-dg-trailer{order:5}
html body #omg-dg #omg-dg-main>#omg-dg-gallery{order:6}
html body #omg-dg #omg-dg-main>#omg-dg-tabs-slot{order:7}
html body #omg-dg #omg-dg-related-slot{display:flex;flex-direction:column}
html body #omg-dg #omg-dg-related-slot>#omg-dg-related-head{order:-1}

/* Seller profile card: hidden for now (offers stay). */
#omg-dg-seller,.omg-dg-seller-card{display:none!important}

/* Specs: icon (the row's ::before), label and value start on one line; a guide link sits under the value. */
html body #omg-dg .omg-dg-spec{align-items:start!important}
html body #omg-dg .omg-dg-spec::before{align-self:start!important;margin:0!important}
html body #omg-dg .omg-dg-spec>.omg-dg-spec-k,html body #omg-dg .omg-dg-spec>.omg-dg-spec-v{align-self:baseline!important;margin-top:9px!important;margin-bottom:0!important}
html body #omg-dg .omg-dg-spec>.omg-spec-link{align-self:start!important;margin-top:2px!important}

/* Buy box: the same layout on every product. Price options first, then trust badges, PRO note, free-game bar. */
html body #omg-dg #omg-dg-buy>.omg-bb{order:-2}
html body #omg-dg #omg-dg-buy>.omg-bb-compare{order:-1}
html body #omg-dg #omg-dg-buy>#omg-dg-trust{order:1}
html body #omg-dg #omg-dg-buy>#omg-dg-pro{order:2}
html body #omg-dg #omg-dg-buy>.omg-bb-free{order:3}
html body #omg-dg .omg-bb-off:not(:empty){background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F)!important;color:#fff!important;box-shadow:0 6px 14px rgba(192,38,211,.25)!important}
/* One price (no PRO price): big price with the discount badge at the top right, never a full-width bar. */
html body #omg-dg #omg-dg-buy .omg-bb-opts:has(.omg-bb-opt-pro[hidden]) .omg-bb-opt{grid-template-columns:minmax(0,1fr) auto!important;align-items:start!important;column-gap:14px}
html body #omg-dg #omg-dg-buy .omg-bb-opts:has(.omg-bb-opt-pro[hidden]) .omg-bb-off:not(:empty){grid-column:2;grid-row:1;align-self:start;justify-self:end;width:auto!important;margin:18px 0 0!important;position:static!important}
html body #omg-dg #omg-dg-buy .omg-bb-opts:has(.omg-bb-opt-pro[hidden]) .omg-bb-body{grid-column:1;grid-row:1}

/* Cart drawer: "Added to your cart" sits under the title; item covers use the product-page ratio. */
.omgd-head .omgd-added{margin:8px 0 2px!important;display:inline-flex}
.omgd-head .omgd-added[hidden]{display:none!important}
.omgd-item .im{width:66px!important;height:88px!important;flex:none}
.omgd-item .im img{width:100%!important;height:100%!important;object-fit:cover}

/* Checkout summary: covers in the product-page ratio; with PRO in the order there is no "No thanks" choice. */
.omgx-items .im{width:72px!important;height:96px!important}
@media (max-width:640px){.omgx-items .im{width:60px!important;height:80px!important}}
.omgx-pro.is-on .seg{display:none!important}
.omgx-pro.is-on .sv{margin-top:4px}

/* Cart page (block cart) covers. */
.wc-block-cart-item__image img,.wc-block-cart-items .wc-block-cart-item__image img{aspect-ratio:3/4;object-fit:cover;width:100%;height:auto}
.wc-block-cart-item__image{width:72px}
</style>
        <?php
    }

    /* ---------------- JS ---------------- */

    public static function js(): void
    {
        if (is_admin()) {
            return;
        }
        $cfg = [
            'proId' => self::pro_id(),
            'member' => self::is_member(),
            'ajax' => esc_url_raw(add_query_arg('wc-ajax', 'add_to_cart', home_url('/'))),
            'cart' => esc_url_raw(rest_url('wc/store/v1/cart')),
        ];
        ?>
<script id="omg-storefront-fixes-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>;
  /* "Added to your cart" right under "Your cart" in the drawer. */
  function moveAdded(){ var a = document.querySelector('.omgd-added'), h = document.querySelector('.omgd-head h2'); if (a && h && a.previousElementSibling !== h) h.insertAdjacentElement('afterend', a); }
  /* PRO price chosen in the buy box: put the PRO membership in the cart too, then carry on (Buy now / Add to cart). */
  var bypass = false;
  function proChosen(){ var o = document.querySelector('.omg-bb .omg-bb-opt-pro'); if (!o || o.hidden) return false; var i = o.querySelector('input'); return !!(i && i.checked); }
  function proInCart(){ return fetch(C.cart, {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(c){ return (c.items || []).some(function(i){ return i.id === C.proId; }); }).catch(function(){ return false; }); }
  function addPro(){ var b = new URLSearchParams(); b.set('product_id', String(C.proId)); b.set('quantity', '1'); return fetch(C.ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:b.toString()}).catch(function(){}); }
  document.addEventListener('click', function(e){
    if (bypass || C.member) return;
    var t = e.target.closest && e.target.closest('[data-omg-buy], [data-omg-cart], .omg-mb-cart, .omgm-buy');
    if (!t || !proChosen()) return;
    var btn = document.querySelector('form.cart .single_add_to_cart_button');
    if (btn && (btn.disabled || btn.classList.contains('disabled') || btn.classList.contains('wc-variation-selection-needed'))) return;
    e.preventDefault(); e.stopImmediatePropagation();
    t.setAttribute('aria-busy', 'true');
    proInCart().then(function(has){ return has ? null : addPro(); }).then(function(){
      t.removeAttribute('aria-busy'); bypass = true; try { t.click(); } finally { bypass = false; }
    });
  }, true);
  /* Card rating tag that has no value prints its raw name ({woo_...}): hide it. */
  function cleanRatings(){ document.querySelectorAll('.omg-ac-rating').forEach(function(r){ if (/\{[a-z_]+\}/.test(r.textContent)) r.style.display = 'none'; }); }
  function start(){ moveAdded(); cleanRatings(); if (window.MutationObserver) new MutationObserver(function(){ clearTimeout(cleanRatings.t); cleanRatings.t = setTimeout(cleanRatings, 150); }).observe(document.body, {childList:true, subtree:true}); if (window.MutationObserver && document.getElementById('omgd')) new MutationObserver(moveAdded).observe(document.getElementById('omgd'), {childList:true, subtree:true}); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Storefront_Fixes::init();
