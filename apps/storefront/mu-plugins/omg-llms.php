<?php
/**
 * Plugin Name: OMGGamer llms.txt
 * Description: Serves /llms.txt (llmstxt.org format): a short plain-text guide to the store for AI assistants and
 *              agents, built from the live collections, platforms and policy pages. Cached for an hour.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Llms
{
    public static function init(): void
    {
        add_action('parse_request', [__CLASS__, 'serve'], 0);
        add_action('save_post_product', static fn () => delete_transient('omg_llms_txt'));
    }

    public static function serve(): void
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path !== '/llms.txt') {
            return;
        }
        $txt = get_transient('omg_llms_txt');
        if (!is_string($txt) || $txt === '') {
            $txt = self::build();
            set_transient('omg_llms_txt', $txt, HOUR_IN_SECONDS);
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=3600');
        echo $txt;
        exit;
    }

    private static function line(string $title, string $url, string $note = ''): string
    {
        return '- [' . $title . '](' . $url . ')' . ($note !== '' ? ': ' . $note : '') . "\n";
    }

    private static function terms(string $tax, int $n): array
    {
        if (!taxonomy_exists($tax)) {
            return [];
        }
        $t = get_terms(['taxonomy' => $tax, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => $n]);
        return is_wp_error($t) ? [] : $t;
    }

    private static function build(): string
    {
        $o = "# OMGGamer\n\n";
        $o .= "> OMGGamer sells digital game keys, game accounts, gift cards and game currency for Steam, PlayStation, Xbox, Nintendo, Epic Games and more, plus gaming hardware and accessories. Digital orders are delivered instantly by email and in the customer's dashboard. Each product page states the platform, the activation region and the delivery type (key, gift, account or top-up).\n\n";
        $o .= "Prices are shown in the visitor's currency. Region-locked products only activate in the region named on the product page; check it before buying.\n\n";

        $o .= "## Collections\n\n";
        foreach (self::terms('product_cat', 20) as $t) {
            if ($t->slug === 'uncategorized') {
                continue;
            }
            $o .= self::line(html_entity_decode($t->name, ENT_QUOTES), get_term_link($t));
        }
        $plats = self::terms('pa_platform', 12);
        if ($plats) {
            $o .= "\n## Platforms\n\n";
            foreach ($plats as $t) {
                $o .= self::line(html_entity_decode($t->name, ENT_QUOTES) . ' games', get_term_link($t));
            }
        }

        $o .= "\n## Help and policies\n\n";
        $pages = [
            'pro' => ['OMG PRO membership', 'member prices on eligible products'],
            'activate' => ['How to activate a product', ''],
            'faq' => ['FAQ', ''],
            'buyer-protection' => ['Buyer protection', ''],
            'refund-policy' => ['Refund policy', 'faulty digital products are replaced or refunded; physical products have a 14-day return window'],
            'terms-and-conditions' => ['Terms and conditions', ''],
            'privacy-policy' => ['Privacy policy', ''],
            'contact' => ['Contact', ''],
        ];
        foreach ($pages as $slug => [$title, $note]) {
            $page = get_page_by_path($slug);
            if ($page && $page->post_status === 'publish') {
                $o .= self::line($title, get_permalink($page), $note);
            }
        }
        $o .= "\n## Optional\n\n";
        $o .= self::line('All products', wc_get_page_permalink('shop'));
        $o .= self::line('Sitemap', home_url('/sitemap_index.xml'));
        return $o;
    }
}

OMG_Llms::init();
