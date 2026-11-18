<?php
/**
 * Plugin Name: OMGGamer platform listings
 * Description: Games published as one listing per platform (OMGGamer OS → one listing per platform) share a listing
 *              group (_omg_listing_group). Their product pages show a "Platform" switcher above the edition/region
 *              picker: the current platform is selected, the others link to their own listing and keep the chosen
 *              region (?attribute_pa_region=...) when that listing sells it.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Platform_Listings
{
    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 95);
    }

    /** Published listings of the same game: [[id, platform, url, regions[]]], main listing first. */
    private static function siblings(int $product_id): array
    {
        $group = (string) get_post_meta($product_id, '_omg_listing_group', true);
        if ($group === '') {
            return [];
        }
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'numberposts' => 20,
            'fields' => 'ids',
            'meta_key' => '_omg_listing_group',
            'meta_value' => $group,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
        $out = [];
        foreach ($ids as $id) {
            $platform = (string) get_post_meta($id, '_omg_listing_platform', true);
            if ($platform === '') {
                continue;
            }
            $regions = wp_get_post_terms($id, 'pa_region', ['fields' => 'slugs']);
            $out[] = [
                'id' => (int) $id,
                'platform' => $platform,
                'url' => get_permalink($id),
                'regions' => is_wp_error($regions) ? [] : array_values($regions),
                'main' => !str_contains((string) get_post_meta($id, '_omg_external_id', true), '~'),
            ];
        }
        usort($out, static fn($a, $b) => [$b['main'], $a['platform']] <=> [$a['main'], $b['platform']]);
        return count($out) > 1 ? $out : [];
    }

    public static function render(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $current = (int) get_queried_object_id();
        $list = self::siblings($current);
        if (!$list) {
            return;
        }
        ?>
<div id="omg-pl" class="omg-pp-card" hidden>
  <div class="omg-pl-head"><span>Platform</span></div>
  <div class="omg-pl-list" role="list">
    <?php foreach ($list as $s) :
        $on = $s['id'] === $current; ?>
    <a role="listitem" class="omg-pl-item<?php echo $on ? ' is-active' : ''; ?>" href="<?php echo esc_url($s['url']); ?>"
       data-regions="<?php echo esc_attr(implode(' ', $s['regions'])); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
      <span class="omg-pf" data-pf="<?php echo esc_attr(sanitize_title($s['platform'])); ?>" aria-hidden="true"></span>
      <span class="omg-pl-name"><?php echo esc_html($s['platform']); ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<style id="omg-pl-css">
#omg-pl{margin-bottom:16px}
#omg-pl .omg-pl-head{margin-bottom:10px;font-weight:600;color:#f4f2fb}
#omg-pl .omg-pl-list{display:flex;flex-wrap:wrap;gap:8px}
#omg-pl .omg-pl-item{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:8px 14px;border-radius:12px;border:1px solid #2a2540;background:#141121;color:#d9d4ea;text-decoration:none;font-weight:600;font-size:14px;transition:border-color .2s,background .2s}
#omg-pl .omg-pl-item:hover{border-color:#8b5cf6}
#omg-pl .omg-pl-item.is-active{border-color:#8b5cf6;background:rgba(139,92,246,.14);color:#fff;cursor:default}
#omg-pl .omg-pf{width:22px;height:22px;border-radius:6px;flex:none}
#omg-pl .omg-pf::before{width:14px;height:14px;margin:4px}
#omg-pl .omg-pl-item:focus-visible{outline:2px solid #c4b5fd;outline-offset:2px}
</style>
<script id="omg-pl-js">
(function () {
  var box = document.getElementById('omg-pl'); if (!box) return;
  // Above the edition / region picker, else above the add-to-cart form.
  var anchor = document.getElementById('omg-dg-ed') || document.querySelector('form.variations_form, form.cart');
  if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(box, anchor);
  box.hidden = false;
  var links = box.querySelectorAll('.omg-pl-item:not(.is-active)');
  var base = []; links.forEach(function (a) { base.push(a.href); });
  function keepRegion() {
    var sel = document.querySelector('select[name="attribute_pa_region"]');
    var r = sel ? sel.value : '';
    links.forEach(function (a, i) {
      var ok = r && (' ' + a.getAttribute('data-regions') + ' ').indexOf(' ' + r + ' ') !== -1;
      a.href = ok ? base[i] + (base[i].indexOf('?') === -1 ? '?' : '&') + 'attribute_pa_region=' + encodeURIComponent(r) : base[i];
    });
  }
  document.addEventListener('change', function (e) { if (e.target && e.target.name === 'attribute_pa_region') keepRegion(); });
  if (window.jQuery) jQuery(document).on('found_variation reset_data', 'form.variations_form', keepRegion);
  keepRegion();
})();
</script>
        <?php
    }
}

OMG_Platform_Listings::init();
