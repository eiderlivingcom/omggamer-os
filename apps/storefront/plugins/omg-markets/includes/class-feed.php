<?php
/**
 * Google Merchant Center product feed, one per market:
 *   https://<store>/feeds/google/us.xml, /gb.xml (or /uk.xml), /au.xml ...
 * Prices are in the market's currency and every link opens the product in that market (?market=xx),
 * so the landing page shows the same price and currency as the feed.
 * A variant whose region (pa_region) cannot be activated in the country is left out of that country's feed.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Markets_Feed
{
    private const VAR = 'omg_feed';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'add_rewrite']);
        add_filter('query_vars', static fn ($v) => array_merge($v, [self::VAR]));
        add_action('template_redirect', [__CLASS__, 'serve'], 0);
        add_filter('redirect_canonical', static fn ($url) => get_query_var(self::VAR) ? false : $url);
        foreach (['woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'omg_markets_rates_updated', 'update_option_' . OMG_Markets::OPTION] as $hook) {
            add_action($hook, [__CLASS__, 'flush']);
        }
    }

    public static function add_rewrite(): void
    {
        add_rewrite_rule('^feeds/google/([A-Za-z]{2})\.xml$', 'index.php?' . self::VAR . '=$matches[1]', 'top');
    }

    public static function url(string $cc): string
    {
        return home_url('/feeds/google/' . strtolower($cc) . '.xml');
    }

    public static function flush(): void
    {
        foreach (array_keys(OMG_Markets::settings()['markets']) as $cc) {
            delete_transient('omg_feed_' . strtolower($cc));
        }
    }

    public static function serve(): void
    {
        $code = get_query_var(self::VAR);
        if (!$code) {
            return;
        }
        $cc = OMG_Markets::normalise($code);
        if (!$cc) {
            status_header(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No such market. Enabled markets: ' . implode(', ', array_map('strtolower', array_keys(OMG_Markets::enabled())));
            exit;
        }
        $key = 'omg_feed_' . strtolower($cc);
        $xml = isset($_GET['fresh']) && current_user_can('manage_woocommerce') ? false : get_transient($key);
        if (!$xml) {
            $xml = self::build($cc)['xml'];
            set_transient($key, $xml, self::CACHE_TTL);
        }
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc() below
        exit;
    }

    private static function esc($s): string
    {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function money(float $v, string $cur): string
    {
        return number_format($v, OMG_Markets::decimals($cur), '.', '') . ' ' . $cur;
    }

    /** Which countries a region slug covers: ['*'] = everywhere, [] = none, null = not mapped. */
    private static function region_countries(string $slug): ?array
    {
        $map = OMG_Markets::settings()['regions'];
        if (!array_key_exists($slug, $map)) {
            return null;
        }
        $v = trim((string) $map[$slug]);
        if ($v === '*') {
            return ['*'];
        }
        return $v === '' ? [] : array_filter(array_map(static fn ($c) => strtoupper(trim($c)) === 'UK' ? 'GB' : strtoupper(trim($c)), explode(',', $v)));
    }

    /** @return true|string true if sellable in $cc, otherwise why not */
    private static function region_ok(array $slugs, string $cc)
    {
        if (!$slugs) {
            return true; // no region set
        }
        foreach ($slugs as $slug) {
            $c = self::region_countries($slug);
            if ($c === null) {
                return 'unmapped region "' . $slug . '"';
            }
            if (in_array('*', $c, true) || in_array($cc, $c, true)) {
                return true;
            }
        }
        return 'region not sold in ' . $cc;
    }

    private static function categories(WC_Product $p): array
    {
        $terms = get_the_terms($p->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return [[], ''];
        }
        $slugs = [];
        $best = '';
        foreach ($terms as $t) {
            $path = [];
            foreach (array_reverse(get_ancestors($t->term_id, 'product_cat', 'taxonomy')) as $aid) {
                $a = get_term($aid, 'product_cat');
                if ($a && !is_wp_error($a)) {
                    $slugs[] = $a->slug;
                    $path[] = html_entity_decode($a->name);
                }
            }
            $slugs[] = $t->slug;
            $path[] = html_entity_decode($t->name);
            $p_str = implode(' > ', $path);
            if (strlen($p_str) > strlen($best)) {
                $best = $p_str;
            }
        }
        return [array_values(array_unique($slugs)), $best];
    }

    private static function brand(WC_Product $p): string
    {
        foreach (['product_brand', 'pa_publishers'] as $tax) {
            if (taxonomy_exists($tax)) {
                $names = wc_get_product_terms($p->get_id(), $tax, ['fields' => 'names']);
                if ($names) {
                    return html_entity_decode($names[0]);
                }
            }
        }
        return '';
    }

    private static function description(WC_Product $p): string
    {
        $d = $p->get_description() ?: $p->get_short_description();
        $d = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($d))));
        if ($d === '') {
            $d = $p->get_name();
        }
        return function_exists('mb_substr') ? mb_substr($d, 0, 5000) : substr($d, 0, 5000);
    }

    /** @return array{xml:string, items:int, skipped:array<string,int>} */
    public static function build(string $cc): array
    {
        OMG_Markets::force($cc);
        $cur = OMG_Markets::currency($cc);
        $items = [];
        $skipped = [];
        $skip = static function (string $why) use (&$skipped): void {
            $skipped[$why] = ($skipped[$why] ?? 0) + 1;
        };

        $ids = wc_get_products(['status' => 'publish', 'limit' => -1, 'type' => ['simple', 'variable'], 'return' => 'ids']);
        foreach ($ids as $id) {
            $parent = wc_get_product($id);
            if (!$parent || $parent->get_catalog_visibility() === 'hidden' || post_password_required($id)) {
                $skip('hidden');
                continue;
            }
            [$cat_slugs, $cat_path] = self::categories($parent);
            $gpc = '';
            foreach (OMG_Markets::settings()['gpc'] as $slug => $gid) {
                if ($gid !== '' && in_array($slug, $cat_slugs, true)) {
                    $gpc = (string) $gid;
                    break;
                }
            }
            $base = [
                'brand' => self::brand($parent),
                'description' => self::description($parent),
                'product_type' => $cat_path,
                'gpc' => $gpc,
                'gallery' => array_slice(array_filter(array_map(static fn ($i) => wp_get_attachment_image_url($i, 'full'), $parent->get_gallery_image_ids())), 0, 10),
                'parent_regions' => wc_get_product_terms($id, 'pa_region', ['fields' => 'slugs']),
            ];
            if ($parent->is_type('variable')) {
                foreach ($parent->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if (!$v || $v->get_status() !== 'publish') {
                        $skip('variant not published');
                        continue;
                    }
                    $attrs = $v->get_attributes();
                    $regions = !empty($attrs['pa_region']) ? [$attrs['pa_region']] : $base['parent_regions'];
                    $item = self::item($v, $parent, $base, $regions, $cc, $cur, $skip);
                    if ($item) {
                        $items[] = $item;
                    }
                }
            } else {
                $item = self::item($parent, null, $base, $base['parent_regions'], $cc, $cur, $skip);
                if ($item) {
                    $items[] = $item;
                }
            }
        }
        OMG_Markets::force(null);

        $title = get_bloginfo('name') . ' – ' . OMG_Markets::country_name($cc) . ' (' . $cur . ')';
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "\n"
            . '<title>' . self::esc($title) . '</title><link>' . self::esc(home_url('/')) . '</link>'
            . '<description>' . self::esc('Products for ' . OMG_Markets::country_name($cc) . ', priced in ' . $cur . '. Generated ' . gmdate('c') . '.') . '</description>' . "\n";
        foreach ($items as $it) {
            $out .= '<item>';
            foreach ($it as $tag => $val) {
                foreach ((array) $val as $one) {
                    $out .= $tag === 'g:shipping'
                        ? '<g:shipping><g:country>' . self::esc($one[0]) . '</g:country><g:price>' . self::esc($one[1]) . '</g:price></g:shipping>'
                        : '<' . $tag . '>' . self::esc($one) . '</' . $tag . '>';
                }
            }
            $out .= "</item>\n";
        }
        $out .= '</channel></rss>' . "\n";

        return ['xml' => $out, 'items' => count($items), 'skipped' => $skipped];
    }

    private static function item(WC_Product $p, ?WC_Product $parent, array $base, array $regions, string $cc, string $cur, callable $skip): ?array
    {
        $ok = self::region_ok($regions, $cc);
        if ($ok !== true) {
            $skip($ok);
            return null;
        }
        if (!$p->is_purchasable()) {
            $skip('not purchasable');
            return null;
        }
        $regular = $p->get_regular_price();
        $price = $p->get_price();
        $regular = $regular !== '' ? (float) $regular : (float) $price;
        if ($regular <= 0) {
            $skip('no price');
            return null;
        }
        $img_id = $p->get_image_id() ?: ($parent ? $parent->get_image_id() : 0);
        $img = $img_id ? wp_get_attachment_image_url($img_id, 'full') : '';
        if (!$img) {
            $skip('no image');
            return null;
        }
        $id = $p->get_sku() ?: (string) $p->get_id();
        $item = [
            'g:id' => substr($id, 0, 50),
            'g:title' => function_exists('mb_substr') ? mb_substr(html_entity_decode($p->get_name()), 0, 150) : substr($p->get_name(), 0, 150),
            'g:description' => $base['description'],
            'g:link' => add_query_arg('market', strtolower($cc), $p->get_permalink()),
            'g:image_link' => $img,
        ];
        if ($base['gallery']) {
            $item['g:additional_image_link'] = $base['gallery'];
        }
        $item['g:availability'] = $p->is_in_stock() ? 'in_stock' : 'out_of_stock';
        $item['g:price'] = self::money($regular, $cur);
        if ($p->is_on_sale() && $p->get_sale_price() !== '' && (float) $p->get_sale_price() < $regular) {
            $item['g:sale_price'] = self::money((float) $p->get_sale_price(), $cur);
            $from = $p->get_date_on_sale_from();
            $to = $p->get_date_on_sale_to();
            if ($from && $to) {
                $item['g:sale_price_effective_date'] = $from->format('c') . '/' . $to->format('c');
            }
        }
        $item['g:condition'] = 'new';
        if ($parent) {
            $item['g:item_group_id'] = substr($parent->get_sku() ?: (string) $parent->get_id(), 0, 50);
        }
        if ($base['brand'] !== '') {
            $item['g:brand'] = $base['brand'];
        }
        $gtin = method_exists($p, 'get_global_unique_id') ? $p->get_global_unique_id() : '';
        if ($gtin) {
            $item['g:gtin'] = $gtin;
        } else {
            $item['g:identifier_exists'] = 'no';
        }
        if ($base['gpc'] !== '') {
            $item['g:google_product_category'] = $base['gpc'];
        }
        if ($base['product_type'] !== '') {
            $item['g:product_type'] = $base['product_type'];
        }
        if ($p->is_virtual() || $p->is_downloadable()) {
            // Delivered digitally: no shipping charge.
            $item['g:shipping'] = [[$cc, self::money(0, $cur)]];
        }
        return $item;
    }
}
