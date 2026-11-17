/* OMG checkout: numbered steps + live order summary on top of the WooCommerce checkout block */
(function () {
  'use strict';
  var C = window.OMGX || {}, D = window.wp && window.wp.data;
  var STORE = 'wc/store/cart';
  var mount = null, last = '', busy = false, couponOpen = false;

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function pick(o) { for (var i = 1; i < arguments.length; i++) { if (o && o[arguments[i]] !== undefined) { return o[arguments[i]]; } } return undefined; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function plain(h) { var d = document.createElement('div'); d.innerHTML = h || ''; return d.textContent; }
  function money(v, cur) {
    cur = cur || {}; var mu = pick(cur, 'currency_minor_unit', 'currencyMinorUnit'); mu = mu == null ? 2 : mu;
    var n = (parseInt(v, 10) || 0) / Math.pow(10, mu);
    return (pick(cur, 'currency_prefix', 'currencyPrefix') || '$') + n.toFixed(mu) + (pick(cur, 'currency_suffix', 'currencySuffix') || '');
  }
  function cart() { try { return D.select(STORE).getCartData(); } catch (e) { return null; } }
  function act() { return D.dispatch(STORE); }

  /* ---------- steps: numbers, titles, wording ---------- */
  var TITLES = { 'Contact information': 'Add an email address', 'Contact': 'Add an email address', 'Shipping address': 'Delivery address', 'Shipping options': 'Delivery method', 'Payment options': 'Choose a payment method', 'Payment': 'Choose a payment method' };
  function steps() {
    var root = $('.wc-block-checkout'); if (!root) { return; }
    var physical = !!$('#shipping-fields, .wc-block-checkout__shipping-fields', root);
    document.body.classList.toggle('omgx-physical', physical);
    var n = 0;
    $$('.wc-block-components-checkout-step', root).forEach(function (st) {
      if (!st.offsetParent && st.offsetHeight === 0) { return; }
      n++;
      var t = $('.wc-block-components-checkout-step__title', st); if (!t) { return; }
      var txt = t.getAttribute('data-omgx-src') || t.textContent.trim();
      if (!t.getAttribute('data-omgx-src')) { t.setAttribute('data-omgx-src', txt); }
      var nice = TITLES[txt] || (txt === 'Billing address' && !physical ? 'Your details' : txt);
      if (t.textContent !== nice) { t.textContent = nice; }
      var h = $('.wc-block-components-checkout-step__heading', st);
      var pill = h && $('.omgx-pill', h);
      if (h && !pill) { pill = document.createElement('span'); pill.className = 'omgx-pill'; h.insertBefore(pill, h.firstChild); }
      if (pill && pill.textContent !== 'Step ' + n) { pill.textContent = 'Step ' + n; }
      if (txt.indexOf('Contact') === 0) {
        var d = $('.wc-block-components-checkout-step__description', st);
        var want = 'Your order will be delivered to this email address.';
        if (d && d.textContent !== want) { d.textContent = want; }
      }
    });
  }

  /* ---------- summary ---------- */
  function render(force) {
    var c = cart(); if (!c || !c.items) { return; }
    var side = $('.wc-block-checkout__sidebar'); if (!side) { return; }
    if (!mount || !side.contains(mount)) { mount = document.createElement('section'); mount.className = 'omgx-sum'; mount.setAttribute('aria-label', 'Order summary'); side.insertBefore(mount, side.firstChild); force = true; }
    var key = JSON.stringify([c.items.map(function (i) { return [i.key, i.quantity, i.totals && pick(i.totals, 'line_total')]; }), c.totals, c.coupons, couponOpen, busy]);
    if (!force && key === last) { return; } last = key;
    var t = c.totals || {}, items = c.items, count = pick(c, 'itemsCount', 'items_count') || 0;
    var hasPro = items.some(function (i) { return i.id === C.proId; });
    var proSave = 0;
    items.forEach(function (i) {
      var pp = C.pro && C.pro[i.id]; if (pp == null) { return; }
      var mu = pick(i.prices || {}, 'currency_minor_unit') || 2, now = parseInt(pick(i.prices || {}, 'price'), 10) / Math.pow(10, mu);
      if (now > pp) { proSave += (now - pp) * i.quantity; }
    });
    var h = '<h2 class="omgx-sum-h">Order summary</h2><div class="omgx-card">';
    if (!C.proMember) {
      h += '<div class="omgx-pro' + (hasPro ? ' is-on' : '') + '"><div class="hd"><b>Choose your <span class="pro">PRO</span> benefit</b>' + (hasPro ? '<span class="tag">Added</span>' : '<span class="tag hot">Best value</span>') + '</div>' +
        '<div class="seg" role="radiogroup" aria-label="PRO membership"><button type="button" role="radio" aria-checked="' + (hasPro ? 'false' : 'true') + '" class="' + (hasPro ? '' : 'is-on') + '" data-omgx-pro="off">No <span class="pro">PRO</span></button><button type="button" role="radio" aria-checked="' + (hasPro ? 'true' : 'false') + '" class="' + (hasPro ? 'is-on' : '') + '" data-omgx-pro="on"><span class="pro">PRO</span></button></div>' +
        '<p class="sv">' + (hasPro ? 'OMG PRO is in your order: ' + (C.proFirst ? money(Math.round(C.proFirst * 100)) + ' for the first month, then ' : '') + money(Math.round((C.proMonthly || C.proPrice || 4.99) * 100)) + '/month, cancel anytime. Promo codes do not apply to PRO.' : 'PRO members get member prices, up to <b>' + money(Math.round(proSave * 100)) + '</b> less on items like these. ' + (C.proFirst ? 'First month ' + money(Math.round(C.proFirst * 100)) + ', then ' : '') + money(Math.round((C.proMonthly || C.proPrice || 4.99) * 100)) + '/month, cancel anytime.') + ' <a href="' + esc(C.proUrl) + '" target="_blank" rel="noopener">How PRO works</a></p></div>';
    }
    h += '<ul class="omgx-items">';
    items.forEach(function (i) {
      var p = i.prices || {}, img = i.images && i.images[0] ? (i.images[0].thumbnail || i.images[0].src) : '';
      var ql = pick(i, 'quantityLimits', 'quantity_limits') || {}, max = pick(ql, 'maximum') || 99, editable = pick(ql, 'editable') !== false;
      var vars = (i.variation || []).map(function (v) { return esc(plain(v.value)); }).join(' · ');
      var type = (C.types && C.types[i.id]) || (i.id === C.proId ? 'Membership' : 'Item');
      var reg = parseInt(pick(p, 'regular_price'), 10) * i.quantity, line = parseInt(pick(i.totals || {}, 'line_total'), 10);
      h += '<li data-key="' + esc(i.key) + '"><a class="im" href="' + esc(i.permalink) + '" target="_blank" rel="noopener">' + (img ? '<img src="' + esc(img) + '" alt="">' : '') + '</a><div class="in">' +
        '<div class="top"><a class="nm" href="' + esc(i.permalink) + '" target="_blank" rel="noopener">' + esc(plain(i.name)) + '</a><span class="ty">' + esc(type) + '</span></div>' +
        (vars ? '<div class="vr">' + vars + '</div>' : '') + '<div class="sold">Sold by <b>OMG Gamer</b></div>' +
        '<div class="ctl"><button type="button" class="rm" aria-label="Remove item"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>' +
        (editable ? '<button type="button" class="dec" aria-label="Decrease"' + (i.quantity <= 1 ? ' disabled' : '') + '>&minus;</button><span class="q">' + i.quantity + '</span><button type="button" class="inc" aria-label="Increase"' + (i.quantity >= max ? ' disabled' : '') + '>+</button>' : '<span class="q">' + i.quantity + '</span>') +
        '<span class="pr">' + (reg > line ? '<del>' + money(reg, p) + '</del>' : '') + money(line, i.totals || p) + '</span></div></div></li>';
    });
    h += '</ul>';
    // coupon
    var coupons = c.coupons || [];
    h += '<div class="omgx-coupon">' + coupons.map(function (cp) { return '<span class="chip">' + esc(cp.code) + ' <button type="button" data-omgx-rmc="' + esc(cp.code) + '" aria-label="Remove code">&times;</button></span>'; }).join('') +
      (couponOpen ? '<form class="cf"><input type="text" placeholder="Discount code" aria-label="Discount code" autocomplete="off"><button type="submit">Apply</button></form><p class="ce" hidden></p>' : '<button type="button" class="ct">Have a discount code?</button>') + '</div>';
    // totals
    var dsc = parseInt(pick(t, 'total_discount'), 10) || 0, shp = parseInt(pick(t, 'total_shipping'), 10) || 0, fee = parseInt(pick(t, 'total_fees'), 10) || 0, tax = parseInt(pick(t, 'total_tax'), 10) || 0;
    var needsShip = pick(c, 'needsShipping', 'needs_shipping');
    h += '<dl class="omgx-tot"><div><dt>Subtotal (' + count + ' item' + (count === 1 ? '' : 's') + ')</dt><dd>' + money(pick(t, 'total_items'), t) + '</dd></div>';
    if (dsc) { h += '<div class="g"><dt>Discount</dt><dd>&minus;' + money(dsc, t) + '</dd></div>'; }
    if (needsShip) { h += '<div><dt>Shipping</dt><dd>' + (shp ? money(shp, t) : (pick(c, 'shippingRates', 'shipping_rates') || []).length ? 'Free' : 'Calculated with address') + '</dd></div>'; }
    (c.fees || []).forEach(function (f) { h += '<div><dt>' + esc(f.name) + '</dt><dd>' + money(pick(f.totals || {}, 'total'), t) + '</dd></div>'; });
    if (tax) { h += '<div><dt>Taxes</dt><dd>' + money(tax, t) + '</dd></div>'; }
    h += '<div class="prot"><dt><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.6 8 11 4.6-1.4 8-6 8-11V5z"/></svg>OMG Buyer Protection</dt><dd><span class="in">Included</span></dd></div></dl>';
    h += '<div class="omgx-total"><span>Total</span><div><b>' + money(pick(t, 'total_price'), t) + '</b></div></div>';
    h += '</div><p class="omgx-legal">By placing the order, I agree to the <a href="' + esc(C.terms) + '" target="_blank" rel="noopener">Terms of Service</a> and <a href="' + esc(C.privacy) + '" target="_blank" rel="noopener">Privacy Policy</a>. ' + (needsShip ? 'Physical items ship to your delivery address.' : 'Digital items are delivered instantly to your email and account.') + '</p>';
    mount.innerHTML = h;
    mount.classList.toggle('is-busy', busy);
    var mt = $('.omgx-mtotal'); if (mt) { mt.textContent = money(pick(t, 'total_price'), t); }
  }

  function run(p) {
    busy = true; render(true);
    return Promise.resolve(p).catch(function (e) { var m = e && (e.message || (e.data && e.data.message)); if (m) { window.alert(plain(m)); } }).then(function () { busy = false; render(true); });
  }

  document.addEventListener('click', function (e) {
    if (!mount || !mount.contains(e.target)) { return; }
    var li = e.target.closest('li[data-key]'), c = cart(), it = li && c && c.items.filter(function (i) { return i.key === li.getAttribute('data-key'); })[0];
    if (e.target.closest('.inc') && it) { run(act().changeCartItemQuantity(it.key, it.quantity + 1)); }
    else if (e.target.closest('.dec') && it) { run(act().changeCartItemQuantity(it.key, Math.max(1, it.quantity - 1))); }
    else if (e.target.closest('.rm') && it) { run(act().removeItemFromCart(it.key)); }
    else if (e.target.closest('.ct')) { couponOpen = true; render(true); var inp = $('.omgx-coupon input', mount); if (inp) { inp.focus(); } }
    else if (e.target.closest('[data-omgx-rmc]')) { run(act().removeCoupon(e.target.closest('[data-omgx-rmc]').getAttribute('data-omgx-rmc'))); }
    else if (e.target.closest('[data-omgx-pro]')) {
      var want = e.target.closest('[data-omgx-pro]').getAttribute('data-omgx-pro') === 'on';
      var pro = c && c.items.filter(function (i) { return Number(i.id) === Number(C.proId); })[0];
      if (want && !pro) { run(act().addItemToCart(C.proId, 1)); } else if (!want && pro) { run(act().removeItemFromCart(pro.key)); }
    }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('.omgx-coupon .cf'); if (!f) { return; }
    e.preventDefault(); var code = $('input', f).value.trim(); if (!code) { return; }
    busy = true;
    Promise.resolve(act().applyCoupon(code)).then(function () { couponOpen = false; }, function (err) { var p = $('.omgx-coupon .ce', mount); couponOpen = true; busy = false; render(true); p = $('.omgx-coupon .ce', mount); if (p) { p.hidden = false; p.textContent = plain((err && err.message) || 'This code could not be applied.'); } })
      .then(function () { busy = false; render(true); });
  });

  function mobileBar() {
    var main = $('.wc-block-checkout__main'); if (!main || $('.omgx-mbar')) { return; }
    var b = document.createElement('button'); b.type = 'button'; b.className = 'omgx-mbar';
    b.innerHTML = '<span>Order summary</span><b class="omgx-mtotal"></b><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
    b.addEventListener('click', function () { if (mount) { mount.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
    main.parentNode.insertBefore(b, main);
  }

  /* WooCommerce's on-load totals request fails without a billing address on digital orders; that notice is noise. */
  function quiet() {
    try {
      var n = D.select('core/notices');
      ['wc/cart', 'wc/checkout', 'default'].forEach(function (ctx) {
        n.getNotices(ctx).forEach(function (x) { if (/billing_address/.test(x.content || '')) { D.dispatch('core/notices').removeNotice(x.id, ctx); } });
      });
    } catch (e) {}
  }
  function tick() { quiet(); steps(); render(false); mobileBar(); }
  function start() {
    if (!D || !D.select) { return; }
    var t = null;
    D.subscribe(function () { clearTimeout(t); t = setTimeout(tick, 60); });
    var host = $('.wp-block-woocommerce-checkout') || document.body;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(tick, 80); }).observe(host, { childList: true, subtree: true });
    tick();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
