<?php
/**
 * Plugin Name: OMGGamer flash sale and live stats
 * Description: Real 6-hour flash sales: each window gives a rotating set of products an extra discount (never below
 *              the profit floor); the buy box counts down to the real end of the window and the price goes back when
 *              it ends. Live stats on product pages from real data: visitors on the page in the last 15 minutes
 *              ("watching", shown from 3) and orders today ("bought today", shown from 1).
 *              Settings: WooCommerce → OMG flash sale.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Flash
{
    public const OPTION = 'omg_flash';
    private const WATCH_WINDOW = 900;   // seconds a visitor counts as "watching"
    private const WATCH_MIN = 3;        // show the watching line from this many visitors

    private static $ids = null;

    public static function settings(): array
    {
        $s = get_option(self::OPTION, []);
        return array_merge(['enabled' => 1, 'hours' => 6, 'percent' => 10, 'count' => 12], is_array($s) ? $s : []);
    }

    public static function init(): void
    {
        add_filter('bricks/frontend/render_data', [__CLASS__, 'buybox_timer'], 30, 3);
        add_action('wp_ajax_nopriv_omg_live', [__CLASS__, 'live']);
        add_action('wp_ajax_omg_live', [__CLASS__, 'live']);
        add_action('wp_footer', [__CLASS__, 'js'], 88);
        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'menu'], 71);
            add_action('admin_post_omg_flash_save', [__CLASS__, 'save']);
        }
    }

    /* ================================================== windows */

    public static function active(): bool
    {
        $s = self::settings();
        return !empty($s['enabled']) && (float) $s['percent'] > 0 && (int) $s['count'] > 0;
    }

    private static function length(): int
    {
        return max(1, min(24, (int) self::settings()['hours'])) * HOUR_IN_SECONDS;
    }

    /** Current window number and its end (unix time). Windows are aligned to midnight UTC. */
    public static function window(): array
    {
        $len = self::length();
        $idx = (int) floor(time() / $len);
        return ['idx' => $idx, 'end' => ($idx + 1) * $len];
    }

    /** Cache key part: prices change when the window or the settings change. */
    public static function key(): string
    {
        return self::active() ? 'f' . self::window()['idx'] . '-' . md5(wp_json_encode(self::settings())) : 'f0';
    }

    /** Product ids in the current window (parent ids). */
    public static function ids(): array
    {
        if (self::$ids !== null) {
            return self::$ids;
        }
        if (!self::active()) {
            return self::$ids = [];
        }
        // Flash deals chosen in OMGGamer OS (Storefront page) replace the rotating set.
        $chosen = function_exists('omg_home_section') ? omg_home_section('flash') : [];
        if ($chosen) {
            return self::$ids = array_values(array_filter($chosen, static function ($id) {
                $p = wc_get_product($id);
                return $p && self::flashable($p);
            }));
        }
        $w = self::window();
        $cache = 'omg_flash_v2_' . $w['idx'] . '_' . md5(wp_json_encode(self::settings()));
        $ids = get_transient($cache);
        if (!is_array($ids)) {
            global $wpdb;
            $s = self::settings();
            $pro = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
            $hidden = 0;
            $t = get_term_by('slug', 'exclude-from-catalog', 'product_visibility');
            if ($t) {
                $hidden = (int) $t->term_taxonomy_id;
            }
            $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p JOIN {$lookup} l ON l.product_id = p.ID
                 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID <> %d AND l.stock_status = 'instock' AND l.min_price > 0
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id = %d)
                 ORDER BY MD5(CONCAT(p.ID, '-', %d)) LIMIT %d",
                $pro,
                $hidden,
                $w['idx'],
                (int) $s['count'] * 4
            ));
            $ids = [];
            foreach ($rows as $id) {
                $p = wc_get_product((int) $id);
                if ($p && self::flashable($p)) {
                    $ids[] = (int) $id;
                }
                if (count($ids) >= (int) $s['count']) {
                    break;
                }
            }
            set_transient($cache, $ids, max(60, $w['end'] - time()));
        }
        return self::$ids = array_map('intval', $ids);
    }

    /** The extra discount would actually lower the price (stays above the profit floor). */
    private static function flashable(WC_Product $p): bool
    {
        // Flash deals are for digital products; hardware and accessories have their own home sections.
        if (class_exists('OMG_SEO') && OMG_SEO::physical($p->get_id())) {
            return false;
        }
        $check = $p->is_type('variable') ? array_slice($p->get_children(), 0, 6) : [$p->get_id()];
        foreach ($check as $cid) {
            $base = self::base_price((int) $cid);
            if ($base > 0 && self::discounted($base, (int) $cid) < $base) {
                return true;
            }
        }
        return false;
    }

    /** Price before the flash discount, in the base currency (storewide sale or own sale price). */
    private static function base_price(int $id): float
    {
        $c = get_post_custom($id);
        $reg = (float) ($c['_regular_price'][0] ?? 0);
        $sale = $c['_sale_price'][0] ?? '';
        if (is_numeric($sale) && (float) $sale > 0 && (float) $sale < $reg) {
            return (float) $sale;
        }
        return $reg > 0 && class_exists('OMG_Pricing') ? OMG_Pricing::sale_of($reg) : $reg;
    }

    private static function floor_for(int $id): float
    {
        $c = get_post_custom($id);
        $meta = $c['_omg_floor_price'][0] ?? '';
        if (is_numeric($meta) && (float) $meta > 0) {
            return (float) $meta;
        }
        $reg = (float) ($c['_regular_price'][0] ?? 0);
        return $reg > 0 && class_exists('OMG_Pricing') ? OMG_Pricing::floor_of($reg) : 0.0;
    }

    private static function discounted(float $base, int $id): float
    {
        $pct = max(0, min(90, (float) self::settings()['percent']));
        $v = round($base * (1 - $pct / 100), 2);
        return max($v, round(self::floor_for($id), 2));
    }

    public static function is_flash($product): bool
    {
        if (!self::active()) {
            return false;
        }
        $id = $product instanceof WC_Product ? ($product->get_parent_id() ?: $product->get_id()) : (int) $product;
        return in_array($id, self::ids(), true);
    }

    /** Flash price for a product/variation given its current price (base currency); unchanged when not in the sale. */
    public static function apply(float $base, $product, int $parent = 0): float
    {
        if ($base <= 0 || !self::is_flash($parent ?: $product)) {
            return $base;
        }
        $id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
        return min($base, self::discounted($base, $id));
    }

    /* ================================================== buy box */

    /** The buy box timer counts down to the real end of this window; label it as the flash sale. */
    public static function buybox_timer($html, $post = null, $area = '')
    {
        if (!is_string($html) || $area !== 'content' || !function_exists('is_product') || !is_product() || strpos($html, 'class="omg-bb"') === false) {
            return $html;
        }
        $pid = get_queried_object_id();
        if (!self::is_flash($pid)) {
            return $html;
        }
        $end = self::window()['end'];
        $out = preg_replace('~(<div class="omg-bb"[^>]*?)data-ends="[^"]*"~', '$1data-ends="' . $end . '"', $html, 1);
        $out = is_string($out) ? str_replace('Ends in <strong data-omg-countdown>', 'Flash sale ends in <strong data-omg-countdown>', $out) : $html;
        return $out;
    }

    /* ================================================== live stats */

    public static function live(): void
    {
        nocache_headers();
        $pid = absint($_POST['pid'] ?? 0);
        $vid = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($_POST['v'] ?? '')));
        if (!$pid || get_post_type($pid) !== 'product' || strlen($vid) < 8) {
            wp_send_json_error(null, 400);
        }
        $vid = substr($vid, 0, 32);
        $key = 'omg_w_' . $pid;
        $now = time();
        $seen = get_transient($key);
        $seen = is_array($seen) ? $seen : [];
        $seen = array_filter($seen, static fn ($t) => $t > $now - self::WATCH_WINDOW);
        $seen[$vid] = $now;
        if (count($seen) > 500) {
            arsort($seen);
            $seen = array_slice($seen, 0, 500, true);
        }
        set_transient($key, $seen, self::WATCH_WINDOW + 300);
        wp_send_json_success(['watching' => count($seen), 'bought' => self::bought_today($pid), 'min' => self::WATCH_MIN]);
    }

    /** Paid orders containing this product (or its variations) since midnight, site time. */
    private static function bought_today(int $pid): int
    {
        $cache = 'omg_bt_' . $pid;
        $v = get_transient($cache);
        if ($v !== false) {
            return (int) $v;
        }
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $stats = $wpdb->prefix . 'wc_order_stats';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return 0;
        }
        $midnight = (new DateTime('today', wp_timezone()))->format('Y-m-d H:i:s');
        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT l.order_id) FROM {$lookup} l JOIN {$stats} s ON s.order_id = l.order_id
             WHERE l.product_id = %d AND l.date_created >= %s AND s.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')",
            $pid,
            $midnight
        ));
        set_transient($cache, $n, 120);
        return $n;
    }

    public static function js(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $cfg = [
            'ajax' => admin_url('admin-ajax.php'),
            'pid' => get_queried_object_id(),
            'flashEnd' => self::is_flash(get_queried_object_id()) ? self::window()['end'] : 0,
        ];
        ?>
<style id="omg-live-css">
.omg-bb-watch{display:flex;align-items:center;gap:10px;margin:0 0 12px;padding:10px 14px;border-radius:12px;background:linear-gradient(120deg,rgba(124,58,237,.38),rgba(192,38,211,.22));border:1px solid rgba(139,92,246,.35);color:#f4f2fb;font:400 14px/1.3 Poppins,system-ui,sans-serif}
.omg-bb-watch[hidden]{display:none}
.omg-bb-watch b{font-weight:700}
.omg-bb-watch .dot{flex:none;width:10px;height:10px;border-radius:50%;background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.6);animation:omg-live-pulse 2s ease-out infinite}
@keyframes omg-live-pulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.55)}70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
@media (prefers-reduced-motion:reduce){.omg-bb-watch .dot{animation:none}}
html body .omg-bb .omg-bb-top{display:flex!important;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
html body .omg-bb .omg-bb-top:has(.omg-bb-sold:not([hidden])){min-height:28px!important;margin-bottom:12px!important}
html body #omg-dg .omg-bb .omg-bb-timer,html body .omg-bb .omg-bb-timer{background:rgba(245,158,11,.12)!important;border:1px solid rgba(245,158,11,.35)!important;color:#fbbf24!important;border-radius:999px!important}
html body .omg-bb .omg-bb-timer strong{color:#fde68a!important;font-variant-numeric:tabular-nums}
.omg-bb-sold{display:inline-flex;align-items:center;gap:6px;margin-left:auto;color:#d9d6e8;font:500 13px/1.2 Poppins,system-ui,sans-serif}
.omg-bb-sold[hidden]{display:none}
.omg-bb-sold b{color:#f4f2fb;font-weight:700}
.omg-bb-sold svg{color:#fb923c;flex:none}
</style>
<script id="omg-live-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>;
  var vid = '';
  try { vid = localStorage.getItem('omg_vid') || ''; if (!vid) { vid = (Date.now().toString(36) + Math.random().toString(36).slice(2, 12)); localStorage.setItem('omg_vid', vid); } } catch (e) { vid = Math.random().toString(36).slice(2, 14); }
  function els(){
    document.querySelectorAll('.omg-bb').forEach(function(bb){
      var opts = bb.querySelector('.omg-bb-opts'), top = bb.querySelector('.omg-bb-top');
      if (opts && !bb.querySelector('.omg-bb-watch')) { var w = document.createElement('div'); w.className = 'omg-bb-watch'; w.hidden = true; w.setAttribute('role', 'status'); w.innerHTML = '<span class="dot" aria-hidden="true"></span><span class="t"></span>'; opts.parentNode.insertBefore(w, opts); }
      if (top && !top.querySelector('.omg-bb-sold')) { var s = document.createElement('span'); s.className = 'omg-bb-sold'; s.hidden = true; s.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3-1.1-2.1-.2-4 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.2.4-2.3 1-3.3.3 1.2 1.1 2.3 2.5 2.8z"/></svg><span class="t"></span>'; top.appendChild(s); }
    });
  }
  function paint(d){
    els();
    document.querySelectorAll('.omg-bb-watch').forEach(function(w){
      var on = d.watching >= d.min; w.hidden = !on;
      if (on) w.querySelector('.t').innerHTML = '<b>' + d.watching + ' people</b> watching this product';
    });
    document.querySelectorAll('.omg-bb-sold').forEach(function(s){
      var on = d.bought >= 1; s.hidden = !on;
      if (on) s.querySelector('.t').innerHTML = '<b>' + d.bought + '</b> ' + (d.bought === 1 ? 'person' : 'people') + ' bought it today';
    });
  }
  function ping(){
    if (document.hidden) return;
    var b = new URLSearchParams(); b.append('action', 'omg_live'); b.append('pid', C.pid); b.append('v', vid);
    fetch(C.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: b.toString()})
      .then(function(r){ return r.json(); }).then(function(j){ if (j && j.success) paint(j.data); }).catch(function(){});
  }
  function start(){ els(); ping(); setInterval(ping, 60000); document.addEventListener('visibilitychange', function(){ if (!document.hidden) ping(); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  // The flash price really ends with the timer: reload to show the new price.
  if (C.flashEnd) {
    var left = C.flashEnd * 1000 - Date.now() + 3000;
    if (left > 0 && left < 2147483647) setTimeout(function reload(){ if (document.hidden) { document.addEventListener('visibilitychange', function once(){ document.removeEventListener('visibilitychange', once); location.reload(); }); } else { location.reload(); } }, left);
  }
})();
</script>
        <?php
    }

    /* ================================================== settings */

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'OMG flash sale', 'OMG flash sale', 'manage_woocommerce', 'omg-flash', [__CLASS__, 'page']);
    }

    public static function save(): void
    {
        check_admin_referer('omg_flash_save');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        $in = wp_unslash($_POST);
        update_option(self::OPTION, [
            'enabled' => empty($in['enabled']) ? 0 : 1,
            'hours' => max(1, min(24, (int) ($in['hours'] ?? 6))),
            'percent' => max(1, min(50, round((float) ($in['percent'] ?? 10), 1))),
            'count' => max(1, min(200, (int) ($in['count'] ?? 12))),
        ]);
        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::get_transient_version('product', true);
        }
        wp_safe_redirect(admin_url('admin.php?page=omg-flash&saved=1'));
        exit;
    }

    public static function page(): void
    {
        $s = self::settings();
        $w = self::window();
        $ids = self::ids();
        ?>
<div class="wrap">
  <h1>OMG flash sale</h1>
  <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
  <p>Each window gives a new set of products an extra discount on top of their sale price. The buy box counts down to the end of the window, and the price goes back to normal when it ends. A product is never discounted below its profit floor (supplier cost + minimum profit), and products the extra discount cannot lower are not picked.</p>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('omg_flash_save'); ?><input type="hidden" name="action" value="omg_flash_save">
    <table class="form-table">
      <tr><th>Flash sales</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Run flash sales</label></td></tr>
      <tr><th>Window length (hours)</th><td><input type="number" min="1" max="24" name="hours" value="<?php echo esc_attr($s['hours']); ?>"> <p class="description">Windows start at midnight UTC and follow on from each other.</p></td></tr>
      <tr><th>Extra discount %</th><td><input type="number" step="0.1" min="1" max="50" name="percent" value="<?php echo esc_attr($s['percent']); ?>"></td></tr>
      <tr><th>Products per window</th><td><input type="number" min="1" max="200" name="count" value="<?php echo esc_attr($s['count']); ?>"></td></tr>
    </table>
    <?php submit_button('Save'); ?>
  </form>
  <?php if (self::active()) : ?>
  <h2>Current window</h2>
  <p>Ends <?php echo esc_html(wp_date('j M Y, H:i', $w['end'])); ?> (<?php echo esc_html(human_time_diff(time(), $w['end'])); ?> from now).</p>
  <ul style="list-style:disc;padding-left:20px">
    <?php foreach ($ids as $id) : ?><li><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a></li><?php endforeach; ?>
    <?php if (!$ids) : ?><li>No product can take the extra discount above its profit floor right now.</li><?php endif; ?>
  </ul>
  <?php endif; ?>
</div>
        <?php
    }
}

OMG_Flash::init();
