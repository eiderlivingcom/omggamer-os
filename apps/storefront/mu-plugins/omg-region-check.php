<?php
/**
 * Plugin Name: OMGGamer region check
 * Description: The product page's "Check the region before you buy" box only appears when the visitor's country
 *              (from their IP, via Cloudflare) is not covered by the region of the chosen product/edition.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Region_Check
{
    private const EUROPE = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'CH', 'GB'];

    public static function init(): void
    {
        add_filter('bricks/frontend/render_data', [__CLASS__, 'hide_box'], 25, 3);
        add_action('wp_footer', [__CLASS__, 'script'], 60);
    }

    /** The visitor's country from the IP (Cloudflare), else their chosen store market. */
    public static function country(): string
    {
        $cc = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')), 0, 2));
        if ($cc === '' || $cc === 'XX' || $cc === 'T1') {
            $cc = class_exists('OMG_Markets') ? OMG_Markets::current() : '';
        }
        return $cc === 'UK' ? 'GB' : $cc;
    }

    /** Countries a region slug covers: ['*'] everywhere, [] unknown/none. */
    private static function covers(string $slug): array
    {
        $map = class_exists('OMG_Markets') ? (OMG_Markets::settings()['regions'] ?? []) : [];
        if (array_key_exists($slug, $map)) {
            $v = trim((string) $map[$slug]);
            if ($v === '*') {
                return ['*'];
            }
            if ($v !== '') {
                return array_map(static fn ($c) => strtoupper(trim($c)) === 'UK' ? 'GB' : strtoupper(trim($c)), explode(',', $v));
            }
        }
        if (preg_match('/global|region-?free|worldwide/', $slug)) {
            return ['*'];
        }
        if (strpos($slug, 'europe') !== false || $slug === 'eu') {
            return self::EUROPE;
        }
        return [];
    }

    /** Start hidden; the script below shows the box only when the region does not cover the visitor. */
    public static function hide_box($html, $post = null, $area = '')
    {
        if (!is_string($html) || strpos($html, 'id="omg-dg-warning"') === false || strpos($html, 'data-omg-rw') !== false) {
            return $html;
        }
        $out = preg_replace('~<div([^>]*\bid="omg-dg-warning")~', '<div$1 hidden data-omg-rw', $html, 1);
        return is_string($out) ? $out : $html;
    }

    public static function script(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $product = wc_get_product(get_queried_object_id());
        if (!$product) {
            return;
        }
        $cc = self::country();
        $regions = [];
        foreach (get_terms(['taxonomy' => 'pa_region', 'hide_empty' => false]) ?: [] as $t) {
            if (is_wp_error($t)) {
                continue;
            }
            $c = self::covers($t->slug);
            $regions[$t->slug] = ['name' => html_entity_decode($t->name), 'ok' => $cc !== '' && (in_array('*', $c, true) || in_array($cc, $c, true))];
        }
        $cfg = [
            'country' => $cc,
            'countryName' => $cc && function_exists('WC') && WC()->countries ? html_entity_decode(WC()->countries->get_countries()[$cc] ?? $cc) : '',
            'regions' => $regions,
            'product' => wc_get_product_terms($product->get_id(), 'pa_region', ['fields' => 'slugs']),
            'custom' => trim(wp_strip_all_tags((string) get_post_meta($product->get_id(), 'omg_region_warning', true))),
        ];
        ?>
<style>.omg-rw-box{display:flex;flex-direction:column;gap:4px;padding:14px 16px;border-radius:14px;background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.35);box-sizing:border-box;width:100%}.omg-rw-box[hidden]{display:none}.omg-rw-box p{margin:0;font-size:13px;line-height:1.45;color:#fbbf24}.omg-rw-box #omg-dg-warning-title{font-weight:600}</style>
<script id="omg-region-check">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>;
  function slugs(){
    var sel = document.querySelector('form.variations_form [name="attribute_pa_region"]');
    if (sel && sel.value) return [sel.value];
    return C.product || [];
  }
  function paint(){
    var s = slugs(), known = s.filter(function(x){ return C.regions[x]; });
    var ok = !C.country || !known.length || known.some(function(x){ return C.regions[x].ok; });
    var box = document.getElementById('omg-dg-warning');
    if (!box) {
      if (ok) return;
      var hero = document.getElementById('omg-dg-hero'); if (!hero) return;
      box = document.createElement('div'); box.id = 'omg-dg-warning'; box.className = 'omg-rw-box';
      box.innerHTML = '<p id="omg-dg-warning-title"></p><p id="omg-dg-warning-text"></p>';
      hero.insertAdjacentElement('afterend', box);
    }
    box.hidden = ok;
    if (ok) return;
    var name = known.map(function(x){ return C.regions[x].name; }).join(', ');
    var title = document.getElementById('omg-dg-warning-title'), text = document.getElementById('omg-dg-warning-text');
    if (title) title.textContent = 'Check the region before you buy';
    if (text) {
      text.textContent = 'This key is for ' + name + '. You are browsing from ' + (C.countryName || C.country) + ', so it may not activate there.';
      if (C.custom && C.custom.length > 12) { var p = document.createElement('span'); p.style.display = 'block'; p.style.marginTop = '4px'; p.textContent = C.custom; text.appendChild(p); }
    }
  }
  function start(){ paint(); document.addEventListener('change', function(e){ if (e.target && e.target.closest && e.target.closest('form.variations_form, #omg-dg-ed')) setTimeout(paint, 60); }); if (window.jQuery) jQuery(document).on('found_variation reset_data', 'form.variations_form', function(){ setTimeout(paint, 30); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}

OMG_Region_Check::init();
