<?php
/**
 * Read-only: published products that share an OMGGamer listing id (would be duplicate store pages), and a count
 * of published listings per game group.   wp eval-file check-duplicates.php --skip-themes
 */
global $wpdb;
$dups = $wpdb->get_results(
    "SELECT m.meta_value AS ext, GROUP_CONCAT(p.ID) AS ids, COUNT(*) AS n FROM {$wpdb->postmeta} m
       JOIN {$wpdb->posts} p ON p.ID = m.post_id
      WHERE m.meta_key = '_omg_external_id' AND p.post_type = 'product' AND p.post_status = 'publish'
      GROUP BY m.meta_value HAVING n > 1"
);
echo 'Duplicate listings: ' . count($dups) . "\n";
foreach ($dups as $d) {
    echo "  {$d->ext} -> {$d->ids}\n";
}
$n = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_omg_external_id'
      WHERE p.post_type = 'product' AND p.post_status = 'publish'"
);
echo "Published OMGGamer products: {$n}\n";
