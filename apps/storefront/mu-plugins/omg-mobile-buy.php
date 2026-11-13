<?php
/**
 * Plugin Name: OMGGamer mobile buy bar
 * Description: On phones and tablets (up to 1024px) the product page's sticky bar shows "Sold by", the price
 *              options from the buy box (PRO price / featured offer) as selectable tiles, and a full-width
 *              Add to cart button. Replaces the compact "Buy now" bar there; desktop is unchanged.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Mobile_Buy
{
    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 70);
    }

    private static function seller(int $product_id): string
    {
        $author = (int) get_post_field('post_author', $product_id);
        if (!$author) {
            return '';
        }
        if (function_exists('dokan_get_store_info')) {
            $info = dokan_get_store_info($author);
            if (!empty($info['store_name'])) {
                return (string) $info['store_name'];
            }
        }
        return (string) get_the_author_meta('display_name', $author);
    }

    public static function render(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $cfg = ['seller' => self::seller((int) get_queried_object_id())];
        ?>
<style id="omg-mobile-buy-css">
.omg-mb{display:none}
@media (max-width:1024px){
  body.omg-mb-on .omgm{display:none!important}
  .omg-mb{display:block;position:fixed;left:0;right:0;bottom:0;z-index:9990;background:#fff;border-top:1px solid #ECE9F3;box-shadow:0 -14px 34px -16px rgba(13,6,25,.28);padding:10px 16px calc(12px + env(safe-area-inset-bottom));transform:translateY(110%);transition:transform .25s ease;font-family:Poppins,system-ui,sans-serif;color:#0D0619}
  .omg-mb.is-on{transform:none}
  .omg-mb-in{max-width:720px;margin:0 auto}
  .omg-mb-seller{text-align:center;font:600 12px/1 Poppins,system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#8A82A3;padding:2px 0 14px}
  .omg-mb-seller b{color:#0D0619;font-weight:700}
  .omg-mb-opts{display:grid;grid-template-columns:repeat(var(--n,2),minmax(0,1fr));gap:10px;margin-bottom:12px}
  .omg-mb-opt{position:relative;display:flex;align-items:center;gap:10px;min-height:54px;padding:12px 14px;border:1.5px solid #E2DDEE;border-radius:14px;background:#fff;cursor:pointer;text-align:left;font:inherit;color:inherit}
  .omg-mb-opt[aria-checked=true]{border-color:#7C3AED;background:#FAF7FF;box-shadow:0 0 0 3px rgba(124,58,237,.12)}
  .omg-mb-opt:focus-visible{outline:2px solid #7C3AED;outline-offset:2px}
  .omg-mb-dot{flex:none;width:20px;height:20px;border-radius:50%;border:2px solid #B9B1CF;display:grid;place-items:center}
  .omg-mb-opt[aria-checked=true] .omg-mb-dot{border-color:#7C3AED}
  .omg-mb-opt[aria-checked=true] .omg-mb-dot::after{content:"";width:10px;height:10px;border-radius:50%;background:#7C3AED}
  .omg-mb-price{font:700 19px/1.1 Poppins,system-ui,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .omg-mb-tag{position:absolute;top:0;right:12px;transform:translateY(-55%);background:#fff;padding:0 6px;font:600 10.5px/1.4 Poppins,system-ui,sans-serif;letter-spacing:.04em;white-space:nowrap;color:#8A82A3;text-transform:uppercase}
  .omg-mb-opt.is-pro .omg-mb-tag{text-transform:none;letter-spacing:0;font-size:11.5px;color:#16A34A}
  .omg-mb-opt.is-pro .omg-mb-tag b{color:#7C3AED}
  .omg-mb-opt[aria-checked=true] .omg-mb-tag{background:linear-gradient(#fff 55%,#FAF7FF 55%)}
  .omg-mb-cart{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:52px;border:0;border-radius:14px;background:linear-gradient(120deg,#7C3AED 0%,#C026D3 100%);color:#fff;font:600 16px/1 Poppins,system-ui,sans-serif;cursor:pointer;box-shadow:0 10px 22px -10px rgba(124,58,237,.7)}
  .omg-mb-cart:active{filter:brightness(.96)}
  .omg-mb-cart svg{width:20px;height:20px}
  body.omg-mb-on.omg-mb-pad{padding-bottom:var(--omg-mb-h,170px)!important}
}
@media (max-width:360px){.omg-mb-price{font-size:17px}.omg-mb-opt{padding:12px 10px;gap:8px}}
</style>
<script id="omg-mobile-buy-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>;
  var MQ = window.matchMedia('(max-width: 1024px)');
  function text(el){ return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
  function vis(el){ return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function num(s){ var m = String(s || '').replace(/[^\d.,]/g, ''); if (!m) return NaN; if (m.indexOf(',') > -1 && m.indexOf('.') > -1) m = m.replace(/,/g, ''); else m = m.replace(',', '.'); return parseFloat(m); }
  var icoCart = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/></svg>';

  function options(){
    var bb = document.querySelector('.omg-bb');
    var out = [];
    if (bb) {
      [].slice.call(bb.querySelectorAll('.omg-bb-opt')).forEach(function(o){
        if (o.hidden || !vis(o) && o.closest('[hidden]')) return;
        var input = o.querySelector('input');
        var p = text(o.querySelector('[data-omg-pro]') || o.querySelector('[data-omg-price]'));
        if (!p) return;
        out.push({ el: o, input: input, pro: o.classList.contains('omg-bb-opt-pro'), price: p, on: !!(input && input.checked) });
      });
    }
    if (!out.length) {
      var pp = document.querySelector('#omg-dg-price-slot .price, .summary .price, p.price');
      var now = pp && (pp.querySelector('ins .amount') || pp.querySelector('.amount'));
      if (now) out.push({ el: null, input: null, pro: false, price: text(now), on: true });
    }
    if (out.length && !out.some(function(o){ return o.on; })) out[0].on = true;
    return out;
  }
  function cartButton(){
    return [].slice.call(document.querySelectorAll('form.cart .single_add_to_cart_button, [data-omg-cart]')).filter(function(b){ return !b.closest('.omg-mb'); })[0] || null;
  }
  function anchor(){
    return [].slice.call(document.querySelectorAll('.omg-bb [data-omg-buy], .omg-bb .omg-buy-now, .omgp-buynow, form.cart .single_add_to_cart_button')).filter(vis)[0] || null;
  }

  function start(){
    if (!document.querySelector('.omg-bb, form.cart')) return;
    var bar = document.createElement('div'); bar.className = 'omg-mb'; bar.setAttribute('aria-hidden', 'true');
    bar.innerHTML = '<div class="omg-mb-in">' + (C.seller ? '<div class="omg-mb-seller">Sold by <b></b></div>' : '') +
      '<div class="omg-mb-opts" role="radiogroup" aria-label="Choose a price"></div>' +
      '<button type="button" class="omg-mb-cart">' + icoCart + '<span>Add to cart</span></button></div>';
    if (C.seller) bar.querySelector('.omg-mb-seller b').textContent = C.seller;
    document.body.appendChild(bar);
    document.body.classList.add('omg-mb-on');
    var box = bar.querySelector('.omg-mb-opts');

    function paint(){
      var opts = options();
      var pro = opts.filter(function(o){ return o.pro; })[0], reg = opts.filter(function(o){ return !o.pro; })[0];
      var save = pro && reg ? Math.round((1 - num(pro.price) / num(reg.price)) * 100) : NaN;
      box.style.setProperty('--n', Math.max(1, opts.length));
      box.innerHTML = '';
      opts.forEach(function(o){
        var b = document.createElement('button'); b.type = 'button'; b.className = 'omg-mb-opt' + (o.pro ? ' is-pro' : '');
        b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', o.on ? 'true' : 'false');
        var tag = o.pro ? (save > 0 ? 'Save ' + save + '% with <b>PRO</b>' : 'With <b>PRO</b>') : (opts.length > 1 ? 'Featured offer' : '');
        b.innerHTML = (tag ? '<span class="omg-mb-tag">' + tag + '</span>' : '') + '<span class="omg-mb-dot" aria-hidden="true"></span><span class="omg-mb-price"></span>';
        b.querySelector('.omg-mb-price').textContent = o.price;
        b.setAttribute('aria-label', o.price + (o.pro ? ' with PRO' : ''));
        b.addEventListener('click', function(){
          if (o.input && !o.input.checked) { o.input.checked = true; o.input.dispatchEvent(new Event('change', { bubbles: true })); }
          paint();
        });
        box.appendChild(b);
      });
      document.documentElement.style.setProperty('--omg-mb-h', (bar.offsetHeight + 8) + 'px');
    }
    paint();
    var watch = document.querySelector('.omg-bb') || document.querySelector('form.cart');
    if (watch && window.MutationObserver) new MutationObserver(function(){ clearTimeout(paint.t); paint.t = setTimeout(paint, 80); })
      .observe(watch, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class', 'hidden', 'checked'] });
    if (watch) watch.addEventListener('change', function(){ setTimeout(paint, 30); });

    var a = anchor(), inView = false;
    function update(){
      var on = MQ.matches && !inView && !document.documentElement.classList.contains('omgd-lock');
      bar.classList.toggle('is-on', on); bar.setAttribute('aria-hidden', on ? 'false' : 'true');
      document.body.classList.toggle('omg-mb-pad', MQ.matches);
      if (on) document.documentElement.style.setProperty('--omg-mb-h', (bar.offsetHeight + 8) + 'px');
    }
    if (a && 'IntersectionObserver' in window) new IntersectionObserver(function(en){ inView = en[0].isIntersecting; update(); }, { threshold: 0 }).observe(a);
    (MQ.addEventListener ? MQ.addEventListener('change', update) : MQ.addListener(update));
    if (window.MutationObserver) new MutationObserver(update).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    update();

    bar.querySelector('.omg-mb-cart').addEventListener('click', function(){
      var btn = cartButton(); if (!btn) return;
      var needsChoice = btn.disabled || btn.classList.contains('disabled') || btn.classList.contains('wc-variation-selection-needed');
      if (needsChoice) { (document.querySelector('#pa_game-edition, .variations, form.cart') || btn).scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      btn.click();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Mobile_Buy::init();
