/* OMG cart drawer */
(function ($) {
  'use strict';
  var C = window.OMGDRAWER || {};
  var $d, $panel, nonce = C.nonce, cart = null, busy = false, lastAdded = '';

  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function money(v, p) { p = p || (cart && cart.totals) || {}; var mu = p.currency_minor_unit == null ? 2 : p.currency_minor_unit; var n = (parseInt(v, 10) || 0) / Math.pow(10, mu); return (p.currency_prefix || '$') + n.toFixed(mu) + (p.currency_suffix || ''); }
  function api(method, path, data) {
    return $.ajax({ url: C.api + path, method: method, contentType: 'application/json', data: data ? JSON.stringify(data) : undefined, headers: { Nonce: nonce, 'X-WC-Store-API-Nonce': nonce } })
      .done(function (r, s, x) { var n = x.getResponseHeader('Nonce'); if (n) { nonce = n; } });
  }
  function setCount(n) { $('#omg-cart-count').text(n); $('.omgd-n').text(n ? '(' + n + ')' : ''); }

  function render() {
    var items = (cart && cart.items) || [], t = (cart && cart.totals) || {};
    var n = cart ? cart.items_count : 0; setCount(n);
    var $b = $d.find('.omgd-body');
    if (!items.length) {
      $b.html('<div class="omgd-empty"><div class="art"><svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></div><h3>Your cart is empty</h3><p>Game keys, accounts, gift cards and gear, delivered fast.</p><a href="' + C.shop + '" class="omgd-checkout">Browse the store</a></div>');
      $d.find('.omgd-foot, .omgd-free').prop('hidden', true);
      recs(); return;
    }
    var html = '<ul class="omgd-items">';
    items.forEach(function (it) {
      var p = it.prices || {}, img = it.images && it.images[0] ? (it.images[0].thumbnail || it.images[0].src) : '';
      var vars = (it.variation || []).map(function (v) { return '<span>' + esc(v.value) + '</span>'; }).join('');
      var reg = parseInt(p.regular_price, 10), now = parseInt(p.price, 10);
      var max = it.quantity_limits && it.quantity_limits.maximum ? it.quantity_limits.maximum : 99;
      html += '<li class="omgd-item' + (it.key === lastAdded ? ' is-new' : '') + '" data-key="' + esc(it.key) + '">' +
        '<a class="im" href="' + esc(it.permalink) + '">' + (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '') + '</a>' +
        '<div class="in"><a class="nm" href="' + esc(it.permalink) + '">' + esc($('<div>').html(it.name).text()) + '</a>' + (vars ? '<div class="vr">' + vars + '</div>' : '') +
        '<div class="rw"><div class="qty"><button type="button" class="dec" aria-label="Decrease quantity"' + (it.quantity <= 1 ? ' data-remove="1"' : '') + '>' + (it.quantity <= 1 ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>' : '&minus;') + '</button><span>' + it.quantity + '</span><button type="button" class="inc" aria-label="Increase quantity"' + (it.quantity >= max ? ' disabled' : '') + '>+</button></div>' +
        '<div class="pr">' + (reg > now ? '<del>' + money(reg * it.quantity, p) + '</del>' : '') + '<b>' + money(it.totals ? it.totals.line_total : now * it.quantity, it.totals || p) + '</b></div></div></div>' +
        '<button type="button" class="rm" aria-label="Remove ' + esc($('<div>').html(it.name).text()) + '"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></li>';
    });
    $b.html(html + '</ul>');

    // free game progress (same rule as the cart page)
    var mu = t.currency_minor_unit == null ? 2 : t.currency_minor_unit;
    var sub = (parseInt(t.total_items, 10) || 0) / Math.pow(10, mu), target = C.target || 80, pct = Math.min(100, Math.round(sub / target * 100));
    var $f = $d.find('.omgd-free').prop('hidden', false).toggleClass('is-done', sub >= target);
    $f.find('.txt').html(sub >= target ? '&#127881; You unlocked a <b>free game</b> with this order' : 'Add <b>' + money((target - sub) * Math.pow(10, mu)) + '</b> more for a <b>free game</b>');
    $f.find('.pct').text(pct + '%'); $f.find('.bar i').css('width', pct + '%');

    var saved = items.reduce(function (s, it) { var p = it.prices || {}; var d = (parseInt(p.regular_price, 10) - parseInt(p.price, 10)) * it.quantity; return s + (d > 0 ? d : 0); }, 0);
    var $ft = $d.find('.omgd-foot').prop('hidden', false);
    $ft.find('.save').prop('hidden', !saved).find('.v').text('-' + money(saved));
    $ft.find('.total .v').text(money(t.total_items));
    $ft.find('.omgd-note').text(cart.needs_shipping ? 'Shipping and taxes are calculated at checkout.' : 'Digital items are delivered instantly after payment.');
    recs();
  }

  var recCache = null;
  function recs() {
    var $r = $d.find('.omgd-recs');
    var inCart = ((cart && cart.items) || []).map(function (i) { return i.id; });
    function draw(list) {
      list = list.filter(function (p) { return inCart.indexOf(p.id) < 0 && p.is_purchasable && p.is_in_stock; }).slice(0, 3);
      if (!list.length) { $r.prop('hidden', true); return; }
      $r.find('.list').html(list.map(function (p) {
        var img = p.images && p.images[0] ? (p.images[0].thumbnail || p.images[0].src) : '', pr = p.prices || {};
        var simple = p.type === 'simple';
        return '<div class="rc"><a href="' + esc(p.permalink) + '" class="im">' + (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '') + '</a><a href="' + esc(p.permalink) + '" class="nm">' + esc($('<div>').html(p.name).text()) + '</a><div class="ft"><b>' + (simple ? '' : '<small>From </small>') + money(pr.price, pr) + '</b>' +
          (simple ? '<button type="button" class="add" data-id="' + p.id + '" aria-label="Add to cart">+</button>' : '<a class="add" href="' + esc(p.permalink) + '" aria-label="Choose options">&rsaquo;</a>') + '</div></div>';
      }).join(''));
      $r.prop('hidden', false);
    }
    if (recCache) { draw(recCache); return; }
    api('GET', 'products?per_page=8&on_sale=true&orderby=popularity').done(function (list) { recCache = list || []; draw(recCache); });
  }

  function load() { return api('GET', 'cart').done(function (c) { cart = c; render(); }); }
  function open(added) {
    if (!$d) { return; }
    lastAdded = added || '';
    $d.addClass('is-open').attr('aria-hidden', 'false'); $('html').addClass('omgd-lock');
    $d.find('.omgd-added').prop('hidden', !added);
    setTimeout(function () { $panel.trigger('focus'); }, 50);
    load();
  }
  function close() { if (!$d) { return; } $d.removeClass('is-open').attr('aria-hidden', 'true'); $('html').removeClass('omgd-lock'); }

  function mutate(path, data, $row) {
    if (busy) { return; } busy = true; if ($row) { $row.addClass('is-busy'); }
    api('POST', path, data).done(function (c) { cart = c; lastAdded = ''; render(); $(document.body).trigger('wc_fragment_refresh'); })
      .fail(function (x) { var m = x.responseJSON && x.responseJSON.message; if (m) { $d.find('.omgd-added').prop('hidden', false).addClass('is-err').find('.t').text($('<div>').html(m).text()); } load(); })
      .always(function () { busy = false; if ($row) { $row.removeClass('is-busy'); } });
  }

  $(function () {
    $d = $('#omgd'); if (!$d.length) { $d = null; return; }
    $panel = $d.find('.omgd-panel');
    $d.on('click', '[data-omgd-close]', close);
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && $d.hasClass('is-open')) { close(); } });
    $(document).on('click', '#omg-hdr-cart, a.omg-open-cart', function (e) { if (e.metaKey || e.ctrlKey) { return; } e.preventDefault(); open(); });
    $d.on('click', '.omgd-item .inc', function () { var $r = $(this).closest('.omgd-item'), it = cart.items.filter(function (i) { return i.key === $r.data('key'); })[0]; mutate('cart/update-item', { key: it.key, quantity: it.quantity + 1 }, $r); });
    $d.on('click', '.omgd-item .dec', function () { var $r = $(this).closest('.omgd-item'), it = cart.items.filter(function (i) { return i.key === $r.data('key'); })[0]; if (it.quantity <= 1) { mutate('cart/remove-item', { key: it.key }, $r); } else { mutate('cart/update-item', { key: it.key, quantity: it.quantity - 1 }, $r); } });
    $d.on('click', '.omgd-item .rm', function () { var $r = $(this).closest('.omgd-item'); $r.addClass('is-out'); mutate('cart/remove-item', { key: $r.data('key') }, $r); });
    $d.on('click', '.omgd-recs button.add', function () { var $b = $(this).prop('disabled', true).text('…'); api('POST', 'cart/add-item', { id: +$b.data('id'), quantity: 1 }).done(function (c) { cart = c; var it = c.items.filter(function (i) { return i.id === +$b.data('id'); })[0]; lastAdded = it ? it.key : ''; render(); $d.find('.omgd-added').prop('hidden', false).removeClass('is-err').find('.t').text('Added to your cart'); }).always(function () { $b.prop('disabled', false).text('+'); }); });
    // AJAX add to cart anywhere on the site (shop loops, Bricks, product pages)
    $(document.body).on('added_to_cart', function (e, frag, hash, $btn) { if ($('.omgp').data('buynow')) { return; } open('added'); });
    // keep header count right on first load
    api('GET', 'cart').done(function (c) { cart = c; setCount(c.items_count); if (C.open) { open(C.open); } });
  });
  window.OMGCartDrawer = { open: open, close: close };
})(jQuery);
