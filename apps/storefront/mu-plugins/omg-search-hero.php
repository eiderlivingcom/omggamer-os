<?php
/**
 * Plugin Name: OMGGamer search results header
 * Description: On product search results the archive banner shows the search instead of the shop page: kicker
 *              "Search results", the search term as the heading, how many products were found and a link to clear
 *              the search. Rewritten on the server, so nothing flashes.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Search_Hero
{
    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'start'], 20);
    }

    public static function start(): void
    {
        if (is_admin() || !is_search() || trim((string) get_search_query(false)) === '') {
            return;
        }
        ob_start([__CLASS__, 'rewrite']);
    }

    public static function rewrite(string $html): string
    {
        if (!str_contains($html, 'omg-ar-title')) {
            return $html;
        }
        global $wp_query;
        $term = trim((string) get_search_query(false));
        $count = (int) ($wp_query->found_posts ?? 0);
        $q = esc_html($term);
        $found = $count === 0
            ? sprintf('No products found for &ldquo;%s&rdquo;. Check the spelling or try a shorter name.', $q)
            : sprintf(
                '%s for &ldquo;%s&rdquo;. Use the filters to narrow it down by platform, region or type.',
                esc_html(sprintf(_n('%s product found', '%s products found', $count), number_format_i18n($count))),
                $q
            );
        $clear = esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/'));

        // Kicker "Collections" → "Search results".
        $html = (string) preg_replace(
            '#(<span[^>]*class="[^"]*omg-ar-kicker[^"]*"[^>]*>)[^<]*(</span>)#',
            '$1Search results$2',
            $html,
            1
        );
        // Heading "Shop" → the search term.
        $html = (string) preg_replace(
            '#(<h1[^>]*class="[^"]*omg-ar-title[^"]*"[^>]*>).*?(</h1>)#s',
            '$1&ldquo;' . str_replace('$', '\$', $q) . '&rdquo;$2',
            $html,
            1
        );
        // Shop description → results line; the generic line below it goes.
        $html = (string) preg_replace(
            '#(<div[^>]*class="[^"]*omg-ar-desc[^"]*"[^>]*>).*?(</div>)#s',
            '$1<p>' . str_replace('$', '\$', $found) . ' <a class="omg-search-clear" href="' . $clear . '">Clear search</a></p>$2',
            $html,
            1
        );
        $html = (string) preg_replace('#<p[^>]*class="[^"]*omg-ar-desc-default[^"]*"[^>]*>.*?</p>#s', '', $html, 1);
        $css = '<style id="omg-search-hero">.omg-ar-title{word-break:break-word}'
            . '.omg-search-clear{margin-left:8px;color:#c4b5fd;font-weight:600;text-decoration:underline;text-underline-offset:3px}'
            . '.omg-search-clear:hover{color:#fff}</style>';
        return (string) preg_replace('#</head>#', $css . '</head>', $html, 1);
    }
}

OMG_Search_Hero::init();
