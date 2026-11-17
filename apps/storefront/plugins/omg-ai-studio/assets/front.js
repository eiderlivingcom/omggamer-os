/* OMG product page (hardware & accessory) */
(function ($) {
  'use strict';
  $(function () {
    var $p = $('.omgp');
    if (!$p.length) { return; }

    /* gallery */
    var $figs = $p.find('.omgp-slides figure'), $th = $p.find('.omgp-thumbs button'), cur = 0;
    function go(i) {
      if (!$figs.length) { return; }
      cur = (i + $figs.length) % $figs.length;
      $figs.removeClass('is-on').eq(cur).addClass('is-on');
      $th.removeClass('is-on').eq(cur).addClass('is-on');
      $p.find('.omgp-count b').text(cur + 1);
    }
    $th.on('click', function () { go(+$(this).data('i')); });
    $p.find('.omgp-nav.prev').on('click', function () { go(cur - 1); });
    $p.find('.omgp-nav.next').on('click', function () { go(cur + 1); });
    var x0 = null;
    $p.find('.omgp-stage').on('touchstart', function (e) { x0 = e.originalEvent.touches[0].clientX; }).on('touchend', function (e) {
      if (x0 === null) { return; } var dx = e.originalEvent.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { go(cur + (dx < 0 ? 1 : -1)); } x0 = null;
    });

    /* tabs */
    function tab(t) { $p.find('.omgp-tabbar button').removeClass('is-on').filter('[data-tab="' + t + '"]').addClass('is-on'); $p.find('.omgp-pane').removeClass('is-on').filter('[data-pane="' + t + '"]').addClass('is-on'); }
    $p.on('click', '.omgp-tabbar button', function () { tab($(this).data('tab')); });
    $p.on('click', '.omgp-to-reviews', function (e) { e.preventDefault(); tab('rv'); $('html,body').animate({ scrollTop: $p.find('.omgp-tabs').offset().top - 90 }, 300); });
    if (location.hash === '#reviews' || location.hash.indexOf('#comment') === 0) { tab('rv'); }

    /* buy now + cart icon */
    var $form = $p.find('.omgp-atc form.cart');
    var $btn = $form.find('.single_add_to_cart_button');
    if ($btn.length) {
      $btn.addClass('omgp-cartbtn').attr('aria-label', 'Add to cart').attr('title', 'Add to cart');
      var $buy = $('<button type="submit" class="omgp-buynow">Buy now</button>');
      $btn.before($buy);
      $buy.on('click', function () {
        if ($btn.is('.disabled, :disabled')) { return; }
        if (!$form.find('input[name=omg_buy_now]').length) { $form.append('<input type="hidden" name="omg_buy_now" value="1">'); }
        if (!$form.find('input[name=add-to-cart]').length) { $form.append('<input type="hidden" name="add-to-cart" value="' + ($btn.val() || $p.data('product')) + '">'); }
        $p.data('buynow', 1);
      });
      $btn.on('click', function () { $form.find('input[name=omg_buy_now]').remove(); if (!$form.hasClass('variations_form')) { $form.find('input[type=hidden][name=add-to-cart]').remove(); } $p.data('buynow', 0); });
      $(document.body).on('added_to_cart', function () { if ($p.data('buynow')) { location.href = $p.data('checkout'); } });
      $form.on('show_variation hide_variation', function (e) { $buy.prop('disabled', e.type === 'hide_variation'); });
    }

    /* colour swatches for colour attributes */
    $form.find('table.variations select').each(function () {
      var $sel = $(this), name = ($sel.attr('name') || '') + ' ' + ($sel.attr('id') || '');
      if (!/colou?r/i.test(name)) { return; }
      var $w = $('<div class="omgp-swatches" role="radiogroup"></div>');
      $sel.find('option').each(function () {
        if (!this.value) { return; }
        var c = this.text.toLowerCase().replace(/\s+/g, '');
        var ok = window.CSS && CSS.supports && CSS.supports('color', c);
        $w.append('<button type="button" class="sw" data-v="' + this.value + '" title="' + $('<div>').text(this.text).html() + '"' + (ok ? ' style="--c:' + c + '"' : '') + '>' + (ok ? '' : $('<div>').text(this.text).html()) + '</button>');
      });
      $sel.after($w).addClass('omgp-hidden-select');
      $w.on('click', '.sw', function () { $sel.val($(this).data('v')).trigger('change'); });
      $sel.on('change', function () { $w.find('.sw').removeClass('is-on').filter('[data-v="' + $sel.val() + '"]').addClass('is-on'); });
      $form.on('woocommerce_update_variation_values', function () { $w.find('.sw').each(function () { $(this).toggleClass('is-na', !$sel.find('option[value="' + $(this).data('v') + '"]').length); }); });
    });

    /* complete your setup */
    var $set = $p.find('.omgp-setup');
    function fmt(n) { var $t = $set.find('.sum .t .amount').first(); var sym = $t.find('.woocommerce-Price-currencySymbol').first().text() || '$'; return sym + n.toFixed(2); }
    $set.on('change', 'input', function () {
      var tot = 0, reg = 0, n = 0;
      $set.find('input:checked').each(function () { tot += +$(this).data('price'); reg += +$(this).data('reg'); n++; });
      $set.find('.n').text(n); $set.find('.sum .t').text(fmt(tot)); $set.find('.sv .s').text(fmt(Math.max(0, reg - tot)));
      $(this).closest('.it').toggleClass('is-off', !this.checked);
      $set.find('.omgp-addall').prop('disabled', !n);
    });
    $set.on('click', '.omgp-addall', function () {
      var ids = $set.find('input:checked').map(function () { return this.value; }).get(), $b = $(this).prop('disabled', true).text('Adding...');
      var url = (window.wc_add_to_cart_params && wc_add_to_cart_params.wc_ajax_url) ? wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart') : '/?wc-ajax=add_to_cart';
      ids.reduce(function (pr, id) { return pr.then(function () { return $.post(url, { product_id: id, quantity: 1 }); }); }, $.Deferred().resolve().promise())
        .always(function () { location.href = $p.data('cart'); });
    });
  });
})(jQuery);
