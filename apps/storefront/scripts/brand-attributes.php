<?php
/**
 * Publisher, developer and genre as real store attributes (pa_publishers, pa_developers, pa_genres).
 *
 *   wp eval-file brand-attributes.php          (dry run: prints what would change)
 *   wp eval-file brand-attributes.php apply    (makes the changes)
 *
 * The catalogue connector used to save OMGGamer OS's "Publisher", "Developer" and "Genre" as plain text
 * attributes, because the store's attributes are named "publishers", "developers" and "genres". Plain text
 * attributes have no archive page, no filter and give the product schema no brand. This moves each product's
 * text attribute onto the store attribute (creating terms as needed), keeping its position and visibility.
 * Connector 1.7.1+ saves them this way on every sync, so this only has to run once.
 */

$apply = isset( $args[0] ) && 'apply' === $args[0];
$map   = array(
	'publisher' => 'pa_publishers',
	'developer' => 'pa_developers',
	'genre'     => 'pa_genres',
);

foreach ( $map as $local => $tax ) {
	if ( ! taxonomy_exists( $tax ) ) {
		WP_CLI::warning( "{$tax} does not exist; '{$local}' attributes are left as they are." );
		unset( $map[ $local ] );
	}
}

$ids     = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ), 'numberposts' => -1, 'fields' => 'ids' ) );
$changed = 0;
$terms   = array();

foreach ( $ids as $id ) {
	$product = wc_get_product( $id );
	if ( ! $product ) {
		continue;
	}
	$attrs = $product->get_attributes();
	$dirty = false;
	foreach ( $attrs as $key => $attr ) {
		if ( ! $attr instanceof WC_Product_Attribute || $attr->is_taxonomy() ) {
			continue;
		}
		$local = sanitize_title( $attr->get_name() );
		if ( ! isset( $map[ $local ] ) || $attr->get_variation() ) {
			continue;
		}
		$tax    = $map[ $local ];
		$values = array_values( array_filter( array_map( 'trim', $attr->get_options() ) ) );
		if ( ! $values ) {
			continue;
		}
		$term_ids = array();
		foreach ( $values as $value ) {
			$term = term_exists( $value, $tax );
			if ( ! $term && $apply ) {
				$term = wp_insert_term( $value, $tax );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
			$terms[ $tax ][ $value ] = true;
		}
		WP_CLI::log( sprintf( '#%d %s: %s → %s (%s)', $id, $product->get_name(), $attr->get_name(), $tax, implode( ', ', $values ) ) );
		if ( ! $apply ) {
			continue;
		}
		$new = new WC_Product_Attribute();
		$new->set_id( wc_attribute_taxonomy_id_by_name( substr( $tax, 3 ) ) );
		$new->set_name( $tax );
		$new->set_options( $term_ids );
		$new->set_position( $attr->get_position() );
		$new->set_visible( $attr->get_visible() );
		$new->set_variation( false );
		unset( $attrs[ $key ] );
		// A product may already carry the store attribute (older imports): merge the terms.
		if ( isset( $attrs[ $tax ] ) && $attrs[ $tax ] instanceof WC_Product_Attribute ) {
			$new->set_options( array_values( array_unique( array_merge( $attrs[ $tax ]->get_options(), $term_ids ) ) ) );
		}
		$attrs[ $tax ] = $new;
		$dirty         = true;
	}
	if ( $dirty ) {
		$product->set_attributes( $attrs );
		$product->save();
		++$changed;
	}
}

foreach ( $terms as $tax => $values ) {
	WP_CLI::log( sprintf( '%s: %d distinct names', $tax, count( $values ) ) );
}
WP_CLI::success( ( $apply ? 'Updated ' : 'Would update ' ) . ( $apply ? $changed : 'the products listed above' ) . ( $apply ? ' products.' : '.' ) );
