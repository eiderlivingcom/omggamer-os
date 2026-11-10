<?php
/**
 * Plugin Name: OMGGamer Quick Buy (product cards)
 * Description: Adds "Buy now" (straight to checkout) and an add-to-cart button to every OMGGamer product card on hover.
 * Version:     1.0.0
 * Author:      OMGGamer
 *
 * Must-use plugin. Cards are the `a.omg-ac-card` links built in Bricks. Product data for each card is
 * collected while the page renders (the_post) and printed once in the footer; cards added later (filters,
 * sliders, infinite scroll) are looked up through a small REST route. Buttons are placed next to the card
 * link, never inside it, so the markup stays valid.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Quick_Buy
{
    private static $seen = [];

    public static function init()
    {
        add_action('the_post', [__CLASS__, 'collect']);
        add_action('wp_footer', [__CLASS__, 'footer'], 50);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_loaded', [__CLASS__, 'before_add'], 15);   // WooCommerce adds to cart at priority 20
        add_action('wp_loaded', [__CLASS__, 'after_add'], 25);
    }

    public static function collect($post)
    {
        if ($post instanceof WP_Post && $post->post_type === 'product' && count(self::$seen) < 300) {
            self::$seen[$post->ID] = true;
        }
    }

    /** Cheapest in-stock, purchasable variation of a variable product. */
    private static function best_variation($product)
    {
        $best = null;
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if (!$v || !$v->is_purchasable() || !$v->is_in_stock() || $v->get_status() !== 'publish' || $v->get_price() === '') {
                continue;
            }
            if ($best === null || (float) $v->get_price() < (float) $best->get_price()) {
                $best = $v;
            }
        }
        return $best;
    }

    public static function data($product_id)
    {
        $p = wc_get_product($product_id);
        if (!$p || $p->get_status() !== 'publish') {
            return null;
        }
        $checkout = wc_get_checkout_url();
        if ($p->is_type('variable')) {
            $v = self::best_variation($p);
            if (!$v) {
                return ['ok' => false];
            }
            $args = ['add-to-cart' => $p->get_id(), 'variation_id' => $v->get_id(), 'quantity' => 1, 'omg_buy' => 1];
            foreach ($v->get_variation_attributes() as $k => $val) {
                $args[$k] = $val;
            }
            return ['ok' => true, 'add' => $v->get_id(), 'buy' => add_query_arg(array_map('rawurlencode', $args), $checkout)];
        }
        if (!$p->is_type('simple') || !$p->is_purchasable() || !$p->is_in_stock()) {
            return ['ok' => false];
        }
        return [
            'ok' => true,
            'add' => $p->get_id(),
            'buy' => add_query_arg(['add-to-cart' => $p->get_id(), 'quantity' => 1, 'omg_buy' => 1], $checkout),
        ];
    }

    private static function map(array $ids)
    {
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $d = self::data($id);
            if ($d) {
                $out[untrailingslashit(get_permalink($id))] = $d;
            }
        }
        return $out;
    }

    public static function routes()
    {
        register_rest_route('omg/v1', '/quick-buy', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'args' => ['urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 60]],
            'callback' => function (WP_REST_Request $r) {
                $ids = [];
                foreach ((array) $r->get_param('urls') as $url) {
                    $id = url_to_postid(esc_url_raw($url));
                    if ($id && get_post_type($id) === 'product') {
                        $ids[] = $id;
                    }
                }
                return rest_ensure_response(self::map($ids));
            },
        ]);
    }

    /** Buy now must not add a second copy when the item is already in the cart. */
    public static function before_add()
    {
        if (empty($_GET['omg_buy']) || empty($_REQUEST['add-to-cart']) || !function_exists('WC') || !WC()->cart) {
            return;
        }
        $pid = absint($_REQUEST['add-to-cart']);
        $vid = absint($_REQUEST['variation_id'] ?? 0);
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) $item['product_id'] === $pid && (int) ($item['variation_id'] ?? 0) === $vid) {
                unset($_REQUEST['add-to-cart'], $_GET['add-to-cart'], $_POST['add-to-cart']);
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
        }
    }

    /** After the add, land on a clean checkout URL so a refresh does not add the item again. */
    public static function after_add()
    {
        if (empty($_GET['omg_buy']) || !function_exists('wc_get_notices')) {
            return;
        }
        $errors = wc_get_notices('error');
        $target = $errors ? get_permalink(absint($_GET['add-to-cart'] ?? 0)) : wc_get_checkout_url();
        if (!$errors) {
            wc_clear_notices(); // no "added to cart" notice on the checkout page
        }
        wp_safe_redirect($target ?: wc_get_checkout_url());
        exit;
    }

    public static function footer()
    {
        if (is_admin() || !function_exists('wc_get_checkout_url') || is_checkout() || is_cart()) {
            return;
        }
        $map = self::map(array_keys(self::$seen));
        $config = [
            'map' => $map,
            'rest' => esc_url_raw(rest_url('omg/v1/quick-buy')),
            'ajax' => esc_url_raw(add_query_arg('wc-ajax', 'add_to_cart', home_url('/'))),
        ];
        ?>
<style id="omg-quick-buy-css">
.omg-qb-wrap{position:relative;display:flex;flex-direction:column;min-width:0}
.omg-qb-wrap>.omg-ac-card{flex:1 1 auto}
.omg-qb{position:absolute;left:10px;right:10px;top:var(--omg-qb-top,auto);bottom:var(--omg-qb-bottom,auto);display:flex;gap:8px;z-index:5;opacity:0;transform:translateY(6px);pointer-events:none;transition:opacity .18s ease,transform .18s ease}
.omg-qb-wrap:hover .omg-qb,.omg-qb-wrap:focus-within .omg-qb{opacity:1;transform:none;pointer-events:auto}
.omg-qb button,.omg-qb a{height:42px;border:0;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font:600 14px/1 Poppins,system-ui,sans-serif;cursor:pointer;text-decoration:none;box-shadow:0 6px 18px rgba(13,6,25,.25)}
.omg-qb .omg-qb-add{width:46px;flex:0 0 46px;background:rgba(13,6,25,.82);color:#fff;backdrop-filter:blur(6px)}
.omg-qb .omg-qb-add:hover{background:#0D0619}
.omg-qb .omg-qb-buy{flex:1 1 auto;background:linear-gradient(120deg,#7C3AED,#C026D3);color:#fff}
.omg-qb .omg-qb-buy:hover{filter:brightness(1.08)}
.omg-qb svg{width:19px;height:19px;flex:none}
.omg-qb .omg-qb-add[aria-busy=true]{opacity:.6;cursor:wait}
.omg-qb .omg-qb-add.is-done{background:#16A34A}
@media (hover:none){.omg-qb{opacity:1;transform:none;pointer-events:auto}.omg-qb button,.omg-qb a{height:38px;font-size:13px}.omg-qb .omg-qb-add{width:40px;flex-basis:40px}}
</style>
<script id="omg-quick-buy-js">
(function(){
  var C = <?php echo wp_json_encode($config); ?>;
  var map = C.map || {};
  var icoCart = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/><path d="M14 4.5v5M11.5 7h5"/></svg>';
  var icoBag = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></svg>';
  function key(href){ try { var u = new URL(href, location.href); return (u.origin + u.pathname).replace(/\/$/, ''); } catch(e){ return ''; } }
  function place(wrap){
    var bar = wrap.querySelector(':scope > .omg-qb'); var cover = wrap.querySelector('.omg-ac-cover');
    if (!bar) return;
    if (cover) { wrap.style.setProperty('--omg-qb-top', (cover.offsetTop + cover.offsetHeight - bar.offsetHeight - 10) + 'px'); }
    else { wrap.style.setProperty('--omg-qb-bottom', '10px'); }
  }
  function addToCart(btn, id){
    if (btn.getAttribute('aria-busy') === 'true') return;
    btn.setAttribute('aria-busy','true');
    var body = new URLSearchParams(); body.set('product_id', id); body.set('quantity', '1');
    fetch(C.ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()})
      .then(function(r){ return r.json(); })
      .then(function(res){
        btn.removeAttribute('aria-busy');
        if (!res || res.error) { if (res && res.product_url) location.href = res.product_url; return; }
        btn.classList.add('is-done'); setTimeout(function(){ btn.classList.remove('is-done'); }, 1600);
        if (window.jQuery) { window.jQuery(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, window.jQuery(btn)]); }
        document.dispatchEvent(new CustomEvent('omg:added-to-cart', {detail: res}));
      })
      .catch(function(){ btn.removeAttribute('aria-busy'); });
  }
  function enhance(card, d){
    if (!d || !d.ok || card.closest('.omg-qb-wrap')) return;
    var wrap = document.createElement('div'); wrap.className = 'omg-qb-wrap';
    card.parentNode.insertBefore(wrap, card); wrap.appendChild(card);
    var bar = document.createElement('div'); bar.className = 'omg-qb';
    var title = (card.querySelector('.omg-ac-title') || {}).textContent || 'this product';
    var add = document.createElement('button'); add.type = 'button'; add.className = 'omg-qb-add';
    add.setAttribute('aria-label', 'Add ' + title.trim() + ' to cart'); add.innerHTML = icoCart;
    add.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); addToCart(add, d.add); });
    var buy = document.createElement('a'); buy.className = 'omg-qb-buy'; buy.href = d.buy; buy.rel = 'nofollow';
    buy.innerHTML = icoBag + '<span>Buy now</span>'; buy.setAttribute('aria-label', 'Buy ' + title.trim() + ' now');
    bar.appendChild(add); bar.appendChild(buy); wrap.appendChild(bar);
    place(wrap);
    if (window.ResizeObserver) { new ResizeObserver(function(){ place(wrap); }).observe(wrap); }
    var img = card.querySelector('img'); if (img) img.addEventListener('load', function(){ place(wrap); });
  }
  var pending = {};
  function scan(root){
    var cards = (root || document).querySelectorAll('a.omg-ac-card[href]:not([data-omg-qb])');
    var missing = [];
    cards.forEach(function(card){
      card.setAttribute('data-omg-qb', '1');
      var k = key(card.href);
      if (k in map) { enhance(card, map[k]); }
      else if (!pending[k]) { pending[k] = []; missing.push(k); pending[k].push(card); }
      else { pending[k].push(card); }
    });
    for (var i = 0; i < missing.length; i += 50) {
      var chunk = missing.slice(i, i + 50);
      var qs = chunk.map(function(u){ return 'urls[]=' + encodeURIComponent(u + '/'); }).join('&');
      fetch(C.rest + (C.rest.indexOf('?') > -1 ? '&' : '?') + qs, {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(res){
          chunk.forEach(function(k){ var d = res && res[k]; map[k] = d || {ok:false}; (pending[k] || []).forEach(function(c){ enhance(c, d); }); delete pending[k]; });
        }).catch(function(){});
    }
  }
  function start(){
    scan(document);
    if (window.MutationObserver) {
      var t; new MutationObserver(function(){ clearTimeout(t); t = setTimeout(function(){ scan(document); }, 120); })
        .observe(document.body, {childList:true, subtree:true});
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Quick_Buy::init();
