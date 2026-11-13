<?php
/**
 * One-off store updates (safe to run again):
 *  1. "Verified seller" -> "Verified partner" in every published Bricks template/page.
 *  2. WooCommerce thumbnails cropped 3:4 (the product-page cover ratio) instead of 1:1.
 *
 *   wp eval-file store-updates-2026-11.php
 */

if (!defined('WP_CLI')) {
    return;
}
global $wpdb;

// 1. Trust badge wording.
$rows = $wpdb->get_results(
    "SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
     WHERE p.post_status = 'publish' AND p.post_type <> 'revision' AND m.meta_key LIKE '\\_bricks\\_page\\_%\\_2'
       AND (m.meta_value LIKE '%Verified seller%' OR m.meta_value LIKE '%s:6:\"seller\";%')"
);
foreach ($rows as $r) {
    $els = maybe_unserialize($r->meta_value);
    if (!is_array($els)) {
        continue;
    }
    $n = 0;
    $byId = [];
    foreach ($els as $e) {
        $byId[$e['id'] ?? ''] = $e;
    }
    foreach ($els as &$e) {
        $t = $e['settings']['text'] ?? null;
        if (!is_string($t)) {
            continue;
        }
        if (strpos($t, 'Verified seller') !== false) {
            $e['settings']['text'] = str_replace('Verified seller', 'Verified partner', $t);
            $n++;
            continue;
        }
        if (trim($t) !== 'seller') {
            continue;
        }
        // "Verified" + "seller" are two text elements in the same trust badge.
        $parent = $byId[$e['parent'] ?? ''] ?? null;
        foreach ((array) ($parent['children'] ?? []) as $sib) {
            if (trim((string) ($byId[$sib]['settings']['text'] ?? '')) === 'Verified') {
                $e['settings']['text'] = 'partner';
                $n++;
                break;
            }
        }
    }
    unset($e);
    if ($n) {
        if (!metadata_exists('post', $r->post_id, '_omg_backup_' . $r->meta_key)) {
            add_post_meta($r->post_id, '_omg_backup_' . $r->meta_key, wp_slash($r->meta_value), true);
        }
        $wpdb->update($wpdb->postmeta, ['meta_value' => maybe_serialize($els)], ['meta_id' => $r->meta_id]);
        wp_cache_delete($r->post_id, 'post_meta');
        WP_CLI::log("Post {$r->post_id} {$r->meta_key}: {$n} text(s) changed");
    }
}

// 2. Thumbnails in the cover ratio.
$was = get_option('woocommerce_thumbnail_cropping');
update_option('woocommerce_thumbnail_cropping', 'custom');
update_option('woocommerce_thumbnail_cropping_custom_width', '3');
update_option('woocommerce_thumbnail_cropping_custom_height', '4');
WP_CLI::log("Thumbnail cropping: {$was} -> custom 3:4");

WP_CLI::success('Done.');
