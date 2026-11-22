<?php
/**
 * Plugin Name: OMGGamer publisher and developer pages
 * Description: Publisher (/publishers/{name}/) and developer (/developers/{name}/) archives use the same product
 *              archive template as collections, platforms, genres and regions: hero, filters, product cards and
 *              the SEO block (about, quick facts, FAQs, related links) from omg-seo. Product pages link the
 *              publisher and developer to these archives.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Brand_Archives
{
    public const TAXONOMIES = ['pa_publishers', 'pa_developers'];
    private const TEMPLATE = 'omg-product-archive-shop-collections-genres-platforms-regions';

    public static function init(): void
    {
        add_filter('bricks/active_templates', [__CLASS__, 'template'], 30);
        add_filter('woocommerce_attribute_label', [__CLASS__, 'label'], 20, 3);
        add_filter('bricks/frontend/render_data', [__CLASS__, 'spec_links'], 45);
    }

    /** Product Specifications: each publisher and developer name links to its archive page. */
    public static function spec_links($html)
    {
        if (!is_string($html) || strpos($html, 'omg-sp-row') === false || !function_exists('is_product') || !is_product()) {
            return $html;
        }
        $id = (int) get_queried_object_id();
        foreach (['publisher' => 'pa_publishers', 'developer' => 'pa_developers'] as $key => $tax) {
            $terms = taxonomy_exists($tax) ? wp_get_post_terms($id, $tax) : [];
            if (is_wp_error($terms) || !$terms) {
                continue;
            }
            $links = [];
            foreach ($terms as $t) {
                $url = get_term_link($t);
                if (!is_wp_error($url)) {
                    $links[] = '<a href="' . esc_url($url) . '">' . esc_html(html_entity_decode($t->name, ENT_QUOTES)) . '</a>';
                }
            }
            if (!$links) {
                continue;
            }
            $html = (string) preg_replace_callback(
                '~(<div[^>]*\bomg-sp-row\b[^>]*data-k="' . $key . '"[^>]*>.*?<span[^>]*\bomg-sp-v\b[^>]*>)(.*?)(</span>)~s',
                static fn ($m) => $m[1] . implode(', ', $links) . $m[3],
                $html,
                1
            );
        }
        return $html;
    }

    private static function template_id(): int
    {
        static $id = null;
        if ($id === null) {
            $p = get_posts(['post_type' => 'bricks_template', 'name' => self::TEMPLATE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
            $id = $p ? (int) $p[0] : 0;
        }
        return $id;
    }

    public static function template($active)
    {
        if (!is_array($active) || is_admin() || !is_tax(self::TAXONOMIES)) {
            return $active;
        }
        $id = self::template_id();
        if ($id) {
            // Bricks files product attribute archives under "wc_archive" (content type); set every slot it may read.
            foreach (['content', 'archive', 'wc_archive'] as $slot) {
                $active[$slot] = $id;
            }
        }
        return $active;
    }

    /** "Publishers" / "Developers" read as one company on a product's specs. */
    public static function label($label, $name = '', $product = null)
    {
        if ($name === 'pa_publishers') {
            return 'Publisher';
        }
        if ($name === 'pa_developers') {
            return 'Developer';
        }
        return $label;
    }
}

OMG_Brand_Archives::init();
