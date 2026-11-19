<?php
/**
 * Plugin Name: OMGGamer platform listings
 * Description: Games published as separate listings (OMGGamer OS: one product per platform + edition + region +
 *              type, like driffle) share a listing group (_omg_listing_group). Each product page gets the group as
 *              window.OMG_LISTINGS (the buy box's region dropdown and edition / type cards link to these products)
 *              and shows the other platforms as sliding product cards right below the offers.
 * Version: 1.2.0
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
            'numberposts' => 200,
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
            $region_names = wp_get_post_terms($id, 'pa_region', ['fields' => 'names']);
            $p = wc_get_product($id);
            $ext = (string) get_post_meta($id, '_omg_external_id', true);
            $out[] = [
                'id' => (int) $id,
                'platform' => $platform,
                'edition' => (string) get_post_meta($id, '_omg_listing_edition', true) ?: 'Standard',
                'region' => (string) get_post_meta($id, '_omg_listing_region', true),
                'type' => (string) get_post_meta($id, '_omg_listing_type', true) ?: 'Key',
                // The platform's own page: the original listing, or "<id>~<platform>" (its URL before the split).
                'primary' => !str_contains($ext, '~') || substr($ext, strpos($ext, '~') + 1) === sanitize_title($platform),
                'price' => $p ? (float) ($p->is_type('variable') ? $p->get_variation_price('min', true) : wc_get_price_to_display($p)) : 0.0,
                'in_stock' => $p ? $p->is_in_stock() : false,
                'url' => get_permalink($id),
                'regions' => is_wp_error($regions) ? [] : array_values($regions),
                'region_names' => is_wp_error($region_names) ? [] : array_values($region_names),
                'main' => !str_contains((string) get_post_meta($id, '_omg_external_id', true), '~'),
            ];
        }
        usort($out, static fn($a, $b) => [$b['main'], $a['platform']] <=> [$a['main'], $b['platform']]);
        return count($out) > 1 ? $out : [];
    }

    /** One card in the store's collection style (same markup as "Related games"). */
    private static function card(array $s): string
    {
        $p = wc_get_product($s['id']);
        if (!$p) {
            return '';
        }
        $img = (int) $p->get_image_id();
        $src = $img ? wp_get_attachment_image_url($img, 'woocommerce_single') : wc_placeholder_img_src();
        $srcset = $img ? (string) wp_get_attachment_image_srcset($img, 'woocommerce_single') : '';
        $min = (float) ($p->is_type('variable') ? $p->get_variation_price('min', true) : wc_get_price_to_display($p));
        $regions = $s['region_names'];
        $region = count($regions) === 1 ? $regions[0] : (count($regions) > 1 ? count($regions) . ' regions' : '');
        $name = trim((string) preg_replace('/\s*\(' . preg_quote($s['platform'], '/') . '\)\s*$/i', '', html_entity_decode($p->get_name(), ENT_QUOTES)));
        ob_start(); ?>
<div class="omg-ac"><a class="omg-ac-card" href="<?php echo esc_url($s['url']); ?>"><div class="omg-ac-cover"><img class="omg-ac-img" src="<?php echo esc_url((string) $src); ?>"<?php if ($srcset) : ?> srcset="<?php echo esc_attr($srcset); ?>" sizes="(max-width: 767px) 50vw, 25vw"<?php endif; ?> alt="<?php echo esc_attr($name . ' ' . $s['platform'] . ' cover'); ?>" loading="lazy"></div><div class="omg-ac-body"><h3 class="omg-ac-title"><?php echo esc_html($name); ?></h3><div class="omg-ac-meta"><span class="omg-ac-region"><?php echo esc_html($s['platform'] . ($region ? ' · ' . $region : '')); ?></span></div><?php if ($min > 0) : ?><span class="omg-ac-from">from</span><div class="omg-ac-pricerow"><div class="omg-ac-price"><p class="price"><?php echo wp_kses_post(wc_price($min)); ?></p></div></div><?php endif; ?></div></a><a class="omg-pf" href="<?php echo esc_url(home_url('/platform/' . sanitize_title($s['platform']) . '/')); ?>" data-pf="<?php echo esc_attr(sanitize_title($s['platform'])); ?>" title="<?php echo esc_attr($s['platform']); ?>" aria-label="<?php echo esc_attr('Shop ' . $s['platform']); ?>"><span><?php echo esc_html($s['platform']); ?></span></a></div>
        <?php
        return (string) ob_get_clean();
    }

    /** "Other platforms": the game's other platform listings as sliding product cards, below the offers. */
    public static function render(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $current = (int) get_queried_object_id();
        $all = self::siblings($current);
        if (!$all) {
            return;
        }
        $me = current(array_filter($all, static fn ($s) => $s['id'] === $current)) ?: null;
        // For the buy box: region dropdown and edition / type cards link to these products.
        $data = array_map(static fn ($s) => [
            'id' => $s['id'], 'url' => $s['url'], 'platform' => $s['platform'], 'edition' => $s['edition'],
            'region' => $s['region'] ?: ($s['region_names'][0] ?? ''), 'type' => $s['type'],
            'price' => $s['price'], 'in_stock' => $s['in_stock'],
        ], $all);
        echo '<script id="omg-listings-data">window.OMG_LISTINGS=' . wp_json_encode(['current' => $current, 'items' => $data]) . ';</script>';
        // "Other platforms": one card per other platform (its own page, else its cheapest product in stock).
        $by = [];
        foreach ($all as $s) {
            if ($me && $s['platform'] === $me['platform']) {
                continue;
            }
            $have = $by[$s['platform']] ?? null;
            $better = !$have || ($s['primary'] && !$have['primary'])
                || ($s['primary'] === $have['primary'] && $s['in_stock'] && (!$have['in_stock'] || $s['price'] < $have['price']));
            if ($better) {
                $by[$s['platform']] = $s;
            }
        }
        $others = array_values($by);
        foreach ($others as &$o) {
            $o['region_names'] = array_values(array_unique(array_column(array_filter($all, static fn ($s) => $s['platform'] === $o['platform']), 'region')));
        }
        unset($o);
        if (!$others) {
            return;
        }
        $cards = implode('', array_map([__CLASS__, 'card'], $others));
        if ($cards === '') {
            return;
        }
        ?>
<section id="omg-pl" class="omg-pp-card" aria-labelledby="omg-pl-h" hidden>
  <div class="omg-pl-head"><h2 id="omg-pl-h" class="omg-pp-h2">Other platforms</h2>
    <div class="omg-rel-nav"><button type="button" class="omg-rel-arrow" data-dir="-1" aria-label="Previous"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg></button><button type="button" class="omg-rel-arrow" data-dir="1" aria-label="Next"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></button></div>
  </div>
  <div class="omg-ar-cards omg-rel-cards omg-rel-track omg-pl-track"><?php echo $cards; // escaped in card() ?></div>
</section>
<style id="omg-pl-css">
#omg-pl{margin:0}
#omg-pl .omg-pl-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px}
#omg-pl .omg-pl-head h2{margin:0}
#omg-pl .omg-pl-track{grid-auto-columns:calc((100% - 60px) / 4)}
#omg-pl .omg-ac-meta{display:flex;flex-wrap:wrap;gap:6px}
@media (max-width:1000px){#omg-pl .omg-pl-track{grid-auto-columns:calc((100% - 20px) / 2.2)}}
@media (max-width:560px){#omg-pl .omg-pl-track{grid-auto-columns:72%}}
</style>
<script id="omg-pl-js">
(function () {
  var box = document.getElementById('omg-pl'); if (!box) return;
  // Right after the offers (else after the add-to-cart form); the layout orders cards with CSS "order".
  var anchor = document.getElementById('omg-dg-offers') || document.querySelector('form.variations_form, form.cart');
  if (anchor && anchor.parentNode) {
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
    var ord = getComputedStyle(anchor).order; if (ord && ord !== '0') box.style.order = ord;
  }
  box.hidden = false;
  var track = box.querySelector('.omg-pl-track'), btns = box.querySelectorAll('.omg-rel-arrow'), nav = box.querySelector('.omg-rel-nav');
  function update() {
    var max = track.scrollWidth - track.clientWidth - 2;
    if (nav) nav.hidden = max <= 0;
    btns[0].disabled = track.scrollLeft <= 2; btns[1].disabled = track.scrollLeft >= max;
  }
  btns.forEach(function (b) {
    b.addEventListener('click', function () {
      var c = track.querySelector('.omg-ac'); var step = c ? c.getBoundingClientRect().width + 20 : track.clientWidth * 0.8;
      track.scrollBy({ left: step * Number(b.getAttribute('data-dir')), behavior: 'smooth' });
    });
  });
  track.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
</script>
        <?php
    }
}

OMG_Platform_Listings::init();
