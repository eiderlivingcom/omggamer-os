<?php
/**
 * Plugin Name: OMGGamer cart page extras
 * Description: Cart page layout: payment tiles and trust strip sit under the cart totals; under the cart items a row of
 *              related products (or the biggest discounts) with quick add.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Cart_Extras
{
    private const COUNT = 10;

    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 85);
    }

    /** Related products of what is in the cart, topped up with the biggest discounts. */
    private static function picks(): array
    {
        $pro = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
        $in = [];
        foreach (WC()->cart ? WC()->cart->get_cart() : [] as $item) {
            $in[] = (int) $item['product_id'];
        }
        $exclude = array_merge($in, [$pro]);
        $ids = [];
        foreach ($in as $pid) {
            if ($pid === $pro) {
                continue;
            }
            foreach (wc_get_related_products($pid, 12, $exclude) as $r) {
                $ids[$r] = true;
            }
        }
        $ok = static function ($p) use ($exclude) {
            return $p instanceof WC_Product && $p->is_visible() && $p->is_in_stock() && !in_array($p->get_id(), $exclude, true) && (float) $p->get_price() > 0;
        };
        $out = [];
        foreach (array_keys($ids) as $id) {
            $p = wc_get_product($id);
            if ($ok($p)) {
                $out[$id] = $p;
            }
            if (count($out) >= self::COUNT) {
                return array_values($out);
            }
        }
        // Top up with the deepest discounts.
        $pool = wc_get_products(['status' => 'publish', 'limit' => 60, 'orderby' => 'date', 'order' => 'DESC', 'exclude' => $exclude, 'visibility' => 'catalog', 'stock_status' => 'instock']);
        usort($pool, static fn ($a, $b) => self::off($b) <=> self::off($a));
        foreach ($pool as $p) {
            if (count($out) >= self::COUNT) {
                break;
            }
            if (!isset($out[$p->get_id()]) && $ok($p)) {
                $out[$p->get_id()] = $p;
            }
        }
        return array_values($out);
    }

    private static function off(WC_Product $p): float
    {
        if ($p->is_type('variable')) {
            $reg = (float) $p->get_variation_regular_price('min');
            $now = (float) $p->get_variation_price('min');
        } else {
            $reg = (float) $p->get_regular_price();
            $now = (float) $p->get_price();
        }
        return $reg > 0 && $now > 0 && $now < $reg ? 1 - $now / $reg : 0.0;
    }

    private static function card(WC_Product $p): string
    {
        $img = wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src('woocommerce_thumbnail');
        $off = (int) round(self::off($p) * 100);
        $var = $p->is_type('variable');
        if ($var) {
            $now = (float) $p->get_variation_price('min', true);
            $reg = (float) $p->get_variation_regular_price('min', true);
        } else {
            $now = (float) wc_get_price_to_display($p);
            $reg = (float) wc_get_price_to_display($p, ['price' => $p->get_regular_price()]);
        }
        $price = ($var ? '<span class="fr">from</span> ' : '') . '<b>' . wc_price($now) . '</b>' . ($reg > $now ? ' <del>' . wc_price($reg) . '</del>' : '');
        $url = esc_url($p->get_permalink());
        $name = esc_html($p->get_name());
        $btn = $var
            ? '<a class="omg-cr-add" href="' . $url . '" aria-label="Choose options for ' . $name . '">Options</a>'
            : '<button type="button" class="omg-cr-add" data-id="' . (int) $p->get_id() . '" aria-label="Add ' . $name . ' to cart"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add</button>';
        return '<li class="omg-cr-card"><a class="im" href="' . $url . '" tabindex="-1" aria-hidden="true"><img src="' . esc_url($img) . '" alt="" loading="lazy" decoding="async">'
            . ($off >= 5 ? '<span class="off">-' . $off . '%</span>' : '') . '</a>'
            . '<a class="nm" href="' . $url . '">' . $name . '</a><div class="pr">' . $price . '</div>' . $btn . '</li>';
    }

    public static function render(): void
    {
        if (!function_exists('is_cart') || !is_cart() || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $picks = self::picks();
        $cards = implode('', array_map([__CLASS__, 'card'], $picks));
        ?>
<template id="omg-cart-recs-tpl"><section class="omg-cr" aria-label="You may also like"><button type="button" class="omg-cr-nav prev" aria-label="Scroll left" hidden><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button><ul class="omg-cr-row"><?php echo $cards; // phpcs:ignore -- escaped above ?></ul><button type="button" class="omg-cr-nav next" aria-label="Scroll right"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button></section></template>
<style id="omg-cart-extras-css">
/* Items card: the table carries the card so a section can sit under it in the same column. */
html body .wc-block-cart .wc-block-cart__main{background:transparent!important;border:0!important;padding:0!important;box-shadow:none!important}
html body .wc-block-cart .wc-block-cart__main>.wc-block-cart-items{background:#171424;border:1px solid #2a2540!important;border-radius:20px;border-collapse:separate;border-spacing:0;padding:6px 20px 8px;width:100%;box-sizing:border-box}
html body .wc-block-cart .wc-block-cart__main>.wc-block-cart-items tr:last-child td{border-bottom:0!important}
/* Sidebar: totals, then payment tiles and the trust strip. */
html body .wc-block-cart__sidebar .omg-cart-pay{justify-content:center;margin:16px 0 0!important}
html body .wc-block-cart__sidebar .omg-cart-trust{display:flex!important;flex-direction:column!important;margin:12px 0 0!important;width:100%!important}
html body .wc-block-cart__sidebar .omg-cart-trust>*{border-left:0!important;border-right:0!important;border-top:1px solid #2a2540!important;justify-content:flex-start!important;width:100%!important;flex:none!important}
html body .wc-block-cart__sidebar .omg-cart-trust>*:first-child{border-top:0!important}
/* Suggestions row. */
.omg-cr{position:relative;margin:20px 0 0}
.omg-cr-row{display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - 36px) / 4);gap:12px;margin:0;padding:2px 2px 6px;list-style:none;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none}
.omg-cr-row::-webkit-scrollbar{display:none}
.omg-cr-card{display:flex;flex-direction:column;gap:6px;min-width:0;padding:10px;border-radius:16px;background:#171424;border:1px solid #2a2540;scroll-snap-align:start;transition:border-color .15s,transform .15s}
.omg-cr-card:hover{border-color:#4b3f78;transform:translateY(-2px)}
.omg-cr-card .im{position:relative;display:block;aspect-ratio:3/4;border-radius:12px;overflow:hidden;background:#1e1a2e}
.omg-cr-card .im img{width:100%;height:100%;object-fit:cover;display:block}
.omg-cr-card .off{position:absolute;top:8px;left:8px;padding:3px 8px;border-radius:8px;background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);color:#fff;font:700 11px/1.2 Poppins,system-ui,sans-serif}
.omg-cr-card .nm{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.6em;color:#f4f2fb!important;font:600 13px/1.3 Poppins,system-ui,sans-serif;text-decoration:none}
.omg-cr-card .nm:hover{color:#c4b5fd!important}
.omg-cr-card .pr{display:flex;align-items:baseline;flex-wrap:wrap;gap:4px 6px;color:#f4f2fb;font:500 12px/1.2 Poppins,system-ui,sans-serif}
.omg-cr-card .pr b{font-size:15px;font-weight:700;color:#f4f2fb}
.omg-cr-card .pr del{color:#77738c;font-size:12px}
.omg-cr-card .pr .fr{color:#a29fb6}
.omg-cr-add{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:34px;margin-top:auto;border:1px solid #2a2540;border-radius:10px;background:#1e1a2e;color:#f4f2fb!important;font:600 13px Poppins,system-ui,sans-serif;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s}
.omg-cr-add:hover{border-color:#8b5cf6;background:rgba(139,92,246,.16)}
.omg-cr-add[disabled]{opacity:.6;cursor:wait}
.omg-cr-add.is-done{background:rgba(16,185,129,.14);border-color:rgba(16,185,129,.4);color:#6ee7b7!important}
.omg-cr-nav{position:absolute;top:38%;z-index:2;display:inline-grid;place-items:center;width:36px;height:36px;border-radius:50%;border:1px solid #2a2540;background:#1e1a2e;color:#f4f2fb;cursor:pointer;box-shadow:0 8px 20px -8px rgba(0,0,0,.7)}
.omg-cr-nav:hover{border-color:#8b5cf6}
.omg-cr-nav.prev{left:-12px}.omg-cr-nav.next{right:-12px}
.omg-cr-nav[hidden]{display:none}
.omg-cr :focus-visible{outline:2px solid #b79cff;outline-offset:2px}
@media (max-width:900px){.omg-cr-row{grid-auto-columns:calc((100% - 24px) / 3)}}
@media (max-width:600px){.omg-cr-row{grid-auto-columns:44%}.omg-cr-nav{display:none!important}html body .wc-block-cart .wc-block-cart__main>.wc-block-cart-items{padding:4px 14px 6px}}
</style>
<script id="omg-cart-extras-js">
(function(){
  function place(){
    var side = document.querySelector('.wc-block-cart__sidebar'), main = document.querySelector('.wc-block-cart__main');
    if (!side || !main) return false;
    var pay = document.querySelector('.omg-cart-pay'), trust = document.querySelector('.omg-cart-trust');
    if (pay && pay.parentNode !== side) side.appendChild(pay);
    if (trust && trust.parentNode !== side) side.appendChild(trust);
    if (!main.querySelector('.omg-cr')) {
      var tpl = document.getElementById('omg-cart-recs-tpl');
      if (tpl && tpl.content.querySelector('.omg-cr-card')) { main.appendChild(tpl.content.cloneNode(true)); wire(main.querySelector('.omg-cr')); }
    }
    return true;
  }
  function wire(sec){
    if (!sec) return;
    var row = sec.querySelector('.omg-cr-row'), prev = sec.querySelector('.prev'), next = sec.querySelector('.next');
    var upd = function(){ prev.hidden = row.scrollLeft < 8; next.hidden = row.scrollLeft + row.clientWidth >= row.scrollWidth - 8; };
    prev.addEventListener('click', function(){ row.scrollBy({left: -row.clientWidth * 0.9, behavior: 'smooth'}); });
    next.addEventListener('click', function(){ row.scrollBy({left: row.clientWidth * 0.9, behavior: 'smooth'}); });
    row.addEventListener('scroll', upd, {passive: true}); window.addEventListener('resize', upd); setTimeout(upd, 50);
    sec.addEventListener('click', function(e){
      var b = e.target.closest && e.target.closest('button.omg-cr-add'); if (!b) return;
      b.disabled = true; var label = b.innerHTML; b.textContent = 'Adding…';
      var f = new FormData(); f.append('product_id', b.getAttribute('data-id')); f.append('quantity', '1');
      fetch('/?wc-ajax=add_to_cart', {method: 'POST', body: f, credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
        if (j && j.error) { b.disabled = false; b.innerHTML = label; if (j.product_url) location.href = j.product_url; return; }
        b.classList.add('is-done'); b.textContent = 'Added';
        if (window.wp && wp.data && wp.data.dispatch('wc/store/cart') && wp.data.dispatch('wc/store/cart').invalidateResolutionForStore) { wp.data.dispatch('wc/store/cart').invalidateResolutionForStore(); }
        setTimeout(function(){ location.reload(); }, 400);
      }).catch(function(){ b.disabled = false; b.innerHTML = label; });
    });
  }
  function start(){
    if (place()) {}
    if (window.MutationObserver) { var mo = new MutationObserver(function(){ place(); }); mo.observe(document.body, {childList: true, subtree: true}); setTimeout(function(){ mo.disconnect(); if (window.MutationObserver) { new MutationObserver(function(){ place(); }).observe(document.querySelector('.wc-block-cart') || document.body, {childList: true, subtree: true}); } }, 8000); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Cart_Extras::init();
