<?php
/**
 * Which blog articles a product page shows under "From the blog", and why.
 *
 *   wp eval-file blog-related.php <product-slug> [fresh]
 *
 * "fresh" ignores the 6-hour cache.
 */
$slug = $args[0] ?? '';
$p = $slug ? get_page_by_path($slug, OBJECT, 'product') : null;
if (!$p) {
	WP_CLI::error('Product not found: ' . $slug);
}
if (($args[1] ?? '') === 'fresh') {
	foreach (['omg_blog_rel_v4_', 'omg_blog_rel_v3_'] as $k) {
		delete_transient($k . $p->ID);
	}
}
$m = new ReflectionMethod('OMG_Blogs', 'posts_for');
$m->setAccessible(true);
$g = new ReflectionMethod('OMG_Blogs', 'game_name');
$g->setAccessible(true);
WP_CLI::log('#' . $p->ID . ' ' . $p->post_title . ' → search name: ' . $g->invoke(null, $p->post_title));
foreach (['pa_genres', 'pa_publishers', 'pa_developers', 'pa_platform'] as $tax) {
	WP_CLI::log($tax . ': ' . implode(', ', wp_get_post_terms($p->ID, $tax, ['fields' => 'names'])));
}
foreach ($m->invoke(null, $p->ID) as $post) {
	WP_CLI::log('  ' . $post['title'] . ' — ' . $post['url']);
}
