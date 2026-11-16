<?php
/** Sets the OMG PRO membership product image from wp-content/uploads/omg-brand/omg-pro-membership.png. */
if (!defined('ABSPATH')) {
    exit;
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$pid = class_exists('OMG_Pro_Offer') ? OMG_Pro_Offer::PRODUCT : 1906;
$src = WP_CONTENT_DIR . '/uploads/omg-brand/omg-pro-membership.png';
if (!file_exists($src)) {
    echo "missing $src\n";
    return;
}
$hash = md5_file($src);
$existing = get_posts(['post_type' => 'attachment', 'meta_key' => '_omg_pro_image', 'meta_value' => $hash, 'fields' => 'ids', 'posts_per_page' => 1]);
if ($existing) {
    $att = (int) $existing[0];
} else {
    $tmp = wp_tempnam('omg-pro');
    copy($src, $tmp);
    $att = media_handle_sideload(['name' => 'omg-pro-membership-member-prices-every-order.png', 'tmp_name' => $tmp], $pid, 'OMG PRO membership: member prices on every order');
    if (is_wp_error($att)) {
        @unlink($tmp);
        echo 'error: ', $att->get_error_message(), "\n";
        return;
    }
    update_post_meta($att, '_omg_pro_image', $hash);
}
update_post_meta($att, '_wp_attachment_image_alt', 'OMG PRO membership: member prices on every order, $0.99 first month then $6.99 a month');
$p = wc_get_product($pid);
$old = $p->get_image_id();
$p->set_image_id($att);
$p->save();
echo "PRO product #$pid image: attachment #$att (was #$old) ", wp_get_attachment_url($att), "\n";
