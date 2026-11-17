<?php
/**
 * Front end: layout switching, SEO tags, "Buy now", and the hardware & accessory product page
 * rendered by the [omg_product_page] shortcode (used inside the Bricks template).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS_Front {

	public static function init() {
		$s = new self();
		add_filter( 'bricks/active_templates', array( $s, 'bricks_layout' ), 20, 3 );
		add_filter( 'pre_get_document_title', array( $s, 'seo_title' ), 20 );
		add_action( 'wp_head', array( $s, 'seo_meta' ), 2 );
		add_shortcode( 'omg_product_page', array( $s, 'page' ) );
		add_action( 'wp_enqueue_scripts', array( $s, 'assets' ) );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $s, 'buy_now_redirect' ), 99 );
	}

	public function bricks_layout( $active, $post_id, $content_type ) {
		if ( ! is_array( $active ) || ! $post_id || 'product' !== get_post_type( $post_id ) || ! is_singular( 'product' ) ) {
			return $active;
		}
		$layout = get_post_meta( $post_id, '_omg_layout', true );
		if ( ! $layout || 'auto' === $layout ) {
			return $active;
		}
		$map = OMG_PS::settings()['layout_map'];
		$tpl = isset( $map[ $layout ] ) ? absint( $map[ $layout ] ) : 0;
		if ( $tpl && 'publish' === get_post_status( $tpl ) ) {
			$active['content'] = $tpl;
		}
		return $active;
	}

	private function seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || class_exists( 'The_SEO_Framework\\Load' );
	}

	public function seo_title( $title ) {
		if ( is_singular( 'product' ) && ! $this->seo_plugin() ) {
			$t = get_post_meta( get_queried_object_id(), '_omg_seo_title', true );
			if ( $t ) {
				return $t;
			}
		}
		return $title;
	}

	public function seo_meta() {
		if ( ! is_singular( 'product' ) || $this->seo_plugin() ) {
			return;
		}
		$d = get_post_meta( get_queried_object_id(), '_omg_seo_description', true );
		if ( $d ) {
			echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
		}
	}

	public function buy_now_redirect( $url ) {
		return ! empty( $_REQUEST['omg_buy_now'] ) ? wc_get_checkout_url() : $url; // phpcs:ignore
	}

	public function assets() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		wp_register_style( 'omgps-front', OMG_PS_URL . 'assets/front.css', array(), OMG_PS_VERSION );
		wp_register_script( 'omgps-front', OMG_PS_URL . 'assets/front.js', array( 'jquery' ), OMG_PS_VERSION, true );
		$layout = get_post_meta( get_queried_object_id(), '_omg_layout', true );
		if ( in_array( $layout, array( 'hardware', 'accessory' ), true ) || in_array( OMG_PS::kind( get_queried_object_id() ), array( 'hardware', 'accessory' ), true ) ) {
			wp_enqueue_style( 'omgps-front' );
		}
	}

	/* ================================================================ page */

	private static function icon( $name ) {
		$p = array(
			'chev'    => '<path d="m9 18 6-6-6-6"/>',
			'left'    => '<path d="m15 18-6-6 6-6"/>',
			'truck'   => '<path d="M10 17h4V5H2v12h3"/><path d="M14 9h4l4 4v4h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
			'return'  => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/>',
			'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
			'check'   => '<path d="M20 6 9 17l-5-5"/>',
			'x'       => '<path d="M18 6 6 18M6 6l12 12"/>',
			'plug'    => '<path d="M12 22v-5"/><path d="M9 8V2M15 8V2"/><path d="M18 8v5a6 6 0 0 1-12 0V8z"/>',
			'cart'    => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
			'star'    => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
			'bolt'    => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
			'cpu'     => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
			'wave'    => '<path d="M2 12h2M6 8v8M10 5v14M14 8v8M18 10v4M22 12h-2"/>',
			'battery' => '<rect x="2" y="7" width="16" height="10" rx="2"/><path d="M22 11v2"/><path d="M6 10v4M10 10v4"/>',
			'blue'    => '<path d="m7 7 10 10-5 5V2l5 5L7 17"/>',
			'game'    => '<path d="M6 11h4M8 9v4M15 12h.01M18 10h.01"/><rect x="2" y="6" width="20" height="12" rx="6"/>',
			'globe'   => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
			'cal'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
			'tag'     => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
		);
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( isset( $p[ $name ] ) ? $p[ $name ] : $p['tag'] ) . '</svg>';
	}

	private static function spec_icon( $key, $label ) {
		$k = strtolower( $key . ' ' . $label );
		foreach ( array( 'battery' => 'battery', 'connect' => 'blue', 'bluetooth' => 'blue', 'haptic' => 'wave', 'trigger' => 'game', 'chip' => 'cpu', 'memory' => 'cpu', 'clock' => 'bolt', 'psu' => 'bolt', 'power' => 'bolt', 'platform' => 'game', 'region' => 'globe', 'release' => 'cal', 'genre' => 'tag', 'interface' => 'plug', 'output' => 'plug' ) as $needle => $ic ) {
			if ( false !== strpos( $k, $needle ) ) {
				return $ic;
			}
		}
		return 'tag';
	}

	/** Spec rows [label, value, key] for the product, with a game fallback. */
	private static function spec_rows( $id, $kind ) {
		$rows = array();
		foreach ( OMG_PS::attr_defs( $kind ) as $k => $def ) {
			$v = OMG_PS::get_attr( $id, $def )[0];
			if ( '' !== $v ) {
				$rows[] = array( $def[0], $v, $k );
			}
		}
		foreach ( OMG_PS::specs( $id )['custom'] as $c ) {
			if ( isset( $c[0], $c[1] ) && '' !== $c[1] ) {
				$rows[] = array( $c[0], $c[1], sanitize_key( $c[0] ) );
			}
		}
		if ( count( $rows ) < 3 && 'digital' !== $kind ) {
			foreach ( OMG_PS::attr_defs( 'digital' ) as $k => $def ) {
				if ( 'kinguin_id' === $k ) {
					continue;
				}
				$v = OMG_PS::get_attr( $id, $def )[0];
				if ( '' !== $v ) {
					$rows[] = array( $def[0], $v, $k );
				}
			}
		}
		return $rows;
	}

	private static function discount( $p ) {
		if ( ! $p->is_on_sale() ) {
			return 0;
		}
		if ( $p->is_type( 'variable' ) ) {
			$max = 0;
			foreach ( $p->get_variation_prices( true )['regular_price'] as $vid => $reg ) {
				$sale = $p->get_variation_prices( true )['price'][ $vid ];
				if ( $reg > 0 && $sale < $reg ) {
					$max = max( $max, round( ( $reg - $sale ) / $reg * 100 ) );
				}
			}
			return (int) $max;
		}
		$reg = (float) $p->get_regular_price();
		$now = (float) $p->get_price();
		return $reg > 0 && $now < $reg ? (int) round( ( $reg - $now ) / $reg * 100 ) : 0;
	}

	private static function card( $p ) {
		$img  = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
		$cats = wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$d    = self::discount( $p );
		$r    = (float) $p->get_average_rating();
		return '<a class="omgp-card" href="' . esc_url( $p->get_permalink() ) . '"><div class="im">' . ( $d ? '<span class="omgp-off">-' . $d . '%</span>' : '' ) . '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $p->get_name() ) . '" loading="lazy"></div><div class="b">' . ( $cats ? '<span class="c">' . esc_html( html_entity_decode( $cats[0] ) ) . '</span>' : '' ) . '<h3>' . esc_html( $p->get_name() ) . '</h3><div class="p"><span class="price">' . wp_kses_post( $p->get_price_html() ) . '</span>' . ( $r > 0 ? '<span class="r">' . self::icon( 'star' ) . esc_html( number_format( $r, 1 ) ) . '</span>' : '' ) . '</div></div></a>';
	}

	public function page( $atts ) {
		$id = get_the_ID() ? get_the_ID() : get_queried_object_id();
		$p  = wc_get_product( $id );
		if ( ! $p ) {
			return '';
		}
		global $product;
		$product = $p; // phpcs:ignore -- WooCommerce templates read the global.
		wp_enqueue_style( 'omgps-front' );
		wp_enqueue_script( 'omgps-front' );
		if ( $p->is_type( 'variable' ) ) {
			wp_enqueue_script( 'wc-add-to-cart-variation' );
		}
		$kind     = OMG_PS::kind( $id );
		$is_phys  = 'digital' !== $kind;
		$rows     = self::spec_rows( $id, $kind );
		$cats     = wp_get_post_terms( $id, 'product_cat' );
		$cats     = is_wp_error( $cats ) ? array() : array_values( array_filter( $cats, function ( $c ) {
			return 'uncategorized' !== $c->slug;
		} ) );
		$brand    = OMG_PS::get_attr( $id, array( '', 'brand', '' ) )[0];
		$secs     = array();
		foreach ( OMG_PS::section_defs( $kind ) as $k => $def ) {
			$secs[ $k ] = OMG_PS::items_from_store( get_post_meta( $id, $def[1], true ), $def[2] );
		}
		if ( 'digital' === $kind ) {
			$secs['in_the_box']   = $secs['whats_included'];
			$secs['key_features'] = array();
		}
		$imgs = OMG_PS::media_ids( $id );
		$disc = self::discount( $p );
		$pills = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $id, 'omg_pills', true ) ) ) );
		$warranty = (string) get_post_meta( $id, 'omg_warranty', true );
		$rating = (float) $p->get_average_rating();
		$reviews = (int) $p->get_review_count();
		$prio = array(
			'accessory' => array( 'haptics', 'triggers', 'battery', 'connection', 'weight', 'colour', 'microphone' ),
			'hardware'  => array( 'chipset', 'memory', 'boost_clock', 'interface', 'outputs', 'psu' ),
			'digital'   => array( 'platform', 'region', 'genres', 'release_date', 'product_type' ),
		);
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r[2] ] = $r;
		}
		$high = array();
		foreach ( array_merge( $prio[ $kind ], array_keys( $by ) ) as $k ) {
			if ( isset( $by[ $k ] ) && ! isset( $high[ $k ] ) && ! in_array( $k, array( 'brand', 'ean', 'warranty', 'model', 'kinguin_id', 'languages', 'edition' ), true ) && count( $high ) < 4 ) {
				$high[ $k ] = $by[ $k ];
			}
		}
		ob_start();
		?>
		<div class="omgp" data-product="<?php echo esc_attr( $id ); ?>" data-checkout="<?php echo esc_url( wc_get_checkout_url() ); ?>" data-cart="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<nav class="omgp-crumbs" aria-label="Breadcrumb"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a><?php echo self::icon( 'chev' ); // phpcs:ignore ?>
				<?php if ( $cats ) : ?><a href="<?php echo esc_url( get_term_link( $cats[0] ) ); ?>"><?php echo esc_html( html_entity_decode( $cats[0]->name ) ); ?></a><?php echo self::icon( 'chev' ); // phpcs:ignore ?><?php endif; ?>
				<span><?php echo esc_html( $p->get_name() ); ?></span></nav>

			<div class="omgp-top">
				<div class="omgp-main">
					<div class="omgp-gallery">
						<?php if ( count( $imgs ) > 1 ) : ?>
							<div class="omgp-thumbs" role="tablist" aria-label="Product images">
								<?php foreach ( $imgs as $i => $mid ) : ?><button type="button" class="<?php echo 0 === $i ? 'is-on' : ''; ?>" data-i="<?php echo esc_attr( $i ); ?>" aria-label="Image <?php echo esc_attr( $i + 1 ); ?>"><?php echo wp_get_attachment_image( $mid, 'thumbnail' ); ?></button><?php endforeach; ?>
							</div>
						<?php endif; ?>
						<div class="omgp-stage">
							<div class="omgp-badges"><?php if ( $disc ) : ?><span class="omgp-off">-<?php echo esc_html( $disc ); ?>%</span><?php endif; ?><?php if ( $pills ) : ?><span class="omgp-pill"><?php echo esc_html( reset( $pills ) ); ?></span><?php endif; ?></div>
							<div class="omgp-slides">
								<?php foreach ( $imgs ? $imgs : array( 0 ) as $i => $mid ) : ?>
									<figure class="<?php echo 0 === $i ? 'is-on' : ''; ?>"><?php echo $mid ? wp_get_attachment_image( $mid, 'large', false, array( 'loading' => 0 === $i ? 'eager' : 'lazy' ) ) : wc_placeholder_img( 'large' ); // phpcs:ignore ?></figure>
								<?php endforeach; ?>
							</div>
							<?php if ( count( $imgs ) > 1 ) : ?>
								<button type="button" class="omgp-nav prev" aria-label="Previous image"><?php echo self::icon( 'left' ); // phpcs:ignore ?></button>
								<button type="button" class="omgp-nav next" aria-label="Next image"><?php echo self::icon( 'chev' ); // phpcs:ignore ?></button>
								<span class="omgp-count"><b>1</b> / <?php echo esc_html( count( $imgs ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( $high ) : ?>
						<div class="omgp-high">
							<?php foreach ( $high as $h ) : ?><div><span class="ic"><?php echo self::icon( self::spec_icon( $h[2], $h[0] ) ); // phpcs:ignore ?></span><span><small><?php echo esc_html( $h[0] ); ?></small><b><?php echo esc_html( $h[1] ); ?></b></span></div><?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $secs['works_with'] ) ) : ?>
						<section class="omgp-box omgp-works">
							<header><span class="ic"><?php echo self::icon( 'plug' ); // phpcs:ignore ?></span><div><h2>Works with</h2><p>Check your console or device before you buy.</p></div></header>
							<div class="grid"><?php foreach ( $secs['works_with'] as $w ) : ?><div class="<?php echo $w['ok'] ? 'is-ok' : 'is-no'; ?>"><span class="st"><?php echo self::icon( $w['ok'] ? 'check' : 'x' ); // phpcs:ignore ?></span><b><?php echo esc_html( $w['name'] ); ?></b><small><?php echo esc_html( $w['note'] ); ?></small></div><?php endforeach; ?></div>
						</section>
					<?php elseif ( ! empty( $secs['compatibility'] ) ) : ?>
						<section class="omgp-box omgp-works">
							<header><span class="ic"><?php echo self::icon( 'plug' ); // phpcs:ignore ?></span><div><h2>Compatibility check</h2><p>Make sure it fits your setup before you order.</p></div></header>
							<ul class="omgp-checks"><?php foreach ( $secs['compatibility'] as $c ) : ?><li><?php echo self::icon( 'check' ); // phpcs:ignore ?><span><?php echo wp_kses_post( $c ); ?></span></li><?php endforeach; ?></ul>
						</section>
					<?php endif; ?>

					<?php echo $this->setup( $p ); // phpcs:ignore ?>

					<section class="omgp-box omgp-tabs">
						<div class="omgp-tabbar" role="tablist">
							<button type="button" role="tab" class="is-on" data-tab="ov">Overview</button>
							<?php if ( $rows ) : ?><button type="button" role="tab" data-tab="sp">Specifications</button><?php endif; ?>
							<?php if ( ! empty( $secs['system_requirements'] ) ) : ?><button type="button" role="tab" data-tab="sr">System requirements</button><?php endif; ?>
							<?php if ( comments_open( $id ) || $reviews ) : ?><button type="button" role="tab" data-tab="rv">Reviews <span><?php echo esc_html( number_format_i18n( $reviews ) ); ?></span></button><?php endif; ?>
						</div>
						<div class="omgp-pane is-on" data-pane="ov">
							<div class="omgp-ov<?php echo $secs['in_the_box'] ? '' : ' is-single'; ?>">
								<div class="omgp-desc"><?php echo wp_kses_post( wpautop( $p->get_description() ? $p->get_description() : $p->get_short_description() ) ); ?>
									<?php if ( $secs['key_features'] ) : ?><div class="omgp-feats"><?php foreach ( $secs['key_features'] as $f ) : $parts = array_map( 'trim', explode( ':', wp_strip_all_tags( $f ), 2 ) ); ?><div><b><?php echo esc_html( $parts[0] ); ?></b><?php if ( isset( $parts[1] ) ) : ?><p><?php echo esc_html( $parts[1] ); ?></p><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
								</div>
								<?php if ( $secs['in_the_box'] ) : ?><aside class="omgp-inbox"><h3><?php echo $is_phys ? 'In the box' : 'Edition includes'; ?></h3><ul><?php foreach ( $secs['in_the_box'] as $it ) : if ( 0 === strpos( $it, '## ' ) ) { continue; } ?><li><?php echo self::icon( 'check' ); // phpcs:ignore ?><span><?php echo wp_kses_post( $it ); ?></span></li><?php endforeach; ?></ul></aside><?php endif; ?>
							</div>
						</div>
						<?php if ( $rows ) : ?><div class="omgp-pane" data-pane="sp"><table class="omgp-spec"><tbody><?php foreach ( $rows as $r ) : ?><tr><th><?php echo esc_html( $r[0] ); ?></th><td><?php echo esc_html( $r[1] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
						<?php if ( ! empty( $secs['system_requirements'] ) ) : ?><div class="omgp-pane" data-pane="sr"><div class="omgp-sysreq"><?php echo wp_kses_post( OMG_PS::items_to_store( $secs['system_requirements'], 'sysreq' ) ); ?></div></div><?php endif; ?>
						<?php if ( comments_open( $id ) || $reviews ) : ?><div class="omgp-pane" data-pane="rv"><?php comments_template(); ?></div><?php endif; ?>
					</section>
				</div>

				<aside class="omgp-side">
					<div class="omgp-buy">
						<?php if ( $brand ) : ?><div class="omgp-brand"><?php echo esc_html( $brand ); ?></div><?php elseif ( $cats ) : ?><div class="omgp-brand"><?php echo esc_html( html_entity_decode( $cats[0]->name ) ); ?></div><?php endif; ?>
						<h1 class="omgp-title"><?php echo esc_html( $p->get_name() ); ?></h1>
						<?php if ( $rating > 0 ) : ?><div class="omgp-rating"><span class="stars" style="--r:<?php echo esc_attr( $rating / 5 * 100 ); ?>%">★★★★★</span><b><?php echo esc_html( number_format( $rating, 1 ) ); ?></b><a href="#reviews" class="omgp-to-reviews"><?php echo esc_html( sprintf( _n( '%s review', '%s reviews', $reviews ), number_format_i18n( $reviews ) ) ); ?></a></div><?php endif; ?>
						<div class="omgp-price">
							<?php if ( $p->is_type( 'variable' ) ) : ?>
								<span class="now"><small>From</small><?php echo wp_kses_post( wc_price( $p->get_variation_price( 'min', true ) ) ); ?></span>
							<?php else : ?>
								<span class="now"><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $p ) ) ); ?></span>
								<?php if ( $p->is_on_sale() && $p->get_regular_price() ) : $save = (float) $p->get_regular_price() - (float) $p->get_price(); ?><span class="save">Save <?php echo wp_kses_post( wc_price( $save ) ); ?></span><?php endif; ?>
							<?php endif; ?>
						</div>
						<div class="omgp-sub"><?php if ( ! $p->is_type( 'variable' ) && $p->is_on_sale() && $p->get_regular_price() ) : ?><del><?php echo wp_kses_post( wc_price( $p->get_regular_price() ) ); ?></del> · <?php endif; ?>Tax and fees included</div>
						<?php if ( $p->get_short_description() ) : ?><div class="omgp-short"><?php echo wp_kses_post( wpautop( $p->get_short_description() ) ); ?></div><?php endif; ?>
						<div class="omgp-stock <?php echo $p->is_in_stock() ? 'in' : 'out'; ?>"><i></i><?php echo $p->is_in_stock() ? ( $p->is_on_backorder() ? 'Available on backorder' : 'In stock' ) : 'Out of stock'; ?></div>
						<div class="omgp-atc"><?php woocommerce_template_single_add_to_cart(); ?></div>
						<p class="omgp-ship"><?php echo $is_phys ? 'Free standard shipping on orders over ' . wp_kses_post( wc_price( 99 ) ) : 'Instant digital delivery after payment'; ?></p>
						<div class="omgp-trust">
							<div><?php echo self::icon( $is_phys ? 'truck' : 'bolt' ); // phpcs:ignore ?><span><?php echo $is_phys ? 'Fast shipping' : 'Instant delivery'; ?></span></div>
							<div><?php echo self::icon( 'return' ); // phpcs:ignore ?><span><?php echo $is_phys ? '30-day returns' : 'Buyer protection'; ?></span></div>
							<div><?php echo self::icon( 'shield' ); // phpcs:ignore ?><span><?php echo esc_html( $warranty ? $warranty : ( $is_phys ? '1-year warranty' : '24/7 support' ) ); ?></span></div>
						</div>
					</div>
					<a class="omgp-seller" href="<?php echo esc_url( home_url( '/seller/omggamer/' ) ); ?>"><span class="lg">OMG</span><span><b>OMG Gamer</b><small><?php echo $brand ? esc_html( 'Authorised ' . $brand . ' retailer' ) : 'Verified seller · buyer protection'; ?></small></span></a>
				</aside>
			</div>

			<?php
			$rel = array_filter( array_map( 'wc_get_product', wc_get_related_products( $id, 10 ) ) );
			if ( $rel ) :
				$label = 'accessory' === $kind ? 'More accessories' : ( 'hardware' === $kind ? 'More hardware' : 'You may also like' );
				?>
				<section class="omgp-more"><div class="hd"><h2><?php echo esc_html( $label ); ?></h2><?php if ( $cats ) : ?><a href="<?php echo esc_url( get_term_link( $cats[0] ) ); ?>">All <?php echo esc_html( strtolower( html_entity_decode( $cats[0]->name ) ) ); ?></a><?php endif; ?></div>
					<div class="omgp-row"><?php foreach ( $rel as $r ) { echo self::card( $r ); } // phpcs:ignore ?></div></section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** "Complete your setup": this product plus up to three simple cross-sells. */
	private function setup( $p ) {
		if ( ! $p->is_type( 'simple' ) || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return '';
		}
		$items = array();
		foreach ( $p->get_cross_sell_ids() as $cid ) {
			$c = wc_get_product( $cid );
			if ( $c && $c->is_type( 'simple' ) && $c->is_purchasable() && $c->is_in_stock() && count( $items ) < 3 ) {
				$items[] = $c;
			}
		}
		if ( ! $items ) {
			return '';
		}
		array_unshift( $items, $p );
		$html = '<section class="omgp-box omgp-setup"><h2>Complete your setup</h2><div class="grid">';
		$total = 0;
		$reg   = 0;
		foreach ( $items as $i => $it ) {
			$price = (float) wc_get_price_to_display( $it );
			$r     = (float) wc_get_price_to_display( $it, array( 'price' => $it->get_regular_price() ? $it->get_regular_price() : $it->get_price() ) );
			$total += $price;
			$reg   += max( $r, $price );
			$img = $it->get_image_id() ? wp_get_attachment_image_url( $it->get_image_id(), 'woocommerce_thumbnail' ) : wc_placeholder_img_src();
			$html .= '<label class="it"><input type="checkbox" checked value="' . esc_attr( $it->get_id() ) . '" data-price="' . esc_attr( $price ) . '" data-reg="' . esc_attr( max( $r, $price ) ) . '"><span class="ck">' . self::icon( 'check' ) . '</span><img src="' . esc_url( $img ) . '" alt="" loading="lazy"><b>' . ( 0 === $i ? 'This item' : esc_html( $it->get_name() ) ) . '</b><span class="pr">' . wc_price( $price ) . ( $r > $price ? ' <del>' . wc_price( $r ) . '</del>' : '' ) . '</span></label>';
		}
		$html .= '<div class="sum"><small>Total for <span class="n">' . count( $items ) . '</span> items</small><b class="t">' . wc_price( $total ) . '</b>' . ( $reg > $total ? '<span class="sv">You save <span class="s">' . wc_price( $reg - $total ) . '</span></span>' : '<span class="sv"></span>' ) . '<button type="button" class="omgp-addall">Add <span class="n">' . count( $items ) . '</span> to cart</button></div></div></section>';
		return $html;
	}
}
