<?php
/**
 * Plugin Name: OMGGamer buy button edge
 * Description: A bright line in the brand gradient runs around the edge of the main purchase buttons (Buy now,
 *              Proceed to checkout, Place order). Card quick-buy buttons run it on hover only. Off for reduced motion.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', static function (): void {
    if (is_admin() || (function_exists('bricks_is_builder') && bricks_is_builder())) {
        return;
    }
    ?>
<style id="omg-edge-css">
@property --omg-edge-a{syntax:"<angle>";inherits:false;initial-value:0deg}
.omg-edge-host{position:relative!important;isolation:isolate;overflow:visible!important}
.omg-edge{position:absolute;inset:-2px;border-radius:inherit;padding:2px;pointer-events:none;z-index:2;
  background:conic-gradient(from var(--omg-edge-a),transparent 0deg 250deg,rgba(124,58,237,.0) 250deg,#7C3AED 290deg,#C026D3 320deg,#ff4f9a 345deg,#fff 356deg,transparent 360deg);
  -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask:linear-gradient(#000 0 0) content-box exclude,linear-gradient(#000 0 0);
  filter:drop-shadow(0 0 4px rgba(224,17,95,.75)) drop-shadow(0 0 10px rgba(192,38,211,.45));
  animation:omg-edge-run 2.4s linear infinite}
.omg-edge::after{content:"";position:absolute;inset:0;border-radius:inherit}
.omg-qb-buy>.omg-edge{animation-play-state:paused;opacity:0;transition:opacity .2s}
.omg-qb-buy:hover>.omg-edge,.omg-qb-buy:focus-visible>.omg-edge,.omg-ac:hover .omg-qb-buy>.omg-edge{animation-play-state:running;opacity:1}
@keyframes omg-edge-run{to{--omg-edge-a:360deg}}
@media (prefers-reduced-motion:reduce){.omg-edge{animation:none;background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);opacity:.55}}
</style>
<script id="omg-edge-js">
(function(){
  var SEL = '.omg-buy-now, .omg-qb-buy, .omg-mb-cart, .omgm-buy, .wc-block-cart__submit-button, .wc-block-components-checkout-place-order-button, #place_order, .omgd-checkout';
  function add(el){
    if (!el || el.querySelector(':scope > .omg-edge') || el.offsetParent === null && !el.closest('.omg-ac')) return;
    var cs = getComputedStyle(el); if (cs.position === 'static') el.classList.add('omg-edge-host'); else el.style.isolation = 'isolate';
    var s = document.createElement('span'); s.className = 'omg-edge'; s.setAttribute('aria-hidden', 'true'); el.appendChild(s);
  }
  function scan(){ document.querySelectorAll(SEL).forEach(add); }
  var t; function soon(){ clearTimeout(t); t = setTimeout(scan, 80); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
  if (window.MutationObserver) new MutationObserver(soon).observe(document.body, {childList: true, subtree: true});
})();
</script>
    <?php
}, 95);
