<?php
/**
 * Plugin Name: OMGGamer SEO
 * Description: Meta titles, descriptions, keywords, canonical, Open Graph / Twitter and structured data for every
 *              store page, built from real catalogue data (counts, prices, genres, platforms). Archive pages
 *              (collections, platforms, genres, regions, shop) get an About block, quick facts, FAQs (FAQPage
 *              schema), related products and related platform / genre / collection links at the bottom.
 *              Every collection, platform, genre and region can override the texts (Products → its edit screen).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_SEO
{
    private const BRAND = 'OMGGamer';
    private const LABELS = [
        'product_cat' => 'Collection',
        'pa_platform' => 'Platform',
        'pa_genres' => 'Genre',
        'pa_region' => 'Region',
        'pa_developers' => 'Developer',
        'pa_publishers' => 'Publisher',
    ];
    private const FIELDS = [
        'omg_seo_title' => 'SEO title',
        'omg_seo_desc' => 'Meta description',
        'omg_seo_keywords' => 'Meta keywords (comma separated)',
        'omg_about' => 'About text (bottom of the page, HTML allowed)',
        'omg_faq' => 'FAQs, one per line: Question | Answer',
    ];

    private static $ctx = null;
    private static $stats = [];

    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'takeover'], 20);
        add_filter('pre_get_document_title', [__CLASS__, 'title'], 99);
        add_action('wp_head', [__CLASS__, 'head'], 1);
        add_filter('wp_robots', [__CLASS__, 'robots'], 20);
        add_filter('bricks/frontend/render_data', [__CLASS__, 'archive_block'], 40, 3);
        add_filter('woocommerce_get_breadcrumb', [__CLASS__, 'breadcrumb'], 20);
        add_action('admin_init', [__CLASS__, 'term_fields']);
    }

    /* =========================================================== context */

    private static function front(): bool
    {
        return !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && !is_feed()
            && !(function_exists('bricks_is_builder') && bricks_is_builder());
    }

    private static function is_archive(): bool
    {
        return function_exists('is_product_taxonomy') && (is_product_taxonomy() || is_shop());
    }

    /** Our SEO tags replace the theme's and the Product Studio's (no duplicates). */
    public static function takeover(): void
    {
        if (!self::front()) {
            return;
        }
        global $wp_filter;
        foreach (['wp_head', 'pre_get_document_title', 'document_title_parts'] as $hook) {
            if (empty($wp_filter[$hook])) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $prio => $cbs) {
                foreach ($cbs as $cb) {
                    $f = $cb['function'];
                    if (is_array($f) && is_object($f[0])) {
                        $cls = get_class($f[0]);
                        $m = $f[1];
                        if (($cls === 'Bricks\\Frontend' && in_array($m, ['add_seo_meta_tags', 'add_open_graph_meta_tags'], true))
                            || in_array($m, ['seo_meta', 'seo_title'], true) && strpos($cls, 'OMG_PS') === 0) {
                            remove_action($hook, $f, $prio);
                        }
                    }
                }
            }
        }
        remove_action('wp_head', 'rel_canonical');
    }

    private static function term()
    {
        $o = get_queried_object();
        return $o instanceof WP_Term ? $o : null;
    }

    private static function tm(?WP_Term $t, string $key): string
    {
        return $t ? trim((string) get_term_meta($t->term_id, $key, true)) : '';
    }

    private static function clean(string $s, int $max = 0): string
    {
        $s = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($s), ENT_QUOTES, 'UTF-8')));
        if ($max && mb_strlen($s) > $max) {
            // Drop whole closing sentences first, then cut at a word.
            $parts = preg_split('/(?<=[.!?])\s+/u', $s);
            while (count($parts) > 1 && mb_strlen(implode(' ', $parts)) > $max) {
                array_pop($parts);
            }
            $s = implode(' ', $parts);
        }
        if ($max && mb_strlen($s) > $max) {
            $s = rtrim(mb_substr($s, 0, $max - 1));
            $s = preg_replace('/\s+\S*$/u', '', $s) . '…';
        }
        return $s;
    }

    private static function money(float $v): string
    {
        return $v > 0 ? self::clean(wc_price($v)) : '';
    }

    private static function name(WP_Term $t): string
    {
        return self::clean($t->name);
    }

    /* ============================================================== data */

    /** Product ids, counts and related terms for a term (or the whole catalogue when $t is null). Cached 6h. */
    private static function stats(?WP_Term $t): array
    {
        $key = $t ? $t->taxonomy . ':' . $t->term_id : 'all';
        if (isset(self::$stats[$key])) {
            return self::$stats[$key];
        }
        $cache = 'omg_seo_' . md5($key);
        $base = get_transient($cache);
        if (!is_array($base)) {
            global $wpdb;
            if ($t) {
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE tt.taxonomy = %s AND tt.term_id = %d AND p.post_type = 'product' AND p.post_status = 'publish'",
                    $t->taxonomy,
                    $t->term_id
                ));
            } else {
                $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");
            }
            $ids = array_map('intval', $ids);
            $hidden = self::hidden_ids();
            $ids = array_values(array_diff($ids, $hidden));
            $in = $ids ? implode(',', $ids) : '0';
            $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
            $popular = $ids ? array_map('intval', $wpdb->get_col("SELECT product_id FROM {$lookup} WHERE product_id IN ({$in}) AND stock_status = 'instock' ORDER BY total_sales DESC, average_rating DESC, product_id DESC LIMIT 10")) : [];
            $terms = static function (string $tax) use ($wpdb, $in, $ids) {
                if (!$ids) {
                    return [];
                }
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.term_id, COUNT(*) c FROM {$wpdb->term_relationships} tr
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s AND tr.object_id IN ({$in}) GROUP BY t.term_id ORDER BY c DESC LIMIT 8",
                    $tax
                ));
                return array_map(static fn ($r) => (int) $r->term_id, $rows ?: []);
            };
            $base = [
                'ids' => $ids,
                'count' => count($ids),
                                'popular' => $popular,
                'genres' => $terms('pa_genres'),
                'platforms' => $terms('pa_platform'),
                'regions' => $terms('pa_region'),
                'cats' => $terms('product_cat'),
            ];
            set_transient($cache, $base, 6 * HOUR_IN_SECONDS);
        }
        return self::$stats[$key] = $base;
    }

    private static function hidden_ids(): array
    {
        static $h = null;
        if ($h === null) {
            $ids = [];
            $t = get_term_by('slug', 'exclude-from-catalog', 'product_visibility');
            if ($t) {
                $ids = get_objects_in_term($t->term_id, 'product_visibility');
            }
            $ids[] = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
            $h = array_map('intval', is_array($ids) ? $ids : []);
        }
        return $h;
    }

    private static function names(array $term_ids, int $n = 3): array
    {
        $out = [];
        foreach (array_slice($term_ids, 0, $n) as $id) {
            $t = get_term($id);
            if ($t instanceof WP_Term) {
                $out[] = self::name($t);
            }
        }
        return $out;
    }

    private static function list_text(array $items): string
    {
        $items = array_values(array_filter($items));
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private static function product_names(array $ids, int $n = 3): array
    {
        $out = [];
        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if ($p && $p->is_visible()) {
                $out[] = self::clean($p->get_name(), 60);
            }
            if (count($out) >= $n) {
                break;
            }
        }
        return $out;
    }

    /* =========================================================== meta */

    /** Title, description, keywords, canonical and image for the current page. */
    private static function ctx(): array
    {
        if (self::$ctx !== null) {
            return self::$ctx;
        }
        $b = self::BRAND;
        $c = ['title' => '', 'desc' => '', 'keys' => [], 'url' => '', 'image' => '', 'type' => 'website'];
        $paged = max(1, (int) get_query_var('paged'));
        if (function_exists('is_product') && is_product()) {
            $p = wc_get_product(get_queried_object_id());
            if ($p) {
                $name = self::clean($p->get_name());
                $plat = self::names(wc_get_product_terms($p->get_id(), 'pa_platform', ['fields' => 'ids']), 1);
                $reg = self::names(wc_get_product_terms($p->get_id(), 'pa_region', ['fields' => 'ids']), 2);
                $gen = self::names(wc_get_product_terms($p->get_id(), 'pa_genres', ['fields' => 'ids']), 3);
                $price = (float) ($p->is_type('variable') ? $p->get_variation_price('min', true) : wc_get_price_to_display($p));
                $own_t = trim((string) get_post_meta($p->get_id(), '_omg_seo_title', true));
                $own_d = trim((string) get_post_meta($p->get_id(), '_omg_seo_description', true));
                // "Buy {Game} ({Region}) ({Devices}) - {Platform} - Digital Key | OMGGamer"; never a price.
                $t_plat = wc_get_product_terms($p->get_id(), 'pa_platform', ['fields' => 'names']);
                $t_reg = wc_get_product_terms($p->get_id(), 'pa_region', ['fields' => 'names']);
                $t_type = (string) get_post_meta($p->get_id(), '_omg_product_type', true) ?: 'game';
                if (function_exists('omg_is_currency') && omg_is_currency($p->get_id())) {
                    $t_type = 'game_currency';
                }
                if (strcasecmp((string) get_post_meta($p->get_id(), '_omg_listing_type', true), 'Gift') === 0) {
                    $t_type = 'gift_link';
                }
                $full = function_exists('omg_listing_title') ? omg_listing_title($name, $t_plat, $t_reg, $t_type) : 'Buy ' . $name;
                $own_t = (string) preg_replace('/\s*[–-]\s*(Buy for\s*)?[$€£]\s?[\d.,]+/u', '', $own_t); // no prices
                $c['title'] = $own_t ?: self::fit([
                    $full . ' | ' . $b,
                    $full,
                    $name . ' | ' . $b,
                    $name,
                ], 110); // full key-store titles run past 66 characters
                $c['desc'] = $own_d ?: sprintf(
                    'Buy %s%s%s on %s. %sInstant digital delivery, secure checkout, buyer protection and 24/7 support.',
                    $name,
                    $plat ? ' for ' . $plat[0] : '',
                    $price ? ' from ' . self::money($price) : '',
                    $b,
                    $reg ? 'Region: ' . self::list_text($reg) . '. ' : ''
                );
                $c['keys'] = array_filter([
                    $name, 'buy ' . $name, $name . ' key', $name . ' cheap', $plat ? $name . ' ' . $plat[0] : '',
                    $plat ? $plat[0] . ' game key' : '', ...array_map(static fn ($g) => $g . ' game', $gen),
                ]);
                $c['url'] = get_permalink($p->get_id());
                $c['image'] = wp_get_attachment_image_url($p->get_image_id(), 'large') ?: '';
                $c['type'] = 'product';
            }
        } elseif (self::is_archive()) {
            $t = self::term();
            $s = self::stats($t);
            if ($t) {
                $n = self::name($t);
                $gen = self::names($s['genres'], 3);
                $plat = self::names($s['platforms'], 3);
                switch ($t->taxonomy) {
                    case 'pa_platform':
                        $c['title'] = "{$n} Games & Keys – Cheap {$n} Deals | {$b}";
                        $c['desc'] = "Buy {$n} games, keys and gift cards on {$b}" . ($gen ? ', including ' . self::list_text($gen) . ' games' : '') . '. Instant delivery, buyer protection and 24/7 support.';
                        $c['keys'] = ["{$n} games", "{$n} keys", "cheap {$n} games", "buy {$n} games", "{$n} game deals", "{$n} digital code", ...array_map(static fn ($g) => "{$g} {$n} games", $gen)];
                        break;
                    case 'pa_genres':
                        $c['title'] = "Best {$n} Games – Cheap {$n} Game Keys | {$b}";
                        $c['desc'] = "Shop {$n} games on {$b}" . ($plat ? ' for ' . self::list_text($plat) : '') . '. Instant digital delivery, secure checkout and 24/7 support.';
                        $c['keys'] = ["{$n} games", "best {$n} games", "cheap {$n} games", "{$n} game keys", ...array_map(static fn ($pl) => "{$n} games {$pl}", $plat)];
                        break;
                    case 'pa_region':
                        $c['title'] = "{$n} Game Keys & Accounts – Cheap {$n} Deals | {$b}";
                        $c['desc'] = "Game keys, accounts and gift cards for the {$n} region on {$b}. Check activation regions, get instant delivery and buyer protection.";
                        $c['keys'] = ["{$n} game keys", "{$n} region games", "{$n} cd keys", "cheap {$n} games"];
                        break;
                    case 'product_cat':
                        $c['title'] = "{$n} – Buy Cheap {$n} Online | {$b}";
                        $c['desc'] = "Shop {$n} on {$b}" . ($plat ? ' for ' . self::list_text($plat) : '') . '. Instant digital delivery, buyer protection and 24/7 support.';
                        $c['keys'] = [$n, "buy {$n}", "cheap {$n}", "{$n} deals", ...array_map(static fn ($pl) => "{$n} {$pl}", $plat)];
                        break;
                    default:
                        $label = self::LABELS[$t->taxonomy] ?? 'Games';
                        $c['title'] = "{$n} Games – Buy {$n} Game Keys | {$b}";
                        $c['desc'] = "Games by {$label} {$n} on {$b}. Instant digital delivery and buyer protection.";
                        $c['keys'] = ["{$n} games", "{$n} game keys", "buy {$n} games"];
                }
                $c['title'] = self::tm($t, 'omg_seo_title') ?: $c['title'];
                $c['desc'] = self::tm($t, 'omg_seo_desc') ?: $c['desc'];
                if ($k = self::tm($t, 'omg_seo_keywords')) {
                    $c['keys'] = array_map('trim', explode(',', $k));
                }
                $c['url'] = get_term_link($t);
                $thumb = (int) get_term_meta($t->term_id, 'thumbnail_id', true);
                $c['image'] = $thumb ? (string) wp_get_attachment_image_url($thumb, 'large') : '';
            } else {
                $c['title'] = "All Games, Keys & Gift Cards – Shop Cheap Games Online | {$b}";
                $c['desc'] = "Browse game keys, gaming accounts and gift cards for Steam, PlayStation, Xbox, Nintendo and more on {$b}. Instant delivery and buyer protection.";
                $c['keys'] = ['game keys', 'buy games online', 'cheap games', 'gaming accounts', 'gift cards', 'cd keys'];
                $c['url'] = wc_get_page_permalink('shop');
            }
            if (!$c['image'] && $s['popular']) {
                $p = wc_get_product($s['popular'][0]);
                $c['image'] = $p ? (string) wp_get_attachment_image_url($p->get_image_id(), 'large') : '';
            }
            if ($paged > 1) {
                $c['title'] = preg_replace('/ \| ' . preg_quote($b, '/') . '$/', " – Page {$paged} | {$b}", $c['title']);
                $c['url'] = trailingslashit($c['url']) . 'page/' . $paged . '/';
            }
        } elseif (is_front_page()) {
            $s = self::stats(null);
            $c['title'] = "{$b} – Cheap Game Keys, Accounts & Gift Cards | Instant Delivery";
            $c['desc'] = 'Cheap game keys for Steam, PlayStation, Xbox, Nintendo and Epic, plus accounts and gift cards. Instant delivery and buyer protection.';
            $c['keys'] = ['game keys', 'cheap game keys', 'steam keys', 'playstation games', 'xbox games', 'nintendo eshop', 'gaming accounts', 'gift cards', 'cd keys'];
            $c['url'] = home_url('/');
            if ($s['popular']) {
                $p = wc_get_product($s['popular'][0]);
                $c['image'] = $p ? (string) wp_get_attachment_image_url($p->get_image_id(), 'large') : '';
            }
        } elseif (is_search()) {
            $c['title'] = 'Search results for “' . self::clean(get_search_query(), 40) . "” | {$b}";
            $c['desc'] = "Search results on {$b}.";
            $c['url'] = '';
        } elseif (is_404()) {
            $c['title'] = "Page not found | {$b}";
            $c['desc'] = "This page does not exist. Browse game keys, accounts and gift cards on {$b}.";
        } elseif (is_page('pro')) {
            $conv = static fn ($v) => class_exists('OMG_Markets') && OMG_Markets::applies() ? (OMG_Markets::convert($v) ?? $v) : $v;
            $pct = class_exists('OMG_Pricing') ? rtrim(rtrim(number_format((float) (OMG_Pricing::settings()['pro'] ?? 0), 1), '0'), '.') : '';
            $mo = class_exists('OMG_Pro_Offer') ? self::money($conv(OMG_Pro_Offer::MONTHLY)) : '';
            $first = class_exists('OMG_Pro_Offer') ? self::money($conv(OMG_Pro_Offer::FIRST)) : '';
            $c['title'] = "OMG PRO Membership – Member Prices on Every Game | {$b}";
            $c['desc'] = 'OMG PRO members get ' . ($pct ? "an extra {$pct}% off" : 'member prices on') . ' game keys and accounts' . ($mo ? ", for {$mo} a month" . ($first ? " (first month {$first})" : '') : '') . '. Cancel anytime.';
            $c['keys'] = ['omg pro', 'game membership', 'cheaper game keys', 'gaming discount membership'];
            $c['url'] = get_permalink(get_queried_object_id());
        } elseif (is_singular()) {
            $id = get_queried_object_id();
            $title = self::clean(get_the_title($id));
            $c['title'] = "{$title} | {$b}";
            $ex = has_excerpt($id) ? get_the_excerpt($id) : (string) get_post_field('post_content', $id);
            $c['desc'] = self::clean($ex) ?: "{$title} on {$b}: game keys, gaming accounts and gift cards with instant delivery.";
            $c['url'] = get_permalink($id);
            $c['image'] = (string) get_the_post_thumbnail_url($id, 'large');
        }
        $c['title'] = self::clean($c['title'], 70);
        $c['desc'] = self::clean($c['desc'], 160);
        $c['keys'] = array_slice(array_values(array_unique(array_filter(array_map(static fn ($k) => mb_strtolower(self::clean((string) $k)), $c['keys'])))), 0, 12);
        return self::$ctx = $c;
    }

    /** First candidate that fits a search result title (about 65 characters). */
    private static function fit(array $candidates, int $max = 66): string
    {
        foreach ($candidates as $t) {
            $t = self::clean($t);
            if (mb_strlen($t) <= $max) {
                return $t;
            }
        }
        return self::clean(end($candidates), $max);
    }

    public static function title($title)
    {
        if (!self::front()) {
            return $title;
        }
        $c = self::ctx();
        return $c['title'] ?: $title;
    }

    public static function robots(array $robots): array
    {
        if (!self::front()) {
            return $robots;
        }
        // Filtered / sorted listings and search results: keep crawlable, not indexed.
        $noise = array_diff(array_keys($_GET), ['market', 'currency', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid']);
        if (is_search() || (self::is_archive() && $noise)) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        } elseif (!isset($robots['noindex'])) {
            $robots['max-image-preview'] = 'large';
        }
        return $robots;
    }

    public static function head(): void
    {
        if (!self::front()) {
            return;
        }
        $c = self::ctx();
        $e = static fn ($v) => esc_attr($v);
        echo "\n<!-- OMGGamer SEO -->\n";
        if ($c['desc']) {
            echo '<meta name="description" content="' . $e($c['desc']) . '">' . "\n";
        }
        if ($c['keys']) {
            echo '<meta name="keywords" content="' . $e(implode(', ', $c['keys'])) . '">' . "\n";
        }
        if ($c['url'] && !is_wp_error($c['url'])) {
            echo '<link rel="canonical" href="' . esc_url($c['url']) . '">' . "\n";
        }
        $og = [
            'og:site_name' => self::BRAND,
            'og:locale' => str_replace('-', '_', get_bloginfo('language')),
            'og:type' => $c['type'],
            'og:title' => $c['title'],
            'og:description' => $c['desc'],
            'og:url' => is_wp_error($c['url']) ? '' : $c['url'],
            'og:image' => $c['image'],
        ];
        foreach ($og as $k => $v) {
            if ($v) {
                echo '<meta property="' . $k . '" content="' . ($k === 'og:url' || $k === 'og:image' ? esc_url($v) : $e($v)) . '">' . "\n";
            }
        }
        echo '<meta name="twitter:card" content="' . ($c['image'] ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . $e($c['title']) . '">' . "\n";
        if ($c['desc']) {
            echo '<meta name="twitter:description" content="' . $e($c['desc']) . '">' . "\n";
        }
        if ($c['image']) {
            echo '<meta name="twitter:image" content="' . esc_url($c['image']) . '">' . "\n";
        }
        if (is_front_page()) {
            $org = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => self::BRAND,
                'url' => home_url('/'),
                'logo' => content_url('uploads/omg-brand/favicon-192.png'),
            ];
            echo '<script type="application/ld+json">' . wp_json_encode($org, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
        }
    }

    public static function breadcrumb(array $crumbs): array
    {
        // No "Page 1", "Page 2" steps on paginated archives.
        $crumbs = array_values(array_filter($crumbs, static fn ($c) => !(isset($c[0]) && preg_match('/^\s*Page\s+\d+\s*$/i', wp_strip_all_tags((string) $c[0])))));
        foreach ($crumbs as &$c) {
            if (isset($c[0]) && strpos($c[0], 'Product ') === 0) {
                $c[0] = substr($c[0], 8);
                $c[0] = $c[0] === 'Platform' ? 'Platforms' : ($c[0] === 'Genres' ? 'Genres' : $c[0]);
            }
        }
        return $crumbs;
    }

    /* ======================================================= archive block */

    private static function activation(WP_Term $t): string
    {
        $s = $t->slug . ' ' . mb_strtolower($t->name);
        $map = [
            'steam' => 'Open the Steam app, choose Games → Activate a Product on Steam, enter the key and follow the prompts. The game is added to your library and you can start the download straight away.',
            'epic' => 'Sign in on the Epic Games Store (website or launcher), open your account menu, choose Redeem Code, paste the code and confirm.',
            'playstation' => 'On your console open PlayStation Store and choose Redeem Code from the menu, or sign in on the PlayStation Store website and choose Redeem Code from your profile menu. Enter the 12-character code.',
            'psn' => 'On your console open PlayStation Store and choose Redeem Code from the menu, or sign in on the PlayStation Store website and choose Redeem Code from your profile menu. Enter the 12-character code.',
            'xbox' => 'Go to redeem.microsoft.com (or Store → Redeem on your console), sign in with your Microsoft account and enter the 25-character code.',
            'nintendo' => 'On your Nintendo Switch open Nintendo eShop, select your user, choose Enter Code and type the 16-character code.',
            'ubisoft' => 'Open Ubisoft Connect, open the menu, choose Activate a key and enter the code.',
            'ea' => 'Open the EA app, go to your profile, choose Redeem code and enter the key.',
            'origin' => 'Open the EA app (formerly Origin), go to your profile, choose Redeem code and enter the key.',
            'gog' => 'Sign in on GOG.com, go to gog.com/redeem and enter the code. The game is added to your GOG library.',
            'battle' => 'Open the Battle.net app, click your account name, choose Redeem Code and enter the key.',
            'rockstar' => 'Open the Rockstar Games Launcher, go to Settings → Account Information → Activate a product, and enter the key.',
        ];
        foreach ($map as $k => $txt) {
            if (preg_match('/(^|[^a-z])' . preg_quote($k, '/') . '/', $s)) {
                return $txt;
            }
        }
        return 'Every product page has an Activation tab with step-by-step instructions for that exact product. Keys are redeemed on the platform shown on the page; accounts come with login details you change on first sign-in.';
    }

    /** FAQs built from the catalogue, plus any written on the term. */
    private static function faqs(?WP_Term $t, array $s): array
    {
        $n = $t ? self::name($t) : 'game';
        $popular = self::product_names($s['popular'], 3);
        $gen = self::names($s['genres'], 4);
        $plat = self::names($s['platforms'], 5);
        $regions = self::names($s['regions'], 4);
        $pro = class_exists('OMG_Pricing') ? (float) (OMG_Pricing::settings()['pro'] ?? 0) : 0;
        $proPrice = class_exists('OMG_Pro_Offer') ? self::money(class_exists('OMG_Markets') && OMG_Markets::applies() ? (OMG_Markets::convert(OMG_Pro_Offer::MONTHLY) ?? OMG_Pro_Offer::MONTHLY) : OMG_Pro_Offer::MONTHLY) : '';
        $f = [];
        $tax = $t ? $t->taxonomy : '';
        if ($tax === 'pa_platform') {
            $f[] = ["How do I activate a {$n} key?", self::activation($t)];
            if ($gen) {
                $f[] = ["What kind of {$n} games can I buy here?", "Most {$n} titles here are " . self::list_text($gen) . ' games, plus accounts, gift cards and add-ons.'];
            }
        } elseif ($tax === 'pa_genres') {
            $f[] = ["What are the most popular {$n} games?", $popular ? 'Popular picks right now: ' . self::list_text($popular) . '.' : "Sort this page by popularity to see the most bought {$n} games."];
            if ($plat) {
                $f[] = ["Which platforms are {$n} games available on?", "You can buy {$n} games here for " . self::list_text($plat) . '. Each product shows its platform and activation steps.'];
            }
        } elseif ($tax === 'pa_region') {
            $f[] = ["Where can I activate {$n} keys?", "Keys marked {$n} are meant to be activated in that region. The product page shows the region and warns you when your location does not match, so check it before you buy. Global keys work everywhere."];
        } else {
            if ($plat) {
                $f[] = ["Which platforms are covered in {$n}?", "This page has products for " . self::list_text($plat) . '. Use the platform filter to narrow the list.'];
            }
        }
        $f[] = ["How do I find the best {$n} deals?", 'Sort this page by price or discount, and turn on OMG PRO for member prices. Prices update with new offers, so check back often.'];
        if ($tax !== 'pa_region') {
            $f[] = ["Are {$n} keys region locked?", 'Some are. Every product shows its activation region' . ($regions ? ' (for example ' . self::list_text($regions) . ')' : '') . ', and we warn you when it does not match the country you are browsing from. Global keys can be activated anywhere.'];
        }
        $f[] = ["How fast is delivery?", 'Digital products are delivered instantly after payment, to your email and your account dashboard.'];
        $what = !$t ? 'games' : (in_array($tax, ['pa_platform', 'pa_genres', 'pa_developers', 'pa_publishers'], true) ? $n . ' games' : ($tax === 'pa_region' ? $n . ' keys' : $n));
        $f[] = ['Is it safe to buy ' . $what . ' on ' . self::BRAND . '?', 'Yes. Payments are processed securely at checkout, every order is covered by OMG Buyer Protection and our support team is available 24/7 if anything goes wrong.'];
        if ($pro > 0) {
            $f[] = ['Do OMG PRO members pay less?', 'Yes. PRO members get an extra ' . rtrim(rtrim(number_format($pro, 1), '0'), '.') . '% off member prices on games like these' . ($proPrice ? ". PRO costs {$proPrice} a month and the first month is discounted" : '') . '.'];
        }
        // Written on the term: added first, and they replace generated ones with the same question.
        $own = [];
        foreach (preg_split('/\r?\n/', self::tm($t, 'omg_faq')) as $line) {
            if (strpos($line, '|') !== false) {
                [$q, $a] = array_map('trim', explode('|', $line, 2));
                if ($q && $a) {
                    $own[mb_strtolower($q)] = [$q, $a];
                }
            }
        }
        foreach ($f as $x) {
            $own[mb_strtolower($x[0])] = $own[mb_strtolower($x[0])] ?? $x;
        }
        return array_values($own);
    }

    private static function about(?WP_Term $t, array $s): string
    {
        if ($own = self::tm($t, 'omg_about')) {
            return wp_kses_post(wpautop($own));
        }
        $n = $t ? self::name($t) : 'games';
        $popular = self::product_names($s['popular'], 3);
        $gen = self::names($s['genres'], 3);
        $plat = self::names($s['platforms'], 4);
        $desc = $t ? self::clean(term_description($t->term_id, $t->taxonomy)) : '';
        $p = [];
        if ($desc) {
            $p[] = esc_html($desc);
        }
        $tax = $t ? $t->taxonomy : '';
        if ($tax === 'pa_platform') {
            $p[] = esc_html("Browse {$n} products on " . self::BRAND . ', from game keys and accounts to gift cards and add-ons. Every order is delivered digitally straight after payment.');
            if ($gen) {
                $p[] = esc_html("Most {$n} titles here are " . self::list_text($gen) . ' games. Each product page shows its activation region and step-by-step activation, so make sure a key matches your account region before you buy.');
            }
        } elseif ($tax === 'pa_genres') {
            $p[] = esc_html("Discover {$n} games" . ($plat ? ' for ' . self::list_text($plat) : '') . '. Compare editions, check the region and get your code instantly after payment.');
        } elseif ($tax === 'pa_region') {
            $p[] = esc_html("These products are sold for the {$n} region. Region keys only activate in the countries they are made for, so check the region on each product page; we warn you when it does not match your location.");
        } elseif ($t) {
            $p[] = esc_html("Shop {$n}" . ($plat ? ' for ' . self::list_text($plat) : '') . '. Digital items are delivered instantly after payment and every order is covered by buyer protection.');
        } else {
            $p[] = esc_html("Browse game keys, gaming accounts and gift cards" . ($plat ? ' for ' . self::list_text($plat) : '') . '. Every digital order is delivered instantly after payment.');
        }
        if ($popular) {
            $p[] = esc_html('Popular right now: ' . self::list_text($popular) . '.');
        }
        return '<p>' . implode('</p><p>', $p) . '</p>';
    }

    private static function chips(string $title, array $terms, ?WP_Term $current = null, bool $icons = false): string
    {
        $items = '';
        foreach ($terms as $t) {
            if (!$t instanceof WP_Term || is_wp_error($link = get_term_link($t))) {
                continue;
            }
            $on = $current && $current->term_id === $t->term_id;
            $ic = $icons ? '<span class="omg-pf omg-seo-pf" data-pf="' . esc_attr($t->slug) . '" aria-hidden="true"></span>' : '';
            $items .= '<li><a class="omg-seo-chip' . ($on ? ' is-on' : '') . '" href="' . esc_url($link) . '"' . ($on ? ' aria-current="page"' : '') . '>' . $ic . esc_html(self::name($t)) . '</a></li>';
        }
        return $items ? '<div class="omg-seo-links"><h3>' . esc_html($title) . '</h3><ul>' . $items . '</ul></div>' : '';
    }

    private static function terms_by_ids(array $ids): array
    {
        return array_values(array_filter(array_map(static fn ($id) => get_term($id), $ids), static fn ($t) => $t instanceof WP_Term && $t->count > 0));
    }

    private static function related_products(?WP_Term $t, array $s): array
    {
        $in = array_flip($s['ids']);
        $exclude = self::hidden_ids();
        $args = ['status' => 'publish', 'limit' => 40, 'visibility' => 'catalog', 'stock_status' => 'instock', 'exclude' => $exclude, 'orderby' => 'date', 'order' => 'DESC'];
        $genres = array_filter(array_map(static fn ($id) => ($x = get_term($id)) instanceof WP_Term ? $x->slug : '', array_slice($s['genres'], 0, 4)));
        $pool = [];
        if ($t && $genres) {
            $pool = wc_get_products($args + ['tax_query' => [['taxonomy' => 'pa_genres', 'field' => 'slug', 'terms' => $genres]]]);
        }
        if (count($pool) < 10) {
            $pool = array_merge($pool, wc_get_products($args));
        }
        $out = [];
        foreach ($pool as $p) {
            if (isset($out[$p->get_id()]) || ($t && isset($in[$p->get_id()])) || !$p->is_visible() || (float) $p->get_price() <= 0) {
                continue;
            }
            $out[$p->get_id()] = $p;
        }
        $out = array_values($out);
        if (class_exists('OMG_Cart_Extras')) {
            usort($out, static fn ($a, $b) => OMG_Cart_Extras::off($b) <=> OMG_Cart_Extras::off($a));
        }
        if (!$t) {
            // Whole shop: the biggest discounts.
            return array_slice($out, 0, 10);
        }
        return array_slice($out, 0, 10);
    }

    public static function archive_block($html, $post = null, $area = '')
    {
        if (!is_string($html) || $area !== 'content' || !self::front() || !self::is_archive() || strpos($html, 'omg-seo-block') !== false) {
            return $html;
        }
        if (max(1, (int) get_query_var('paged')) > 1) {
            return $html; // only on the first page
        }
        $t = self::term();
        $s = self::stats($t);
        if (!$s['count']) {
            return $html;
        }
        $n = $t ? self::name($t) : 'All products';
        $label = $t ? (self::LABELS[$t->taxonomy] ?? 'Collection') : 'Store';
        $faqs = self::faqs($t, $s);
        $gen = self::names($s['genres'], 4);
        $plat = self::names($s['platforms'], 5);
        $reg = self::names($s['regions'], 4);

        $facts = [];
        if ($gen && (!$t || $t->taxonomy !== 'pa_genres')) {
            $facts[] = ['Top genres', implode(', ', $gen)];
        }
        if ($plat && (!$t || $t->taxonomy !== 'pa_platform')) {
            $facts[] = ['Platforms', implode(', ', $plat)];
        }
        if ($reg && (!$t || $t->taxonomy !== 'pa_region')) {
            $facts[] = ['Regions', implode(', ', $reg)];
        }
        $facts[] = ['Delivery', 'Instant, by email and in your dashboard'];
        $facts[] = ['Buyer protection', 'Included on every order'];

        $out = '<section class="omg-seo-block" aria-labelledby="omg-seo-h"><div class="omg-seo-wrap">';
        $out .= '<div class="omg-seo-grid"><div class="omg-seo-about"><h2 class="omg-seo-h2" id="omg-seo-h">About ' . esc_html($n) . '</h2>' . self::about($t, $s) . '</div>';
        $out .= '<aside class="omg-seo-facts" aria-label="' . esc_attr($n . ' at a glance') . '"><h3>' . esc_html($label) . ' at a glance</h3><dl>';
        foreach ($facts as [$k, $v]) {
            $out .= '<div><dt>' . esc_html($k) . '</dt><dd>' . esc_html($v) . '</dd></div>';
        }
        $out .= '</dl></aside></div>';

        if ($faqs) {
            $out .= '<div class="omg-seo-faq"><h2 class="omg-seo-h2">' . esc_html(($t ? $n : 'Shopping') . ' FAQs') . '</h2>';
            foreach ($faqs as $i => [$q, $a]) {
                $out .= '<details' . ($i === 0 ? ' open' : '') . '><summary>' . esc_html($q) . '<span class="i" aria-hidden="true"></span></summary><div class="a">' . esc_html($a) . '</div></details>';
            }
            $out .= '</div>';
        }

        // Related navigation.
        $links = '';
        if ($t && $t->taxonomy === 'pa_platform') {
            $links .= self::chips('All platforms', get_terms(['taxonomy' => 'pa_platform', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']), $t, true);
            $links .= self::chips('Popular ' . self::name($t) . ' genres', self::terms_by_ids($s['genres']));
        } elseif ($t && $t->taxonomy === 'pa_genres') {
            $links .= self::chips('Other genres', get_terms(['taxonomy' => 'pa_genres', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 24]), $t);
            $links .= self::chips('Platforms', self::terms_by_ids($s['platforms']), null, true);
        } elseif ($t && $t->taxonomy === 'pa_region') {
            $links .= self::chips('All regions', get_terms(['taxonomy' => 'pa_region', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']), $t);
            $links .= self::chips('Platforms', self::terms_by_ids($s['platforms']), null, true);
        } else {
            $links .= self::chips('Collections', get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0]), $t);
            $links .= self::chips('Platforms', $t ? self::terms_by_ids($s['platforms']) : get_terms(['taxonomy' => 'pa_platform', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']), null, true);
            $links .= self::chips('Popular genres', self::terms_by_ids($s['genres']));
        }
        if ($links) {
            $out .= '<nav class="omg-seo-nav" aria-label="Related pages">' . $links . '</nav>';
        }
        $out .= '</div></section>';

        // Structured data: FAQPage + CollectionPage with the products listed first on this page.
        $ld = [];
        if ($faqs) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn ($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faqs)];
        }
        $c = self::ctx();
        $items = [];
        foreach (array_slice($s['popular'], 0, 10) as $i => $id) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink($id), 'name' => self::clean(get_the_title($id))];
        }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $c['title'], 'description' => $c['desc'], 'url' => is_wp_error($c['url']) ? '' : $c['url'], 'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $s['count'], 'itemListElement' => $items]];
        foreach ($ld as $j) {
            $out .= '<script type="application/ld+json">' . wp_json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }
        $out .= self::assets();
        return $html . $out;
    }

    private static function assets(): string
    {
        $card = class_exists('OMG_Cart_Extras') ? OMG_Cart_Extras::card_css() : '';
        return '<style id="omg-seo-css">' . $card . '
.omg-seo-block{padding:8px 0 56px}
.omg-seo-wrap{box-sizing:border-box;width:100%;max-width:1360px;margin:0 auto;padding:0 40px;display:flex;flex-direction:column;gap:40px;color:#d9d6e8;font-family:Poppins,system-ui,sans-serif}
.omg-seo-h2{margin:0 0 16px;color:#f4f2fb;font:700 24px/1.25 Poppins,system-ui,sans-serif}
.omg-seo-rel .omg-cr-row{grid-auto-columns:calc((100% - 60px) / 6)}
.omg-seo-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:24px;align-items:start}
.omg-seo-about{padding:28px;border-radius:20px;background:#171424;border:1px solid #2a2540}
.omg-seo-about p{margin:0 0 12px;font-size:15px;line-height:1.7;color:#d9d6e8}
.omg-seo-about p:last-child{margin-bottom:0}
.omg-seo-facts{padding:24px;border-radius:20px;background:#171424;border:1px solid #2a2540}
.omg-seo-facts h3,.omg-seo-links h3{margin:0 0 14px;color:#f4f2fb;font:600 16px/1.3 Poppins,system-ui,sans-serif}
.omg-seo-facts dl{margin:0;display:flex;flex-direction:column}
.omg-seo-facts dl>div{display:flex;justify-content:space-between;gap:16px;padding:11px 0;border-top:1px solid #2a2540}
.omg-seo-facts dl>div:first-child{border-top:0;padding-top:0}
.omg-seo-facts dt{color:#a29fb6;font-size:14px;white-space:nowrap}
.omg-seo-facts dd{margin:0;color:#f4f2fb;font-size:14px;font-weight:600;text-align:right}
.omg-seo-faq details{border-radius:16px;background:#171424;border:1px solid #2a2540;margin:0 0 10px;transition:border-color .15s}
.omg-seo-faq details[open]{border-color:#4b3f78}
.omg-seo-faq summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;cursor:pointer;list-style:none;color:#f4f2fb;font:600 16px/1.4 Poppins,system-ui,sans-serif}
.omg-seo-faq summary::-webkit-details-marker{display:none}
.omg-seo-faq summary .i{flex:none;position:relative;width:14px;height:14px}
.omg-seo-faq summary .i::before,.omg-seo-faq summary .i::after{content:"";position:absolute;left:0;top:6px;width:14px;height:2px;border-radius:2px;background:#b79cff;transition:transform .2s}
.omg-seo-faq summary .i::after{transform:rotate(90deg)}
.omg-seo-faq details[open] summary .i::after{transform:rotate(0)}
.omg-seo-faq .a{padding:0 20px 18px;color:#d9d6e8;font-size:15px;line-height:1.7}
.omg-seo-faq summary:focus-visible,.omg-seo-chip:focus-visible{outline:2px solid #b79cff;outline-offset:2px}
.omg-seo-nav{display:flex;flex-direction:column;gap:24px}
.omg-seo-links ul{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.omg-seo-chip{display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 14px;border-radius:999px;background:#171424;border:1px solid #2a2540;color:#d9d6e8!important;font:500 14px/1 Poppins,system-ui,sans-serif;text-decoration:none;transition:border-color .15s,background .15s}
.omg-seo-chip:hover{border-color:#8b5cf6;color:#f4f2fb!important}
.omg-seo-chip.is-on{background:rgba(139,92,246,.18);border-color:#8b5cf6;color:#f4f2fb!important}
.omg-seo-chip .c{color:#77738c;font-size:12px}
.omg-seo-chip .omg-seo-pf{position:relative!important;top:auto!important;right:auto!important;width:22px!important;height:22px!important;border:0!important;box-shadow:none!important;flex:none}
.omg-seo-chip .omg-seo-pf::before{width:12px!important;height:12px!important}
.omg-seo-chip .omg-seo-pf[data-pf^="xbox"]{background:#107c10!important;display:inline-grid!important;place-items:center}
.omg-seo-chip .omg-seo-pf[data-pf^="xbox"]::before{content:"";background:#fff;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27black%27 stroke-width=%273.2%27 stroke-linecap=%27round%27%3E%3Cpath d=%27M5 4c4 3 10 13 14 16M19 4C15 7 9 17 5 20%27/%3E%3C/svg%3E") center/contain no-repeat;mask:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27black%27 stroke-width=%273.2%27 stroke-linecap=%27round%27%3E%3Cpath d=%27M5 4c4 3 10 13 14 16M19 4C15 7 9 17 5 20%27/%3E%3C/svg%3E") center/contain no-repeat}
@media (max-width:1100px){.omg-seo-rel .omg-cr-row{grid-auto-columns:calc((100% - 36px) / 4)}}
@media (max-width:900px){.omg-seo-grid{grid-template-columns:1fr}.omg-seo-rel .omg-cr-row{grid-auto-columns:calc((100% - 24px) / 3)}}
@media (max-width:600px){.omg-seo-wrap{padding:0 16px;gap:32px}.omg-seo-rel .omg-cr-row{grid-auto-columns:44%}.omg-cr-nav{display:none!important}.omg-seo-about,.omg-seo-facts{padding:20px}.omg-seo-h2{font-size:20px}.omg-seo-faq summary{font-size:15px;padding:16px}}
</style><script id="omg-seo-js">(function(){document.querySelectorAll(".omg-seo-rel .omg-cr").forEach(function(sec){var row=sec.querySelector(".omg-cr-row"),pv=sec.querySelector(".prev"),nx=sec.querySelector(".next");if(!row||!pv||!nx)return;var u=function(){pv.hidden=row.scrollLeft<8;nx.hidden=row.scrollLeft+row.clientWidth>=row.scrollWidth-8;};pv.addEventListener("click",function(){row.scrollBy({left:-row.clientWidth*.9,behavior:"smooth"});});nx.addEventListener("click",function(){row.scrollBy({left:row.clientWidth*.9,behavior:"smooth"});});row.addEventListener("scroll",u,{passive:true});window.addEventListener("resize",u);setTimeout(u,60);sec.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("button.omg-cr-add");if(!b)return;b.disabled=true;var l=b.innerHTML;b.textContent="Adding…";var f=new FormData();f.append("product_id",b.getAttribute("data-id"));f.append("quantity","1");fetch("/?wc-ajax=add_to_cart",{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j&&j.error){b.disabled=false;b.innerHTML=l;if(j.product_url)location.href=j.product_url;return;}b.classList.add("is-done");b.textContent="Added";if(window.jQuery)jQuery(document.body).trigger("added_to_cart",[j.fragments,j.cart_hash,jQuery(b)]);}).catch(function(){b.disabled=false;b.innerHTML=l;});});});})();</script>';
    }

    /* ======================================================= term fields */

    public static function term_fields(): void
    {
        $taxes = array_merge(['product_cat'], function_exists('wc_get_attribute_taxonomy_names') ? wc_get_attribute_taxonomy_names() : []);
        foreach ($taxes as $tax) {
            add_action("{$tax}_edit_form_fields", [__CLASS__, 'term_form'], 30);
            add_action("edited_{$tax}", [__CLASS__, 'term_save'], 10);
        }
    }

    public static function term_form($term): void
    {
        wp_nonce_field('omg_seo_term', 'omg_seo_nonce');
        echo '<tr class="form-field"><th colspan="2"><h2 style="margin:16px 0 0">OMGGamer SEO</h2><p class="description" style="font-weight:400">Leave empty to use the texts built from the catalogue (genres, platforms, regions).</p></th></tr>';
        foreach (self::FIELDS as $k => $label) {
            $v = (string) get_term_meta($term->term_id, $k, true);
            $area = in_array($k, ['omg_about', 'omg_faq', 'omg_seo_desc'], true);
            echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td>'
                . ($area ? '<textarea id="' . esc_attr($k) . '" name="' . esc_attr($k) . '" rows="' . ($k === 'omg_seo_desc' ? 2 : 6) . '">' . esc_textarea($v) . '</textarea>' : '<input type="text" id="' . esc_attr($k) . '" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">')
                . ($k === 'omg_seo_desc' ? '<p class="description">About 150–160 characters.</p>' : '')
                . '</td></tr>';
        }
    }

    public static function term_save($term_id): void
    {
        if (!isset($_POST['omg_seo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['omg_seo_nonce'])), 'omg_seo_term') || !current_user_can('manage_product_terms')) {
            return;
        }
        foreach (array_keys(self::FIELDS) as $k) {
            $raw = isset($_POST[$k]) ? wp_unslash($_POST[$k]) : '';
            $v = $k === 'omg_about' ? wp_kses_post($raw) : ($k === 'omg_faq' ? sanitize_textarea_field($raw) : sanitize_text_field($raw));
            if ($v === '') {
                delete_term_meta($term_id, $k);
            } else {
                update_term_meta($term_id, $k, $v);
            }
        }
        $t = get_term($term_id);
        if ($t instanceof WP_Term) {
            delete_transient('omg_seo_' . md5($t->taxonomy . ':' . $t->term_id));
        }
    }
}

OMG_SEO::init();
