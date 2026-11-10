<?php
/**
 * Replace the text "OMG / OMGGamer" logos with the OMGGamer logo image.
 *
 *   wp eval-file brand-apply.php                         (store: Bricks header 98 and footer 100)
 *   wp --url=<site>/blogs/ eval-file brand-apply.php     (blog: omg-header, omg-footer, omg-footer-min template parts)
 *
 * The original content is kept in the _omg_brand_backup post meta the first time it runs. Safe to run again.
 */

if (!defined('WP_CLI')) {
    return;
}

$logo = esc_url(content_url('uploads/omg-brand/omggamer-logo.svg'));
$img = static function (string $class) use ($logo): string {
    return '<img src="' . $logo . '" alt="OMGGamer" width="135" height="38" class="' . $class . '" decoding="async">';
};

$backup = static function (int $id, $value): void {
    if (!metadata_exists('post', $id, '_omg_brand_backup')) {
        add_post_meta($id, '_omg_brand_backup', wp_slash($value), true);
    }
};

if ((int) get_current_blog_id() === 1) {
    foreach ([98 => '_bricks_page_header_2', 100 => '_bricks_page_footer_2'] as $pid => $key) {
        $els = get_post_meta($pid, $key, true);
        if (!is_array($els) || !$els) {
            WP_CLI::warning("Template $pid has no $key data; skipped.");
            continue;
        }
        $backup($pid, $els);
        $drop = [];
        $changed = 0;
        foreach ($els as &$e) {
            $css = $e['settings']['_cssId'] ?? '';
            if ($css === 'omg-logo-mark') {
                $drop[] = $e['id'];
            } elseif ($css === 'omg-logo-text') {
                $e['settings']['text'] = $img('omg-brand-img');
                $changed++;
            } elseif ($css === 'omg-ftr-logo') {
                $e['settings']['text'] = '<a href="/" aria-label="OMGGamer home">' . $img('omg-brand-img omg-brand-img--light') . '</a>';
                $changed++;
            }
        }
        unset($e);
        if ($drop) {
            $els = array_values(array_filter($els, static fn ($e) => !in_array($e['id'], $drop, true)));
            foreach ($els as &$e) {
                if (!empty($e['children'])) {
                    $e['children'] = array_values(array_diff($e['children'], $drop));
                }
            }
            unset($e);
        }
        update_post_meta($pid, $key, wp_slash($els));
        WP_CLI::log("Template $pid: $changed logo element(s) replaced, " . count($drop) . ' removed.');
    }
} else {
    $pattern = '~<!-- wp:paragraph (?:(?!-->).)*--><p[^>]*>OMG</p><!-- /wp:paragraph -->\s*'
        . '<!-- wp:paragraph (?:(?!-->).)*--><p[^>]*><a href="([^"]+)" style="color:(#[0-9A-Fa-f]{3,6})">OMGGamer</a></p><!-- /wp:paragraph -->~s';
    foreach (['omg-header', 'omg-footer', 'omg-footer-min'] as $slug) {
        $post = get_page_by_path($slug, OBJECT, 'wp_template_part');
        if (!$post) {
            WP_CLI::warning("Template part $slug not found; skipped.");
            continue;
        }
        $n = 0;
        $content = preg_replace_callback($pattern, static function ($m) use ($img) {
            $light = strtoupper($m[2]) === '#FFFFFF' || strtoupper($m[2]) === '#FFF';
            return '<!-- wp:html --><a class="omg-brand-logo" href="' . esc_url($m[1]) . '" aria-label="OMGGamer home">'
                . $img('omg-brand-img' . ($light ? ' omg-brand-img--light' : '')) . '</a><!-- /wp:html -->';
        }, $post->post_content, -1, $n);
        if (!$n) {
            WP_CLI::log("Template part $slug: no text logo found (already done?).");
            continue;
        }
        $backup($post->ID, $post->post_content);
        wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $content]));
        WP_CLI::log("Template part $slug: $n logo(s) replaced.");
    }
}
WP_CLI::success('Done.');
