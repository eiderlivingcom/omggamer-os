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
        add_filter('bricks/frontend/render_data', [__CLASS__, 'kicker'], 46);
        add_filter('bricks/frontend/render_data', [__CLASS__, 'genre_chips'], 47);
    }

    /** Product page "Genre" chips (#omg-dg-genre) link to their genre pages. */
    public static function genre_chips($html)
    {
        if (!is_string($html) || strpos($html, 'omg-dg-genre') === false || !function_exists('is_product') || !is_product()) {
            return $html;
        }
        $links = [];
        foreach (['pa_genres', 'product_cat'] as $tax) {
            $terms = taxonomy_exists($tax) ? wp_get_post_terms((int) get_queried_object_id(), $tax) : [];
            foreach (is_wp_error($terms) ? [] : $terms as $t) {
                $url = get_term_link($t);
                $key = mb_strtolower(trim(html_entity_decode($t->name, ENT_QUOTES)));
                if (!is_wp_error($url) && !isset($links[$key])) {
                    $links[$key] = $url; // genre pages first, collections only as a fallback
                }
            }
        }
        if (!$links) {
            return $html;
        }
        $start = strpos($html, 'id="omg-dg-genre"');
        $end = $start === false ? false : strpos($html, '<!--brx-loop-end', $start);
        if ($start === false) {
            return $html;
        }
        $end = $end === false ? min(strlen($html), $start + 6000) : $end;
        $block = substr($html, $start, $end - $start);
        $block = (string) preg_replace_callback(
            '~<div class="([^"]*\bomg-dg-chip\b[^"]*)"(\s[^>]*)?>\s*(<span[^>]*>)(.*?)(</span>)\s*</div>~s',
            static function ($m) use ($links) {
                $key = mb_strtolower(trim(html_entity_decode(wp_strip_all_tags($m[4]), ENT_QUOTES)));
                if (!isset($links[$key])) {
                    return $m[0];
                }
                return '<a class="' . $m[1] . ' omg-dg-chip-link" href="' . esc_url($links[$key]) . '">' . $m[3] . $m[4] . $m[5] . '</a>';
            },
            $block
        );
        $css = '<style id="omg-genre-chip-links">a.omg-dg-chip-link{text-decoration:none!important;cursor:pointer;transition:border-color .15s,color .15s,background-color .15s}'
            . 'a.omg-dg-chip-link:hover,a.omg-dg-chip-link:focus-visible{border-color:#8b5cf6!important;color:#fff!important}a.omg-dg-chip-link:hover span{color:#fff!important}</style>';
        return substr($html, 0, $start) . $block . substr($html, $end) . $css;
    }

    /** The archive hero's small label reads "Publisher" / "Developer" instead of the template's "Collections". */
    public static function kicker($html)
    {
        if (!is_string($html) || strpos($html, 'omg-ar-kicker') === false || !is_tax(self::TAXONOMIES)) {
            return $html;
        }
        $label = is_tax('pa_publishers') ? 'Publisher' : 'Developer';
        $html = (string) preg_replace('#(<span[^>]*class="[^"]*omg-ar-kicker[^"]*"[^>]*>)[^<]*(</span>)#', '${1}' . $label . '$2', $html, 1);
        // The template's own script relabels the kicker after load: keep ours.
        $js = '<script id="omg-brand-kicker">(function(){var k=document.querySelector(".omg-ar-kicker");if(!k)return;var t=' . wp_json_encode($label) . ';'
            . 'var fix=function(){if(k.textContent!==t)k.textContent=t;};fix();'
            . 'if(window.MutationObserver){var o=new MutationObserver(fix);o.observe(k,{childList:true,characterData:true,subtree:true});setTimeout(function(){o.disconnect();},8000);}})();</script>';
        return $html . $js;
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
