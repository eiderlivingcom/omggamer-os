<?php
/**
 * Adds five hardware products (data, prices and images from their amazon.com listings, 15 Nov 2026) and five
 * accessory drafts for the Etsy items (prices still to be set). Safe to run again: products are matched by
 * _omg_import_ref. Run as an admin: wp eval-file ~/omg-scripts/new-products-2026-11.php --user=1
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$dry = getenv('OMG_DRY') === '1';

function omg_np_cat(string $name, string $slug, int $parent = 0): int
{
    $t = get_term_by('slug', $slug, 'product_cat');
    if ($t) {
        return (int) $t->term_id;
    }
    $r = wp_insert_term($name, 'product_cat', ['slug' => $slug, 'parent' => $parent]);
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

function omg_np_find(string $ref): int
{
    $q = get_posts(['post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_omg_import_ref', 'meta_value' => $ref, 'fields' => 'ids', 'posts_per_page' => 1]);
    return $q ? (int) $q[0] : 0;
}

function omg_np_image(string $url, int $post_id, string $alt): int
{
    $existing = get_posts(['post_type' => 'attachment', 'meta_key' => '_omg_src_url', 'meta_value' => $url, 'fields' => 'ids', 'posts_per_page' => 1]);
    if ($existing) {
        return (int) $existing[0];
    }
    $id = media_sideload_image($url, $post_id, $alt, 'id');
    if (is_wp_error($id)) {
        echo "  image failed: ", $id->get_error_message(), "\n";
        return 0;
    }
    update_post_meta($id, '_omg_src_url', $url);
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    return (int) $id;
}

$hw = omg_np_cat('Hardware', 'hardware');
$acc = omg_np_cat('Accessories', 'accessories');
$gpu = omg_np_cat('Graphics Cards', 'graphics-cards', $hw);
$mon = omg_np_cat('Gaming Monitors', 'gaming-monitors', $hw);
$mice = omg_np_cat('Gaming Mice', 'gaming-mice', $acc);
$stands = omg_np_cat('Stands & Collectibles', 'stands-collectibles', $acc);
$img = static fn (string $id) => "https://m.media-amazon.com/images/I/{$id}._AC_SL1500_.jpg";

$products = [
    [
        'ref' => 'amazon:B0F8PR9L3X',
        'name' => 'ASUS Dual GeForce RTX 5060 8GB GDDR7 OC Edition Graphics Card',
        'price' => '469.99',
        'cats' => [$hw, $gpu],
        'kind' => 'hardware',
        'short' => 'NVIDIA Blackwell graphics card with 8GB GDDR7, DLSS 4 and a compact 2.5-slot, SFF-ready design.',
        'desc' => "<p>The ASUS Dual GeForce RTX 5060 OC Edition brings NVIDIA's Blackwell architecture and DLSS 4 to compact gaming PCs. It pairs 8GB of fast GDDR7 memory with a factory overclock of up to 2565 MHz in OC mode.</p><p>The 2.5-slot, SFF-ready card uses ASUS Axial-tech fans with longer blades and a barrier ring for stronger airflow, and 0dB technology lets the fans stop completely during light loads. Outputs include three DisplayPort 2.1b and one HDMI 2.1b.</p>",
        'attrs' => ['brand' => 'ASUS', 'chipset' => 'NVIDIA GeForce RTX 5060', 'memory' => '8GB GDDR7', 'boost_clock' => '2565 MHz (OC mode), 2535 MHz (default)', 'interface' => 'PCIe 5.0', 'outputs' => '3x DisplayPort 2.1b, 1x HDMI 2.1b', 'slot_width' => '2.5-slot', 'warranty' => '3-year manufacturer warranty'],
        'custom' => [['AI performance', '623 AI TOPS']],
        'features' => ['NVIDIA Blackwell: Built on the Blackwell architecture with support for DLSS 4.', 'Fast GDDR7 memory: 8GB of GDDR7 for high-resolution textures.', 'Factory overclocked: Up to 2565 MHz in OC mode.', 'SFF-ready: A 2.5-slot design that fits compact builds.', 'Axial-tech fans: Longer blades and a barrier ring push more air onto the heatsink.', '0dB technology: Fans stop during light loads for silent desktop use.'],
        'images' => ['81i5KcFKysL', '71gLSOI5PPL', '81lByEmmE4L', '81z9EHtVtbL', '71OuaYJLGaL', '81FfrUaIaNL'],
    ],
    [
        'ref' => 'amazon:B0DW4FRCQR',
        'name' => 'XFX Swift AMD Radeon RX 9070 XT Triple Fan Gaming Edition 16GB GDDR6',
        'price' => '959.99',
        'cats' => [$hw, $gpu],
        'kind' => 'hardware',
        'short' => 'AMD RDNA 4 graphics card with 16GB GDDR6 and a triple-fan cooler, ready for high-refresh 1440p and 4K gaming.',
        'desc' => "<p>The XFX Swift Radeon RX 9070 XT Gaming Edition is built on AMD's RDNA 4 architecture with 16GB of GDDR6 memory, giving plenty of headroom for demanding games at high resolutions.</p><p>A triple-fan Swift cooler keeps temperatures in check under load. Connect your displays through one HDMI and three DisplayPort outputs. Model RX-97TSWF3BA.</p>",
        'attrs' => ['brand' => 'XFX', 'chipset' => 'AMD Radeon RX 9070 XT', 'memory' => '16GB GDDR6', 'interface' => 'PCIe 5.0', 'outputs' => '1x HDMI, 3x DisplayPort'],
        'custom' => [['Architecture', 'AMD RDNA 4'], ['Cooling', 'Triple fan'], ['Model', 'RX-97TSWF3BA']],
        'features' => ['AMD RDNA 4: The latest Radeon architecture for modern games.', '16GB GDDR6: Large memory buffer for high-resolution textures.', 'Triple-fan cooler: XFX Swift cooling for steady performance under load.', 'Four display outputs: One HDMI and three DisplayPort connections.'],
        'images' => ['61Ktllk8BvL'],
    ],
    [
        'ref' => 'amazon:B0HG3FYY4D',
        'name' => 'Samsung 32" Odyssey G5 (G51F) QHD 180Hz Gaming Monitor',
        'price' => '179.99',
        'cats' => [$hw, $mon],
        'kind' => 'hardware',
        'short' => '32-inch QHD gaming monitor with a 180Hz refresh rate and 1ms (MPRT) response time.',
        'desc' => "<p>The Samsung Odyssey G5 (G51F) gives you a big 32-inch QHD screen for sharp detail in games and on the desktop.</p><p>A 180Hz refresh rate and 1ms (MPRT) response time keep fast motion smooth and clear, so it suits competitive shooters as well as open-world games.</p>",
        'attrs' => ['brand' => 'Samsung'],
        'custom' => [['Screen size', '32"'], ['Resolution', 'QHD (2560 x 1440)'], ['Refresh rate', '180Hz'], ['Response time', '1ms (MPRT)'], ['Model', 'Odyssey G5 G51F']],
        'features' => ['32-inch QHD: A large, sharp 2560 x 1440 screen.', '180Hz refresh rate: Smooth motion in fast games.', '1ms (MPRT) response: Clear moving images with little blur.'],
        'images' => ['81qOErv699L'],
    ],
    [
        'ref' => 'amazon:B0FNQ4B2Z2',
        'name' => 'LG UltraGear 27GX704A-B 27" QHD OLED Gaming Monitor 240Hz',
        'price' => '334.70',
        'cats' => [$hw, $mon],
        'kind' => 'hardware',
        'short' => '27-inch QHD OLED gaming monitor with 240Hz, 0.03ms response time, G-Sync Compatible and AMD FreeSync.',
        'desc' => "<p>The LG UltraGear 27GX704A-B uses an OLED panel for deep blacks and vivid colour on a 27-inch QHD screen.</p><p>With a 240Hz refresh rate and a 0.03ms response time it is built for fast, competitive play, and it supports NVIDIA G-Sync Compatible and AMD FreeSync for tear-free gaming.</p>",
        'attrs' => ['brand' => 'LG'],
        'custom' => [['Screen size', '27"'], ['Panel', 'OLED'], ['Resolution', 'QHD (2560 x 1440)'], ['Refresh rate', '240Hz'], ['Response time', '0.03ms'], ['Adaptive sync', 'NVIDIA G-Sync Compatible, AMD FreeSync'], ['Model', '27GX704A-B']],
        'features' => ['OLED panel: Deep blacks and vivid colour.', '240Hz refresh rate: Very smooth motion for competitive games.', '0.03ms response time: Near-instant pixel response.', 'Adaptive sync: NVIDIA G-Sync Compatible and AMD FreeSync support.'],
        'images' => ['81RbXU8hp5L'],
    ],
    [
        'ref' => 'amazon:B07GBZ4Q68',
        'name' => 'Logitech G502 HERO Wired Gaming Mouse',
        'price' => '37.99',
        'cats' => [$acc, $mice],
        'kind' => 'accessory',
        'short' => 'Wired gaming mouse with the HERO 25K sensor (up to 25,600 DPI), RGB lighting, adjustable weights and 11 programmable buttons.',
        'desc' => "<p>The Logitech G502 HERO is one of the most popular gaming mice ever made. Its HERO 25K sensor tracks precisely from low sensitivity up to 25,600 DPI.</p><p>Tune the feel with adjustable weights, set up 11 programmable buttons for your games, and match your setup with customisable RGB lighting. Works with PC and Mac.</p>",
        'attrs' => ['brand' => 'Logitech', 'model' => 'G502 HERO', 'connection' => 'Wired (USB)'],
        'custom' => [['Sensor', 'HERO 25K'], ['Max DPI', '25,600'], ['Buttons', '11 programmable'], ['Lighting', 'RGB'], ['Weights', 'Adjustable']],
        'features' => ['HERO 25K sensor: Precise tracking up to 25,600 DPI.', 'Adjustable weights: Set the weight and balance you like.', '11 programmable buttons: Map commands and macros to each game.', 'RGB lighting: Customisable lighting to match your setup.'],
        'works' => [['name' => 'Windows PC', 'note' => 'USB', 'ok' => true], ['name' => 'Mac', 'note' => 'USB', 'ok' => true]],
        'images' => ['61A2SEGdV2L'],
    ],
];

// Etsy items: drafts in Accessories until their prices (2x the Etsy price, in USD) are set. No personalisation.
$drafts = [
    ['ref' => 'etsy:4590609828', 'name' => 'Gengar Anime Controller Stand'],
    ['ref' => 'etsy:4521208844', 'name' => 'Handmade Wooden Hollow Knight Figurine'],
    ['ref' => 'etsy:4510346143', 'name' => 'Bulbasaur Xbox Controller Holder'],
    ['ref' => 'etsy:4564793124', 'name' => 'Controller & Headset Stand'],
    ['ref' => 'etsy:4584111611', 'name' => 'Magnetic Levitation Iron Man Figure'],
];

foreach ($products as $d) {
    $id = omg_np_find($d['ref']);
    echo ($id ? "update" : "create"), " {$d['name']} \${$d['price']}\n";
    if ($dry) {
        continue;
    }
    $p = $id ? wc_get_product($id) : new WC_Product_Simple();
    $p->set_name($d['name']);
    $p->set_status('publish');
    $p->set_catalog_visibility('visible');
    $p->set_regular_price($d['price']);
    $p->set_sale_price('');
    $p->set_short_description($d['short']);
    $p->set_description($d['desc']);
    $p->set_virtual(false);
    $p->set_downloadable(false);
    $p->set_manage_stock(false);
    $p->set_stock_status('instock');
    $p->set_category_ids($d['cats']);
    $id = $p->save();
    update_post_meta($id, '_omg_import_ref', $d['ref']);
    update_post_meta($id, '_omg_kind', $d['kind']);
    update_post_meta($id, '_omg_layout', $d['kind']);
    $media = [];
    foreach ($d['images'] as $i) {
        $m = omg_np_image($img($i), $id, $d['name']);
        if ($m) {
            $media[] = $m;
        }
    }
    if ($media) {
        $p = wc_get_product($id);
        $p->set_image_id($media[0]);
        $p->set_gallery_image_ids(array_slice($media, 1));
        $p->save();
    }
    OMG_PS::set_attrs($id, $d['kind'], $d['attrs']);
    $specs = OMG_PS::specs($id);
    $specs['custom'] = $d['custom'];
    update_post_meta($id, 'omg_specs', wp_slash(wp_json_encode($specs)));
    update_post_meta($id, 'omg_key_features', implode("\n", $d['features']));
    if (!empty($d['works'])) {
        update_post_meta($id, 'omg_works_with', wp_slash(wp_json_encode($d['works'])));
    }
    echo "  => #$id, ", count($media), " images, ", get_permalink($id), "\n";
}

foreach ($drafts as $d) {
    $id = omg_np_find($d['ref']);
    echo ($id ? "exists" : "draft"), " {$d['name']}\n";
    if ($dry || $id) {
        continue;
    }
    $p = new WC_Product_Simple();
    $p->set_name($d['name']);
    $p->set_status('draft');
    $p->set_virtual(false);
    $p->set_category_ids([$acc, $stands]);
    $id = $p->save();
    update_post_meta($id, '_omg_import_ref', $d['ref']);
    update_post_meta($id, '_omg_kind', 'accessory');
    update_post_meta($id, '_omg_layout', 'accessory');
    echo "  => draft #$id (add price, photos and description, then publish)\n";
}
wc_delete_product_transients();
