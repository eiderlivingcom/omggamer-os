/* OMG AI Product Studio — admin */
(function ($) {
  'use strict';
  var C = window.OMGPS || {};

  function post(action, data) {
    return new Promise(function (resolve, reject) {
      $.post(C.ajax, $.extend({ action: action, nonce: C.nonce }, data || {}))
        .done(function (r) { if (r && r.success) { resolve(r.data); } else { reject((r && r.data && r.data.message) || 'Something went wrong.'); } })
        .fail(function (x) { reject((x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) || ('Request failed (' + x.status + ').')); });
    });
  }
  function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
  function strip(h) { return $('<div>').html(h || '').text().replace(/\s+/g, ' ').trim(); }
  function round99(v) { v = parseFloat(v) || 0; if (v <= 0) { return 0; } return Math.max(0.99, Math.ceil(v) - 0.01); }

  /* ================================================================ settings + import pages */
  $(function () {
    $('#omgps-test').on('click', function () {
      var $o = $('#omgps-test-out').text('Testing...');
      post('omg_ps_test').then(function (d) { $o.text(d.message); }, function (m) { $o.text(m); });
    });
    function prov() { var p = $('#omg-ai-provider').val(); $('.omgps-base').toggle(p === 'custom'); $('.omgps-fb').toggle(p === 'anthropic'); }
    $('#omg-ai-provider').on('change', prov); prov();

    // Import page.
    var $res = $('#omgps-imp-results');
    if ($res.length) {
      $('#omgps-imp-search').on('click', function () {
        var $b = $(this).prop('disabled', true).text('Searching...');
        post('omg_ps_source', { type: 'kinguin_search', q: $('#omgps-imp-q').val(), platform: $('#omgps-imp-pf').val() }).then(function (d) {
          if (!d.rows.length) { $res.html('<p class="omgps-muted">No Kinguin products found.</p>'); return; }
          var h = '<table class="widefat striped omgps-ktable"><thead><tr><th class="check-column"><input type="checkbox" class="omgps-all"></th><th>Kinguin product</th><th>Platform · region</th><th>Lowest offer</th><th>Offers</th><th>Stock</th></tr></thead><tbody>';
          d.rows.forEach(function (r) { h += '<tr><th class="check-column"><input type="checkbox" value="' + r.id + '"></th><td><b>' + esc(r.name) + '</b><br><code>ID ' + r.id + '</code></td><td>' + esc(r.platform) + ' · ' + esc(r.region) + '</td><td>' + C.currency + Number(r.price).toFixed(2) + '</td><td>' + r.offers + '</td><td>' + r.qty + '</td></tr>'; });
          $res.html(h + '</tbody></table><p class="omgps-muted">' + d.total + ' results on Kinguin.</p>');
        }, function (m) { $res.html('<div class="omgps-notice">' + esc(m) + '</div>'); }).then(function () { $b.prop('disabled', false).text('Search API'); });
      });
      $res.on('change', 'input[type=checkbox]', function () {
        if ($(this).hasClass('omgps-all')) { $res.find('tbody input').prop('checked', this.checked); }
        $('#omgps-imp-go').prop('disabled', !$res.find('tbody input:checked').length);
      });
      $('#omgps-imp-go').on('click', function () {
        var ids = $res.find('tbody input:checked').map(function () { return this.value; }).get();
        var $b = $(this).prop('disabled', true), $o = $('#omgps-imp-out').text('Importing ' + ids.length + '...');
        post('omg_ps_import', { ids: ids }).then(function (d) {
          $o.html(d.rows.map(function (r) { return (r.ok ? '&#10003; ' : '&#10007; ') + 'ID ' + r.id + ': ' + esc(r.msg) + (r.edit ? ' · <a href="' + r.edit + '">edit</a>' : ''); }).join('<br>'));
        }, function (m) { $o.text(m); }).then(function () { $b.prop('disabled', false); });
      });
      $('#omgps-queue-drafts').on('click', function () {
        var $o = $('#omgps-queue-out').text(' Queuing...');
        post('omg_ps_queue').then(function (d) { $o.text(' ' + d.message); }, function (m) { $o.text(' ' + m); });
      });
    }
  });

  /* ================================================================ product editor */
  $(function () {
    var $s = $('#omgps');
    if (!$s.length) { return; }
    var postId = $s.data('post');
    var published = String($s.data('published')) === '1';
    var edited = {};
    var $boxes = $s.add('#omgps-pricing, #omgps-sync, #omgps-ai');

    function J(sel, fallback) { try { var v = JSON.parse($s.find(sel).val() || ''); return v == null ? fallback : v; } catch (e) { return fallback; } }
    function setJ(sel, v) { $s.find(sel).val(JSON.stringify(v)); }
    var approved = J('.omgps-approved', []);
    var written = J('.omgps-written', []);
    var source = J('.omgps-source', {}); if ($.isArray(source)) { source = {}; }
    var attrSrc = J('.omgps-attr-src', {}); if ($.isArray(attrSrc)) { attrSrc = {}; }
    function kind() { return $s.attr('data-kind'); }
    function reviewKeys() { return (C.kinds[kind()] || {}).review || []; }

    /* ---------- sidebar order: Publish first, then the studio boxes ---------- */
    if (!$('#side-sortables').data('omgps-sorted')) { $('#submitdiv').after($('#omgps-pricing'), $('#omgps-sync'), $('#omgps-ai')); $('#side-sortables').data('omgps-sorted', 1); }

    $('#woocommerce-product-data').removeClass('closed');

    /* ---------- header buttons ---------- */
    $('.wp-heading-inline').first().after('<span class="omgps-headbadge">&#10022; AI Product Studio</span>');
    $('.wrap .page-title-action').first().after('<span class="omgps-headbtns"><a class="omgps-btn is-blue" href="' + C.importUrl + '">Bulk import from Kinguin</a><a class="omgps-btn is-blue" href="' + C.csvUrl + '">Import CSV</a></span>');

    /* ---------- kind switching ---------- */
    function applyKind() {
      var k = kind();
      $boxes.find('[data-for]').each(function () {
        var on = (' ' + $(this).attr('data-for') + ' ').indexOf(' ' + k + ' ') > -1;
        $(this).toggle(on);
        $(this).find('input, select, textarea').prop('disabled', !on);
      });
      $s.find('.omgps-chip.is-off input').prop('disabled', true);
      $s.find('.omgps-kind').each(function () { $(this).toggleClass('is-on', $(this).find('input').val() === k); });
      refreshReview(); refreshBadges(); pricing();
    }
    $s.on('change', '.omgps-kind input', function () { $s.attr('data-kind', this.value); applyKind(); });

    /* ---------- title / short / description ---------- */
    var $title = $s.find('.omgps-title'), $short = $s.find('.omgps-short');
    function descEd() { return window.tinymce && tinymce.get('omgpsdesc'); }
    function getDesc() { var ed = descEd(); return ed && !ed.isHidden() ? ed.getContent() : ($('#omgpsdesc').val() || ''); }
    function setDesc(h) { var ed = descEd(); if (ed) { ed.setContent(h); ed.save(); } $('#omgpsdesc').val(h); counts(); }
    function counts() {
      var t = $title.val() || ''; $s.find('[data-key=title] [data-count=chars]').text(t.length + ' characters');
      var w = strip(getDesc()); $s.find('[data-key=description] [data-count=words]').text((w ? w.split(' ').length : 0) + ' words');
      seo();
    }
    $title.on('input', function () { $('#title').val(this.value); $('#title-prompt-text').toggleClass('screen-reader-text', !!this.value); mark('title'); counts(); });
    $short.on('input', function () { $('#excerpt').val(this.value); mark('short_description'); seo(); });
    $('#omgpsdesc').on('input', function () { mark('description'); counts(); });
    $(document).on('tinymce-editor-init', function (e, ed) { if (ed.id === 'omgpsdesc') { ed.on('input keyup', function () { mark('description'); counts(); }); counts(); } });

    $s.on('change', '.omgps-tone input', function () { $s.find('.omgps-tone label').removeClass('is-on'); $(this).closest('label').addClass('is-on'); });
    $(document).on('change', '.omgps-langs input', function () { $('.omgps-langs label').removeClass('is-on'); $(this).closest('label').addClass('is-on'); });
    $s.on('change', '.omgps-chip input', function () { $(this).closest('.omgps-chip').toggleClass('is-unchecked', !this.checked); });
    $s.find('.omgps-chip input').each(function () { $(this).closest('.omgps-chip').toggleClass('is-unchecked', !this.checked); });

    /* ---------- edited / approved / written ---------- */
    function mark(key) { edited[key] = true; }
    function canWrite(key) { return approved.indexOf(key) < 0 && !edited[key]; }
    function setWritten(key) { if (written.indexOf(key) < 0) { written.push(key); setJ('.omgps-written', written); } refreshBadges(); }
    function refreshBadges() {
      $s.find('[data-key]').each(function () {
        var k = $(this).data('key');
        $(this).toggleClass('is-written', written.indexOf(k) > -1).toggleClass('is-approved', approved.indexOf(k) > -1);
      });
      $s.find('.omgps-extras').toggleClass('is-written', written.indexOf('extras') > -1);
    }
    function refreshReview() {
      var keys = reviewKeys(), n = keys.filter(function (k) { return approved.indexOf(k) > -1; }).length;
      $('.omgps-reviewed .n').text(n); $('.omgps-reviewed .of').text(keys.length); $('.omgps-reviewed .bar i').css('width', (keys.length ? n / keys.length * 100 : 0) + '%');
    }
    $s.on('click', '.omgps-approve', function () {
      var k = $(this).data('key'), i = approved.indexOf(k);
      if (i > -1) { approved.splice(i, 1); } else { approved.push(k); }
      setJ('.omgps-approved', approved); refreshBadges(); refreshReview();
    });
    $(document).on('click', '.omgps-approve-all', function () {
      reviewKeys().forEach(function (k) { if (approved.indexOf(k) < 0) { approved.push(k); } });
      setJ('.omgps-approved', approved); refreshBadges(); refreshReview();
      $('#publish').data('omgps-ok', 1).trigger('click');
    });
    $('#publish').on('click', function (e) {
      if ($(this).data('omgps-ok') || published || C.rule !== 'human' || !written.length) { return; }
      var keys = reviewKeys(), left = keys.filter(function (k) { return approved.indexOf(k) < 0; }).length;
      if (left && !window.confirm(left + ' of ' + keys.length + ' AI fields are not reviewed yet. Publish anyway?')) { e.preventDefault(); e.stopImmediatePropagation(); }
    });

    /* ---------- attributes ---------- */
    function attrInputs() { return $s.find('.omgps-attrgrid:visible input[data-attr]'); }
    $s.on('input', 'input[data-attr]', function () { mark('attr:' + $(this).data('attr')); attrSrc[$(this).data('attr')] = 'product'; $(this).siblings('.dot').attr('data-src', 'product'); setJ('.omgps-attr-src', attrSrc); });
    $s.on('input', '.omgps-sync-ean', function () { $s.find('.omgps-sync-ean').not(this).val(this.value); });
    function setAttr(k, v, src) {
      var $i = attrInputs().filter('[data-attr="' + k + '"]');
      if (!$i.length || $i.prop('readonly') || v === '' || v == null) { return; }
      $i.val(v); if (k === 'ean') { $s.find('.omgps-sync-ean').val(v); }
      attrSrc[k] = src || 'ai'; $i.siblings('.dot').attr('data-src', attrSrc[k]); setJ('.omgps-attr-src', attrSrc);
    }
    function attrValues() { var o = {}; attrInputs().each(function () { o[$(this).data('attr')] = $(this).val(); }); return o; }

    // custom specs
    var $crows = $s.find('.omgps-custom-rows');
    function customSave() { var rows = []; $crows.find('.omgps-crow').each(function () { rows.push([$(this).find('.cl').val(), $(this).find('.cv').val()]); }); $s.find('.omgps-custom-val').val(JSON.stringify(rows)); }
    $s.on('click', '.omgps-add-spec', function () { $crows.append('<div class="omgps-crow"><input type="text" class="cl" placeholder="Spec"><input type="text" class="cv" placeholder="Value"><button type="button" class="omgps-x" aria-label="Remove">&times;</button></div>'); customSave(); });
    $crows.on('click', '.omgps-x', function () { $(this).closest('.omgps-crow').remove(); customSave(); });
    $crows.on('input', 'input', customSave); customSave();

    /* ---------- list editors ---------- */
    function rowHtml(fmt, it) {
      if (fmt === 'works') {
        it = it || { name: '', note: '', ok: true };
        return '<li class="row works"><span class="h" aria-hidden="true">&#8942;&#8942;</span><label class="ok" title="Supported"><input type="checkbox" ' + (it.ok === false ? '' : 'checked') + '><span></span></label><input type="text" class="n" value="' + esc(it.name) + '" placeholder="PS5"><input type="text" class="d" value="' + esc(it.note) + '" placeholder="Full features"><button type="button" class="omgps-x" aria-label="Remove">&times;</button></li>';
      }
      it = it == null ? '' : String(it);
      var head = fmt === 'sysreq' && it.indexOf('## ') === 0;
      return '<li class="row' + (head ? ' is-head' : '') + '"><span class="h" aria-hidden="true">&#8942;&#8942;</span><input type="text" class="it" value="' + esc(it) + '" placeholder="' + (fmt === 'features' ? 'Title: one sentence' : (fmt === 'sysreq' ? 'OS: Windows 10 64-bit  (## Minimum starts a group)' : 'Item')) + '"><button type="button" class="omgps-x" aria-label="Remove">&times;</button></li>';
    }
    function listSave($l) {
      var fmt = $l.data('format'), items = [];
      $l.find('.row').each(function () {
        if (fmt === 'works') { var n = $(this).find('.n').val().trim(); if (n) { items.push({ name: n, note: $(this).find('.d').val().trim(), ok: $(this).find('.ok input').is(':checked') }); } }
        else { var v = $(this).find('.it').val().trim(); $(this).toggleClass('is-head', fmt === 'sysreq' && v.indexOf('## ') === 0); if (v) { items.push(v); } }
      });
      $l.find('.omgps-list-val').val(JSON.stringify(items));
      return items;
    }
    function listSet($l, items) { var fmt = $l.data('format'), $u = $l.find('.rows').empty(); (items || []).forEach(function (it) { $u.append(rowHtml(fmt, it)); }); listSave($l); }
    function listGet($l) { return listSave($l); }
    $s.find('.omgps-list').each(function () {
      var $l = $(this), items = $l.data('items') || [];
      listSet($l, items);
      if ($.fn.sortable) { $l.find('.rows').sortable({ handle: '.h', axis: 'y', update: function () { listSave($l); mark($l.closest('[data-key]').data('key')); } }); }
    });
    $s.on('click', '.omgps-add', function () { var $l = $(this).closest('.omgps-list'); $l.find('.rows').append(rowHtml($l.data('format'))); $l.find('.rows .row:last input[type=text]').first().trigger('focus'); listSave($l); });
    $s.on('click', '.omgps-list .omgps-x', function () { var $l = $(this).closest('.omgps-list'); $(this).closest('.row').remove(); listSave($l); mark($l.closest('[data-key]').data('key')); });
    $s.on('input change', '.omgps-list input', function () { var $l = $(this).closest('.omgps-list'); listSave($l); mark($l.closest('[data-key]').data('key')); });
    function secList(k) { return $s.find('.omgps-secgrid:visible [data-key="sec_' + k + '"] .omgps-list'); }
    function sections() { var o = {}; $s.find('.omgps-secgrid:visible .omgps-sec').each(function () { o[String($(this).data('key')).slice(4)] = listGet($(this).find('.omgps-list')); }); return o; }

    /* ---------- extras ---------- */
    $s.on('input', 'input[data-extra]', function () { mark('extras'); });

    /* ---------- SEO ---------- */
    var $st = $s.find('.omgps-seo-t'), $sd = $s.find('.omgps-seo-d'), $slug = $s.find('.omgps-slug'), $kw = $s.find('.omgps-kw');
    function seo() {
      if (!$st.length) { return; } // SEO is edited in Rank Math
      var t = $st.val() || (($title.val() || '') + ' | OMGGamer'), d = $sd.val() || strip($short.val()), kw = ($kw.val() || '').toLowerCase().trim();
      $s.find('.omgps-serp-t').text(t); $s.find('.omgps-serp-d').text(d.length > 160 ? d.slice(0, 157) + '...' : d); $s.find('.omgps-serp-slug').text($slug.val() || '');
      var tl = ($st.val() || '').length, dl = ($sd.val() || '').length;
      $s.find('.omgps-c-t').text(tl + ' / 60').toggleClass('is-bad', tl > 60);
      $s.find('.omgps-c-d').text(dl + ' / 160').toggleClass('is-bad', dl > 160);
      var good = tl >= 30 && tl <= 60 && dl >= 110 && dl <= 160 && kw && t.toLowerCase().indexOf(kw) > -1;
      $s.find('.omgps-seo-state').attr('class', 'omgps-seo-state ' + (good ? 'is-good' : 'is-warn')).text(good ? 'Good' : 'Needs work');
    }
    $st.add($sd).add($slug).add($kw).on('input', function () { mark('seo'); seo(); });

    /* ---------- pricing ---------- */
    function pricing() {
      var cost = parseFloat($('#omgps-cost').val()), m = parseFloat($('#omgps-margin').val()) || 0;
      $('.omgps-m').text(m);
      var sale = cost > 0 ? round99(cost * (1 + m / 100)) : 0;
      $('.omgps-sale').text(sale ? C.currency + sale.toFixed(2) : '—').data('v', sale);
    }
    $(document).on('input', '#omgps-cost, #omgps-margin', pricing);
    $(document).on('click', '.omgps-apply-price', function () {
      var v = $('.omgps-sale').data('v');
      if (!v) { window.alert('Enter the source cost first.'); return; }
      if (String($('.omgps-pricing').data('variable')) === '1') { window.alert('This is a variable product: set the price on each variation in Product data › Variations. Suggested price: ' + C.currency + v.toFixed(2)); return; }
      $('#_regular_price').val(v.toFixed(2)).trigger('change');
      $(this).text('Price set — click Update to save');
    });

    /* ---------- media ---------- */
    function thumbHtml(id, src, featured, alt) { return '<figure class="omgps-thumb' + (featured ? ' is-featured' : '') + '" data-id="' + id + '"><div class="im"><img src="' + src + '" alt="">' + (featured ? '<em>Featured</em>' : '') + '</div><input type="text" name="omg_ps[alt][' + id + ']" value="' + esc(alt || '') + '" placeholder="Alt text"></figure>'; }
    function rebuildMedia() {
      var $t = $s.find('.omgps-thumbs'), keep = {};
      $t.find('.omgps-thumb').each(function () { keep[$(this).data('id')] = $(this).find('input').val(); });
      var list = [], fid = parseInt($('#_thumbnail_id').val(), 10);
      if (fid > 0) { list.push([fid, $('#postimagediv img').attr('src'), true]); }
      $('#product_images_container .product_images li.image').each(function () { list.push([$(this).data('attachment_id'), $(this).find('img').attr('src'), false]); });
      $t.html(list.filter(function (r) { return r[0] && r[1]; }).map(function (r) {
        var alt = keep[r[0]];
        if (alt === undefined && wp.media) { wp.media.attachment(r[0]).fetch().then(function () { $t.find('[data-id="' + r[0] + '"] input').val(wp.media.attachment(r[0]).get('alt') || ''); }); }
        return thumbHtml(r[0], r[1], r[2], alt);
      }).join(''));
      $s.find('.omgps-nomedia').prop('hidden', !!list.length);
    }
    var mo = window.MutationObserver ? new MutationObserver(function () { clearTimeout(mo.t); mo.t = setTimeout(rebuildMedia, 300); }) : null;
    if (mo) { ['#product_images_container', '#postimagediv .inside'].forEach(function (sel) { var el = document.querySelector(sel); if (el) { mo.observe(el, { childList: true, subtree: true }); } }); }
    $s.on('click', '.omgps-addmedia', function () { $('#woocommerce-product-images .add_product_images a').trigger('click'); });
    function mediaIds() { return $s.find('.omgps-thumb').map(function () { return $(this).data('id'); }).get(); }

    // datasheet picker
    var pdfFrame;
    $s.on('click', '.omgps-pdf-pick', function (e) {
      e.preventDefault();
      if (!pdfFrame) {
        pdfFrame = wp.media({ title: 'Choose the datasheet PDF', library: { type: 'application/pdf' }, button: { text: 'Use this PDF' }, multiple: false });
        pdfFrame.on('select', function () { var a = pdfFrame.state().get('selection').first().toJSON(); $s.find('.omgps-pdf-id').val(a.id); $s.find('.omgps-pdf-name').text(a.filename); $s.find('.omgps-pdf-pick').text('Replace file'); });
      }
      pdfFrame.open();
    });

    /* ---------- categories ---------- */
    function suggestCats(ids) {
      (ids || []).forEach(function (id) {
        var $c = $('#in-product_cat-' + id); if (!$c.length) { return; }
        $c.prop('checked', true);
        var $lab = $c.closest('label'); if (!$lab.find('.omgps-sugg').length) { $lab.append(' <span class="omgps-sugg">&#10022; suggested</span>'); }
      });
    }

    /* ---------- generation ---------- */
    function doc() { return { title: $title.val(), short_description: $short.val(), description: getDesc(), attrs: attrValues(), sections: sections() }; }
    function opts(group) {
      return { post_id: postId, kind: kind(), group: group, tone: $s.find('.omgps-tone input:checked').val(), voice: $('#omgps-voice').val(), lang: $('.omgps-langs input:checked').val(), extra: $('#omgps-extra').val(), doc: JSON.stringify(doc()), source: JSON.stringify(source) };
    }
    function fill(d, force) {
      var w = [];
      if (d.title != null && d.title !== '' && (force || canWrite('title'))) { $title.val(d.title); $('#title').val(d.title); $('#title-prompt-text').addClass('screen-reader-text'); w.push('title'); }
      if (d.short_description != null && (force || canWrite('short_description'))) { $short.val(d.short_description); $('#excerpt').val(d.short_description); w.push('short_description'); }
      if (d.description != null && d.description !== '' && (force || canWrite('description'))) { setDesc(d.description); w.push('description'); }
      if (d.attributes && (force || approved.indexOf('attributes') < 0)) {
        $.each(d.attributes, function (k, v) { if (force || !edited['attr:' + k]) { setAttr(k, v, (d.attribute_sources || {})[k] || 'ai'); } });
        w.push('attributes');
      }
      if (d.sections) { $.each(d.sections, function (k, items) { if (force || canWrite('sec_' + k)) { var $l = secList(k); if ($l.length) { listSet($l, items); w.push('sec_' + k); } } }); }
      if (d.extras && (force || canWrite('extras'))) { $.each(d.extras, function (k, v) { $s.find('input[data-extra="' + k + '"]').val(v); }); w.push('extras'); }
      if ((d.seo_title != null || d.seo_description != null) && (force || canWrite('seo'))) {
        if (d.seo_title != null) { $st.val(d.seo_title); }
        if (d.seo_description != null) { $sd.val(d.seo_description); }
        if (d.focus_keyword != null) { $kw.val(d.focus_keyword); }
        if (d.slug && !published) { $slug.val(d.slug); }
        w.push('seo');
      }
      if (d.alt) { $.each(d.alt, function (id, t) { var $i = $s.find('.omgps-thumb[data-id="' + id + '"] input'); if ($i.length && (force || !$i.val())) { $i.val(t); } }); w.push('alt'); }
      if (d.categories) { suggestCats(d.categories); }
      w.forEach(setWritten); counts();
      return w;
    }
    function msg(t, cls) { $s.find('.omgps-msg').attr('class', 'omgps-msg ' + (cls || '')).html(t || ''); }

    // single group regenerate (always overwrites that group)
    $s.on('click', '.omgps-regen', function () {
      var $b = $(this), g = $b.data('group'), label = $b.html();
      if (g === 'alt' && !mediaIds().length) { msg('Add images first.', 'is-error'); return; }
      $b.prop('disabled', true).text('Writing...');
      var $f = $b.closest('[data-key], .omgps-extras').addClass('is-loading');
      post('omg_ps_generate', opts(g)).then(function (d) {
        if (g === 'sections' || g.indexOf('sec_') === 0) { approved = approved.filter(function (k) { return k !== g; }); }
        fill(d, true); msg('Written. Review it, edit if needed, then click Approve. Nothing is saved until you click Update.', 'is-ok');
      }, function (m) { msg(esc(m), 'is-error'); }).then(function () { $b.prop('disabled', false).html(label); $f.removeClass('is-loading'); });
    });

    // Kinguin search in the editor
    var kinguinPick = null;
    $s.on('click', '.omgps-k-search', function () {
      var $b = $(this).prop('disabled', true).text('Searching...'), $r = $s.find('.omgps-k-results');
      post('omg_ps_source', { type: 'kinguin_search', post_id: postId, q: $s.find('.omgps-k-q').val(), platform: $s.find('.omgps-k-pf').val() }).then(function (d) {
        if (!d.rows.length) { $r.html('<p class="omgps-muted">No Kinguin products found.</p>'); return; }
        var h = '<table class="omgps-ktable"><thead><tr><th></th><th>Kinguin product</th><th>Platform · region</th><th>Lowest offer</th><th>Offers</th></tr></thead><tbody>';
        d.rows.forEach(function (r, i) { h += '<tr' + (i === 0 ? ' class="is-on"' : '') + '><td><input type="radio" name="omgps_k_row" value="' + r.id + '"' + (i === 0 ? ' checked' : '') + ' data-price="' + r.price + '"></td><td><b>' + esc(r.name) + '</b><code>ID ' + r.id + '</code></td><td>' + esc(r.platform) + ' · ' + esc(r.region) + '</td><td><b>' + C.currency + Number(r.price).toFixed(2) + '</b></td><td>' + r.offers + '</td></tr>'; });
        $r.html(h + '</tbody></table>'); kinguinPick = d.rows[0].id;
      }, function (m) { $r.html('<div class="omgps-notice">' + esc(m) + '</div>'); }).then(function () { $b.prop('disabled', false).text('Search API'); });
    });
    $s.on('change', 'input[name=omgps_k_row]', function () { kinguinPick = this.value; $(this).closest('tbody').find('tr').removeClass('is-on'); $(this).closest('tr').addClass('is-on'); });

    function chipOn(k) { var $c = $s.find('.omgps-chip input[value="' + k + '"]'); return $c.length && !$c.prop('disabled') && $c.is(':checked'); }
    function saveSource() { setJ('.omgps-source', source); }

    $s.on('click', '.omgps-fetchgen', function () {
      var $b = $(this).prop('disabled', true), k = kind(), steps = [], $ol = $s.find('.omgps-steps').empty().prop('hidden', false);
      msg('');
      function step(label, run, skip) { steps.push({ label: label, run: run, skip: skip }); }
      function gen(group) { return post('omg_ps_generate', opts(group)).then(function (d) { fill(d, false); }); }
      if (k === 'digital') {
        step('Fetch product data from OMGGamer OS', function () {
          return post('omg_ps_source', { type: 'os', post_id: postId, name: $title.val() }).then(function (d) {
            source.os = d; delete source.kinguin; saveSource();
          });
        }, chipOn('os') ? '' : 'OMGGamer OS is off or not connected');
        step('Search Steam by title for missing details', function () {
          var f = (source.os && source.os.facts) || {};
          return post('omg_ps_source', { type: 'steam', post_id: postId, name: (source.os && source.os.original_name) || f.product_name || $title.val(), app_id: (source.os && source.os.steam_app_id) || '' }).then(function (d) { source.steam = d; saveSource(); });
        }, chipOn('steam') ? '' : 'Steam is off');
      } else {
        var ean = $s.find('.omgps-sync-ean').first().val(), url = $s.find('.omgps-mfr').val(), pdf = $s.find('.omgps-pdf-id').val();
        step('Look up EAN on Icecat', function () {
          return post('omg_ps_source', { type: 'icecat', post_id: postId, ean: ean, brand: attrInputs().filter('[data-attr=brand]').val(), mpn: $s.find('[name="omg_ps[mpn]"]').val() }).then(function (d) { source.icecat = d; saveSource(); });
        }, !chipOn('icecat') ? 'Icecat is off or not connected' : (!ean ? 'No EAN' : ''));
        step('Read manufacturer page', function () {
          return post('omg_ps_source', { type: 'manufacturer', post_id: postId, url: url }).then(function (d) { source.manufacturer = d; saveSource(); });
        }, !chipOn('manufacturer') ? 'Manufacturer page is off' : (!url ? 'No manufacturer URL' : ''));
        step('Extract specs from datasheet PDF', function () {
          return post('omg_ps_source', { type: 'datasheet', post_id: postId, id: pdf }).then(function (d) { source.datasheet = d; saveSource(); });
        }, !chipOn('datasheet') ? 'Datasheet is off' : (!pdf ? 'No PDF uploaded' : ''));
      }
      step('Write title & description', function () { return gen('content'); });
      step('Write attributes', function () { return gen('attributes'); });
      step('Write page sections' + (k === 'digital' ? ' & notes' : ''), function () { return gen('sections').then(function () { return k === 'digital' ? gen('extras') : null; }); });
      step('Write image alt text', function () { return gen('alt'); }, mediaIds().length ? '' : 'No images');

      steps.forEach(function (st, i) { $ol.append('<li class="' + (st.skip ? 'is-skip' : 'is-wait') + '" data-i="' + i + '"><i></i><span>' + esc(st.label) + (st.skip ? '<small>' + esc(st.skip) + '</small>' : '') + '</span></li>'); });
      var errors = [];
      steps.reduce(function (p, st, i) {
        return p.then(function () {
          if (st.skip) { return; }
          var $li = $ol.find('[data-i=' + i + ']').attr('class', 'is-run');
          return st.run().then(function () { $li.attr('class', 'is-ok'); }, function (m) { $li.attr('class', 'is-err').find('span').append('<small>' + esc(m) + '</small>'); errors.push(m); });
        });
      }, Promise.resolve()).then(function () {
        $b.prop('disabled', false);
        msg(errors.length ? 'Finished with ' + errors.length + ' problem(s). Fields that were written are filled in below.' : 'Done. Review each field, edit if needed and click Approve. Nothing is saved until you click Update.', errors.length ? 'is-error' : 'is-ok');
      });
    });

    applyKind(); counts(); seo(); refreshBadges(); refreshReview(); pricing();
    $('#post').on('submit', function () { $s.find('.omgps-list').each(function () { listSave($(this)); }); customSave(); var ed = descEd(); if (ed) { ed.save(); } });
  });
})(jQuery);
