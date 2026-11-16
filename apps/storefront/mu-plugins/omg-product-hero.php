<?php
/**
 * Plugin Name: OMGGamer product hero and recent purchases
 * Description: Product page hero: the platform/region chips (repeated in the specs below) are replaced by the live
 *              "N people bought it today" line. "Recent purchases" lists real paid orders only (initial, country
 *              flag, product, time); new purchases slide in one at a time as they happen. Nothing is invented: with
 *              no recent orders the box stays hidden.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Product_Hero
{
    private const DAYS = 3;      // orders older than this are not shown
    private const LIMIT = 8;

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_footer', [__CLASS__, 'footer'], 96);
        add_action('woocommerce_order_status_changed', static fn () => delete_transient('omg_recent_purchases'));
    }

    public static function routes(): void
    {
        register_rest_route('omg/v1', '/recent-purchases', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => static function () {
                $r = rest_ensure_response(self::recent());
                $r->header('Cache-Control', 'no-store');
                return $r;
            },
        ]);
    }

    /** Real paid orders from the last few days. Only a first initial and the billing country are shown. */
    public static function recent(): array
    {
        $cached = get_transient('omg_recent_purchases');
        if (is_array($cached)) {
            return $cached;
        }
        $out = [];
        if (function_exists('wc_get_orders')) {
            $pro = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 0;
            $orders = wc_get_orders([
                'status' => ['wc-processing', 'wc-completed'],
                'date_created' => '>' . (time() - self::DAYS * DAY_IN_SECONDS),
                'orderby' => 'date',
                'order' => 'DESC',
                'limit' => self::LIMIT * 2,
                'type' => 'shop_order',
            ]);
            foreach ($orders as $o) {
                $name = '';
                foreach ($o->get_items() as $item) {
                    if ((int) $item->get_product_id() !== $pro) {
                        $name = $item->get_name();
                        break;
                    }
                }
                if ($name === '') {
                    continue;
                }
                $first = trim((string) $o->get_billing_first_name());
                $cc = strtoupper((string) $o->get_billing_country());
                $created = $o->get_date_paid() ?: $o->get_date_created();
                $out[] = [
                    'id' => md5((string) $o->get_id() . wp_salt()),
                    'who' => $first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) . '.' : 'A customer',
                    'cc' => preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '',
                    'product' => wp_strip_all_tags($name),
                    'at' => $created ? $created->getTimestamp() : time(),
                ];
                if (count($out) >= self::LIMIT) {
                    break;
                }
            }
        }
        set_transient('omg_recent_purchases', $out, 60);
        return $out;
    }

    public static function footer(): void
    {
        if (is_admin() || !function_exists('is_product') || !is_product()) {
            return;
        }
        ?>
<style id="omg-hero-css">
/* Hero: platform and region chips are already listed in the specs; the "bought today" line takes their place. */
html body #omg-dg-pill-platform,html body #omg-dg-pill-region{display:none!important}
html body #omg-dg-pills .omg-hero-sold{display:inline-flex;align-items:center;gap:8px;font:500 15px/1.3 Poppins,system-ui,sans-serif;color:#d9d6e8}
html body #omg-dg-pills .omg-hero-sold[hidden]{display:none!important}
html body #omg-dg-pills .omg-hero-sold b{color:#fff;font-weight:700}
html body #omg-dg-pills .omg-hero-sold svg{color:#fb923c;flex:none}
body:has(#omg-dg-pills .omg-hero-sold) .omg-bb .omg-bb-sold:not(.omg-hero-sold){display:none!important}
/* The template's own feed and counters ("Recent purchases", "N people bought it today", "N people watching")
   are generated from random numbers: never shown. The real ones come from orders and live visitors. */
html body .omg-fx-feed,html body .omg-fx-sold,html body .omg-fx-watch{display:none!important}
.omg-rp{margin-top:16px;padding:18px 20px;border-radius:16px;background:#141121;border:1px solid #2a2540}
.omg-rp-h{display:flex;align-items:center;gap:10px;margin:0 0 10px;color:#fff;font:600 16px/1.2 Poppins,system-ui,sans-serif}
.omg-rp-h .dot{width:10px;height:10px;border-radius:50%;background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.55);animation:omg-rp-pulse 2s ease-out infinite}
@keyframes omg-rp-pulse{70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.omg-rp ul{list-style:none;margin:0;padding:0}
.omg-rp li{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid #231f35;font:400 14px/1.4 Poppins,system-ui,sans-serif;color:#a79ec0}
.omg-rp li:first-child{border-top:0}
.omg-rp li.is-new{animation:omg-rp-in .5s ease-out}
@keyframes omg-rp-in{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.omg-rp .av{flex:none;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#2a2540;color:#cdbbff}
.omg-rp .tx{flex:1 1 auto;min-width:0}
.omg-rp .tx b{color:#f4f2fb;font-weight:600}
.omg-rp .fl{font-size:15px;line-height:1}
.omg-rp .ago{flex:none;color:#8d84a8;font-size:13px}
@media (prefers-reduced-motion:reduce){.omg-rp li.is-new{animation:none}.omg-rp-h .dot{animation:none}}
</style>
<script id="omg-hero-js">
(function(){
  var REST = <?php echo wp_json_encode(esc_url_raw(rest_url('omg/v1/recent-purchases'))); ?>;
  var fire = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c4 0 7-3 7-7 0-3-2-5.5-3.5-7 0 2-1 3-2 3 0-3-1.5-6-4.5-8 .5 3-1 5-2.5 6.5C5 11 5 13 5 15c0 4 3 7 7 7z"/></svg>';
  var user = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
  function hero(){
    var pills = document.getElementById('omg-dg-pills');
    if (!pills || pills.querySelector('.omg-hero-sold')) return;
    var s = document.createElement('span');
    s.className = 'omg-bb-sold omg-hero-sold'; s.hidden = true; s.setAttribute('role', 'status');
    s.innerHTML = fire + '<span class="t"></span>';
    pills.appendChild(s);
  }
  function flag(cc){
    if (!/^[A-Z]{2}$/.test(cc || '')) return '';
    return String.fromCodePoint(0x1F1E6 + cc.charCodeAt(0) - 65, 0x1F1E6 + cc.charCodeAt(1) - 65);
  }
  function ago(ts){
    var m = Math.max(1, Math.round((Date.now() / 1000 - ts) / 60));
    if (m < 60) return m + ' min ago';
    var h = Math.round(m / 60); if (h < 24) return h + (h === 1 ? ' hour ago' : ' hours ago');
    var d = Math.round(h / 24); return d + (d === 1 ? ' day ago' : ' days ago');
  }
  function esc(t){ var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
  var box = null, list = null, seen = {};
  function row(p, isNew){
    var li = document.createElement('li'); if (isNew) li.className = 'is-new';
    li.innerHTML = '<span class="av">' + user + '</span><span class="tx"><b>' + esc(p.who) + '</b>'
      + (p.cc ? ' <span class="fl" role="img" aria-label="' + esc(p.cc) + '">' + flag(p.cc) + '</span>' : '')
      + ' purchased <b>' + esc(p.product) + '</b></span><span class="ago" data-at="' + p.at + '">' + ago(p.at) + '</span>';
    return li;
  }
  function ensureBox(){
    if (box) return true;
    var anchor = document.getElementById('omg-dg-help') || document.querySelector('.omg-bb');
    if (!anchor || !anchor.parentNode) return false;
    box = document.createElement('div'); box.className = 'omg-rp';
    box.innerHTML = '<div class="omg-rp-h"><span class="dot" aria-hidden="true"></span>Recent purchases</div><ul aria-live="polite"></ul>';
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
    list = box.querySelector('ul');
    return true;
  }
  function load(first){
    fetch(REST, {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(items){
      if (!Array.isArray(items) || !items.length) return;
      if (!ensureBox()) return;
      var fresh = items.filter(function(p){ return !seen[p.id]; });
      if (first) {
        items.slice(0, 5).forEach(function(p){ seen[p.id] = 1; list.appendChild(row(p, false)); });
      } else {
        // Real new orders appear one at a time at the top.
        fresh.reverse().forEach(function(p, i){
          setTimeout(function(){ seen[p.id] = 1; list.insertBefore(row(p, true), list.firstChild); while (list.children.length > 5) list.removeChild(list.lastChild); }, i * 1500);
        });
      }
      list.querySelectorAll('.ago').forEach(function(a){ a.textContent = ago(+a.getAttribute('data-at')); });
    }).catch(function(){});
  }
  function start(){
    hero();
    load(true);
    setInterval(function(){ if (!document.hidden) load(false); }, 120000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Product_Hero::init();
