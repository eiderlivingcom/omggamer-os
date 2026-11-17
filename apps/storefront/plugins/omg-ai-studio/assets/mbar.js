/* OMG sticky buy bar for phones and tablets (product pages) */
(function ($) {
  'use strict';
  var MQ = window.matchMedia('(max-width: 1024px)');
  function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
  function vis(el) { return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  $(function () {
    if (!document.body.classList.contains('single-product') && !document.querySelector('.omgp, .omg-bb')) { return; }
    function buyBtn() {
      return [].concat(
        [].slice.call(document.querySelectorAll('.omg-bb [data-omg-buy]')),
        [].slice.call(document.querySelectorAll('.omgp-buynow')),
        [].slice.call(document.querySelectorAll('form.cart .single_add_to_cart_button'))
      ).filter(vis)[0] || null;
    }
    function price() {
      var bb = document.querySelector('.omg-bb');
      if (bb) {
        var opt = bb.querySelector('.omg-bb-opt.is-active') || bb.querySelector('.omg-bb-opt:not([hidden])');
        var now = opt && (opt.querySelector('[data-omg-price]') || opt.querySelector('[data-omg-pro]'));
        var reg = opt && opt.querySelector('[data-omg-regular]');
        var off = opt && opt.querySelector('.omg-bb-off');
        return { now: text(now), reg: text(reg), off: text(off) };
      }
      var vp = document.querySelector('.omgp-atc .woocommerce-variation-price .price');
      if (vp && vis(vp)) { var ins = vp.querySelector('ins') || vp; var del = vp.querySelector('del'); return { now: text(ins.querySelector('.amount') || ins), reg: text(del), off: '' }; }
      var op = document.querySelector('.omgp-price .now');
      if (op) { return { now: text(op), reg: text(document.querySelector('.omgp-sub del')), off: '' }; }
      var pp = document.querySelector('.summary .price, p.price');
      return { now: text(pp && (pp.querySelector('ins .amount') || pp.querySelector('.amount') || pp)), reg: text(pp && pp.querySelector('del')), off: '' };
    }
    var title = text(document.querySelector('.omgp-title, h1')) || document.title;
    var og = document.querySelector('meta[property="og:image"]');
    var imgEl = document.querySelector('.omgp-slides figure img, .woocommerce-product-gallery img');
    var img = og && og.content ? { src: og.content } : (imgEl ? { src: imgEl.currentSrc || imgEl.src } : null);
    var bar = document.createElement('div');
    bar.className = 'omgm';
    bar.setAttribute('aria-hidden', 'true');
    bar.innerHTML = '<div class="omgm-in">' + (img ? '<img class="omgm-img" alt="" src="' + img.src + '">' : '') +
      '<div class="omgm-t"><span class="omgm-name"></span><span class="omgm-p"><b class="omgm-now"></b><del class="omgm-reg"></del><em class="omgm-off"></em></span></div>' +
      '<button type="button" class="omgm-buy">Buy now</button></div>';
    document.body.appendChild(bar);
    bar.querySelector('.omgm-name').textContent = title;
    function paint() {
      var p = price();
      bar.querySelector('.omgm-now').textContent = p.now;
      bar.querySelector('.omgm-reg').textContent = p.reg && p.reg !== p.now ? p.reg : '';
      bar.querySelector('.omgm-off').textContent = p.off;
      bar.classList.toggle('no-price', !p.now);
    }
    paint();
    var watch = document.querySelector('.omg-bb, .omgp-buy, .summary') || document.body;
    new MutationObserver(function () { clearTimeout(paint.t); paint.t = setTimeout(paint, 80); }).observe(watch, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class', 'hidden'] });

    var btn = buyBtn(), inView = true;
    function update() {
      var on = MQ.matches && btn && !inView && !document.documentElement.classList.contains('omgd-lock');
      bar.classList.toggle('is-on', !!on); bar.setAttribute('aria-hidden', on ? 'false' : 'true');
      document.body.classList.toggle('omgm-pad', !!(MQ.matches && btn));
    }
    if (btn && 'IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { inView = en[0].isIntersecting; update(); }, { threshold: 0 }).observe(btn);
    }
    (MQ.addEventListener ? MQ.addEventListener('change', update) : MQ.addListener(update));
    new MutationObserver(update).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    update();

    bar.querySelector('.omgm-buy').addEventListener('click', function () {
      btn = buyBtn() || btn; if (!btn) { return; }
      var needsChoice = btn.disabled || btn.classList.contains('disabled') || btn.classList.contains('wc-variation-selection-needed');
      if (needsChoice) { btn.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
      btn.click();
    });
  });
})(jQuery);
