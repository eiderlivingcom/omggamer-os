<?php
/**
 * Core: settings, the product data model, AI calls, generation and the rewrite queue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS {

	const OPT        = 'omg_ai_settings';
	const KEY_OPT    = 'omg_ai_api_key';
	const KINGUIN    = 'omg_kinguin_api_key';
	const NONCE      = 'omg_ai_studio';
	const PROVIDERS  = array(
		'omniroute'  => 'OmniRoute (free models, omniroute.1wp.site)',
		'anthropic'  => 'Anthropic (Claude)',
		'openai'     => 'OpenAI',
		'openrouter' => 'OpenRouter',
		'custom'     => 'Other OpenAI-compatible API (enter the address)',
	);
	const OMNIROUTE  = 'https://omniroute.1wp.site/v1';
	const LANGS      = array( 'en' => 'English', 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish' );
	const TONES      = array( 'informative' => 'Informative', 'hype' => 'Hype', 'concise' => 'Concise' );
	const VOICES     = array( 'default' => 'OMGGamer default', 'technical' => 'Technical / spec-first', 'marketplace' => 'Short marketplace copy' );

	/* ================================================================ settings */

	public static function settings() {
		$defaults = array(
			'provider'        => 'anthropic',
			'model'           => '',
			'base_url'        => '',
			'fallbacks'       => 1,
			'brand_voice'     => 'Write for OMGGamer, a store for game keys, game accounts, gift cards, hardware and accessories. Friendly, clear and confident, like a gamer helping a friend. Short sentences. No hype words like "ultimate experience" or "epic adventure awaits", no emojis, no exclamation marks. Never invent facts: only use details from the product data.',
			'tone'            => 'informative',
			'language'        => 'English',
			'layout_map'      => array( 'digital' => 104, 'account' => 1873, 'giftcard' => 106, 'hardware' => 102, 'accessory' => 102 ),
			'kinguin_sandbox' => 0,
			'icecat_user'     => '',
			'review_rule'     => 'human',
			'margin'          => 18,
			'auto_rewrite'    => 1,
			// Custom prompts for the short and full description. {title} is replaced with the product title.
			'os_storefront'   => '',
			'prompt_short'    => '',
			'prompt_long'     => '',
		);
		$s = get_option( self::OPT, array() );
		$s = is_array( $s ) ? array_merge( $defaults, $s ) : $defaults;
		$s['layout_map'] = array_merge( $defaults['layout_map'], is_array( $s['layout_map'] ) ? $s['layout_map'] : array() );
		return $s;
	}

	public static function default_model( $provider ) {
		$m = array( 'anthropic' => 'claude-opus-5-5', 'openai' => 'gpt-4o-mini', 'openrouter' => 'anthropic/claude-opus-5-5', 'omniroute' => 'free-stack', 'custom' => '' );
		return isset( $m[ $provider ] ) ? $m[ $provider ] : '';
	}

	public static function api_key() {
		if ( defined( 'OMG_AI_API_KEY' ) && OMG_AI_API_KEY ) {
			return (string) OMG_AI_API_KEY;
		}
		return (string) get_option( self::KEY_OPT, '' );
	}

	public static function kinguin_key() {
		if ( defined( 'OMG_KINGUIN_API_KEY' ) && OMG_KINGUIN_API_KEY ) {
			return (string) OMG_KINGUIN_API_KEY;
		}
		return (string) get_option( self::KINGUIN, '' );
	}

	/* ================================================================ product model */

	public static function kinds() {
		return array(
			'digital'   => array( 'Digital game', 'Keys & accounts · OMGGamer OS data', '&#127918;' ),
			'hardware'  => array( 'Hardware', 'GPUs, consoles, PCs · EAN + datasheet', '&#128421;' ),
			'accessory' => array( 'Accessory', 'Headsets, controllers · EAN + datasheet', '&#127911;' ),
		);
	}

	public static function digital_layouts() {
		return array(
			'auto'     => 'Automatic (by collection)',
			'digital'  => 'Game key page',
			'account'  => 'Game account page',
			'giftcard' => 'Gift card / currency page',
		);
	}

	/** Attribute definitions: key => [label, storage, target, instruction]. */
	public static function attr_defs( $kind ) {
		if ( 'hardware' === $kind ) {
			return array(
				'brand'           => array( 'Brand', 'brand', 'product_brand', 'Manufacturer brand name.' ),
				'chipset'         => array( 'Chipset', 'spec', 'chipset', 'Main chip or processor, e.g. "GeForce RTX 5070".' ),
				'memory'          => array( 'Memory', 'spec', 'memory', 'Memory size and type, e.g. "12 GB GDDR7".' ),
				'boost_clock'     => array( 'Boost clock', 'spec', 'boost_clock', 'Boost or max clock with unit.' ),
				'interface'       => array( 'Interface', 'spec', 'interface', 'Bus or connection interface.' ),
				'outputs'         => array( 'Outputs', 'spec', 'outputs', 'Video or I/O outputs.' ),
				'power_connector' => array( 'Power connector', 'spec', 'power_connector', 'Power connector(s).' ),
				'psu'             => array( 'Recommended PSU', 'spec', 'psu', 'Recommended power supply in watts.' ),
				'length'          => array( 'Length', 'spec', 'length', 'Length with unit.' ),
				'slot_width'      => array( 'Slot width', 'spec', 'slot_width', 'Slot width or form factor.' ),
				'warranty'        => array( 'Warranty', 'meta', 'omg_warranty', 'Warranty length, e.g. "3 years".' ),
				'ean'             => array( 'EAN', 'ean', '', 'EAN / UPC barcode digits only.' ),
			);
		}
		if ( 'accessory' === $kind ) {
			return array(
				'brand'      => array( 'Brand', 'brand', 'product_brand', 'Manufacturer brand name.' ),
				'model'      => array( 'Model', 'spec', 'model', 'Model name or number.' ),
				'connection' => array( 'Connection', 'spec', 'connection', 'Wired/wireless and standard, e.g. "Bluetooth 5.1 · USB-C".' ),
				'battery'    => array( 'Battery', 'spec', 'battery', 'Battery life with unit, e.g. "Up to 12 hours".' ),
				'weight'     => array( 'Weight', 'spec', 'weight', 'Weight with unit.' ),
				'colour'     => array( 'Colour', 'spec', 'colour', 'Colour name(s).' ),
				'haptics'    => array( 'Haptics', 'spec', 'haptics', 'Haptic or vibration feature.' ),
				'triggers'   => array( 'Triggers', 'spec', 'triggers', 'Trigger or button feature.' ),
				'microphone' => array( 'Microphone', 'spec', 'microphone', 'Microphone type, if any.' ),
				'cable'      => array( 'Cable', 'spec', 'cable', 'Cable type and length, if included.' ),
				'warranty'   => array( 'Warranty', 'meta', 'omg_warranty', 'Warranty length, e.g. "1 year".' ),
				'ean'        => array( 'EAN', 'ean', '', 'EAN / UPC barcode digits only.' ),
			);
		}
		return array(
			'platform'     => array( 'Platform', 'tax', 'pa_platform', 'Store platform, e.g. "Steam", "Xbox", "PlayStation 5", "Epic Games".' ),
			'product_type' => array( 'Product type', 'meta', 'omg_type', 'One of: "Digital key", "Account (credentials)", "Gift card", "DLC", "Software".' ),
			'edition'      => array( 'Edition', 'tax', 'pa_game-edition', 'Edition name, e.g. "Ultimate Edition".' ),
			'region'       => array( 'Region', 'tax', 'pa_region', 'Activation region, e.g. "Global", "Europe", "United States".' ),
			'genres'       => array( 'Genres', 'tax', 'pa_genres', 'Comma separated genres.' ),
			'release_date' => array( 'Release date', 'meta', 'omg_release_date', 'Release date as YYYY-MM-DD.' ),
			'publisher'    => array( 'Publisher', 'tax', 'pa_publishers', 'Publisher name(s), comma separated.' ),
			'developer'    => array( 'Developer', 'tax', 'pa_developers', 'Developer name(s), comma separated.' ),
			'age_rating'   => array( 'Age rating', 'meta', 'omg_age_rating', 'Age rating, e.g. "PEGI 16" or "ESRB M".' ),
			'languages'    => array( 'Languages', 'tax', 'pa_languages', 'Comma separated supported languages.' ),
			'warranty'     => array( 'Warranty', 'meta', 'omg_warranty', 'Warranty, e.g. "30-day account warranty".' ),
			'kinguin_id'   => array( 'Kinguin ID', 'meta', 'omg_kinguin_id', 'Kinguin product ID, digits only. Never invent it.' ),
		);
	}

	/** Page sections: key => [label, meta key, format, badge, instruction]. */
	public static function section_defs( $kind ) {
		if ( 'digital' === $kind ) {
			return array(
				'whats_included'      => array( 'Edition includes', 'omg_whats_included', 'ul', 'OMGGamer OS', '3 to 8 items listing only content the edition is known to include. Empty list if the data does not say.' ),
				'activation_steps'    => array( 'Activation steps', 'omg_activation_steps', 'ol', 'AI written', '3 to 6 short steps that fit the product type (account login, key redemption on the platform, or gift card redeem).' ),
				'system_requirements' => array( 'System requirements', 'omg_system_requirements', 'sysreq', 'OMGGamer OS + Steam', 'Lines like "OS: Windows 10 64-bit". Start a group with "## Minimum" or "## Recommended" when the data has both. Empty list for consoles or when the data does not say.' ),
			);
		}
		$third = 'hardware' === $kind
			? array( 'compatibility', array( 'Compatibility check', 'omg_compatibility', 'ul', 'AI written', '3 to 5 things the buyer must check before ordering (case size, power supply, slot, ports), from the specs only.' ) )
			: array( 'works_with', array( 'Works with', 'omg_works_with', 'works', 'AI written', '3 to 6 devices or platforms. Each item: {"name": "PS5", "note": "Full features", "ok": true}. Use ok false for a platform the data says is not supported.' ) );
		return array(
			'key_features' => array( 'Key features', 'omg_key_features', 'features', 'AI written', '4 to 6 items, each "Short title: one sentence", from the specs only.' ),
			'in_the_box'   => array( 'In the box', 'omg_in_the_box', 'ul', 'Datasheet PDF', 'Box contents only as stated by the data. Empty list if the data does not say.' ),
			$third[0]      => $third[1],
		);
	}

	/** Extra digital fields shown on the game page. */
	public static function extra_defs( $kind ) {
		if ( 'digital' !== $kind ) {
			return array();
		}
		return array(
			'region_warning' => array( 'Region warning', 'omg_region_warning', 'One or two sentences on where this product works, from the region data. Empty when global.' ),
			'delivery_note'  => array( 'Delivery note', 'omg_delivery_note', 'Under 60 characters, e.g. "Instant, by email and dashboard".' ),
			'pills'          => array( 'Product pills', 'omg_pills', '2 or 3 short selling points separated by commas, each under 22 characters.' ),
			'metacritic'     => array( 'Metacritic score', 'omg_metacritic', 'Metacritic score number only, only if given in the data.' ),
		);
	}

	/** The groups a reviewer approves ("AI fields reviewed x of 8"). */
	public static function review_keys( $kind ) {
		$keys = array( 'title', 'short_description', 'description', 'attributes' );
		foreach ( array_keys( self::section_defs( $kind ) ) as $k ) {
			$keys[] = 'sec_' . $k;
		}
		return $keys; // SEO (meta title and description) is handled by Rank Math.
	}

	public static function kind( $post_id ) {
		$k = get_post_meta( $post_id, '_omg_kind', true );
		if ( isset( self::kinds()[ $k ] ) ) {
			return $k;
		}
		$layout = get_post_meta( $post_id, '_omg_layout', true );
		if ( in_array( $layout, array( 'hardware', 'accessory' ), true ) ) {
			return $layout;
		}
		$slugs = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'slugs' ) );
		$slugs = is_array( $slugs ) ? $slugs : array();
		if ( in_array( 'accessories', $slugs, true ) ) {
			return 'accessory';
		}
		if ( in_array( 'hardware', $slugs, true ) ) {
			return 'hardware';
		}
		return 'digital';
	}

	public static function specs( $post_id ) {
		$s = json_decode( (string) get_post_meta( $post_id, 'omg_specs', true ), true );
		$s = is_array( $s ) ? $s : array();
		return array(
			'fields' => isset( $s['fields'] ) && is_array( $s['fields'] ) ? $s['fields'] : array(),
			'custom' => isset( $s['custom'] ) && is_array( $s['custom'] ) ? $s['custom'] : array(),
		);
	}

	/** Returns [value, locked_reason]. */
	public static function get_attr( $post_id, $def ) {
		list( , $store, $target ) = $def;
		switch ( $store ) {
			case 'tax':
				$p = wc_get_product( $post_id );
				$locked = '';
				if ( $p ) {
					$attrs = $p->get_attributes();
					if ( isset( $attrs[ $target ] ) && $attrs[ $target ]->get_variation() ) {
						$locked = 'Used for variations. Edit it in Product data › Attributes.';
					}
				}
				$names = taxonomy_exists( $target ) ? wc_get_product_terms( $post_id, $target, array( 'fields' => 'names' ) ) : array();
				return array( implode( ', ', (array) $names ), $locked );
			case 'meta':
				return array( (string) get_post_meta( $post_id, $target, true ), '' );
			case 'spec':
				$s = self::specs( $post_id );
				return array( isset( $s['fields'][ $target ] ) ? (string) $s['fields'][ $target ] : '', '' );
			case 'ean':
				$p = wc_get_product( $post_id );
				$v = $p && method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id() : get_post_meta( $post_id, '_global_unique_id', true );
				return array( (string) $v, '' );
			case 'brand':
				$names = taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $post_id, 'product_brand', array( 'fields' => 'names' ) ) : array();
				$names = is_wp_error( $names ) ? array() : $names;
				if ( ! $names ) {
					$s = self::specs( $post_id );
					return array( isset( $s['fields']['brand'] ) ? (string) $s['fields']['brand'] : '', '' );
				}
				return array( implode( ', ', $names ), '' );
		}
		return array( '', '' );
	}

	/** Writes attribute values. $values: key => string. Saves the product once. */
	public static function set_attrs( $post_id, $kind, $values ) {
		$defs = self::attr_defs( $kind );
		$p    = wc_get_product( $post_id );
		if ( ! $p ) {
			return;
		}
		$attrs = $p->get_attributes();
		$specs = self::specs( $post_id );
		$dirty = false;
		$sdirty = false;
		foreach ( $values as $key => $val ) {
			if ( ! isset( $defs[ $key ] ) ) {
				continue;
			}
			$val = trim( sanitize_text_field( (string) $val ) );
			list( , $store, $target ) = $defs[ $key ];
			if ( 'tax' === $store ) {
				if ( ! taxonomy_exists( $target ) || ( isset( $attrs[ $target ] ) && $attrs[ $target ]->get_variation() ) ) {
					continue;
				}
				$ids = array();
				foreach ( array_filter( array_map( 'trim', explode( ',', $val ) ) ) as $name ) {
					$t = get_term_by( 'name', $name, $target );
					if ( ! $t ) {
						$r = wp_insert_term( $name, $target );
						if ( is_wp_error( $r ) ) {
							continue;
						}
						$ids[] = (int) $r['term_id'];
					} else {
						$ids[] = (int) $t->term_id;
					}
				}
				if ( $ids ) {
					$a = isset( $attrs[ $target ] ) ? $attrs[ $target ] : new WC_Product_Attribute();
					$a->set_id( wc_attribute_taxonomy_id_by_name( $target ) );
					$a->set_name( $target );
					$a->set_options( $ids );
					$a->set_visible( true );
					$a->set_variation( false );
					if ( ! isset( $attrs[ $target ] ) ) {
						$a->set_position( count( $attrs ) );
					}
					$attrs[ $target ] = $a;
					wp_set_object_terms( $post_id, $ids, $target );
				} else {
					unset( $attrs[ $target ] );
					wp_set_object_terms( $post_id, array(), $target );
				}
				$dirty = true;
			} elseif ( 'meta' === $store ) {
				if ( '' === $val ) {
					delete_post_meta( $post_id, $target );
				} else {
					update_post_meta( $post_id, $target, $val );
				}
			} elseif ( 'spec' === $store ) {
				$specs['fields'][ $target ] = $val;
				$sdirty = true;
			} elseif ( 'ean' === $store ) {
				$val = preg_replace( '/[^0-9A-Za-z-]/', '', $val );
				if ( method_exists( $p, 'set_global_unique_id' ) ) {
					try {
						$p->set_global_unique_id( $val );
						$dirty = true;
					} catch ( Exception $e ) { // duplicate EAN.
						update_post_meta( $post_id, '_omg_ean_error', $e->getMessage() );
					}
				} else {
					update_post_meta( $post_id, '_global_unique_id', $val );
				}
			} elseif ( 'brand' === $store ) {
				$specs['fields']['brand'] = $val;
				$sdirty = true;
				if ( taxonomy_exists( 'product_brand' ) ) {
					wp_set_object_terms( $post_id, array_filter( array_map( 'trim', explode( ',', $val ) ) ), 'product_brand' );
				}
			}
		}
		if ( $dirty ) {
			$p->set_attributes( $attrs );
			$p->save();
		}
		if ( $sdirty ) {
			self::save_specs( $post_id, $specs );
		}
	}

	public static function save_specs( $post_id, $specs ) {
		$specs['fields'] = array_filter( array_map( 'strval', (array) $specs['fields'] ), 'strlen' );
		$specs['custom'] = array_values( array_filter( (array) $specs['custom'], function ( $r ) {
			return is_array( $r ) && isset( $r[0], $r[1] ) && '' !== trim( $r[0] ) && '' !== trim( $r[1] );
		} ) );
		if ( ! $specs['fields'] && ! $specs['custom'] ) {
			delete_post_meta( $post_id, 'omg_specs' );
			return;
		}
		update_post_meta( $post_id, 'omg_specs', wp_slash( wp_json_encode( $specs, JSON_UNESCAPED_UNICODE ) ) );
	}

	/* ---------------------------------------------------------------- list sections */

	public static function inline_kses( $s ) {
		return trim( wp_kses( (string) $s, array( 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'code' => array(), 'br' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ) );
	}

	/** Stored value -> list items (strings, or arrays for "works"). */
	public static function items_from_store( $raw, $format ) {
		if ( 'works' === $format ) {
			$d = json_decode( (string) $raw, true );
			$out = array();
			foreach ( is_array( $d ) ? $d : array() as $r ) {
				if ( isset( $r['name'] ) && '' !== trim( $r['name'] ) ) {
					$out[] = array( 'name' => (string) $r['name'], 'note' => isset( $r['note'] ) ? (string) $r['note'] : '', 'ok' => ! isset( $r['ok'] ) || ! empty( $r['ok'] ) );
				}
			}
			return $out;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		if ( ! preg_match( '#<(li|p|h[1-6])\b#i', $raw ) ) {
			return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $raw ) ) ), 'strlen' ) );
		}
		$out = array();
		preg_match_all( '#<li[^>]*>(.*?)</li>|<p[^>]*>\s*<(?:strong|b)>(.*?)</(?:strong|b)>\s*:?\s*</p>|<h[2-6][^>]*>(.*?)</h[2-6]>#is', $raw, $m, PREG_SET_ORDER );
		foreach ( $m as $row ) {
			if ( isset( $row[1] ) && '' !== $row[1] ) {
				$out[] = self::inline_kses( $row[1] );
			} else {
				$h = isset( $row[3] ) && '' !== $row[3] ? $row[3] : ( isset( $row[2] ) ? $row[2] : '' );
				if ( '' !== trim( wp_strip_all_tags( $h ) ) ) {
					$out[] = '## ' . trim( wp_strip_all_tags( $h ) );
				}
			}
		}
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/** List items -> stored value. */
	public static function items_to_store( $items, $format ) {
		if ( 'works' === $format ) {
			$rows = array();
			foreach ( (array) $items as $r ) {
				if ( is_array( $r ) && ! empty( $r['name'] ) ) {
					$rows[] = array( 'name' => sanitize_text_field( $r['name'] ), 'note' => isset( $r['note'] ) ? sanitize_text_field( $r['note'] ) : '', 'ok' => ! empty( $r['ok'] ) && 'false' !== $r['ok'] );
				}
			}
			return $rows ? wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) : '';
		}
		$items = array_values( array_filter( array_map( function ( $i ) {
			return is_scalar( $i ) ? trim( (string) $i ) : '';
		}, (array) $items ), 'strlen' ) );
		if ( ! $items ) {
			return '';
		}
		$tag = 'ol' === $format ? 'ol' : 'ul';
		$html = '';
		$open = false;
		foreach ( $items as $i ) {
			if ( 0 === strpos( $i, '## ' ) ) {
				if ( $open ) {
					$html .= '</' . $tag . '>';
					$open = false;
				}
				$html .= '<p><strong>' . esc_html( trim( substr( $i, 3 ) ) ) . '</strong></p>';
				continue;
			}
			if ( ! $open ) {
				$html .= '<' . $tag . '>';
				$open = true;
			}
			$html .= '<li>' . self::inline_kses( $i ) . '</li>';
		}
		if ( $open ) {
			$html .= '</' . $tag . '>';
		}
		return $html;
	}

	/* ================================================================ AI */

	/**
	 * Calls the configured provider. $pdf: optional absolute path to a PDF the model should read.
	 * Returns text or WP_Error.
	 */
	public static function call_ai( $system, $user, $max_tokens = 4000, $pdf = '' ) {
		$s        = self::settings();
		$provider = $s['provider'];
		$key      = self::api_key();
		$model    = $s['model'] ? $s['model'] : self::default_model( $provider );
		if ( '' === $key ) {
			return new WP_Error( 'omg_ai_no_key', 'No AI API key is set. Add one in Products › AI Studio.' );
		}
		if ( '' === $model ) {
			return new WP_Error( 'omg_ai_no_model', 'No model is set. Add a model name in Products › AI Studio.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore
		}
		$pdf_b64 = '';
		if ( $pdf && is_readable( $pdf ) && filesize( $pdf ) < 9 * MB_IN_BYTES ) {
			$pdf_b64 = base64_encode( file_get_contents( $pdf ) ); // phpcs:ignore
		}
		if ( 'anthropic' === $provider ) {
			$headers = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
			$content = $user;
			if ( $pdf_b64 ) {
				$content = array(
					array( 'type' => 'document', 'source' => array( 'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdf_b64 ) ),
					array( 'type' => 'text', 'text' => $user ),
				);
			}
			$body = array( 'model' => $model, 'max_tokens' => $max_tokens, 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $content ) ) );
			if ( ! empty( $s['fallbacks'] ) && preg_match( '/^claude-(opus-5|fable-5|sonnet-5-5)/', $model ) ) {
				$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
				$body['fallbacks']         = 'default';
			}
			$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 'timeout' => 200, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code >= 300 ) {
				$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
				return new WP_Error( 'omg_ai_http', 'Anthropic: ' . $msg );
			}
			if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
				return new WP_Error( 'omg_ai_refusal', 'The model declined this request. Try different extra instructions.' );
			}
			$text = '';
			foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
			if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
				return new WP_Error( 'omg_ai_len', 'The answer was cut off. Generate fewer fields at once.' );
			}
			return $text;
		}
		$base = 'openai' === $provider ? 'https://api.openai.com/v1' : ( 'openrouter' === $provider ? 'https://openrouter.ai/api/v1' : rtrim( $s['base_url'], '/' ) );
		if ( 'omniroute' === $provider && '' === $base ) {
			$base = self::OMNIROUTE;
		}
		if ( '' === $base ) {
			return new WP_Error( 'omg_ai_no_base', 'Add the API base URL for your OpenAI-compatible provider in Products › AI Studio.' );
		}
		$headers = array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' );
		if ( 'openrouter' === $provider ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = get_bloginfo( 'name' );
		}
		$ucontent = $user;
		if ( $pdf_b64 ) {
			$ucontent = array(
				array( 'type' => 'file', 'file' => array( 'filename' => basename( $pdf ), 'file_data' => 'data:application/pdf;base64,' . $pdf_b64 ) ),
				array( 'type' => 'text', 'text' => $user ),
			);
		}
		$body = array( 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $ucontent ) ) );
		// OmniRoute sits behind Cloudflare, which closes a request after 100 seconds without data (HTTP 524).
		// Streaming keeps data flowing while a slow free model writes; the reply is joined back together below.
		$stream = 'omniroute' === $provider;
		if ( $stream ) {
			$body['stream'] = true;
		}
		$res  = wp_remote_post( $base . '/chat/completions', array( 'timeout' => 280, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		if ( $code < 300 && $stream && false !== strpos( $raw, 'data:' ) ) {
			return self::join_stream( $raw, self::PROVIDERS[ $provider ] );
		}
		$data = json_decode( $raw, true );
		if ( $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code );
			return new WP_Error( 'omg_ai_http', self::PROVIDERS[ $provider ] . ': ' . $msg );
		}
		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		if ( is_array( $text ) ) {
			$parts = '';
			foreach ( $text as $part ) {
				$parts .= isset( $part['text'] ) ? $part['text'] : '';
			}
			$text = $parts;
		}
		return (string) $text;
	}

	/** Joins a streamed (server-sent events) chat completion into the reply text. */
	private static function join_stream( $raw, $label ) {
		$text = '';
		foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}
			$chunk = trim( substr( $line, 5 ) );
			if ( '' === $chunk || '[DONE]' === $chunk ) {
				continue;
			}
			$d = json_decode( $chunk, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			if ( isset( $d['error'] ) ) {
				$msg = is_array( $d['error'] ) && isset( $d['error']['message'] ) ? $d['error']['message'] : 'the model stopped with an error';
				return new WP_Error( 'omg_ai_http', $label . ': ' . $msg );
			}
			if ( isset( $d['choices'][0]['delta']['content'] ) && is_string( $d['choices'][0]['delta']['content'] ) ) {
				$text .= $d['choices'][0]['delta']['content'];
			}
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'omg_ai_empty', $label . ': the model returned no text. Try again.' );
		}
		return $text;
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$a = strpos( $text, '{' );
		$b = strrpos( $text, '}' );
		if ( false !== $a && false !== $b && $b > $a ) {
			$data = json_decode( substr( $text, $a, $b - $a + 1 ), true );
		}
		return is_array( $data ) ? $data : null;
	}

	/* ---------------------------------------------------------------- context */

	/** Current product data. $doc (from the editor form) overrides stored values. */
	public static function context( $post_id, $kind, $doc = array(), $source = array() ) {
		$p    = wc_get_product( $post_id );
		$post = get_post( $post_id );
		$ctx  = array(
			'product_kind'  => $kind,
			'store'         => get_bloginfo( 'name' ),
			'name'          => ! empty( $doc['title'] ) ? $doc['title'] : ( $post ? $post->post_title : '' ),
			'woo_type'      => $p ? $p->get_type() : '',
			'price'         => $p && '' !== $p->get_price() ? html_entity_decode( wp_strip_all_tags( wc_price( $p->get_price() ) ) ) : '',
			'regular_price' => $p && $p->get_regular_price() ? html_entity_decode( wp_strip_all_tags( wc_price( $p->get_regular_price() ) ) ) : '',
			'collections'   => $post ? wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) ) : array(),
			'current_short' => wp_strip_all_tags( isset( $doc['short_description'] ) ? $doc['short_description'] : ( $post ? $post->post_excerpt : '' ) ),
			'current_long'  => wp_trim_words( wp_strip_all_tags( isset( $doc['description'] ) ? $doc['description'] : ( $post ? $post->post_content : '' ) ), 450 ),
			'attributes'    => array(),
			'all_product_attributes' => array(),
		);
		foreach ( self::attr_defs( $kind ) as $k => $def ) {
			$v = isset( $doc['attrs'][ $k ] ) ? $doc['attrs'][ $k ] : self::get_attr( $post_id, $def )[0];
			if ( '' !== (string) $v ) {
				$ctx['attributes'][ $def[0] ] = (string) $v;
			}
		}
		if ( $p ) {
			foreach ( $p->get_attributes() as $tax => $attr ) {
				$vals = $attr->is_taxonomy() ? wc_get_product_terms( $post_id, $tax, array( 'fields' => 'names' ) ) : $attr->get_options();
				if ( $vals ) {
					$ctx['all_product_attributes'][ wc_attribute_label( $tax ) ] = array_slice( array_map( 'strval', (array) $vals ), 0, 25 );
				}
			}
		}
		foreach ( self::section_defs( $kind ) as $k => $def ) {
			$items = isset( $doc['sections'][ $k ] ) ? $doc['sections'][ $k ] : self::items_from_store( get_post_meta( $post_id, $def[1], true ), $def[2] );
			if ( $items ) {
				$ctx[ 'current_' . $k ] = $items;
			}
		}
		if ( $source ) {
			$ctx['source_data'] = $source;
		}
		return $ctx;
	}

	public static function voice_text( $voice ) {
		$s = self::settings();
		if ( 'technical' === $voice ) {
			return $s['brand_voice'] . "\nStyle for this product: technical and spec-first. Lead with the key specifications and exact numbers. Minimal adjectives.";
		}
		if ( 'marketplace' === $voice ) {
			return $s['brand_voice'] . "\nStyle for this product: short marketplace copy. Brief, scannable, benefit-first sentences.";
		}
		return $s['brand_voice'];
	}

	/** Builds the JSON instruction for a group. Returns [spec lines, max tokens]. */
	private static function group_spec( $group, $kind, $post_id ) {
		$lines = array();
		$max   = 2500;
		switch ( $group ) {
			case 'title':
				$lines[] = '"title": plain text, 70 characters or fewer. ' . ( 'digital' === $kind ? 'Pattern: "<Game name>: <Edition> <Platform> <Account|Key|Gift card>". Keep the real edition and platform.' : 'Pattern: "<Product name> <key spec> by <Brand>" or the clean official product name.' );
				break;
			case 'short_description':
				$lines[] = '"short_description": HTML, one <p> of 20 to 40 words shown under the price. Say what the buyer receives' . ( 'digital' === $kind ? ', the platform and region, and that delivery is instant.' : ' and the one or two specs that matter most.' );
				break;
			case 'description':
				$lines[] = '"description": HTML, 2 to 4 <p> paragraphs, 110 to 220 words in total. ' . ( 'digital' === $kind ? 'About the game itself and what this listing delivers.' : 'What the product does, the key specs in plain words, and what to check before buying.' ) . ' No headings, no lists, no prices.';
				$max     = 3000;
				break;
			case 'content':
				foreach ( array( 'title', 'short_description', 'description' ) as $g ) {
					$lines = array_merge( $lines, self::group_spec( $g, $kind, $post_id )[0] );
				}
				$max = 3500;
				break;
			case 'attributes':
				$a = array();
				foreach ( self::attr_defs( $kind ) as $k => $def ) {
					$a[] = '    "' . $k . '": ' . $def[0] . '. ' . $def[3];
				}
				$lines[] = '"attributes": an object with these keys (plain text values, empty string when the data does not say):' . "\n" . implode( "\n", $a );
				$lines[] = '"attribute_sources": an object with the same keys, each value one of "os", "steam", "kinguin", "icecat", "manufacturer", "datasheet", "product" telling where the value came from ("os" for source_data.os).';
				break;
			case 'sections':
				foreach ( array_keys( self::section_defs( $kind ) ) as $k ) {
					$lines = array_merge( $lines, self::group_spec( 'sec_' . $k, $kind, $post_id )[0] );
				}
				$max = 3000;
				break;
			case 'extras':
				foreach ( self::extra_defs( $kind ) as $k => $def ) {
					$lines[] = '"' . $k . '": plain text. ' . $def[2];
				}
				break;
			case 'seo':
				$lines[] = '"seo_title": plain text, 60 characters or fewer, ending with " | OMGGamer".';
				$lines[] = '"seo_description": plain text, 120 to 155 characters, with the product name, the price if known, and the main benefit.';
				$lines[] = '"slug": lowercase URL slug, words joined by hyphens, under 75 characters.';
				$lines[] = '"focus_keyword": the 2 to 5 word phrase a buyer would search for, lowercase.';
				$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>name' ) );
				if ( $cats && ! is_wp_error( $cats ) ) {
					$list = array();
					foreach ( $cats as $id => $name ) {
						$list[] = $id . ' = ' . html_entity_decode( $name );
					}
					$lines[] = '"categories": array of category IDs (numbers) from this list that fit the product: ' . implode( '; ', $list ) . '.';
				}
				break;
			case 'alt':
				$ids = self::media_ids( $post_id );
				$lines[] = '"alt": an object mapping each image ID (' . ( $ids ? implode( ', ', $ids ) : 'none' ) . ') to alt text under 110 characters. Image 0 in the list is the main image; others are gallery images. Describe the product, not "image of".';
				break;
			default:
				if ( 0 === strpos( $group, 'sec_' ) ) {
					$k    = substr( $group, 4 );
					$defs = self::section_defs( $kind );
					if ( isset( $defs[ $k ] ) ) {
						$lines[] = '"' . $k . '": ' . ( 'works' === $defs[ $k ][2] ? 'array of objects. ' : 'array of strings (one per list item, plain text, no numbering). ' ) . $defs[ $k ][4];
					}
				}
		}
		return array( $lines, $max );
	}

	public static function media_ids( $post_id ) {
		$p   = wc_get_product( $post_id );
		$ids = array();
		if ( $p ) {
			if ( $p->get_image_id() ) {
				$ids[] = (int) $p->get_image_id();
			}
			foreach ( $p->get_gallery_image_ids() as $g ) {
				$ids[] = (int) $g;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Runs one generation group. $opts: tone, voice, lang, extra, doc, source, pdf.
	 * Returns a cleaned array of values or WP_Error.
	 */
	public static function generate( $post_id, $kind, $group, $opts = array() ) {
		$opts = wp_parse_args( $opts, array( 'tone' => self::settings()['tone'], 'voice' => 'default', 'lang' => '', 'extra' => '', 'doc' => array(), 'source' => array(), 'pdf' => '' ) );
		$custom = self::custom_prompt_run( $post_id, $kind, $group, $opts );
		if ( null !== $custom ) {
			return $custom;
		}
		list( $lines, $max ) = self::group_spec( $group, $kind, $post_id );
		if ( ! $lines ) {
			return new WP_Error( 'omg_ai_group', 'Unknown field.' );
		}
		$tones = array( 'informative' => 'Informative: clear and factual, explain what the buyer gets.', 'hype' => 'Hype: energetic and exciting, but still factual and without exclamation marks.', 'concise' => 'Concise: as short as the format allows, no filler.' );
		$lang  = $opts['lang'] && isset( self::LANGS[ $opts['lang'] ] ) ? self::LANGS[ $opts['lang'] ] : self::settings()['language'];
		$system = self::voice_text( $opts['voice'] ) . "\n\nYou write WooCommerce product content for OMGGamer. Rules:\n- Use only facts found in the PRODUCT DATA (and the attached datasheet, if any). Prefer source_data over the current text. source_data.os comes from OMGGamer OS and is the main source (facts, suppliers, activation details, system requirements); source_data.steam (the official Steam store page, matched by title) fills gaps such as developer, publisher, release date, genres and requirements. If a value needs a fact the data does not give, return an empty string or empty list rather than guessing.\n- Rewrite in your own words; never copy source descriptions sentence by sentence.\n- Never mention other stores or marketplaces (including Kinguin), never promise discounts that are not in the data, never invent ratings, sales numbers or IDs.\n- Write in " . $lang . ".\n- Tone: " . ( isset( $tones[ $opts['tone'] ] ) ? $tones[ $opts['tone'] ] : $tones['informative'] ) . "\n- Reply with one JSON object only, no markdown fences, no commentary.";
		$ctx  = self::context( $post_id, $kind, $opts['doc'], $opts['source'] );
		$user = "PRODUCT DATA (JSON):\n" . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n\nReturn a JSON object with exactly these keys:\n" . implode( "\n", $lines );
		if ( '' !== trim( $opts['extra'] ) ) {
			$user .= "\n\nExtra instructions from the store manager:\n" . mb_substr( $opts['extra'], 0, 800 );
		}
		$text = self::call_ai( $system, $user, $max, $opts['pdf'] );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = self::parse_json( $text );
		if ( ! $data ) {
			return new WP_Error( 'omg_ai_json', 'The AI answer was not valid JSON. Please try again.' );
		}
		return self::clean( $kind, $data );
	}

	/**
	 * Short/full description from the custom prompts in AI Studio settings. Only the product title is filled in
	 * ({title}); {facts} adds the OMGGamer OS data when a prompt asks for it. Returns null when no custom prompt
	 * applies, so the standard writer runs.
	 */
	private static function custom_prompt_run( $post_id, $kind, $group, $opts ) {
		$s    = self::settings();
		$want = array();
		if ( in_array( $group, array( 'short_description', 'content' ), true ) && '' !== trim( $s['prompt_short'] ) ) {
			$want['short_description'] = array( $s['prompt_short'], 'HTML: one or two <p> paragraphs.' );
		}
		if ( in_array( $group, array( 'description', 'content' ), true ) && '' !== trim( $s['prompt_long'] ) ) {
			$want['description'] = array( $s['prompt_long'], 'HTML: <p> paragraphs; <h3>, <ul> and <li> are allowed. No prices.' );
		}
		if ( ! $want ) {
			return null;
		}
		$title = ! empty( $opts['doc']['title'] ) ? wp_strip_all_tags( $opts['doc']['title'] ) : get_the_title( $post_id );
		$facts = ! empty( $opts['source'] ) ? wp_json_encode( $opts['source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '{}';
		$lang  = $opts['lang'] && isset( self::LANGS[ $opts['lang'] ] ) ? self::LANGS[ $opts['lang'] ] : $s['language'];
		$system = self::voice_text( $opts['voice'] ) . "\n\nYou write WooCommerce product content for OMGGamer in " . $lang . ". Never mention other stores or marketplaces, never invent prices, discounts, ratings or sales numbers. Reply with one JSON object only, no markdown fences, no commentary.";
		$out = array();
		foreach ( $want as $key => $w ) {
			$user = str_replace( array( '{title}', '{facts}' ), array( $title, $facts ), $w[0] );
			$user .= "\n\nReturn a JSON object with exactly one key, \"" . $key . "\": " . $w[1];
			$text = self::call_ai( $system, $user, 3000 );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			$data = self::parse_json( $text );
			if ( ! $data || ! isset( $data[ $key ] ) ) {
				return new WP_Error( 'omg_ai_json', 'The AI answer was not valid JSON. Please try again.' );
			}
			$out = array_merge( $out, self::clean( $kind, array( $key => $data[ $key ] ) ) );
		}
		if ( 'content' === $group ) {
			// The title still comes from the standard writer.
			$t = self::generate( $post_id, $kind, 'title', $opts );
			if ( ! is_wp_error( $t ) ) {
				$out = array_merge( $t, $out );
			}
		}
		return $out;
	}

	/** Sanitises an AI answer. */
	public static function clean( $kind, $data ) {
		$out = array();
		foreach ( array( 'title', 'seo_title', 'focus_keyword' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				$out[ $k ] = trim( sanitize_text_field( $data[ $k ] ) );
			}
		}
		if ( isset( $data['seo_description'] ) && is_scalar( $data['seo_description'] ) ) {
			$out['seo_description'] = trim( sanitize_textarea_field( $data['seo_description'] ) );
		}
		if ( isset( $data['slug'] ) && is_scalar( $data['slug'] ) ) {
			$out['slug'] = sanitize_title( $data['slug'] );
		}
		foreach ( array( 'short_description', 'description' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				$out[ $k ] = trim( wp_kses_post( $data[ $k ] ) );
			}
		}
		if ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
			$defs = self::attr_defs( $kind );
			$out['attributes'] = array();
			foreach ( $data['attributes'] as $k => $v ) {
				if ( isset( $defs[ $k ] ) && is_scalar( $v ) ) {
					$out['attributes'][ $k ] = trim( sanitize_text_field( $v ) );
				}
			}
			$out['attribute_sources'] = array();
			if ( isset( $data['attribute_sources'] ) && is_array( $data['attribute_sources'] ) ) {
				foreach ( $data['attribute_sources'] as $k => $v ) {
					if ( isset( $defs[ $k ] ) && is_scalar( $v ) ) {
						$out['attribute_sources'][ $k ] = sanitize_key( $v );
					}
				}
			}
		}
		foreach ( self::section_defs( $kind ) as $k => $def ) {
			if ( ! isset( $data[ $k ] ) || ! is_array( $data[ $k ] ) ) {
				continue;
			}
			$items = array();
			foreach ( $data[ $k ] as $item ) {
				if ( 'works' === $def[2] ) {
					if ( is_array( $item ) && ! empty( $item['name'] ) ) {
						$items[] = array( 'name' => sanitize_text_field( $item['name'] ), 'note' => isset( $item['note'] ) ? sanitize_text_field( $item['note'] ) : '', 'ok' => ! isset( $item['ok'] ) || ! empty( $item['ok'] ) );
					}
				} elseif ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					$items[] = self::inline_kses( $item );
				}
			}
			$out['sections'][ $k ] = $items;
		}
		foreach ( self::extra_defs( $kind ) as $k => $def ) {
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				$out['extras'][ $k ] = trim( sanitize_text_field( $data[ $k ] ) );
			}
		}
		if ( isset( $data['alt'] ) && is_array( $data['alt'] ) ) {
			foreach ( $data['alt'] as $id => $t ) {
				if ( absint( $id ) && is_scalar( $t ) ) {
					$out['alt'][ absint( $id ) ] = trim( sanitize_text_field( $t ) );
				}
			}
		}
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$out['categories'] = array_values( array_filter( array_map( 'absint', $data['categories'] ), function ( $id ) {
				return $id && term_exists( $id, 'product_cat' );
			} ) );
		}
		return $out;
	}

	/** Writes a generated (or edited) document to the product. Used by the queue. */
	public static function apply( $post_id, $kind, $doc, $skip = array() ) {
		$post_up = array( 'ID' => $post_id );
		if ( isset( $doc['title'] ) && '' !== $doc['title'] && ! in_array( 'title', $skip, true ) ) {
			$post_up['post_title'] = $doc['title'];
		}
		if ( isset( $doc['short_description'] ) && ! in_array( 'short_description', $skip, true ) ) {
			$post_up['post_excerpt'] = $doc['short_description'];
		}
		if ( isset( $doc['description'] ) && '' !== $doc['description'] && ! in_array( 'description', $skip, true ) ) {
			$post_up['post_content'] = $doc['description'];
		}
		if ( ! empty( $doc['slug'] ) && ! in_array( 'seo', $skip, true ) && 'publish' !== get_post_status( $post_id ) ) {
			$post_up['post_name'] = $doc['slug'];
		}
		if ( count( $post_up ) > 1 ) {
			wp_update_post( wp_slash( $post_up ) );
		}
		if ( ! empty( $doc['attributes'] ) && ! in_array( 'attributes', $skip, true ) ) {
			self::set_attrs( $post_id, $kind, $doc['attributes'] );
			if ( ! empty( $doc['attribute_sources'] ) ) {
				$src = (array) json_decode( (string) get_post_meta( $post_id, '_omg_attr_src', true ), true );
				update_post_meta( $post_id, '_omg_attr_src', wp_json_encode( array_merge( $src, $doc['attribute_sources'] ) ) );
			}
		}
		foreach ( self::section_defs( $kind ) as $k => $def ) {
			if ( isset( $doc['sections'][ $k ] ) && ! in_array( 'sec_' . $k, $skip, true ) ) {
				$v = self::items_to_store( $doc['sections'][ $k ], $def[2] );
				'' === $v ? delete_post_meta( $post_id, $def[1] ) : update_post_meta( $post_id, $def[1], wp_slash( $v ) );
			}
		}
		foreach ( self::extra_defs( $kind ) as $k => $def ) {
			if ( isset( $doc['extras'][ $k ] ) && ! in_array( 'extras', $skip, true ) ) {
				'' === $doc['extras'][ $k ] ? delete_post_meta( $post_id, $def[1] ) : update_post_meta( $post_id, $def[1], $doc['extras'][ $k ] );
			}
		}
		if ( ! in_array( 'seo', $skip, true ) ) {
			foreach ( array( 'seo_title' => '_omg_seo_title', 'seo_description' => '_omg_seo_description', 'focus_keyword' => '_omg_seo_keyword' ) as $k => $meta ) {
				if ( isset( $doc[ $k ] ) ) {
					update_post_meta( $post_id, $meta, $doc[ $k ] );
				}
			}
		}
		if ( ! empty( $doc['alt'] ) ) {
			foreach ( $doc['alt'] as $id => $alt ) {
				if ( 'attachment' === get_post_type( $id ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				}
			}
		}
		if ( ! empty( $doc['categories'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $doc['categories'] ), 'product_cat', true );
		}
	}

	/* ================================================================ rewrite queue */

	public static function init_queue() {
		add_action( 'omg_ps_rewrite', array( __CLASS__, 'run_rewrite' ), 10, 1 );
	}

	public static function queue_rewrite( $post_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'omg_ps_rewrite', array( (int) $post_id ), 'omg-ai' ) ) {
			return true;
		}
		update_post_meta( $post_id, '_omg_ai_status', 'queued' );
		as_enqueue_async_action( 'omg_ps_rewrite', array( (int) $post_id ), 'omg-ai' );
		return true;
	}

	/** Background rewrite of one product: writes every field that is not approved. */
	public static function run_rewrite( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$kind     = self::kind( $post_id );
		$approved = (array) json_decode( (string) get_post_meta( $post_id, '_omg_ai_approved', true ), true );
		$source   = (array) json_decode( (string) get_post_meta( $post_id, '_omg_source', true ), true );
		if ( 'digital' === $kind ) {
			// Games: refresh the OMGGamer OS data, then the Steam store page for gaps.
			$os = OMG_PS_Sources::os( $post_id );
			if ( ! is_wp_error( $os ) ) {
				$source['os'] = $os;
			}
			$name   = ! empty( $source['os']['facts']['product_name'] ) ? $source['os']['facts']['product_name'] : get_the_title( $post_id );
			$app_id = ! empty( $source['os']['steam_app_id'] ) ? (string) $source['os']['steam_app_id'] : '';
			$steam  = OMG_PS_Sources::steam( $name, $app_id );
			if ( ! is_wp_error( $steam ) ) {
				$source['steam'] = $steam;
			}
			update_post_meta( $post_id, '_omg_source', wp_slash( wp_json_encode( $source, JSON_UNESCAPED_UNICODE ) ) );
		}
		$opts     = array( 'source' => $source, 'voice' => get_post_meta( $post_id, '_omg_ai_voice', true ) ?: 'default', 'lang' => get_post_meta( $post_id, '_omg_ai_lang', true ), 'extra' => (string) get_post_meta( $post_id, '_omg_ai_extra', true ) );
		$groups   = array( 'content', 'attributes', 'sections', 'extras' );
		$written  = array();
		$errors   = array();
		foreach ( $groups as $g ) {
			if ( 'extras' === $g && ! self::extra_defs( $kind ) ) {
				continue;
			}
			$r = self::generate( $post_id, $kind, $g, $opts );
			if ( is_wp_error( $r ) ) {
				$errors[] = $g . ': ' . $r->get_error_message();
				continue;
			}
			if ( 'attributes' === $g && ! empty( $r['attributes'] ) ) {
				// Fill empty attributes only; never overwrite values a person entered.
				foreach ( $r['attributes'] as $k => $v ) {
					$def = self::attr_defs( $kind )[ $k ];
					if ( '' !== self::get_attr( $post_id, $def )[0] || '' === $v ) {
						unset( $r['attributes'][ $k ] );
					}
				}
			}
			self::apply( $post_id, $kind, $r, $approved );
			$written = array_merge( $written, array_keys( $r ) );
		}
		update_post_meta( $post_id, '_omg_ai_written', wp_json_encode( array_values( array_diff( self::review_keys( $kind ), $approved ) ) ) );
		update_post_meta( $post_id, '_omg_ai_status', $errors ? 'error' : 'written' );
		update_post_meta( $post_id, '_omg_ai_log', implode( "\n", $errors ) );
		if ( ! $errors && 'auto' === self::settings()['review_rule'] && 'draft' === get_post_status( $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		}
	}
}
