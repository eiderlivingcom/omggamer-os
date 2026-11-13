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
        return '<button type="button" class="omg-mk-btn" data-omg-market>' . OMG_Markets::flag($cc) . ' ' . esc_html($cc . ' · ' . OMG_Markets::currency($cc)) . '</button>';
    }

    public static function render(): void
    {
        if (is_admin() || count(OMG_Markets::enabled()) < 2) {
            return;
        }
        $cfg = ['current' => OMG_Markets::current(), 'items' => self::items(), 'base' => OMG_Markets::base_currency()];
        ?>
<style id="omg-markets-css">
#omg-hdr-locale.omg-mk-on,.omgx-loc.omg-mk-on,.omg-mk-btn{cursor:pointer;user-select:none}
#omg-hdr-locale.omg-mk-on:hover{background:#ECE7F7}
.omgx-loc.omg-mk-on:hover{opacity:.85}
.omg-mk-flag{font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif;margin-right:6px}
.omg-mk-pop{position:fixed;z-index:100000;width:300px;max-width:calc(100vw - 24px);max-height:min(420px,70vh);overflow:auto;background:#fff;border:1px solid #ECE9F3;border-radius:16px;box-shadow:0 24px 48px -12px rgba(13,6,25,.25);padding:8px;font:500 14px/1.3 Poppins,system-ui,sans-serif;color:#0D0619}
.omg-mk-pop[hidden]{display:none}
.omg-mk-pop h4{margin:6px 10px 8px;font:600 12px/1 Poppins,system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#8A82A3}
.omg-mk-pop a{display:flex;align-items:center;gap:10px;padding:10px;border-radius:10px;color:inherit;text-decoration:none}
.omg-mk-pop a:hover,.omg-mk-pop a:focus-visible{background:#F3F0FA;outline:none}
.omg-mk-pop a[aria-current=true]{background:#F3F0FA;color:#7C3AED;font-weight:600}
.omg-mk-pop .f{font-size:20px;line-height:1;font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif}
.omg-mk-pop .n{flex:1 1 auto}
.omg-mk-pop .c{color:#8A82A3;font-size:13px;font-variant-numeric:tabular-nums}
.omg-mk-pop .note{margin:8px 10px 4px;color:#8A82A3;font-size:12px;line-height:1.45}
</style>
<script id="omg-markets-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>, pop, opener;
  function url(cc){ var u = new URL(location.href); u.searchParams.set('market', cc.toLowerCase()); return u.toString(); }
  function cur(){ for (var i = 0; i < C.items.length; i++) if (C.items[i].cc === C.current) return C.items[i]; return C.items[0]; }
  function build(){
    pop = document.createElement('div'); pop.className = 'omg-mk-pop'; pop.hidden = true; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Choose your country and currency');
    var h = '<h4>Country &amp; currency</h4>';
    C.items.forEach(function(it){
      h += '<a href="' + url(it.cc) + '" rel="nofollow"' + (it.cc === C.current ? ' aria-current="true"' : '') + '><span class="f">' + it.flag + '</span><span class="n"></span><span class="c">' + it.cur + ' ' + it.sym + '</span></a>';
    });
    h += '<p class="note">Prices are converted from ' + C.base + ' at the European Central Bank’s daily reference rate. You pay in the currency shown.</p>';
    pop.innerHTML = h;
    pop.querySelectorAll('.n').forEach(function(n, i){ n.textContent = C.items[i].name; });
    document.body.appendChild(pop);
    document.addEventListener('click', function(e){ if (!pop.hidden && !pop.contains(e.target) && e.target !== opener && !(opener && opener.contains(e.target))) close(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !pop.hidden) { close(); opener && opener.focus(); } });
    window.addEventListener('resize', close); window.addEventListener('scroll', function(){ if (!pop.hidden) place(); }, {passive:true});
  }
  function place(){
    var r = opener.getBoundingClientRect(), w = pop.offsetWidth;
    pop.style.top = Math.round(r.bottom + 8) + 'px';
    pop.style.left = Math.round(Math.max(12, Math.min(window.innerWidth - w - 12, r.right - w))) + 'px';
  }
  function open(el){ if (!pop) build(); opener = el; pop.hidden = false; el.setAttribute('aria-expanded', 'true'); place(); var a = pop.querySelector('a[aria-current]') || pop.querySelector('a'); a && a.focus(); }
  function close(){ if (!pop || pop.hidden) return; pop.hidden = true; opener && opener.setAttribute('aria-expanded', 'false'); }
  function label(el, withName){
    var it = cur(); if (!it) return;
    el.textContent = '';
    var f = document.createElement('span'); f.className = 'omg-mk-flag'; f.setAttribute('aria-hidden', 'true'); f.textContent = it.flag;
    el.appendChild(f);
    el.appendChild(document.createTextNode(withName ? it.cur + ' · ' + it.name : it.cc));
    if (!withName) { var dot = document.createElement('span'); dot.className = 'omg-dot'; dot.textContent = '·'; el.appendChild(dot); el.appendChild(document.createTextNode(it.cur)); }
  }
  function wire(el, withName){
    if (el.dataset.omgMk) return; el.dataset.omgMk = '1';
    label(el, withName);
    el.classList.add('omg-mk-on'); el.setAttribute('role', 'button'); el.setAttribute('tabindex', '0'); el.setAttribute('aria-haspopup', 'dialog'); el.setAttribute('aria-expanded', 'false');
    el.setAttribute('title', 'Change country and currency');
    el.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); pop && !pop.hidden && opener === el ? close() : open(el); });
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
