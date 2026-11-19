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

    /** "All variations": every product of the game in a responsive pop-up, filtered by platform and region. */
    private static function variations_modal(): void
    {
        ?>
<div id="omg-av" class="omg-av" role="dialog" aria-modal="true" aria-labelledby="omg-av-h" hidden>
  <div class="omg-av-backdrop" data-close></div>
  <div class="omg-av-box">
    <div class="omg-av-top"><h2 id="omg-av-h">All variations</h2><button type="button" class="omg-av-x" data-close aria-label="Close"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
    <div class="omg-av-filters"><div class="omg-av-chips" role="group" aria-label="Platform"></div><label class="omg-av-region"><span class="sr">Region</span><select aria-label="Region"></select></label></div>
    <ul class="omg-av-list"></ul>
  </div>
</div>
<style id="omg-av-css">
.omg-av{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;font-family:Poppins,system-ui,sans-serif}
.omg-av[hidden]{display:none}
.omg-av-backdrop{position:absolute;inset:0;background:rgba(5,3,12,.72);backdrop-filter:blur(2px)}
.omg-av-box{position:relative;width:min(920px,calc(100vw - 32px));max-height:min(86vh,900px);display:flex;flex-direction:column;background:#141121;border:1px solid #2a2540;border-radius:20px;box-shadow:0 40px 80px -24px rgba(0,0,0,.8);overflow:hidden}
.omg-av-top{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid #2a2540}
.omg-av-top h2{margin:0;color:#fff;font:700 22px/1.2 Poppins,sans-serif}
.omg-av-x{width:40px;height:40px;display:grid;place-items:center;border-radius:10px;border:1px solid transparent;background:transparent;color:#f4f2fb;cursor:pointer}
.omg-av-x:hover,.omg-av-x:focus-visible{border-color:#8b5cf6;outline:none}
.omg-av-filters{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:16px 24px 8px}
.omg-av-chips{display:flex;flex-wrap:wrap;gap:8px;min-width:0;max-width:100%}
.omg-av-chip{min-height:40px;padding:8px 16px;border-radius:12px;border:1px solid #2a2540;background:#1e1a2e;color:#d9d4ea;font:600 14px/1 Poppins,sans-serif;cursor:pointer}
.omg-av-chip.is-on{background:#f4f2fb;color:#0d0619;border-color:#f4f2fb}
.omg-av-chip:focus-visible{outline:2px solid #c4b5fd;outline-offset:2px}
.omg-av-region select{box-sizing:border-box;max-width:100%;min-height:40px;padding:0 36px 0 14px;border-radius:12px;border:1px solid #2a2540;background:#1e1a2e;color:#f4f2fb;font:600 14px/1 Poppins,sans-serif}
.omg-av .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.omg-av-list{list-style:none;margin:0;padding:4px 24px 20px;overflow:auto}
.omg-av-row{display:grid;grid-template-columns:72px 1fr auto auto;align-items:center;gap:16px;padding:14px 0;border-bottom:1px solid #2a2540}
.omg-av-row:last-child{border-bottom:0}
.omg-av-img{width:72px;height:96px;border-radius:10px;object-fit:cover;background:#1d1930}
.omg-av-t{margin:0 0 8px;color:#f4f2fb;font:600 15px/1.35 Poppins,sans-serif}
.omg-av-tag{display:inline-flex;align-items:center;gap:8px;padding:5px 10px 5px 6px;border:1px solid #2a2540;border-radius:10px;color:#a79ec0;font:600 12px/1 Poppins,sans-serif;letter-spacing:.04em;text-transform:uppercase}
.omg-av-tag .omg-pf{width:20px;height:20px;border-radius:6px;flex:none}
.omg-av-tag .omg-pf::before{width:12px;height:12px;margin:4px}
.omg-av-price{color:#fff;font:700 18px/1 Poppins,sans-serif;white-space:nowrap;text-align:right}
.omg-av-price small{display:block;margin-top:4px;color:#8d84a8;font:500 12px/1 Poppins,sans-serif}
.omg-av-go{display:inline-flex;align-items:center;justify-content:center;min-width:110px;min-height:44px;padding:0 18px;border-radius:12px;background:#2a2540;color:#fff;font:600 15px/1 Poppins,sans-serif;text-decoration:none}
.omg-av-go:hover,.omg-av-go:focus-visible{background:#8b5cf6;outline:none}
.omg-av-go.is-cur{background:transparent;border:1px solid #8b5cf6;color:#c4b5fd;cursor:default}
.omg-av-empty{padding:28px 0;color:#a79ec0;text-align:center}
#omg-dg-ed-all{cursor:pointer}
@media (max-width:640px){
  .omg-av{align-items:stretch}
  .omg-av-box{width:100vw;max-height:none;height:100%;border-radius:0;border:0}
  .omg-av-top{padding:16px}.omg-av-filters{padding:12px 16px 4px}.omg-av-list{padding:0 16px 16px}
  .omg-av-filters{flex-direction:column;align-items:stretch}.omg-av-chips{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}
  .omg-av-chip{flex:none}.omg-av-region select{width:100%}
  .omg-av-row{grid-template-columns:56px 1fr;grid-template-areas:"i t" "i p" "g g";row-gap:10px}
  .omg-av-img{grid-area:i;width:56px;height:76px}.omg-av-row>div:nth-child(2){grid-area:t}
  .omg-av-price{grid-area:p;text-align:left}.omg-av-go{grid-area:g;width:100%}
}
</style>
<script id="omg-av-js">
(function () {
  var L = window.OMG_LISTINGS, box = document.getElementById('omg-av'); if (!L || !box || L.items.length < 2) return;
  var chips = box.querySelector('.omg-av-chips'), sel = box.querySelector('select'), list = box.querySelector('.omg-av-list');
  var labelOf = function (x) { return x.platform + (x.type && x.type !== 'Key' ? ' ' + x.type : ''); };
  var state = { chip: '', region: '' }, opener = null;
  var uniq = function (a) { return a.filter(function (v, i) { return v && a.indexOf(v) === i; }); };
  var add = function (v, t) { var b = document.createElement('button'); b.type = 'button'; b.className = 'omg-av-chip'; b.textContent = t; b.setAttribute('data-v', v); chips.appendChild(b); };
  add('', 'All platforms'); uniq(L.items.map(labelOf)).forEach(function (v) { add(v, v); });
  sel.appendChild(new Option('Region', ''));
  uniq(L.items.map(function (x) { return x.region; })).sort().forEach(function (r) { sel.appendChild(new Option(r, r)); });
  function draw() {
    chips.querySelectorAll('.omg-av-chip').forEach(function (c) { var on = c.getAttribute('data-v') === state.chip; c.classList.toggle('is-on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    var rows = L.items.filter(function (x) { return (!state.chip || labelOf(x) === state.chip) && (!state.region || x.region === state.region); })
      .sort(function (a, b) { return ((b.in_stock ? 1 : 0) - (a.in_stock ? 1 : 0)) || (a.price - b.price); });
    list.innerHTML = '';
    if (!rows.length) { var e = document.createElement('li'); e.className = 'omg-av-empty'; e.textContent = 'No products for this choice.'; list.appendChild(e); return; }
    rows.forEach(function (x) {
      var li = document.createElement('li'); li.className = 'omg-av-row';
      var slug = x.platform.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      li.innerHTML = '<img class="omg-av-img" alt="" loading="lazy"><div><p class="omg-av-t"></p><span class="omg-av-tag"><span class="omg-pf" aria-hidden="true"></span><span class="r"></span></span></div><div class="omg-av-price"></div><a class="omg-av-go"></a>';
      var img = li.querySelector('img'); if (x.image) img.src = x.image; else img.remove();
      li.querySelector('.omg-av-t').textContent = x.title;
      li.querySelector('.omg-pf').setAttribute('data-pf', slug);
      li.querySelector('.r').textContent = x.region;
      li.querySelector('.omg-av-price').innerHTML = x.in_stock && x.price_html ? '' : '<small>Out of stock</small>';
      if (x.in_stock && x.price_html) li.querySelector('.omg-av-price').textContent = x.price_html;
      var go = li.querySelector('.omg-av-go');
      if (x.id === L.current) { go.textContent = 'Current'; go.classList.add('is-cur'); go.setAttribute('aria-current', 'page'); }
      else { go.textContent = 'View'; go.href = x.url; }
      list.appendChild(li);
    });
  }
  function open(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    opener = document.activeElement; box.hidden = false; document.documentElement.style.overflow = 'hidden'; draw();
    box.querySelector('.omg-av-x').focus();
  }
  function close() { box.hidden = true; document.documentElement.style.overflow = ''; if (opener && opener.focus) opener.focus(); }
  chips.addEventListener('click', function (e) { var c = e.target.closest('.omg-av-chip'); if (c) { state.chip = c.getAttribute('data-v'); draw(); } });
  sel.addEventListener('change', function () { state.region = sel.value; draw(); });
  box.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', function (e) { if (!box.hidden && e.key === 'Escape') close(); });
  // "All variations" next to the region opens it (also with the keyboard).
  document.addEventListener('click', function (e) { if (e.target.closest('#omg-dg-ed-all')) open(e); }, true);
  document.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('#omg-dg-ed-all')) open(e); }, true);
  window.OMG_OPEN_VARIATIONS = open;
})();
</script>
        <?php
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
        $data = array_map(static function ($s) {
            $region = $s['region'] ?: ($s['region_names'][0] ?? '');
            $p = wc_get_product($s['id']);
            $img = $p && $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : '';
            $types = ['Gift' => 'gift_link', 'Account' => 'game_account'];
            $title = function_exists('omg_listing_title') && $p
                ? omg_listing_title($p->get_name(), [$s['platform']], $region ? [$region] : [], $types[$s['type']] ?? ((string) get_post_meta($s['id'], '_omg_product_type', true) ?: 'game'), $s['edition'] !== 'Standard' ? $s['edition'] : '', false)
                : ($p ? $p->get_name() : '');
            return [
                'id' => $s['id'], 'url' => $s['url'], 'platform' => $s['platform'], 'edition' => $s['edition'],
                'region' => $region, 'type' => $s['type'], 'price' => $s['price'], 'in_stock' => $s['in_stock'],
                'title' => html_entity_decode($title, ENT_QUOTES), 'image' => (string) $img,
                'price_html' => $s['price'] > 0 ? html_entity_decode(wp_strip_all_tags(wc_price($s['price'])), ENT_QUOTES, 'UTF-8') : '',
            ];
        }, $all);
        echo '<script id="omg-listings-data">window.OMG_LISTINGS=' . wp_json_encode(['current' => $current, 'items' => $data]) . ';</script>';
        self::variations_modal();
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
