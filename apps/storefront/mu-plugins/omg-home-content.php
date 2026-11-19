<?php
/**
 * Plugin Name: OMGGamer home content (from OMGGamer OS)
 * Description: Home page sections and hero banners chosen in OMGGamer OS (Storefront page). Each section shows the
 *              chosen products in their order; flash deals also get the flash discount on exactly those products.
 *              Active banners (within their dates) replace the hero slides. Nothing chosen: the page stays automatic.
 *              Data: option omg_storefront_content, written by the catalogue connector (/storefront/content).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Product IDs chosen in OMGGamer OS for a home section (flash, best, accounts, hardware, accessories). */
function omg_home_section(string $key): array
{
    $c = get_option('omg_storefront_content', []);
    $ids = is_array($c) && isset($c['sections'][$key]) && is_array($c['sections'][$key]) ? $c['sections'][$key] : [];
    return array_values(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0 && get_post_status($id) === 'publish'));
}

final class OMG_Home_Content
{
    /** Bricks query loop element of each home section. */
    private const LOOPS = [
        '3ea92f' => 'flash',
        '6296d9' => 'best',
        'be38bf' => 'accounts',
        '3eh28h' => 'hardware',
        '986d0j' => 'accessories',
    ];

    public static function init(): void
    {
        add_filter('bricks/posts/query_vars', [__CLASS__, 'query'], 20, 3);
        add_action('template_redirect', [__CLASS__, 'start_banners'], 5);
    }

    public static function query($vars, $settings, $element_id)
    {
        $key = self::LOOPS[(string) $element_id] ?? null;
        if ($key === null) {
            return $vars;
        }
        $ids = omg_home_section($key);
        if (!$ids) {
            return $vars; // nothing chosen: the section stays automatic
        }
        foreach (['tax_query', 'meta_query', 's', 'product_cat', 'offset', 'meta_key', 'order'] as $k) {
            unset($vars[$k]);
        }
        $vars['post_type'] = 'product';
        $vars['post_status'] = 'publish';
        $vars['post__in'] = $ids;
        $vars['orderby'] = 'post__in';
        $vars['posts_per_page'] = count($ids);
        $vars['ignore_sticky_posts'] = true;
        $vars['no_found_rows'] = true;
        return $vars;
    }

    private static function active_banners(): array
    {
        $c = get_option('omg_storefront_content', []);
        $now = time();
        $out = [];
        foreach ((is_array($c) ? ($c['banners'] ?? []) : []) as $b) {
            if (!is_array($b) || empty($b['title']) || empty($b['image'])) {
                continue;
            }
            $from = !empty($b['starts_at']) ? strtotime((string) $b['starts_at']) : false;
            $to = !empty($b['ends_at']) ? strtotime((string) $b['ends_at']) : false;
            if (($from && $from > $now) || ($to && $to < $now)) {
                continue;
            }
            $out[] = $b;
        }
        return $out;
    }

    public static function start_banners(): void
    {
        if (is_admin() || !is_front_page() || !self::active_banners()) {
            return;
        }
        ob_start([__CLASS__, 'replace_slides']);
    }

    private static function slide(array $b, int $i, int $n): string
    {
        $tags = '';
        foreach ((array) ($b['tags'] ?? []) as $k => $t) {
            $tags .= '<span class="omg-hs-tag' . ($k === 0 ? ' hot' : '') . '">' . esc_html($t) . '</span>';
        }
        $title = esc_html($b['title']) . (!empty($b['highlight']) ? ' <span>' . esc_html($b['highlight']) . '</span>' : '');
        $img = '<picture>' . (!empty($b['image_mobile']) ? '<source media="(max-width:767px)" srcset="' . esc_url($b['image_mobile']) . '">' : '')
            . '<img src="' . esc_url($b['image']) . '" alt="" ' . ($i === 0 ? 'fetchpriority="high"' : 'loading="lazy"') . ' decoding="async"></picture>';
        $label = ($i + 1) . ' of ' . $n . ': ' . $b['title'];
        return '<a class="omg-hs-slide s-os' . ($i === 0 ? ' is-on' : '') . '" href="' . esc_url($b['url'] ?: home_url('/')) . '" role="group" aria-roledescription="slide" aria-label="' . esc_attr($label) . '"'
            . ($i === 0 ? '' : ' tabindex="-1" aria-hidden="true"') . '>'
            . '<div class="omg-hs-art" aria-hidden="true">' . $img . '<span class="shade"></span></div>'
            . '<div class="omg-hs-copy">' . ($tags ? '<div class="omg-hs-tags">' . $tags . '</div>' : '')
            . '<h2 class="omg-hs-title">' . $title . '</h2>'
            . (!empty($b['subtitle']) ? '<p class="omg-hs-sub">' . esc_html($b['subtitle']) . '</p>' : '')
            . '<span class="omg-hs-cta">' . esc_html($b['button'] ?: 'Shop now') . ' <span aria-hidden="true">&rarr;</span></span></div></a>';
    }

    public static function replace_slides(string $html): string
    {
        $start = strpos($html, '<div class="omg-hs" id="omg-hs"');
        if ($start === false) {
            return $html;
        }
        $open_end = strpos($html, '>', $start);
        $nav = strpos($html, '<div class="omg-hs-nav">', $open_end);
        if ($open_end === false || $nav === false) {
            return $html;
        }
        $banners = self::active_banners();
        $n = count($banners);
        $slides = '';
        foreach ($banners as $i => $b) {
            $slides .= self::slide($b, $i, $n);
        }
        $html = substr($html, 0, $open_end + 1) . $slides . substr($html, $nav);
        // One dot per banner.
        $dots = '';
        for ($i = 0; $i < $n; $i++) {
            $dots .= '<button type="button" class="omg-hs-dot' . ($i === 0 ? ' is-on' : '') . '" aria-label="Show slide ' . ($i + 1) . '"></button>';
        }
        $html = (string) preg_replace('#(<div class="omg-hs-dots">).*?(</div>)#s', '$1' . str_replace('$', '\\$', $dots) . '$2', $html, 1);
        $css = '<style id="omg-hs-os">'
            . '.s-os .omg-hs-art{background:#0d0619}'
            . '.s-os .omg-hs-art img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center}'
            . '.s-os .shade{position:absolute;inset:0;background:linear-gradient(90deg,rgba(13,6,25,.82) 0%,rgba(13,6,25,.45) 45%,rgba(13,6,25,.05) 75%),linear-gradient(0deg,rgba(13,6,25,.55),transparent 45%)}'
            . '.s-os .omg-hs-title span{color:#c4b5fd}'
            . ($n < 2 ? '.omg-hs-nav{display:none!important}' : '')
            . '</style>';
        return (string) preg_replace('#</head>#', $css . '</head>', $html, 1);
    }
}

OMG_Home_Content::init();
