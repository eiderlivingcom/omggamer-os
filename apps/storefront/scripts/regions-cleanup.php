<?php
/**
 * Region and platform terms: one defined name each, no duplicates.
 *
 *   wp eval-file regions-cleanup.php          (dry run: prints what would change)
 *   wp eval-file regions-cleanup.php apply    (makes the changes)
 *
 * Uses the same region names as OMGGamer OS (apps/api/app/catalogue/regions.py). A term whose name is another
 * spelling of a defined region is merged into it: products and variations move to the defined term, then the
 * duplicate is deleted. Terms already named right but in the wrong case are renamed. Empty duplicates are
 * deleted. GOG.com is a store, not a platform: its platform term is merged into PC.
 */

$apply = isset( $args[0] ) && 'apply' === $args[0];

$defined = array(
	'Global', 'Global (some countries excluded)', 'Europe', 'Europe (some countries excluded)', 'Europe & North America',
	'North America', 'United States', 'Canada', 'Americas', 'Latin America', 'Argentina', 'Brazil', 'Rest of the World',
	'Asia', 'South East Asia', 'China', 'Japan', 'India', 'South Korea', 'Taiwan', 'Singapore', 'Malaysia',
	'Australia & Oceania', 'Australia', 'New Zealand', 'Middle East & North Africa', 'Europe, Middle East & Africa',
	'Africa', 'South Africa', 'United Arab Emirates', 'Turkey', 'Russia & CIS', 'Russia', 'Russia (VPN activation)',
	'United Kingdom', 'Ireland', 'Germany', 'Greece', 'Czechia', 'Other',
);
$aliases = array(
	'region free' => 'Global', 'ww' => 'Global', 'worldwide' => 'Global', 'all' => 'Global',
	'europe - all countries' => 'Europe', 'eu' => 'Europe', 'european union' => 'Europe', 'european union + uk' => 'Europe',
	'rest of world (row)' => 'Rest of the World', 'rest of the world (row) - custom' => 'Rest of the World', 'row' => 'Rest of the World',
	'rest of world' => 'Rest of the World', 'outside europe' => 'Rest of the World',
	'asia - all countries' => 'Asia', 'australia and oceania' => 'Australia & Oceania', 'latin america (latam)' => 'Latin America',
	'latam' => 'Latin America', 'middle east and north africa (mena)' => 'Middle East & North Africa', 'mena' => 'Middle East & North Africa',
	'ru + cis' => 'Russia & CIS', 'ru vpn' => 'Russia (VPN activation)', 'turkey (türkiye)' => 'Turkey', 'us' => 'United States',
	'uk' => 'United Kingdom', 'emea' => 'Europe, Middle East & Africa', 'other - custom' => 'Other',
);
$by_lower = array();
foreach ( $defined as $d ) {
	$by_lower[ strtolower( $d ) ] = $d;
}

function omg_target( $name, $by_lower, $aliases ) {
	$low = strtolower( trim( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );
	return $by_lower[ $low ] ?? $aliases[ $low ] ?? null;
}

/** Moves every product and variation from term $from to term $to (same taxonomy), then deletes $from. */
function omg_merge_term( $tax, WP_Term $from, WP_Term $to, $apply ) {
	global $wpdb;
	$products = get_objects_in_term( $from->term_id, $tax );
	$meta_key = 'attribute_' . $tax;
	$variations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", $meta_key, $from->slug ) );
	printf( "  merge %-40s -> %-30s (%d products, %d variations)\n", '"' . $from->name . '"', '"' . $to->name . '"', count( $products ), $variations );
	if ( ! $apply ) {
		return;
	}
	foreach ( $products as $pid ) {
		wp_remove_object_terms( (int) $pid, $from->term_id, $tax );
		wp_set_object_terms( (int) $pid, $to->term_id, $tax, true );
		// Keep the product's attribute options in step (stored as term IDs on the product).
		$attrs = get_post_meta( (int) $pid, '_product_attributes', true );
		if ( is_array( $attrs ) && isset( $attrs[ $tax ] ) ) {
			wc_delete_product_transients( (int) $pid );
		}
	}
	$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $to->slug ), array( 'meta_key' => $meta_key, 'meta_value' => $from->slug ) );
	foreach ( $products as $pid ) {
		wc_delete_product_transients( (int) $pid );
		clean_post_cache( (int) $pid );
	}
	wp_delete_term( $from->term_id, $tax );
}

echo $apply ? "APPLYING\n" : "DRY RUN (add 'apply' to make the changes)\n";

/* ---------------- regions ---------------- */
$tax   = 'pa_region';
$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
// Pick (or create) the one term for each defined name.
$keep = array();
foreach ( $terms as $t ) {
	$target = omg_target( $t->name, $by_lower, $aliases );
	if ( $target && strtolower( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ) === strtolower( $target ) && ! isset( $keep[ $target ] ) ) {
		$keep[ $target ] = $t;
	}
}
foreach ( $terms as $t ) {
	$target = omg_target( $t->name, $by_lower, $aliases );
	if ( ! $target ) {
		printf( "  leave  %-40s (not a known spelling; %d products)\n", '"' . $t->name . '"', $t->count );
		continue;
	}
	if ( isset( $keep[ $target ] ) && $keep[ $target ]->term_id === $t->term_id ) {
		if ( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) !== $target ) {
			printf( "  rename %-40s -> \"%s\"\n", '"' . $t->name . '"', $target );
			if ( $apply ) {
				wp_update_term( $t->term_id, $tax, array( 'name' => $target ) );
			}
		}
		continue;
	}
	if ( ! isset( $keep[ $target ] ) ) {
		// No term with the defined name yet: this one becomes it.
		printf( "  rename %-40s -> \"%s\"\n", '"' . $t->name . '"', $target );
		if ( $apply ) {
			wp_update_term( $t->term_id, $tax, array( 'name' => $target ) );
		}
		$keep[ $target ] = $t;
		continue;
	}
	omg_merge_term( $tax, $t, $keep[ $target ], $apply );
}

/* ---------------- platforms: GOG.com is a store ---------------- */
$ptax = 'pa_platform';
$pc   = get_term_by( 'name', 'PC', $ptax );
foreach ( array( 'GOG.com', 'GOG', 'GOG COM' ) as $n ) {
	$g = get_term_by( 'name', $n, $ptax );
	if ( ! $g ) {
		continue;
	}
	if ( 0 === (int) $g->count ) {
		printf( "  delete empty platform \"%s\"\n", $g->name );
		if ( $apply ) {
			wp_delete_term( $g->term_id, $ptax );
		}
		continue;
	}
	if ( ! $pc && $apply ) {
		$r  = wp_insert_term( 'PC', $ptax );
		$pc = is_wp_error( $r ) ? null : get_term( $r['term_id'], $ptax );
	}
	if ( $pc ) {
		omg_merge_term( $ptax, $g, $pc, $apply );
	} else {
		printf( "  merge platform \"%s\" -> \"PC\" (%d products)\n", $g->name, $g->count );
	}
}
if ( $apply && function_exists( 'wc_delete_product_transients' ) ) {
	delete_transient( 'wc_attribute_taxonomies' );
}
echo "done\n";
