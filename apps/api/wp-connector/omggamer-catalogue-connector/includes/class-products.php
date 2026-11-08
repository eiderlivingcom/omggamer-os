<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create or update one WooCommerce product from an OMGGamer OS payload.
 *
 * Mapping: the product carries meta _omg_external_id (the OMGGamer OS product ID); each variation carries
 * _omg_variant_id. Lookups go through that meta, so the same product is never created twice.
 * Only fields OMGGamer OS owns are written. Orders, reviews, Dokan settings and anything not in the
 * payload are left alone. Products are never deleted; variations that disappear are set to private.
 */
class OMG_Connector_Products
{
    const STATUSES = ['draft', 'pending', 'publish', 'private'];

    private $warnings = [];

    public static function find_by_external($external_id)
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_omg_external_id' AND pm.meta_value = %s AND p.post_type = 'product'
             ORDER BY p.ID ASC LIMIT 1",
            (string) $external_id
        ));
        return $id ? (int) $id : 0;
    }

    private static function find_variation($parent_id, $variant_id)
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_omg_variant_id' AND pm.meta_value = %s AND p.post_parent = %d
             AND p.post_type = 'product_variation' LIMIT 1",
            (string) $variant_id,
            (int) $parent_id
        ));
        return $id ? (int) $id : 0;
    }

    public function upsert(array $p)
    {
        $this->warnings = [];
        $external_id = sanitize_text_field($p['external_id'] ?? '');
        if ($external_id === '' || empty($p['name'])) {
            return new WP_Error('omg_invalid', 'external_id and name are required.', ['status' => 422]);
        }
        $variants = array_values(array_filter((array) ($p['variants'] ?? []), 'is_array'));
        if (!$variants) {
            return new WP_Error('omg_invalid', 'At least one variant is required.', ['status' => 422]);
        }
        $status = in_array($p['status'] ?? 'draft', self::STATUSES, true) ? $p['status'] : 'draft';
        $want_variable = count($variants) > 1;

        $id = self::find_by_external($external_id);
        $created = false;
        if ($id && get_post_status($id) === 'trash') {
            wp_untrash_post($id);
        }
        if ($id) {
            $current_type = WC_Product_Factory::get_product_type($id);
            $target = $want_variable ? 'variable' : 'simple';
            if ($current_type !== $target && in_array($current_type, ['simple', 'variable'], true)) {
                wp_set_object_terms($id, $target, 'product_type');
                wc_delete_product_transients($id);
            }
            $product = wc_get_product($id);
        } else {
            $product = $want_variable ? new WC_Product_Variable() : new WC_Product_Simple();
            $created = true;
            $product->set_virtual(true); // digital goods; only set on create
            if (!empty($p['slug'])) {
                $product->set_slug(sanitize_title($p['slug']));
            }
        }
        if (!$product) {
            return new WP_Error('omg_load_failed', 'Could not load the WooCommerce product.', ['status' => 500]);
        }

        $product->set_name(wp_strip_all_tags((string) $p['name']));
        if (array_key_exists('description', $p)) {
            $product->set_description(wp_kses_post((string) $p['description']));
        }
        if (array_key_exists('short_description', $p)) {
            $product->set_short_description(wp_kses_post((string) $p['short_description']));
        }
        $product->set_status($status);
        if (!empty($p['categories'])) {
            $product->set_category_ids($this->category_ids((array) $p['categories']));
        }
        if (isset($p['tags'])) {
            $product->set_tag_ids($this->tag_ids((array) $p['tags']));
        }

        // Display attributes (developer, publisher, genre...) plus variation dimensions.
        $dims = $this->dimensions($variants);
        $product->set_attributes($this->attributes((array) ($p['attributes'] ?? []), $dims, $want_variable));

        if (!$want_variable) {
            $v = $variants[0];
            $this->apply_sku($product, $v['sku'] ?? '');
            $this->apply_price($product, $v);
        }
        $product_id = $product->save();
        if (!$product_id) {
            return new WP_Error('omg_save_failed', 'WooCommerce did not save the product.', ['status' => 500]);
        }
        update_post_meta($product_id, '_omg_external_id', $external_id);
        update_post_meta($product_id, '_omg_synced_at', gmdate('c'));
        if (!empty($p['meta']) && is_array($p['meta'])) {
            foreach ($p['meta'] as $k => $val) {
                $key = '_omg_' . sanitize_key($k);
                update_post_meta($product_id, $key, is_scalar($val) ? (string) $val : wp_json_encode($val));
            }
        }
        if ($created) {
            $this->assign_author($product_id);
        }

        $variation_ids = [];
        if ($want_variable) {
            $variation_ids = $this->sync_variations($product_id, $variants, $dims);
            WC_Product_Variable::sync($product_id);
        } else {
            update_post_meta($product_id, '_omg_variant_id', sanitize_text_field($variants[0]['variant_id'] ?? ''));
            $variation_ids[(string) ($variants[0]['variant_id'] ?? '')] = $product_id;
        }

        $this->sync_images($product_id, (array) ($p['images'] ?? []));
        $this->seo($product_id, (array) ($p['seo'] ?? []));
        wc_delete_product_transients($product_id);

        return [
            'ok' => true,
            'product_id' => $product_id,
            'created' => $created,
            'type' => $want_variable ? 'variable' : 'simple',
            'status' => get_post_status($product_id),
            'permalink' => get_permalink($product_id),
            'variations' => $variation_ids,
            'warnings' => $this->warnings,
        ];
    }

    public function set_status($external_id, $status)
    {
        $id = self::find_by_external($external_id);
        if (!$id) {
            return new WP_Error('omg_not_found', 'No WooCommerce product is mapped to this OMGGamer product.', ['status' => 404]);
        }
        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('omg_invalid', 'Unknown status.', ['status' => 422]);
        }
        $product = wc_get_product($id);
        $product->set_status($status);
        $product->save();
        return ['ok' => true, 'product_id' => $id, 'status' => $status];
    }

    /** Price and stock only (used by price/stock synchronisation). */
    public function update_prices(array $items)
    {
        $results = [];
        $touched = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ext = sanitize_text_field($item['external_id'] ?? '');
            $vid = sanitize_text_field($item['variant_id'] ?? '');
            $parent = self::find_by_external($ext);
            if (!$parent) {
                $results[] = ['variant_id' => $vid, 'ok' => false, 'error' => 'product not mapped'];
                continue;
            }
            $target_id = WC_Product_Factory::get_product_type($parent) === 'variable' ? self::find_variation($parent, $vid) : $parent;
            $target = $target_id ? wc_get_product($target_id) : null;
            if (!$target) {
                $results[] = ['variant_id' => $vid, 'ok' => false, 'error' => 'variant not mapped'];
                continue;
            }
            $this->apply_price($target, $item);
            $target->save();
            $touched[$parent] = true;
            $results[] = ['variant_id' => $vid, 'ok' => true, 'product_id' => $target_id];
        }
        foreach (array_keys($touched) as $parent) {
            if (WC_Product_Factory::get_product_type($parent) === 'variable') {
                WC_Product_Variable::sync($parent);
            }
            wc_delete_product_transients($parent);
        }
        return ['ok' => true, 'results' => $results];
    }

    // ------------------------------------------------------------------ helpers

    private function apply_price($product, array $v)
    {
        $price = isset($v['regular_price']) && $v['regular_price'] !== null && $v['regular_price'] !== '' ? wc_format_decimal($v['regular_price']) : '';
        $in_stock = !empty($v['in_stock']) && $price !== '';
        $product->set_regular_price($price);
        $product->set_manage_stock(false);
        $product->set_stock_status($in_stock ? 'instock' : 'outofstock');
    }

    private function apply_sku($product, $sku)
    {
        $sku = wc_clean((string) $sku);
        if ($sku === '' || $product->get_sku() === $sku) {
            return;
        }
        try {
            $product->set_sku($sku);
        } catch (WC_Data_Exception $e) {
            $this->warnings[] = "SKU {$sku} is already used by another product; kept the existing SKU.";
        }
    }

    private function dimensions(array $variants)
    {
        $dims = [];
        foreach ($variants as $v) {
            foreach ((array) ($v['attributes'] ?? []) as $name => $value) {
                $name = wc_clean((string) $name);
                $value = wc_clean((string) $value);
                if ($name === '' || $value === '') {
                    continue;
                }
                $dims[$name][$value] = true;
            }
        }
        $out = [];
        foreach ($dims as $name => $values) {
            $out[$name] = array_keys($values);
        }
        return $out;
    }

    /** Use the store's global attribute (pa_*) when one exists with that name, otherwise a product-level one. */
    private function attribute_object($name, array $values, $position, $variation, $visible = true)
    {
        $attr = new WC_Product_Attribute();
        $tax_id = wc_attribute_taxonomy_id_by_name(sanitize_title($name));
        if ($tax_id) {
            $taxonomy = wc_attribute_taxonomy_name(sanitize_title($name));
            $term_ids = [];
            foreach ($values as $value) {
                $term = term_exists($value, $taxonomy);
                if (!$term) {
                    $term = wp_insert_term($value, $taxonomy);
                }
                if (!is_wp_error($term)) {
                    $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
                }
            }
            $attr->set_id($tax_id);
            $attr->set_name($taxonomy);
            $attr->set_options($term_ids);
        } else {
            $attr->set_id(0);
            $attr->set_name($name);
            $attr->set_options(array_values($values));
        }
        $attr->set_position($position);
        $attr->set_visible($visible);
        $attr->set_variation($variation);
        return $attr;
    }

    private function attributes(array $display, array $dims, $variable)
    {
        $out = [];
        $pos = 0;
        foreach ($dims as $name => $values) {
            $obj = $this->attribute_object($name, $values, $pos++, (bool) $variable);
            $out[$obj->get_name()] = $obj;
        }
        foreach ($display as $a) {
            if (!is_array($a) || empty($a['name']) || empty($a['options'])) {
                continue;
            }
            $name = wc_clean((string) $a['name']);
            if (isset($dims[$name])) {
                continue;
            }
            $obj = $this->attribute_object($name, array_map('wc_clean', (array) $a['options']), $pos++, false, !isset($a['visible']) || $a['visible']);
            $out[$obj->get_name()] = $obj;
        }
        return $out;
    }

    private function variation_attributes(array $v)
    {
        $out = [];
        foreach ((array) ($v['attributes'] ?? []) as $name => $value) {
            $name = wc_clean((string) $name);
            $value = wc_clean((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $slug = sanitize_title($name);
            if (wc_attribute_taxonomy_id_by_name($slug)) {
                $taxonomy = wc_attribute_taxonomy_name($slug);
                $term = get_term_by('name', $value, $taxonomy);
                $out[$taxonomy] = $term ? $term->slug : sanitize_title($value);
            } else {
                $out[$slug] = $value;
            }
        }
        return $out;
    }

    private function sync_variations($parent_id, array $variants, array $dims)
    {
        $ids = [];
        $keep = [];
        foreach ($variants as $i => $v) {
            $vid = sanitize_text_field($v['variant_id'] ?? '');
            if ($vid === '') {
                continue;
            }
            $existing = self::find_variation($parent_id, $vid);
            $variation = $existing ? wc_get_product($existing) : new WC_Product_Variation();
            if (!$existing) {
                $variation->set_parent_id($parent_id);
                $variation->set_virtual(true);
            }
            $variation->set_attributes($this->variation_attributes($v));
            $variation->set_status('publish');
            $variation->set_menu_order($i);
            $this->apply_sku($variation, $v['sku'] ?? '');
            $this->apply_price($variation, $v);
            $variation_id = $variation->save();
            update_post_meta($variation_id, '_omg_variant_id', $vid);
            $ids[$vid] = $variation_id;
            $keep[] = $variation_id;
        }
        // Variations OMGGamer OS no longer sends are hidden, not deleted (orders may reference them).
        $parent = wc_get_product($parent_id);
        foreach ($parent ? $parent->get_children() : [] as $child_id) {
            if (!in_array($child_id, $keep, true) && get_post_meta($child_id, '_omg_variant_id', true) !== '') {
                $child = wc_get_product($child_id);
                if ($child && $child->get_status() !== 'private') {
                    $child->set_status('private');
                    $child->save();
                }
            }
        }
        return $ids;
    }

    private function category_ids(array $paths)
    {
        $ids = [];
        foreach ($paths as $path) {
            $parent = 0;
            foreach ((array) $path as $name) {
                $name = wc_clean((string) $name);
                if ($name === '') {
                    continue;
                }
                $term = term_exists($name, 'product_cat', $parent);
                if (!$term) {
                    $term = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
                }
                if (is_wp_error($term)) {
                    $this->warnings[] = "Category {$name}: " . $term->get_error_message();
                    break;
                }
                $parent = (int) (is_array($term) ? $term['term_id'] : $term);
            }
            if ($parent) {
                $ids[] = $parent;
            }
        }
        return array_values(array_unique($ids));
    }

    private function tag_ids(array $tags)
    {
        $ids = [];
        foreach (array_slice($tags, 0, 20) as $tag) {
            $tag = wc_clean((string) $tag);
            if ($tag === '') {
                continue;
            }
            $term = term_exists($tag, 'product_tag');
            if (!$term) {
                $term = wp_insert_term($tag, 'product_tag');
            }
            if (!is_wp_error($term)) {
                $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }
        return $ids;
    }

    private function find_attachment($url)
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_omg_source_url' AND meta_value = %s LIMIT 1",
            $url
        ));
        return $id ? (int) $id : 0;
    }

    /** Download each image once (remembered by source URL), set alt text, featured image and gallery. */
    private function sync_images($product_id, array $images)
    {
        if (!$images) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ids = [];
        foreach (array_slice($images, 0, 8) as $img) {
            $url = esc_url_raw(is_array($img) ? ($img['url'] ?? '') : (string) $img);
            if (!$url || !preg_match('#^https://#i', $url)) {
                continue;
            }
            $att = $this->find_attachment($url);
            if (!$att) {
                $tmp = download_url($url, 30);
                if (is_wp_error($tmp)) {
                    $this->warnings[] = "Image not downloaded ({$url}): " . $tmp->get_error_message();
                    continue;
                }
                $name = basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg';
                if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                    $name .= '.jpg';
                }
                $att = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $product_id);
                if (is_wp_error($att)) {
                    @unlink($tmp);
                    $this->warnings[] = "Image not saved ({$url}): " . $att->get_error_message();
                    continue;
                }
                update_post_meta($att, '_omg_source_url', $url);
            }
            if (is_array($img) && !empty($img['alt'])) {
                update_post_meta($att, '_wp_attachment_image_alt', sanitize_text_field($img['alt']));
            }
            $ids[] = (int) $att;
        }
        if ($ids) {
            $product = wc_get_product($product_id);
            $product->set_image_id($ids[0]);
            $product->set_gallery_image_ids(array_slice($ids, 1));
            $product->save();
        }
    }

    private function seo($product_id, array $seo)
    {
        $title = isset($seo['title']) ? sanitize_text_field($seo['title']) : '';
        $desc = isset($seo['description']) ? sanitize_text_field($seo['description']) : '';
        if ($title === '' && $desc === '') {
            return;
        }
        if (defined('WPSEO_VERSION')) {
            if ($title !== '') update_post_meta($product_id, '_yoast_wpseo_title', $title);
            if ($desc !== '') update_post_meta($product_id, '_yoast_wpseo_metadesc', $desc);
        }
        if (class_exists('RankMath')) {
            if ($title !== '') update_post_meta($product_id, 'rank_math_title', $title);
            if ($desc !== '') update_post_meta($product_id, 'rank_math_description', $desc);
        }
        update_post_meta($product_id, '_omg_seo_title', $title);
        update_post_meta($product_id, '_omg_seo_description', $desc);
    }

    /** New products belong to the configured vendor (Dokan) or the first administrator. */
    private function assign_author($product_id)
    {
        $author = (int) get_option('omg_connector_vendor_id', 0);
        if (!$author || !get_userdata($author)) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID']);
            $author = $admins ? (int) $admins[0] : 0;
        }
        if ($author) {
            wp_update_post(['ID' => $product_id, 'post_author' => $author]);
        }
    }
}
