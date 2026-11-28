/* OMGGamer account: reveal keys (fetched only when asked), copy buttons, image key downloads. */
(function () {
  var C = window.OMGACC || {};

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function copyBtn(value, label) {
    return '<button type="button" class="oa-copy" data-copy="' + esc(value) + '" aria-label="Copy ' + esc(label || 'key') + '">'
      + '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8" fill="none" stroke="currentColor" stroke-width="2"/></svg><span>Copy</span></button>';
  }

  function renderKey(k, i, name) {
    if (k.kind === 'image') {
      if (!k.src) return '<div class="oa-key"><p class="oa-key-l">Image key</p><p>This key is an image. Contact support if it does not show.</p></div>';
      return '<div class="oa-key oa-key-img"><p class="oa-key-l">Image key</p><img src="' + esc(k.src) + '" alt="Key image ' + (i + 1) + '">'
        + '<a class="oa-btn sm" href="' + esc(k.src) + '" download="' + esc((name || 'omggamer-key').replace(/[^a-z0-9]+/gi, '-').toLowerCase()) + '-' + (i + 1) + '.png">Download image</a></div>';
    }
    if (k.kind === 'link') {
      return '<div class="oa-key"><p class="oa-key-l">Gift / activation link</p><div class="oa-key-v"><code>' + esc(k.url) + '</code>' + copyBtn(k.url, 'link')
        + '<a class="oa-btn sm pri" href="' + esc(k.url) + '" target="_blank" rel="noopener noreferrer">Open link</a></div></div>';
    }
    if (k.kind === 'account') {
      var rows = (k.rows || []).map(function (r) {
        return '<div class="oa-cred"><span>' + esc(r[0]) + '</span><code>' + esc(r[1]) + '</code>' + copyBtn(r[1], r[0]) + '</div>';
      }).join('');
      return '<div class="oa-key"><p class="oa-key-l">Account details</p>' + rows + (k.note ? '<p class="oa-key-note">' + esc(k.note).replace(/\n/g, '<br>') + '</p>' : '') + '</div>';
    }
    return '<div class="oa-key"><p class="oa-key-l">Key' + (i ? ' ' + (i + 1) : '') + '</p><div class="oa-key-v"><code>' + esc(k.value) + '</code>' + copyBtn(k.value, 'key') + '</div></div>';
  }

  document.addEventListener('click', function (e) {
    var rev = e.target.closest('.oa-reveal');
    if (rev) {
      var box = rev.closest('.oa-keys');
      var list = box.querySelector('.oa-key-list');
      if (!list.hidden && list.innerHTML) { list.hidden = true; rev.textContent = 'Show key'; return; }
      if (list.innerHTML) { list.hidden = false; rev.textContent = 'Hide key'; return; }
      rev.disabled = true; rev.textContent = 'Loading…';
      var body = new URLSearchParams({ action: 'omg_acc_keys', _ajax_nonce: box.dataset.nonce, order: box.dataset.order, item: box.dataset.item });
      fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (r) {
        rev.disabled = false;
        if (!r || !r.success) { list.hidden = false; list.innerHTML = '<p class="oa-key-err">' + esc((r && r.data && r.data.message) || 'Could not load the key. Refresh the page and try again.') + '</p>'; rev.textContent = 'Try again'; list.innerHTML && setTimeout(function () { list.innerHTML = ''; }, 6000); return; }
        var name = (box.closest('.oa-item') || document).querySelector('.oa-item-name');
        list.innerHTML = r.data.keys.map(function (k, i) { return renderKey(k, i, name ? name.textContent : ''); }).join('');
        list.hidden = false; rev.textContent = 'Hide key';
      }).catch(function () { rev.disabled = false; rev.textContent = 'Try again'; });
      return;
    }
    var cp = e.target.closest('.oa-copy');
    if (cp) {
      var v = cp.getAttribute('data-copy');
      var done = function () { var s = cp.querySelector('span'); if (s) { s.textContent = 'Copied'; setTimeout(function () { s.textContent = 'Copy'; }, 1600); } };
      if (navigator.clipboard) navigator.clipboard.writeText(v).then(done, done); else { var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (x) {} t.remove(); done(); }
    }
  });
})();
