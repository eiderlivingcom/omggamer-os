<?php
/**
 * Source data: OMGGamer OS (games), Steam Store, Kinguin, Icecat, manufacturer page and datasheet PDF.
 * Every function returns a normalised array or WP_Error.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS_Sources {

	private static function get_json( $url, $headers = array(), $timeout = 25 ) {
		$res = wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => $headers, 'user-agent' => 'OMGGamer-ProductStudio/' . OMG_PS_VERSION . '; ' . home_url( '/' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : ( is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code );
			return new WP_Error( 'omg_src_http', $msg, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : new WP_Error( 'omg_src_json', 'The source did not return JSON.' );
	}

	private static function text( $html, $max = 4000 ) {
		$t = wp_strip_all_tags( preg_replace( '#<br\s*/?>|</(p|li|h[1-6]|div)>#i', "\n", (string) $html ) );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = preg_replace( "/[ \t]+/", ' ', $t );
		$t = preg_replace( "/\n\s*\n+/", "\n", $t );
		return mb_substr( trim( $t ), 0, $max );
	}

	/* ---------------------------------------------------------------- OMGGamer OS */

	/** True when the OMGGamer catalogue connector is set up (it holds the OS address, storefront code and secret). */
	public static function os_ready() {
		return '' !== (string) get_option( 'omg_connector_secret', '' ) && '' !== self::os_code();
	}

	/**
	 * The storefront code in OMGGamer OS. AI Studio keeps its own copy so reading product data does not switch on
	 * the connector's checkout checks and order sending (which use the connector's own storefront code).
	 */
	public static function os_code() {
		$c = trim( (string) OMG_PS::settings()['os_storefront'] );
		return '' !== $c ? $c : (string) get_option( 'omg_connector_storefront', '' );
	}

	/**
	 * Everything OMGGamer OS knows about the product: all suppliers' data, attributes, system requirements,
	 * activation details and the OS AI text. Looked up by the OS product ID the connector stored on the
	 * product, or by title for products added by hand. Signed with the connector secret.
	 */
	public static function os( $post_id, $title = '' ) {
		if ( ! self::os_ready() ) {
			return new WP_Error( 'omg_os_off', 'OMGGamer OS is not connected. Set up the OMGGamer Catalogue Connector first.' );
		}
		$os_id = (string) get_post_meta( $post_id, '_omg_external_id', true );
		$title = '' !== $title ? $title : get_the_title( $post_id );
		$body  = wp_json_encode( $os_id ? array( 'product_id' => $os_id, 'title' => $title ) : array( 'title' => $title ) );
		$base  = rtrim( (string) get_option( 'omg_connector_os_url', 'https://os.omggamer.com' ), '/' );
		$code  = rawurlencode( self::os_code() );
		$ts    = (string) time();
		$res   = wp_remote_post( $base . '/api/v1/webhooks/storefronts/' . $code . '/product-facts', array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'X-OMG-Timestamp' => $ts,
				'X-OMG-Signature' => hash_hmac( 'sha256', $ts . '.' . $body, (string) get_option( 'omg_connector_secret', '' ) ),
			),
			'body'    => $body,
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'omg_os', 'OMGGamer OS: ' . $res->get_error_message() );
		}
		$code_http = wp_remote_retrieve_response_code( $res );
		$data      = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 404 === $code_http ) {
			return new WP_Error( 'omg_os_404', 'This product is not in OMGGamer OS yet. Publish it from os.omggamer.com, or the Steam step will search by title.' );
		}
		if ( $code_http >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code_http;
			return new WP_Error( 'omg_os', 'OMGGamer OS: ' . $msg );
		}
		$data['provider'] = 'os';
		return $data;
	}

	/* ---------------------------------------------------------------- Kinguin */

	public static function kinguin_base() {
		return OMG_PS::settings()['kinguin_sandbox'] ? 'https://gateway.sandbox.kinguin.net/esa/api' : 'https://gateway.kinguin.net/esa/api';
	}

	public static function kinguin_search( $q, $platform = '' ) {
		$key = OMG_PS::kinguin_key();
		if ( '' === $key ) {
			return new WP_Error( 'omg_kinguin_key', 'Kinguin is not connected yet. Add your Kinguin API key in Products › AI Studio.' );
		}
		$args = array( 'name' => $q, 'limit' => 20, 'page' => 1 );
		if ( $platform ) {
			$args['platform'] = $platform;
		}
		$d = self::get_json( add_query_arg( array_map( 'rawurlencode', $args ), self::kinguin_base() . '/v1/products' ), array( 'X-Api-Key' => $key ) );
		if ( is_wp_error( $d ) ) {
			return new WP_Error( 'omg_kinguin', 'Kinguin: ' . $d->get_error_message() );
		}
		$rows = array();
		foreach ( (array) ( isset( $d['results'] ) ? $d['results'] : array() ) as $r ) {
			$rows[] = array(
				'id'       => isset( $r['kinguinId'] ) ? (int) $r['kinguinId'] : 0,
				'name'     => isset( $r['name'] ) ? (string) $r['name'] : '',
				'platform' => isset( $r['platform'] ) ? (string) $r['platform'] : '',
				'region'   => isset( $r['regionalLimitations'] ) ? (string) $r['regionalLimitations'] : '',
				'price'    => isset( $r['price'] ) ? (float) $r['price'] : 0,
				'offers'   => isset( $r['offersCount'] ) ? (int) $r['offersCount'] : ( isset( $r['offers'] ) && is_array( $r['offers'] ) ? count( $r['offers'] ) : 0 ),
				'qty'      => isset( $r['qty'] ) ? (int) $r['qty'] : 0,
				'cover'    => isset( $r['images']['cover']['thumbnail'] ) ? esc_url_raw( $r['images']['cover']['thumbnail'] ) : '',
			);
		}
		return array( 'rows' => $rows, 'total' => isset( $d['item_count'] ) ? (int) $d['item_count'] : count( $rows ) );
	}

	public static function kinguin_product( $kinguin_id ) {
		$key = OMG_PS::kinguin_key();
		if ( '' === $key ) {
			return new WP_Error( 'omg_kinguin_key', 'Kinguin is not connected yet. Add your Kinguin API key in Products › AI Studio.' );
		}
		$r = self::get_json( self::kinguin_base() . '/v1/products/' . absint( $kinguin_id ), array( 'X-Api-Key' => $key ) );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'omg_kinguin', 'Kinguin: ' . $r->get_error_message() );
		}
		$req = array();
		foreach ( (array) ( isset( $r['systemRequirements'] ) ? $r['systemRequirements'] : array() ) as $sr ) {
			$req[] = array( 'system' => isset( $sr['system'] ) ? (string) $sr['system'] : '', 'lines' => array_slice( array_map( 'strval', (array) ( isset( $sr['requirement'] ) ? $sr['requirement'] : array() ) ), 0, 20 ) );
		}
		return array(
			'provider'            => 'kinguin',
			'kinguin_id'          => isset( $r['kinguinId'] ) ? (int) $r['kinguinId'] : absint( $kinguin_id ),
			'product_id'          => isset( $r['productId'] ) ? (string) $r['productId'] : '',
			'name'                => isset( $r['name'] ) ? (string) $r['name'] : '',
			'original_name'       => isset( $r['originalName'] ) ? (string) $r['originalName'] : '',
			'platform'            => isset( $r['platform'] ) ? (string) $r['platform'] : '',
			'region'              => isset( $r['regionalLimitations'] ) ? (string) $r['regionalLimitations'] : '',
			'lowest_price'        => isset( $r['price'] ) ? (float) $r['price'] : 0,
			'offers'              => isset( $r['offersCount'] ) ? (int) $r['offersCount'] : 0,
			'stock'               => isset( $r['totalQty'] ) ? (int) $r['totalQty'] : ( isset( $r['qty'] ) ? (int) $r['qty'] : 0 ),
			'developers'          => array_map( 'strval', (array) ( isset( $r['developers'] ) ? $r['developers'] : array() ) ),
			'publishers'          => array_map( 'strval', (array) ( isset( $r['publishers'] ) ? $r['publishers'] : array() ) ),
			'genres'              => array_map( 'strval', (array) ( isset( $r['genres'] ) ? $r['genres'] : array() ) ),
			'tags'                => array_slice( array_map( 'strval', (array) ( isset( $r['tags'] ) ? $r['tags'] : array() ) ), 0, 20 ),
			'languages'           => array_slice( array_map( 'strval', (array) ( isset( $r['languages'] ) ? $r['languages'] : array() ) ), 0, 40 ),
			'release_date'        => isset( $r['releaseDate'] ) ? substr( (string) $r['releaseDate'], 0, 10 ) : '',
			'metacritic'          => isset( $r['metacriticScore'] ) ? (string) $r['metacriticScore'] : '',
			'age_rating'          => isset( $r['ageRating'] ) ? (string) $r['ageRating'] : '',
			'is_preorder'         => ! empty( $r['isPreorder'] ),
			'activation_details'  => isset( $r['activationDetails'] ) ? self::text( $r['activationDetails'], 1500 ) : '',
			'description'         => isset( $r['description'] ) ? self::text( $r['description'], 5000 ) : '',
			'system_requirements' => $req,
			'steam_app_id'        => isset( $r['steam'] ) ? (string) $r['steam'] : '',
			'videos'              => array_slice( array_map( function ( $v ) {
				return is_array( $v ) && isset( $v['video_id'] ) ? (string) $v['video_id'] : '';
			}, (array) ( isset( $r['videos'] ) ? $r['videos'] : array() ) ), 0, 3 ),
			'screenshot_count'    => isset( $r['images']['screenshots'] ) ? count( (array) $r['images']['screenshots'] ) : 0,
		);
	}

	/* ---------------------------------------------------------------- Steam Store (no key) */

	public static function clean_game_name( $name ) {
		$n = preg_replace( '/\([^)]*\)|\[[^\]]*\]/', ' ', (string) $name );
		$n = preg_replace( '/\b(pc|mac|steam|epic|gog|uplay|ubisoft connect|origin|ea app|xbox( one| series x\|s| series)?|ps[345]|playstation \d|nintendo|switch|account|cd[- ]?key|key|digital|code|global|europe|eu|us|na|row|uk|dlc|gift)\b/i', ' ', $n );
		$n = preg_replace( '/\b(standard|deluxe|gold|ultimate|premium|complete|definitive|goty|game of the year|collector\'?s?|special|enhanced|anniversary)\s+edition\b/i', ' ', $n );
		$n = preg_replace( '/[:\-–|]+\s*$/', '', trim( preg_replace( '/\s+/', ' ', $n ) ) );
		return trim( $n, " :-–|" );
	}

	public static function steam( $name, $app_id = '' ) {
		if ( ! $app_id ) {
			$term = self::clean_game_name( $name );
			if ( '' === $term ) {
				return new WP_Error( 'omg_steam', 'No product name to search Steam with.' );
			}
			$s = self::get_json( 'https://store.steampowered.com/api/storesearch/?' . http_build_query( array( 'term' => $term, 'cc' => 'us', 'l' => 'english' ) ) );
			if ( is_wp_error( $s ) ) {
				return new WP_Error( 'omg_steam', 'Steam: ' . $s->get_error_message() );
			}
			if ( empty( $s['items'][0]['id'] ) ) {
				return new WP_Error( 'omg_steam_none', 'Steam has no match for "' . $term . '".' );
			}
			$app_id = (string) $s['items'][0]['id'];
		}
		$d = self::get_json( 'https://store.steampowered.com/api/appdetails?' . http_build_query( array( 'appids' => $app_id, 'cc' => 'us', 'l' => 'english' ) ) );
		if ( is_wp_error( $d ) ) {
			return new WP_Error( 'omg_steam', 'Steam: ' . $d->get_error_message() );
		}
		$a = isset( $d[ $app_id ]['data'] ) ? $d[ $app_id ]['data'] : null;
		if ( ! $a ) {
			return new WP_Error( 'omg_steam_none', 'Steam has no details for app ' . $app_id . '.' );
		}
		$req = function ( $html ) {
			$lines = array();
			preg_match_all( '#<li[^>]*>(.*?)</li>#is', (string) $html, $m );
			foreach ( $m[1] as $li ) {
				$t = trim( html_entity_decode( wp_strip_all_tags( $li ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $t ) {
					$lines[] = preg_replace( '/\s+/', ' ', $t );
				}
			}
			return $lines;
		};
		$pc = isset( $a['pc_requirements'] ) && is_array( $a['pc_requirements'] ) ? $a['pc_requirements'] : array();
		return array(
			'provider'     => 'steam',
			'app_id'       => (string) $app_id,
			'name'         => isset( $a['name'] ) ? (string) $a['name'] : '',
			'type'         => isset( $a['type'] ) ? (string) $a['type'] : '',
			'developers'   => array_map( 'strval', (array) ( isset( $a['developers'] ) ? $a['developers'] : array() ) ),
			'publishers'   => array_map( 'strval', (array) ( isset( $a['publishers'] ) ? $a['publishers'] : array() ) ),
			'genres'       => array_map( function ( $g ) {
				return isset( $g['description'] ) ? (string) $g['description'] : '';
			}, (array) ( isset( $a['genres'] ) ? $a['genres'] : array() ) ),
			'release_date' => isset( $a['release_date']['date'] ) ? (string) $a['release_date']['date'] : '',
			'metacritic'   => isset( $a['metacritic']['score'] ) ? (string) $a['metacritic']['score'] : '',
			'required_age' => isset( $a['required_age'] ) ? (string) $a['required_age'] : '',
			'languages'    => isset( $a['supported_languages'] ) ? self::text( str_replace( '<br>', ' ', $a['supported_languages'] ), 600 ) : '',
			'short'        => isset( $a['short_description'] ) ? self::text( $a['short_description'], 600 ) : '',
			'about'        => isset( $a['about_the_game'] ) ? self::text( $a['about_the_game'], 3500 ) : '',
			'requirements' => array(
				'minimum'     => isset( $pc['minimum'] ) ? $req( $pc['minimum'] ) : array(),
				'recommended' => isset( $pc['recommended'] ) ? $req( $pc['recommended'] ) : array(),
			),
		);
	}

	/* ---------------------------------------------------------------- Icecat (open catalogue) */

	public static function icecat( $ean, $brand = '', $mpn = '' ) {
		$user = trim( (string) OMG_PS::settings()['icecat_user'] );
		if ( '' === $user ) {
			return new WP_Error( 'omg_icecat_user', 'Icecat is not connected. Add your free Icecat username in Products › AI Studio.' );
		}
		$args = array( 'UserName' => $user, 'Language' => 'en' );
		if ( $ean ) {
			$args['GTIN'] = preg_replace( '/\D/', '', $ean );
		} elseif ( $brand && $mpn ) {
			$args['Brand']       = $brand;
			$args['ProductCode'] = $mpn;
		} else {
			return new WP_Error( 'omg_icecat_id', 'Add an EAN, or a brand and manufacturer part number, to look the product up on Icecat.' );
		}
		$d = self::get_json( 'https://live.icecat.biz/api?' . http_build_query( $args ) );
		if ( is_wp_error( $d ) ) {
			return new WP_Error( 'omg_icecat', 'Icecat: ' . $d->get_error_message() );
		}
		$g = isset( $d['data']['GeneralInfo'] ) ? $d['data']['GeneralInfo'] : array();
		$specs = array();
		foreach ( (array) ( isset( $d['data']['FeaturesGroups'] ) ? $d['data']['FeaturesGroups'] : array() ) as $grp ) {
			$gname = isset( $grp['FeatureGroup']['Name']['Value'] ) ? (string) $grp['FeatureGroup']['Name']['Value'] : 'Specs';
			foreach ( (array) ( isset( $grp['Features'] ) ? $grp['Features'] : array() ) as $f ) {
				if ( isset( $f['Feature']['Name']['Value'], $f['PresentationValue'] ) && count( $specs ) < 120 ) {
					$specs[ $gname ][ (string) $f['Feature']['Name']['Value'] ] = (string) $f['PresentationValue'];
				}
			}
		}
		return array(
			'provider'    => 'icecat',
			'title'       => isset( $g['Title'] ) ? (string) $g['Title'] : '',
			'brand'       => isset( $g['Brand'] ) ? (string) $g['Brand'] : '',
			'mpn'         => isset( $g['BrandPartCode'] ) ? (string) $g['BrandPartCode'] : '',
			'summary'     => isset( $g['SummaryDescription']['LongSummaryDescription'] ) ? self::text( $g['SummaryDescription']['LongSummaryDescription'], 800 ) : '',
			'description' => isset( $g['Description']['LongDesc'] ) ? self::text( $g['Description']['LongDesc'], 3000 ) : '',
			'specs'       => $specs,
		);
	}

	/* ---------------------------------------------------------------- Manufacturer product page */

	public static function page( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( ! $url ) {
			return new WP_Error( 'omg_page_url', 'Add the manufacturer product page URL first.' );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$res = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 4, 'limit_response_size' => 3 * MB_IN_BYTES, 'user-agent' => 'Mozilla/5.0 (compatible; OMGGamer-ProductStudio/' . OMG_PS_VERSION . ')' ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'omg_page', 'Manufacturer page: ' . $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 ) {
			return new WP_Error( 'omg_page', 'Manufacturer page answered HTTP ' . $code . '. Some sites block automatic reading; upload the datasheet PDF instead.' );
		}
		$html = (string) wp_remote_retrieve_body( $res );
		$title = preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ? self::text( $m[1], 200 ) : '';
		$desc  = preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m ) ? self::text( $m[1], 400 ) : '';
		$body  = preg_replace( '#<(script|style|noscript|svg|nav|footer|header|form|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text  = self::text( $body, 14000 );
		if ( mb_strlen( $text ) < 200 ) {
			return new WP_Error( 'omg_page_empty', 'The manufacturer page has almost no readable text (it may load with JavaScript). Upload the datasheet PDF instead.' );
		}
		return array( 'provider' => 'manufacturer', 'url' => $url, 'title' => $title, 'meta_description' => $desc, 'text' => $text );
	}

	/* ---------------------------------------------------------------- Datasheet PDF (read by the AI) */

	public static function datasheet( $attachment_id ) {
		$path = get_attached_file( absint( $attachment_id ) );
		if ( ! $path || ! is_readable( $path ) || 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return new WP_Error( 'omg_pdf', 'Upload a datasheet PDF first.' );
		}
		if ( filesize( $path ) >= 9 * MB_IN_BYTES ) {
			return new WP_Error( 'omg_pdf_size', 'The PDF is larger than 9 MB. Upload a smaller datasheet.' );
		}
		$text = OMG_PS::call_ai(
			'You read product datasheets and return the facts as JSON. Reply with one JSON object only.',
			'Read the attached datasheet. Return {"product":"name","brand":"","specs":{"Spec name":"value with unit"},"in_the_box":["item"],"compatibility":["requirement"],"features":["short feature"]}. Only include facts printed in the datasheet. At most 40 specs.',
			2500,
			$path
		);
		if ( is_wp_error( $text ) ) {
			return new WP_Error( 'omg_pdf_ai', 'Datasheet: ' . $text->get_error_message() );
		}
		$d = OMG_PS::parse_json( $text );
		if ( ! $d ) {
			return new WP_Error( 'omg_pdf_json', 'The datasheet could not be read. Try again or use the manufacturer page.' );
		}
		$d = map_deep( $d, 'sanitize_text_field' );
		$d['provider'] = 'datasheet';
		$d['file']     = basename( $path );
		return $d;
	}
}
