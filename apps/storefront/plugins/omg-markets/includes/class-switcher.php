<?php
/**
 * Country / currency picker. Turns the header's "US · USD" label (#omg-hdr-locale) and the checkout's
 * ".omgx-loc" label into a menu of the enabled markets. Choosing one reloads the page with ?market=xx.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets_Switcher
{
    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 60);
        add_shortcode('omg_market_switcher', [__CLASS__, 'shortcode']);
    }

    private static function items(): array
    {
        $out = [];
        foreach (OMG_Markets::enabled() as $cc => $m) {
            $out[] = [
                'cc' => $cc,
                'name' => OMG_Markets::country_name($cc),
                'cur' => $m['currency'],
                'sym' => html_entity_decode(get_woocommerce_currency_symbol($m['currency'])),
                'flag' => html_entity_decode(OMG_Markets::flag($cc)),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function shortcode(): string
    {
        $cc = OMG_Markets::current();
        return '<button type="button" class="omg-mk-btn" data-omg-market>' . OMG_Markets::flag($cc) . ' ' . esc_html($cc . ' · ' . OMG_Markets::currency()) . '</button>';
    }

    public static function render(): void
    {
        if (is_admin() || count(OMG_Markets::enabled()) < 2) {
            return;
        }
        $names = function_exists('get_woocommerce_currencies') ? get_woocommerce_currencies() : [];
        $curs = [];
        foreach (OMG_Markets::currencies() as $c) {
            $curs[] = ['code' => $c, 'name' => html_entity_decode($names[$c] ?? $c), 'sym' => html_entity_decode(get_woocommerce_currency_symbol($c))];
        }
        $cfg = [
            'current' => OMG_Markets::current(),
            'currency' => OMG_Markets::currency(),
            'items' => self::items(),
            'currencies' => $curs,
            'base' => OMG_Markets::base_currency(),
        ];
        ?>
<style id="omg-markets-css">
#omg-hdr-locale.omg-mk-on,.omgx-loc.omg-mk-on,.omg-mk-btn{cursor:pointer;user-select:none}
html body #omg-hdr-locale.omg-mk-on{display:inline-flex!important;align-items:center;gap:8px;height:38px;padding:0 10px 0 12px!important;border-radius:999px!important;background:#1e1a2e!important;border:1px solid #2a2540!important;color:#f4f2fb!important;font:600 13px/1 Poppins,system-ui,sans-serif!important;transition:border-color .15s,box-shadow .15s}
html body #omg-hdr-locale.omg-mk-on:hover,html body #omg-hdr-locale.omg-mk-on[aria-expanded=true]{border-color:#8b5cf6!important;box-shadow:0 0 0 3px rgba(139,92,246,.18)}
#omg-hdr-locale .omg-mk-cc{color:#a29fb6;letter-spacing:.02em}
#omg-hdr-locale .omg-mk-sep{width:1px;height:16px;background:#2a2540}
#omg-hdr-locale .omg-mk-cur{display:inline-flex;align-items:center;gap:4px;color:#f4f2fb}
#omg-hdr-locale .omg-mk-sym{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 4px;border-radius:6px;background:rgba(139,92,246,.18);color:#c4b5fd;font-size:11px;font-weight:700}
#omg-hdr-locale .omg-mk-chev{color:#77738c;flex:none}
#omg-hdr-locale .omg-mk-flag img.emoji{width:20px!important;height:20px!important;border-radius:4px}
@media (max-width:767px){#omg-hdr-locale .omg-mk-cc,#omg-hdr-locale .omg-mk-sep{display:none}html body #omg-hdr-locale.omg-mk-on{height:34px;padding:0 8px!important}}
#omg-hdr-locale.omg-mk-on::before{display:none!important}
#omg-hdr-locale .omg-dot{opacity:.5}
.omgx-loc.omg-mk-on:hover{opacity:.85}
.omg-mk-flag{display:inline-flex;align-items:center;font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;margin-right:2px}
.omg-mk-flag img.emoji,.omg-mk-m img.emoji{width:18px!important;height:18px!important;margin:0!important;border-radius:3px;vertical-align:middle}
.omg-mk-ov{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(5,4,10,.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.omg-mk-ov[hidden]{display:none}
.omg-mk-m{box-sizing:border-box;width:520px;max-width:100%;max-height:min(680px,calc(100vh - 32px));display:flex;flex-direction:column;gap:16px;padding:24px;background:#13111e;border:1px solid #2a2540;border-radius:24px;box-shadow:0 40px 80px -20px rgba(0,0,0,.8);color:#f4f2fb;font:500 14px/1.3 Poppins,system-ui,sans-serif}
.omg-mk-m *{box-sizing:border-box}
.omg-mk-hd{display:flex;align-items:center;justify-content:space-between;gap:12px}
.omg-mk-hd h2{margin:0;font:700 20px/1.2 Poppins,system-ui,sans-serif;color:#f4f2fb}
.omg-mk-x{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:0;border-radius:10px;background:transparent;color:#f4f2fb;cursor:pointer}
.omg-mk-x:hover{background:#1e1a2e}
.omg-mk-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.omg-mk-tab{display:flex;flex-direction:column;align-items:flex-start;gap:6px;min-width:0;padding:10px 12px;border:1px solid #2a2540;border-radius:12px;background:#1e1a2e;color:#f4f2fb;text-align:left;cursor:pointer;font:inherit}
.omg-mk-tab:hover{border-color:#4b3f78}
.omg-mk-tab[aria-selected=true]{border-color:#8b5cf6;box-shadow:0 0 0 1px #8b5cf6}
.omg-mk-tab .k{font:600 11px/1 Poppins,system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#a29fb6}
.omg-mk-tab .v{display:flex;align-items:center;gap:6px;width:100%;font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.omg-mk-tab .v>span:last-child{overflow:hidden;text-overflow:ellipsis}
.omg-mk-search{position:relative}
.omg-mk-search svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#a29fb6;pointer-events:none}
html body .omg-mk-search input{width:100%;height:46px;padding:0 16px 0 46px!important;border:1px solid #2a2540!important;border-radius:12px!important;background:#1e1a2e!important;color:#f4f2fb!important;font:500 14px Poppins,system-ui,sans-serif;outline:none}
html body .omg-mk-search input:focus{border-color:#8b5cf6!important;box-shadow:0 0 0 3px rgba(139,92,246,.25)}
.omg-mk-list{flex:1 1 auto;min-height:120px;overflow:auto;margin:0 -8px;padding:0 8px;list-style:none}
.omg-mk-list li{display:flex;align-items:center;gap:12px;margin:0;padding:11px 12px;border-radius:10px;cursor:pointer;color:#f4f2fb}
.omg-mk-list li:hover,.omg-mk-list li.is-hl{background:#1e1a2e}
.omg-mk-list li[aria-selected=true]{background:rgba(139,92,246,.16)}
.omg-mk-list li[aria-selected=true] .n{font-weight:600}
.omg-mk-list .f{flex:none;width:22px;font-size:18px;line-height:1;font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif}
.omg-mk-list .n{flex:1 1 auto;min-width:0}
.omg-mk-list .c{flex:none;color:#77738c;font-weight:600;font-size:12px;letter-spacing:.04em}
.omg-mk-list li[aria-selected=true] .c{color:#c4b5fd}
.omg-mk-empty{padding:16px 12px;color:#a29fb6}
.omg-mk-note{margin:0;color:#77738c;font-size:12px;line-height:1.45}
.omg-mk-ft{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.omg-mk-ft button{display:flex;align-items:center;justify-content:center;text-align:center;padding:0 16px;height:48px;border-radius:12px;font:700 15px Poppins,system-ui,sans-serif;cursor:pointer}
.omg-mk-cancel{border:1px solid #2a2540;background:transparent;color:#f4f2fb}
.omg-mk-cancel:hover{background:#1e1a2e}
.omg-mk-save{border:0;background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);color:#fff;box-shadow:0 10px 24px -12px rgba(192,38,211,.7)}
.omg-mk-save:hover{filter:brightness(1.08)}
.omg-mk-ft button:focus-visible,.omg-mk-tab:focus-visible,.omg-mk-x:focus-visible{outline:2px solid #b79cff;outline-offset:2px}
@media (max-width:520px){.omg-mk-ov{align-items:flex-end;padding:0}.omg-mk-m{border-radius:24px 24px 0 0;max-height:92vh;padding:20px 16px}.omg-mk-tabs{gap:6px}.omg-mk-tab{padding:9px 10px}}
html.omg-mk-lock,html.omg-mk-lock body{overflow:hidden}
</style>
<script id="omg-markets-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>, ov, opener, tab = 'region', pick = {region: C.current, currency: C.currency}, hl = -1;
  function esc(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function item(cc){ for (var i = 0; i < C.items.length; i++) if (C.items[i].cc === cc) return C.items[i]; return C.items[0]; }
  function curr(code){ for (var i = 0; i < C.currencies.length; i++) if (C.currencies[i].code === code) return C.currencies[i]; return {code: code, name: code, sym: ''}; }
  function build(){
    ov = document.createElement('div'); ov.className = 'omg-mk-ov'; ov.hidden = true;
    ov.innerHTML = '<div class="omg-mk-m" role="dialog" aria-modal="true" aria-labelledby="omg-mk-title">'
      + '<div class="omg-mk-hd"><h2 id="omg-mk-title">Region, language &amp; currency</h2><button type="button" class="omg-mk-x" aria-label="Close"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>'
      + '<div class="omg-mk-tabs" role="tablist">'
      + '<button type="button" role="tab" class="omg-mk-tab" data-tab="region"><span class="k">Region</span><span class="v"></span></button>'
      + '<button type="button" role="tab" class="omg-mk-tab" data-tab="language"><span class="k">Language</span><span class="v"><span>English</span></span></button>'
      + '<button type="button" role="tab" class="omg-mk-tab" data-tab="currency"><span class="k">Currency</span><span class="v"></span></button></div>'
      + '<label class="omg-mk-search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" autocomplete="off" aria-controls="omg-mk-list"></label>'
      + '<ul class="omg-mk-list" id="omg-mk-list" role="listbox"></ul>'
      + '<p class="omg-mk-note"></p>'
      + '<div class="omg-mk-ft"><button type="button" class="omg-mk-cancel">Cancel</button><button type="button" class="omg-mk-save">Save</button></div></div>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function(e){ if (e.target === ov) close(); });
    ov.querySelector('.omg-mk-x').addEventListener('click', close);
    ov.querySelector('.omg-mk-cancel').addEventListener('click', close);
    ov.querySelector('.omg-mk-save').addEventListener('click', save);
    ov.querySelectorAll('.omg-mk-tab').forEach(function(t){ t.addEventListener('click', function(){ tab = t.dataset.tab; q().value = ''; paint(); q().focus(); }); });
    q().addEventListener('input', list);
    q().addEventListener('keydown', function(e){
      var lis = ov.querySelectorAll('.omg-mk-list li[data-v]'), n = lis.length; if (!n) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); setHl((hl + (e.key === 'ArrowDown' ? 1 : -1) + n) % n); }
      else if (e.key === 'Enter') { e.preventDefault(); if (lis[hl]) choose(lis[hl].dataset.v); }
    });
    document.addEventListener('keydown', function(e){
      if (ov.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') { var f = ov.querySelectorAll('button,input'), a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    });
  }
  function q(){ return ov.querySelector('.omg-mk-search input'); }
  function setHl(i){ var lis = ov.querySelectorAll('.omg-mk-list li[data-v]'); lis.forEach(function(l, k){ l.classList.toggle('is-hl', k === i); }); hl = i; if (lis[i]) lis[i].scrollIntoView({block: 'nearest'}); }
  function paint(){
    var it = item(pick.region), cu = curr(pick.currency);
    ov.querySelector('[data-tab=region] .v').innerHTML = '<span class="f omg-mk-flag">' + it.flag + '</span><span>' + esc(it.name) + '</span>';
    ov.querySelector('[data-tab=currency] .v').innerHTML = '<span>' + esc(cu.name) + '</span>';
    ov.querySelectorAll('.omg-mk-tab').forEach(function(t){ t.setAttribute('aria-selected', t.dataset.tab === tab ? 'true' : 'false'); });
    q().placeholder = tab === 'region' ? 'Search region' : tab === 'currency' ? 'Search currency' : 'Search language';
    ov.querySelector('.omg-mk-note').textContent = tab === 'currency' ? 'Prices are converted from ' + C.base + ' at the European Central Bank’s daily reference rate. You pay in the currency shown.' : tab === 'language' ? 'More languages are coming soon.' : 'We show prices, taxes and region warnings for the country you choose.';
    list();
  }
  function list(){
    var s = q().value.trim().toLowerCase(), h = '', rows = [];
    if (tab === 'region') rows = C.items.map(function(i){ return {v: i.cc, f: i.flag, n: i.name, c: i.cc, on: i.cc === pick.region}; });
    else if (tab === 'currency') rows = C.currencies.map(function(c){ return {v: c.code, f: '', n: c.name, c: c.code + (c.sym && c.sym !== c.code ? ' ' + c.sym : ''), on: c.code === pick.currency}; });
    else rows = [{v: 'en', f: '', n: 'English', c: 'EN', on: true}];
    rows = rows.filter(function(r){ return !s || r.n.toLowerCase().indexOf(s) > -1 || r.c.toLowerCase().indexOf(s) > -1; });
    rows.forEach(function(r){ h += '<li role="option" data-v="' + esc(r.v) + '" aria-selected="' + r.on + '">' + (tab === 'region' ? '<span class="f">' + r.f + '</span>' : '') + '<span class="n">' + esc(r.n) + '</span><span class="c">' + esc(r.c) + '</span></li>'; });
    var ul = ov.querySelector('.omg-mk-list'); ul.innerHTML = h || '<li class="omg-mk-empty">No matches</li>';
    ul.querySelectorAll('li[data-v]').forEach(function(li, k){ li.addEventListener('click', function(){ choose(li.dataset.v); }); li.addEventListener('mousemove', function(){ if (hl !== k) setHl(k); }); });
    var sel = 0; ul.querySelectorAll('li[data-v]').forEach(function(li, k){ if (li.getAttribute('aria-selected') === 'true') sel = k; }); setHl(s ? 0 : sel);
  }
  function choose(v){
    if (tab === 'region') { var was = item(pick.region); pick.region = v; if (pick.currency === was.cur) pick.currency = item(v).cur; }
    else if (tab === 'currency') pick.currency = v;
    paint();
  }
  function save(){
    var u = new URL(location.href); u.searchParams.set('market', pick.region.toLowerCase());
    if (pick.currency !== item(pick.region).cur) u.searchParams.set('currency', pick.currency.toLowerCase()); else u.searchParams.delete('currency');
    if (pick.region === C.current && pick.currency === C.currency) { close(); return; }
    location.href = u.toString();
  }
  function open(el){
    if (!ov) build(); opener = el; tab = 'region'; pick = {region: C.current, currency: C.currency}; q().value = '';
    ov.hidden = false; document.documentElement.classList.add('omg-mk-lock'); el.setAttribute('aria-expanded', 'true'); paint(); setTimeout(function(){ q().focus(); }, 30);
  }
  function close(){ if (!ov || ov.hidden) return; ov.hidden = true; document.documentElement.classList.remove('omg-mk-lock'); if (opener) { opener.setAttribute('aria-expanded', 'false'); opener.focus(); } }
  function label(el, withName){
    var it = item(C.current); if (!it) return;
    el.textContent = '';
    var f = document.createElement('span'); f.className = 'omg-mk-flag'; f.setAttribute('aria-hidden', 'true'); f.textContent = it.flag;
    el.appendChild(f);
    if (withName) { el.appendChild(document.createTextNode(C.currency + ' · ' + it.name)); return; }
    var cu = curr(C.currency), sym = cu.sym && cu.sym !== cu.code ? cu.sym : '';
    var cc = document.createElement('span'); cc.className = 'omg-mk-cc'; cc.textContent = it.cc; el.appendChild(cc);
    var sp = document.createElement('span'); sp.className = 'omg-mk-sep'; sp.setAttribute('aria-hidden', 'true'); el.appendChild(sp);
    var cr = document.createElement('span'); cr.className = 'omg-mk-cur';
    if (sym) { var sy = document.createElement('span'); sy.className = 'omg-mk-sym'; sy.setAttribute('aria-hidden', 'true'); sy.textContent = sym; cr.appendChild(sy); }
    cr.appendChild(document.createTextNode(C.currency)); el.appendChild(cr);
    el.insertAdjacentHTML('beforeend', '<svg class="omg-mk-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>');
    el.setAttribute('aria-label', 'Region ' + it.name + ', currency ' + cu.name + '. Change');
  }
  function wire(el, withName){
    if (el.dataset.omgMk) return; el.dataset.omgMk = '1';
    label(el, withName);
    el.classList.add('omg-mk-on'); el.setAttribute('role', 'button'); el.setAttribute('tabindex', '0'); el.setAttribute('aria-haspopup', 'dialog'); el.setAttribute('aria-expanded', 'false');
    el.setAttribute('title', 'Region, language & currency');
    el.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); open(el); });
    el.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(el); } });
  }
  function start(){
    document.querySelectorAll('#omg-hdr-locale').forEach(function(el){ wire(el, false); });
    document.querySelectorAll('.omgx-loc').forEach(function(el){ wire(el, true); });
    document.querySelectorAll('[data-omg-market]').forEach(function(el){ wire(el, false); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}
