<?php
/**
 * Admin: the product editor (type cards, source data, content, attributes, page sections,
 * media, SEO, pricing, sync, AI settings), settings page and the Kinguin import page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OMG_PS_Admin {

	public static function init() {
		$s = new self();
		add_action( 'admin_menu', array( $s, 'menu' ) );
		add_action( 'admin_init', array( $s, 'register_settings' ) );
		add_action( 'edit_form_after_title', array( $s, 'editor' ) );
		add_action( 'add_meta_boxes_product', array( $s, 'meta_boxes' ), 30 );
		add_action( 'post_submitbox_misc_actions', array( $s, 'submitbox' ) );
		add_action( 'post_submitbox_start', array( $s, 'submitbox_start' ) );
		add_filter( 'rwmb_meta_boxes', array( $s, 'trim_meta_box' ), 999 );
		add_filter( 'wp_insert_post_data', array( $s, 'post_data' ), 20, 2 );
		add_action( 'woocommerce_process_product_meta', array( $s, 'save' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $s, 'assets' ) );
		add_filter( 'admin_body_class', array( $s, 'body_class' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OMG_PS_FILE ), array( $s, 'action_links' ) );
		foreach ( array( 'source', 'generate', 'test', 'import', 'queue' ) as $a ) {
			add_action( 'wp_ajax_omg_ps_' . $a, array( $s, 'ajax_' . $a ) );
		}
	}

	private function is_product_screen() {
		$sc = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $sc && 'product' === $sc->post_type && 'post' === $sc->base;
	}

	public function body_class( $c ) {
		return $this->is_product_screen() ? $c . ' omg-ps-on' : $c;
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=omg-ai-studio' ) ) . '">Settings</a>' );
		return $links;
	}

	/* ================================================================ menu + settings */

	public function menu() {
		add_submenu_page( 'edit.php?post_type=product', 'Import from APIs', 'Import from APIs', 'manage_woocommerce', 'omg-ps-import', array( $this, 'import_page' ) );
		add_submenu_page( 'edit.php?post_type=product', 'AI Product Studio', 'AI Studio', 'manage_woocommerce', 'omg-ai-studio', array( $this, 'settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'omg_ai_studio', OMG_PS::OPT, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = OMG_PS::settings();
		$out['provider']        = isset( $in['provider'], OMG_PS::PROVIDERS[ $in['provider'] ] ) ? $in['provider'] : 'anthropic';
		$out['model']           = isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : '';
		$out['base_url']        = isset( $in['base_url'] ) ? esc_url_raw( trim( $in['base_url'] ) ) : '';
		$out['fallbacks']       = empty( $in['fallbacks'] ) ? 0 : 1;
		$out['brand_voice']     = isset( $in['brand_voice'] ) ? sanitize_textarea_field( $in['brand_voice'] ) : $out['brand_voice'];
		$out['tone']            = isset( $in['tone'], OMG_PS::TONES[ $in['tone'] ] ) ? $in['tone'] : 'informative';
		$out['language']        = isset( $in['language'] ) ? sanitize_text_field( $in['language'] ) : 'English';
		$out['kinguin_sandbox'] = empty( $in['kinguin_sandbox'] ) ? 0 : 1;
		$out['icecat_user']     = isset( $in['icecat_user'] ) ? sanitize_text_field( $in['icecat_user'] ) : '';
		$out['review_rule']     = isset( $in['review_rule'] ) && 'auto' === $in['review_rule'] ? 'auto' : 'human';
		$out['margin']          = isset( $in['margin'] ) ? max( 0, min( 300, (float) $in['margin'] ) ) : 18;
		$out['auto_rewrite']    = empty( $in['auto_rewrite'] ) ? 0 : 1;
		$out['os_storefront']   = isset( $in['os_storefront'] ) ? sanitize_key( $in['os_storefront'] ) : $out['os_storefront'];
		$out['prompt_short']    = isset( $in['prompt_short'] ) ? mb_substr( sanitize_textarea_field( $in['prompt_short'] ), 0, 3000 ) : $out['prompt_short'];
		$out['prompt_long']     = isset( $in['prompt_long'] ) ? mb_substr( sanitize_textarea_field( $in['prompt_long'] ), 0, 3000 ) : $out['prompt_long'];
		$map = array();
		foreach ( array( 'digital', 'account', 'giftcard', 'hardware', 'accessory' ) as $k ) {
			$map[ $k ] = isset( $in['layout_map'][ $k ] ) ? absint( $in['layout_map'][ $k ] ) : 0;
		}
		$out['layout_map'] = $map;
		foreach ( array( 'api_key' => OMG_PS::KEY_OPT, 'kinguin_key' => OMG_PS::KINGUIN ) as $field => $opt ) {
			if ( isset( $in[ $field ] ) && '' !== trim( (string) $in[ $field ] ) ) {
				update_option( $opt, sanitize_text_field( trim( (string) $in[ $field ] ) ), false );
			}
			if ( ! empty( $in[ 'clear_' . $field ] ) ) {
				delete_option( $opt );
			}
		}
		return $out;
	}

	private function templates() {
		$q = get_posts( array( 'post_type' => 'bricks_template', 'post_status' => 'publish', 'numberposts' => 50, 'meta_key' => '_bricks_template_type', 'meta_value' => 'wc_product', 'orderby' => 'title', 'order' => 'ASC' ) );
		$out = array();
		foreach ( $q as $t ) {
			$out[ $t->ID ] = $t->post_title;
		}
		return $out;
	}

	private function secret_row( $label, $field, $has, $const, $help ) {
		$n = esc_attr( OMG_PS::OPT );
		echo '<tr><th><label for="omg-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $const ) {
			echo '<p><strong>Set in wp-config.php.</strong> Remove the constant to manage it here.</p>';
		} else {
			echo '<input id="omg-' . esc_attr( $field ) . '" type="password" class="regular-text" autocomplete="new-password" name="' . $n . '[' . esc_attr( $field ) . ']" value="" placeholder="' . esc_attr( $has ? 'Saved. Leave empty to keep it.' : 'Paste the key' ) . '">';
			if ( $has ) {
				echo ' <label><input type="checkbox" name="' . $n . '[clear_' . esc_attr( $field ) . ']" value="1"> Remove saved key</label>';
			}
		}
		echo '<p class="description">' . wp_kses_post( $help ) . '</p></td></tr>';
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = OMG_PS::settings();
		$n = esc_attr( OMG_PS::OPT );
		$templates = $this->templates();
		?>
		<div class="wrap omgps-settings">
			<h1><span class="omgps-spark">&#10022;</span> AI Product Studio</h1>
			<p class="description">Connect the AI provider and the product data sources, and choose which product page each product type uses.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'omg_ai_studio' ); ?>
				<div class="omgps-card"><h2>AI provider</h2><table class="form-table" role="presentation">
					<tr><th><label for="omg-ai-provider">Provider</label></th><td><select id="omg-ai-provider" name="<?php echo $n; ?>[provider]"><?php foreach ( OMG_PS::PROVIDERS as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['provider'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
					<?php $this->secret_row( 'API key', 'api_key', '' !== OMG_PS::api_key(), defined( 'OMG_AI_API_KEY' ) && OMG_AI_API_KEY, 'Stored on the server and never sent to the browser. You can define <code>OMG_AI_API_KEY</code> in wp-config.php instead.' ); ?>
					<tr><th><label for="omg-ai-model">Model</label></th><td><input id="omg-ai-model" type="text" class="regular-text" name="<?php echo $n; ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="<?php echo esc_attr( OMG_PS::default_model( $s['provider'] ) ); ?>"><p class="description">Leave empty for the default: OmniRoute <code>free-stack</code> (your combo of free Gemini models), Anthropic <code>claude-opus-5-5</code>, OpenAI <code>gpt-4o-mini</code>, OpenRouter <code>anthropic/claude-opus-5-5</code>. OpenAI-compatible providers need a model name.</p></td></tr>
					<tr class="omgps-base"><th><label for="omg-ai-base">API base URL</label></th><td><input id="omg-ai-base" type="url" class="regular-text" name="<?php echo $n; ?>[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" placeholder="https://omniroute.1wp.site/v1"><p class="description">For OmniRoute leave empty (it uses https://omniroute.1wp.site/v1). For any other OpenAI-compatible gateway enter its address. The plugin calls <code>{base}/chat/completions</code>.</p></td></tr>
					<tr class="omgps-fb"><th>Refusal fallback</th><td><label><input type="checkbox" name="<?php echo $n; ?>[fallbacks]" value="1" <?php checked( $s['fallbacks'], 1 ); ?>> Let Anthropic retry a declined request on a fallback model</label></td></tr>
					<tr><th>Connection</th><td><button type="button" class="button" id="omgps-test">Test connection</button> <span id="omgps-test-out" class="omgps-muted"></span><p class="description">Save first, then test. The test sends one very short request.</p></td></tr>
				</table></div>
				<div class="omgps-card"><h2>Product data sources</h2><table class="form-table" role="presentation">
					<?php $this->secret_row( 'Kinguin API key', 'kinguin_key', '' !== OMG_PS::kinguin_key(), defined( 'OMG_KINGUIN_API_KEY' ) && OMG_KINGUIN_API_KEY, 'From your Kinguin Integration account (eCommerce API). Used to search products, read price, stock and product data. You can define <code>OMG_KINGUIN_API_KEY</code> in wp-config.php instead.' ); ?>
					<tr><th>Kinguin sandbox</th><td><label><input type="checkbox" name="<?php echo $n; ?>[kinguin_sandbox]" value="1" <?php checked( $s['kinguin_sandbox'], 1 ); ?>> Use the Kinguin sandbox (for testing)</label></td></tr>
					<tr><th><label for="omg-os-code">OMGGamer OS storefront code</label></th><td><input id="omg-os-code" type="text" class="regular-text" name="<?php echo $n; ?>[os_storefront]" value="<?php echo esc_attr( $s['os_storefront'] ); ?>" placeholder="omggamer-staging"><p class="description"><?php echo OMG_PS_Sources::os_ready() ? 'Connected. Game pages are written from the OMGGamer OS data (all suppliers, attributes, requirements), signed with the catalogue connector secret.' : 'Not connected. Enter this store&rsquo;s code from OMGGamer OS (Publishing &rarr; Storefronts); the catalogue connector secret must be set.'; ?> Only used to read product data; it does not change checkout or order sending.</p></td></tr>
					<tr><th>Steam Store</th><td><p>Connected. Steam's public store API needs no key: the studio reads system requirements, genres, credits and the release date.</p></td></tr>
					<tr><th><label for="omg-icecat">Icecat username</label></th><td><input id="omg-icecat" type="text" class="regular-text" name="<?php echo $n; ?>[icecat_user]" value="<?php echo esc_attr( $s['icecat_user'] ); ?>"><p class="description">Free Open Icecat account, used to look up hardware and accessories by EAN.</p></td></tr>
				</table></div>
				<div class="omgps-card"><h2>Writing</h2><table class="form-table" role="presentation">
					<tr><th><label for="omg-voice">Brand voice</label></th><td><textarea id="omg-voice" class="large-text" rows="5" name="<?php echo $n; ?>[brand_voice]"><?php echo esc_textarea( $s['brand_voice'] ); ?></textarea></td></tr>
					<tr><th><label for="omg-tone">Default tone</label></th><td><select id="omg-tone" name="<?php echo $n; ?>[tone]"><?php foreach ( OMG_PS::TONES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['tone'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="omg-lang">Default language</label></th><td><input id="omg-lang" type="text" class="regular-text" name="<?php echo $n; ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>"></td></tr>
				</table></div>
				<div class="omgps-card"><h2>Description prompts</h2><p class="description">Optional. When a prompt is filled in, the short or full description is written from it instead of the standard instructions. Use <code>{title}</code> where the product title goes. Add <code>{facts}</code> to also give the AI the OMGGamer OS and Steam data (recommended, so it does not guess). Leave empty to use the standard writer.</p><table class="form-table" role="presentation">
					<tr><th><label for="omg-p-short">Short description prompt</label></th><td><textarea id="omg-p-short" class="large-text" rows="5" name="<?php echo $n; ?>[prompt_short]" placeholder="Write a 30-word short description for {title}: what the buyer gets, the platform and region, and that delivery is instant. Facts: {facts}"><?php echo esc_textarea( $s['prompt_short'] ); ?></textarea></td></tr>
					<tr><th><label for="omg-p-long">Full description prompt</label></th><td><textarea id="omg-p-long" class="large-text" rows="8" name="<?php echo $n; ?>[prompt_long]" placeholder="Write a 200-word description of {title} for gamers: the story and gameplay, what is new, and what this listing delivers. Facts: {facts}"><?php echo esc_textarea( $s['prompt_long'] ); ?></textarea></td></tr>
				</table></div>
				<div class="omgps-card"><h2>Workflow</h2><table class="form-table" role="presentation">
					<tr><th>Publishing rule</th><td><select name="<?php echo $n; ?>[review_rule]"><option value="human" <?php selected( $s['review_rule'], 'human' ); ?>>Human review required</option><option value="auto" <?php selected( $s['review_rule'], 'auto' ); ?>>Publish automatically after AI writes it</option></select><p class="description">With human review, imported products stay drafts until someone approves and publishes them.</p></td></tr>
					<tr><th><label for="omg-margin">Default margin</label></th><td><input id="omg-margin" type="number" min="0" max="300" step="0.5" name="<?php echo $n; ?>[margin]" value="<?php echo esc_attr( $s['margin'] ); ?>"> %<p class="description">Sale price = source cost + margin, rounded to .99.</p></td></tr>
					<tr><th>Imports</th><td><label><input type="checkbox" name="<?php echo $n; ?>[auto_rewrite]" value="1" <?php checked( $s['auto_rewrite'], 1 ); ?>> Rewrite imported products with AI in the background</label></td></tr>
				</table></div>
				<div class="omgps-card"><h2>Product page layouts</h2><p class="description">Which Bricks product template each product type uses.</p><table class="form-table" role="presentation">
					<?php foreach ( array( 'digital' => 'Digital game: key page', 'account' => 'Digital game: account page', 'giftcard' => 'Digital game: gift card page', 'hardware' => 'Hardware', 'accessory' => 'Accessory' ) as $k => $l ) : ?>
					<tr><th><?php echo esc_html( $l ); ?></th><td><select name="<?php echo $n; ?>[layout_map][<?php echo esc_attr( $k ); ?>]"><option value="0">Automatic (by collection)</option><?php foreach ( $templates as $id => $title ) : ?><option value="<?php echo esc_attr( $id ); ?>" <?php selected( (int) $s['layout_map'][ $k ], $id ); ?>><?php echo esc_html( $title . ' (#' . $id . ')' ); ?></option><?php endforeach; ?></select></td></tr>
					<?php endforeach; ?>
				</table></div>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/* ================================================================ import page */

	public function import_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		global $wpdb;
		$counts = $wpdb->get_results( "SELECT meta_value AS s, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = '_omg_ai_status' GROUP BY meta_value", OBJECT_K ); // phpcs:ignore
		$c = function ( $k ) use ( $counts ) {
			return isset( $counts[ $k ] ) ? (int) $counts[ $k ]->n : 0;
		};
		$has_k = '' !== OMG_PS::kinguin_key();
		?>
		<div class="wrap omgps-import">
			<h1>Import from APIs</h1>
			<p class="description">Search Kinguin, import products as drafts with their source data, and let the AI rewrite them in the background. Drafts are not visible in the shop.</p>
			<div class="omgps-stats">
				<div><b><?php echo esc_html( $c( 'queued' ) ); ?></b><span>Waiting for AI</span></div>
				<div><b><?php echo esc_html( $c( 'written' ) ); ?></b><span>AI written, ready to review</span></div>
				<div><b><?php echo esc_html( $c( 'error' ) ); ?></b><span>Failed (see product)</span></div>
				<div><button type="button" class="button" id="omgps-queue-drafts">Rewrite all unwritten drafts</button><span class="omgps-muted" id="omgps-queue-out"></span></div>
			</div>
			<div class="omgps-card">
				<h2>Kinguin</h2>
				<?php if ( ! $has_k ) : ?><div class="omgps-notice">Kinguin is not connected yet. Add your Kinguin API key in <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=omg-ai-studio' ) ); ?>">AI Studio settings</a>.</div><?php endif; ?>
				<div class="omgps-search"><input type="search" id="omgps-imp-q" placeholder="Search Kinguin, e.g. Star Wars Outlaws"><select id="omgps-imp-pf"><?php echo $this->platform_options(); // phpcs:ignore ?></select><button type="button" class="button button-primary" id="omgps-imp-search" <?php disabled( ! $has_k ); ?>>Search API</button></div>
				<div id="omgps-imp-results"></div>
				<p><button type="button" class="button button-primary" id="omgps-imp-go" disabled>Import selected as drafts</button> <span id="omgps-imp-out" class="omgps-muted"></span></p>
			</div>
			<div class="omgps-card"><h2>CSV</h2><p>For a supplier CSV, use the <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product_importer' ) ); ?>">WooCommerce product importer</a>, then open each product and click <b>Fetch &amp; generate with AI</b>, or use <b>Rewrite all unwritten drafts</b> above.</p></div>
		</div>
		<?php
	}

	private function platform_options() {
		$o = '<option value="">All platforms</option>';
		foreach ( array( 'Steam', 'Xbox Live', 'PlayStation Network', 'Nintendo eShop', 'EA App', 'Ubisoft Connect', 'Epic Games', 'GOG COM', 'Battle.net', 'Rockstar Games Launcher' ) as $p ) {
			$o .= '<option value="' . esc_attr( $p ) . '">' . esc_html( $p ) . '</option>';
		}
		return $o;
	}

	/* ================================================================ product editor */

	public function meta_boxes( $post ) {
		remove_meta_box( 'omg-ai-studio', 'product', 'normal' );
		add_meta_box( 'omgps-pricing', 'Pricing', array( $this, 'box_pricing' ), 'product', 'side', 'high' );
		add_meta_box( 'omgps-sync', 'Sync &amp; automation', array( $this, 'box_sync' ), 'product', 'side', 'high' );
		add_meta_box( 'omgps-ai', '&#10022; AI writing settings', array( $this, 'box_ai' ), 'product', 'side', 'high' );
	}

	/** Removes the fields this editor manages from the Meta Box Builder group, keeping seller offers. */
	public function trim_meta_box( $boxes ) {
		if ( ! is_admin() ) {
			return $boxes;
		}
		$managed = array( 'omg_region_warning', 'omg_activation_steps', 'omg_system_requirements', 'omg_whats_included', 'omg_delivery_note', 'omg_compatibility', 'omg_pro_price', 'omg_warranty', 'omg_pills' );
		foreach ( $boxes as &$b ) {
			if ( isset( $b['id'] ) && 'omg-product-details' === $b['id'] && ! empty( $b['fields'] ) ) {
				$b['fields'] = array_values( array_filter( $b['fields'], function ( $f ) use ( $managed ) {
					return ! isset( $f['id'] ) || ! in_array( $f['id'], $managed, true );
				} ) );
				$b['title'] = 'Seller offers & platform';
			}
		}
		return $boxes;
	}

	private function json_meta( $id, $key ) {
		$v = json_decode( (string) get_post_meta( $id, $key, true ), true );
		return is_array( $v ) ? $v : array();
	}

	private function badge( $text, $cls = '' ) {
		return '<span class="omgps-badge ' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
	}

	private function field_actions( $key ) {
		return '<span class="omgps-acts"><button type="button" class="omgps-btn omgps-regen" data-group="' . esc_attr( $key ) . '">&#8635; Regenerate</button><button type="button" class="omgps-btn omgps-approve" data-key="' . esc_attr( $key ) . '"><span class="a">Approve</span><span class="b">&#10003; Approved</span></button></span>';
	}

	public function editor( $post ) {
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		$id       = $post->ID;
		$raw      = get_post( $id );
		$raw_title   = get_post_field( 'post_title', $id, 'raw' );
		$raw_excerpt = get_post_field( 'post_excerpt', $id, 'raw' );
		$raw_content = get_post_field( 'post_content', $id, 'raw' );
		$p        = wc_get_product( $id );
		$kind     = OMG_PS::kind( $id );
		$layout   = get_post_meta( $id, '_omg_layout', true );
		$layout   = isset( OMG_PS::digital_layouts()[ $layout ] ) ? $layout : 'auto';
		$approved = $this->json_meta( $id, '_omg_ai_approved' );
		$written  = $this->json_meta( $id, '_omg_ai_written' );
		$source   = $this->json_meta( $id, '_omg_source' );
		$attr_src = $this->json_meta( $id, '_omg_attr_src' );
		$chips    = $this->json_meta( $id, '_omg_sources' );
		$has_ai   = '' !== OMG_PS::api_key();
		$has_k    = '' !== OMG_PS::kinguin_key();
		$has_os   = OMG_PS_Sources::os_ready();
		$os_id    = (string) get_post_meta( $id, '_omg_external_id', true );
		$ice      = '' !== trim( OMG_PS::settings()['icecat_user'] );
		$status   = get_post_meta( $id, '_omg_ai_status', true );
		$specs    = OMG_PS::specs( $id );
		$pdf_id   = (int) get_post_meta( $id, 'omg_datasheet', true );
		wp_nonce_field( OMG_PS::NONCE, 'omg_ps_nonce' );
		?>
		<div id="omgps" class="omgps" data-post="<?php echo esc_attr( $id ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-published="<?php echo 'publish' === $post->post_status ? '1' : '0'; ?>" data-has-ai="<?php echo $has_ai ? '1' : '0'; ?>">
			<input type="hidden" name="omg_ps[on]" value="1">
			<input type="hidden" name="omg_ps[approved]" class="omgps-approved" value="<?php echo esc_attr( wp_json_encode( array_values( $approved ) ) ); ?>">
			<input type="hidden" name="omg_ps[written]" class="omgps-written" value="<?php echo esc_attr( wp_json_encode( array_values( $written ) ) ); ?>">
			<input type="hidden" name="omg_ps[source]" class="omgps-source" value="<?php echo esc_attr( wp_json_encode( $source ? $source : new stdClass() ) ); ?>">
			<input type="hidden" name="omg_ps[attr_src]" class="omgps-attr-src" value="<?php echo esc_attr( wp_json_encode( $attr_src ? $attr_src : new stdClass() ) ); ?>">

			<?php if ( ! $has_ai ) : ?><div class="omgps-notice">AI writing is off until you connect a provider in <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=omg-ai-studio' ) ); ?>">AI Studio settings</a>. Every field below can still be edited by hand.</div><?php endif; ?>
			<?php if ( 'error' === $status ) : ?><div class="omgps-notice">The background AI rewrite failed: <?php echo esc_html( get_post_meta( $id, '_omg_ai_log', true ) ); ?></div><?php endif; ?>
			<?php if ( 'queued' === $status ) : ?><div class="omgps-notice is-info">This product is waiting in the AI rewrite queue.</div><?php endif; ?>

			<!-- product type -->
			<div class="omgps-kinds" role="radiogroup" aria-label="Product type">
				<?php foreach ( OMG_PS::kinds() as $k => $l ) : ?>
					<label class="omgps-kind<?php echo $kind === $k ? ' is-on' : ''; ?>"><span class="ic"><?php echo $l[2]; // static entity ?></span><span class="t"><b><?php echo esc_html( $l[0] ); ?></b><small><?php echo esc_html( $l[1] ); ?></small></span><input type="radio" name="omg_ps[kind]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $kind, $k ); ?>></label>
				<?php endforeach; ?>
			</div>
			<div class="omgps-layoutrow" data-for="digital"><label>Product page <select name="omg_ps[layout]"><?php foreach ( OMG_PS::digital_layouts() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $layout, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label><span class="omgps-muted">Digital products are delivered without shipping, so checkout skips the address.</span></div>
			<div class="omgps-layoutrow" data-for="hardware accessory"><span class="omgps-muted">Physical products ship to the buyer, so checkout asks for the address. The page uses the hardware &amp; accessory layout.</span></div>

			<!-- 1 · source data -->
			<section class="omgps-card" id="omgps-source">
				<header><h3>1 · Source data</h3><span class="sub" data-for="digital">OMGGamer OS supplies the product data; Steam fills gaps by title</span><span class="sub" data-for="hardware accessory">Identify the product, AI reads the rest</span></header>
				<div class="omgps-chips" data-for="digital">
					<?php $this->chip( 'os', 'OMGGamer OS', 'All suppliers, attributes, requirements', $has_os, $chips, 'Set up the OMGGamer Catalogue Connector' ); ?>
					<?php $this->chip( 'steam', 'Steam Store', 'Search by title: credits, release, requirements', true, $chips ); ?>
				</div>
				<div data-for="digital" class="omgps-os-link">
					<?php if ( $os_id ) : ?><p class="omgps-muted">Linked to OMGGamer OS product <code><?php echo esc_html( $os_id ); ?></code>.<?php if ( ! empty( $source['os']['title'] ) ) : ?> Last data: <b><?php echo esc_html( $source['os']['title'] ); ?></b>.<?php endif; ?></p>
					<?php else : ?><p class="omgps-muted">Not published from OMGGamer OS: the OS is searched by the product title.</p><?php endif; ?>
				</div>
				<div class="omgps-chips" data-for="hardware accessory">
					<?php $this->chip( 'manufacturer', 'Manufacturer page', 'Official specs & text', true, $chips ); ?>
					<?php $this->chip( 'datasheet', 'Datasheet PDF', 'Specs, dimensions, box contents', $has_ai, $chips, 'Needs the AI provider' ); ?>
					<?php $this->chip( 'icecat', 'Icecat', 'Open product catalogue', $ice, $chips, 'Add the Icecat username in settings' ); ?>
					<?php $this->chip( 'distributor', 'Custom feed', 'Distributor API', false, $chips, 'Ask us to add a feed' ); ?>
				</div>
				<div class="omgps-grid2" data-for="hardware accessory">
					<label>EAN / UPC<input type="text" class="omgps-sync-ean" value="<?php echo esc_attr( OMG_PS::get_attr( $id, array( '', 'ean', '' ) )[0] ); ?>" placeholder="4711377237510"></label>
					<label>Manufacturer part no.<input type="text" name="omg_ps[mpn]" value="<?php echo esc_attr( get_post_meta( $id, 'omg_mpn', true ) ); ?>" placeholder="G5070-12GTOC"></label>
					<label class="full">Manufacturer product page<input type="url" name="omg_ps[mfr_url]" class="omgps-mfr" value="<?php echo esc_attr( get_post_meta( $id, 'omg_mfr_url', true ) ); ?>" placeholder="https://www.msi.com/Graphics-Card/..."></label>
					<div class="full omgps-pdf"><span class="pdf">PDF</span><div><b class="omgps-pdf-name"><?php echo $pdf_id ? esc_html( basename( (string) get_attached_file( $pdf_id ) ) ) : 'No datasheet yet'; ?></b><small>Datasheet · AI reads specs, dimensions and box contents from it</small></div><input type="hidden" name="omg_ps[datasheet]" class="omgps-pdf-id" value="<?php echo esc_attr( $pdf_id ? $pdf_id : '' ); ?>"><button type="button" class="omgps-btn omgps-pdf-pick"><?php echo $pdf_id ? 'Replace file' : 'Upload PDF'; ?></button></div>
				</div>
				<div class="omgps-gen">
					<button type="button" class="omgps-btn is-ai omgps-fetchgen" <?php disabled( ! $has_ai ); ?>>&#10022; Fetch &amp; generate with AI</button>
					<span class="omgps-muted">Fetches the selected sources, then writes every field below. Fields you approve or edit are never overwritten.</span>
				</div>
				<ol class="omgps-steps" hidden></ol>
				<div class="omgps-msg" aria-live="polite"></div>
			</section>

			<!-- 2 · product content -->
			<section class="omgps-card" id="omgps-content">
				<header><h3>2 · Product content</h3><span class="sub">Written in the OMGGamer brand voice</span></header>
				<div class="omgps-field" data-key="title">
					<div class="fh"><b>Product title</b><?php echo $this->badge( 'AI written', 'ai' ); ?><span class="cnt" data-count="chars"></span><?php echo $this->field_actions( 'title' ); // phpcs:ignore ?></div>
					<input type="text" class="omgps-title" name="omg_ps[title]" value="<?php echo esc_attr( $raw_title ); ?>" placeholder="Product name">
				</div>
				<div class="omgps-field" data-key="short_description">
					<div class="fh"><b>Short description</b><?php echo $this->badge( 'AI written', 'ai' ); ?><span class="cnt">Shown under the price</span><?php echo $this->field_actions( 'short_description' ); // phpcs:ignore ?></div>
					<textarea rows="3" class="omgps-short" name="omg_ps[short]"><?php echo esc_textarea( $raw_excerpt ); ?></textarea>
				</div>
				<div class="omgps-field" data-key="description">
					<div class="fh"><b>Full description</b><?php echo $this->badge( 'AI written', 'ai' ); ?><span class="cnt" data-count="words"></span><?php echo $this->field_actions( 'description' ); // phpcs:ignore ?></div>
					<div class="omgps-tone"><span>Tone</span><?php $tone = get_post_meta( $id, '_omg_ai_tone', true ) ?: OMG_PS::settings()['tone']; foreach ( OMG_PS::TONES as $k => $l ) : ?><label class="<?php echo $tone === $k ? 'is-on' : ''; ?>"><input type="radio" name="omg_ps[tone]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $tone, $k ); ?>><?php echo esc_html( $l ); ?></label><?php endforeach; ?></div>
					<?php wp_editor( $raw_content, 'omgpsdesc', array( 'textarea_name' => 'omg_ps[desc]', 'media_buttons' => false, 'textarea_rows' => 12, 'tinymce' => array( 'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo', 'toolbar2' => '' ), 'quicktags' => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ) ) ); ?>
				</div>
			</section>

			<!-- attributes -->
			<section class="omgps-card" id="omgps-attrs" data-key="attributes">
				<header><h3>Attributes</h3><span class="sub" data-for="digital">From OMGGamer OS data, Steam for gaps</span><span class="sub" data-for="hardware accessory">Mapped from manufacturer feed + datasheet</span><?php echo $this->badge( 'AI written', 'ai' ); ?><?php echo $this->field_actions( 'attributes' ); // phpcs:ignore ?></header>
				<?php foreach ( OMG_PS::kinds() as $k => $l ) : ?>
					<div class="omgps-attrgrid" data-for="<?php echo esc_attr( $k ); ?>">
						<?php foreach ( OMG_PS::attr_defs( $k ) as $ak => $def ) :
							list( $val, $locked ) = OMG_PS::get_attr( $id, $def );
							$src = isset( $attr_src[ $ak ] ) ? $attr_src[ $ak ] : ( '' !== $val ? 'product' : '' );
							?>
							<label class="omgps-attr<?php echo $locked ? ' is-locked' : ''; ?>" title="<?php echo esc_attr( $locked ); ?>"><span class="l"><?php echo esc_html( $def[0] ); ?></span>
								<input type="<?php echo 'release_date' === $ak ? 'date' : 'text'; ?>" data-attr="<?php echo esc_attr( $ak ); ?>" <?php echo 'ean' === $ak ? 'class="omgps-sync-ean"' : ''; ?> name="omg_ps[attr][<?php echo esc_attr( $k ); ?>][<?php echo esc_attr( $ak ); ?>]" value="<?php echo esc_attr( $val ); ?>" <?php echo $locked ? 'readonly' : ''; ?>>
								<input type="hidden" name="omg_ps[attr_orig][<?php echo esc_attr( $k ); ?>][<?php echo esc_attr( $ak ); ?>]" value="<?php echo esc_attr( $val ); ?>">
								<i class="dot" data-src="<?php echo esc_attr( $src ); ?>"></i></label>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<div class="omgps-custom" data-for="hardware accessory">
					<div class="omgps-custom-rows"><?php foreach ( $specs['custom'] as $row ) : if ( ! isset( $row[0], $row[1] ) ) { continue; } ?><div class="omgps-crow"><input type="text" class="cl" value="<?php echo esc_attr( $row[0] ); ?>" placeholder="Spec"><input type="text" class="cv" value="<?php echo esc_attr( $row[1] ); ?>" placeholder="Value"><button type="button" class="omgps-x" aria-label="Remove">&times;</button></div><?php endforeach; ?></div>
					<button type="button" class="omgps-link omgps-add-spec">+ Add spec</button>
					<input type="hidden" name="omg_ps[custom_specs]" class="omgps-custom-val" value="">
				</div>
				<div class="omgps-legend"><i class="dot" data-src="os"></i>OMGGamer OS <i class="dot" data-src="steam"></i>Steam <i class="dot" data-src="manufacturer"></i>Manufacturer <i class="dot" data-src="datasheet"></i>Datasheet <i class="dot" data-src="ai"></i>AI <i class="dot" data-src="product"></i>Store / manual</div>
			</section>

			<!-- page sections -->
			<section class="omgps-card" id="omgps-sections">
				<header><h3>Page sections</h3><span class="sub">Lists shown on the product page · drag to reorder</span></header>
				<?php foreach ( OMG_PS::kinds() as $k => $l ) : ?>
					<div class="omgps-secgrid" data-for="<?php echo esc_attr( $k ); ?>">
						<?php foreach ( OMG_PS::section_defs( $k ) as $sk => $def ) :
							$items = OMG_PS::items_from_store( get_post_meta( $id, $def[1], true ), $def[2] );
							?>
							<div class="omgps-sec omgps-field" data-key="sec_<?php echo esc_attr( $sk ); ?>">
								<div class="fh"><b><?php echo esc_html( $def[0] ); ?></b><?php echo $this->badge( $def[3], 'src' ); ?><?php echo $this->badge( 'AI written', 'ai' ); ?></div>
								<div class="omgps-list" data-format="<?php echo esc_attr( $def[2] ); ?>" data-items="<?php echo esc_attr( wp_json_encode( $items ) ); ?>"><ul class="rows"></ul><button type="button" class="omgps-link omgps-add">+ Add item</button><input type="hidden" class="omgps-list-val" name="omg_ps[sec][<?php echo esc_attr( $k ); ?>][<?php echo esc_attr( $sk ); ?>]" value="<?php echo esc_attr( wp_json_encode( $items ) ); ?>"></div>
								<div class="fa"><?php echo $this->field_actions( 'sec_' . $sk ); // phpcs:ignore ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<div class="omgps-extras" data-for="digital">
					<?php foreach ( OMG_PS::extra_defs( 'digital' ) as $ek => $def ) : ?>
						<label class="omgps-xf"><span><?php echo esc_html( $def[0] ); ?></span><input type="text" data-extra="<?php echo esc_attr( $ek ); ?>" name="omg_ps[extra][<?php echo esc_attr( $ek ); ?>]" value="<?php echo esc_attr( get_post_meta( $id, $def[1], true ) ); ?>"></label>
					<?php endforeach; ?>
					<div class="fa"><?php echo $this->badge( 'AI written', 'ai' ); ?><button type="button" class="omgps-btn omgps-regen" data-group="extras">&#8635; Regenerate notes</button></div>
				</div>
			</section>

			<!-- media -->
			<section class="omgps-card" id="omgps-media">
				<header><h3>Media</h3><span class="sub">Product image and gallery · alt text written by AI</span><span class="omgps-acts"><button type="button" class="omgps-btn omgps-regen" data-group="alt">&#8635; Write alt text</button><button type="button" class="omgps-btn is-blue omgps-addmedia">Add from library</button></span></header>
				<div class="omgps-thumbs">
					<?php foreach ( OMG_PS::media_ids( $id ) as $i => $mid ) : ?>
						<?php echo $this->thumb( $mid, 0 === $i ); // phpcs:ignore ?>
					<?php endforeach; ?>
				</div>
				<p class="omgps-muted omgps-nomedia" <?php echo OMG_PS::media_ids( $id ) ? 'hidden' : ''; ?>>No images yet. Set the product image and add gallery images.</p>
				<label class="omgps-xf" data-for="digital"><span>Trailer (YouTube link or ID)</span><input type="text" name="omg_ps[trailer]" value="<?php echo esc_attr( get_post_meta( $id, 'omg_trailer_youtube', true ) ); ?>" placeholder="https://youtu.be/..."></label>
			</section>

			<!-- SEO: meta title, description and focus keyword are edited in Rank Math below. -->
		</div>
		<?php
	}

	private function chip( $key, $title, $sub, $available, $chips, $why = '' ) {
		// OMGGamer OS is always on when connected (products saved before it existed have no choice stored for it).
		$on = $available && ( 'os' === $key || ! $chips || in_array( $key, $chips, true ) );
		printf(
			'<label class="omgps-chip%1$s"%5$s><input type="checkbox" name="omg_ps[sources][]" value="%2$s" %3$s %4$s><span><b>%6$s</b><small>%7$s</small></span></label>',
			$available ? '' : ' is-off',
			esc_attr( $key ),
			checked( $on, true, false ),
			disabled( ! $available, true, false ),
			$available ? '' : ' title="' . esc_attr( $why ) . '"',
			esc_html( $title ),
			esc_html( $available ? $sub : $why )
		);
	}

	private function thumb( $mid, $featured ) {
		$src = wp_get_attachment_image_url( $mid, 'thumbnail' );
		if ( ! $src ) {
			return '';
		}
		return '<figure class="omgps-thumb' . ( $featured ? ' is-featured' : '' ) . '" data-id="' . esc_attr( $mid ) . '"><div class="im"><img src="' . esc_url( $src ) . '" alt="">' . ( $featured ? '<em>Featured</em>' : '' ) . '</div><input type="text" name="omg_ps[alt][' . esc_attr( $mid ) . ']" value="' . esc_attr( get_post_meta( $mid, '_wp_attachment_image_alt', true ) ) . '" placeholder="Alt text"></figure>';
	}

	/* ---------------------------------------------------------------- side boxes */

	public function submitbox( $post ) {
		if ( 'product' !== $post->post_type ) {
			return;
		}
		$rule = OMG_PS::settings()['review_rule'];
		echo '<div class="misc-pub-section omgps-rule">&#128737; Rule: <b>' . ( 'auto' === $rule ? 'Publish after AI writes' : 'Human review required' ) . '</b></div>';
		echo '<div class="misc-pub-section omgps-reviewed"><div class="row"><span>AI fields reviewed</span><b><span class="n">0</span> of <span class="of">8</span></b></div><div class="bar"><i></i></div></div>';
	}

	public function submitbox_start( $post ) {
		if ( $post && 'product' === $post->post_type ) {
			echo '<button type="button" class="button button-primary omgps-approve-all">Approve all &amp; ' . ( 'publish' === $post->post_status ? 'update' : 'publish' ) . '</button>';
		}
	}

	public function box_pricing( $post ) {
		$id     = $post->ID;
		$s      = OMG_PS::settings();
		$cost   = get_post_meta( $id, 'omg_cost', true );
		$margin = get_post_meta( $id, 'omg_margin', true );
		$margin = '' === $margin ? $s['margin'] : $margin;
		$ends   = (int) get_post_meta( $id, 'omg_sale_ends', true );
		$p      = wc_get_product( $id );
		?>
		<div class="omgps-pricing" data-variable="<?php echo $p && $p->is_type( 'variable' ) ? '1' : '0'; ?>">
			<div class="row"><label for="omgps-cost"><span data-for="digital">Kinguin lowest offer</span><span data-for="hardware accessory">Supplier cost</span></label><span class="in"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?><input id="omgps-cost" type="number" step="0.01" min="0" name="omg_ps[cost]" value="<?php echo esc_attr( $cost ); ?>"></span></div>
			<div class="row"><label for="omgps-margin">Margin</label><b><span class="omgps-m"><?php echo esc_html( $margin ); ?></span>%</b></div>
			<input id="omgps-margin" type="range" min="0" max="60" step="1" name="omg_ps[margin]" value="<?php echo esc_attr( $margin ); ?>">
			<div class="row sale"><span>Sale price</span><b class="omgps-sale">—</b></div>
			<p class="omgps-muted">Rounded to .99. <span data-for="digital">Repriced when the Kinguin offer changes.</span><span data-for="hardware accessory">Repriced when supplier cost changes.</span></p>
			<p><button type="button" class="button omgps-apply-price">Use as regular price</button></p>
			<hr>
			<div class="row"><label for="omgps-pro">PRO member price</label><span class="in"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?><input id="omgps-pro" type="number" step="0.01" min="0" name="omg_ps[pro_price]" value="<?php echo esc_attr( get_post_meta( $id, 'omg_pro_price', true ) ); ?>"></span></div>
			<div class="row col"><label for="omgps-ends">Deal timer ends</label><input id="omgps-ends" type="datetime-local" name="omg_ps[sale_ends]" value="<?php echo $ends ? esc_attr( wp_date( 'Y-m-d\TH:i', $ends ) ) : ''; ?>"></div>
		</div>
		<?php
	}

	public function box_sync( $post ) {
		$sync = $this->json_meta( $post->ID, '_omg_sync' );
		$rows = array(
			'digital'  => array(
				'sync_price'   => array( 'Sync price & stock', 'Every 15 minutes from Kinguin' ),
				'auto_deliver' => array( 'Auto-deliver keys', 'Pull a key from Kinguin when an order is paid' ),
			),
			'physical' => array(
				'sync_stock'  => array( 'Sync stock from warehouse', 'Every 30 minutes' ),
				'price_watch' => array( 'Price watch', 'Alert when a competitor drops below your price' ),
			),
		);
		echo '<div class="omgps-sync">';
		foreach ( $rows as $grp => $items ) {
			foreach ( $items as $k => $l ) {
				$on = isset( $sync[ $k ] ) ? ! empty( $sync[ $k ] ) : true;
				printf( '<label class="omgps-toggle" data-for="%1$s"><input type="checkbox" name="omg_ps[sync][%2$s]" value="1" %3$s><i></i><span><b>%4$s</b><small>%5$s</small></span></label>', 'digital' === $grp ? 'digital' : 'hardware accessory', esc_attr( $k ), checked( $on, true, false ), esc_html( $l[0] ), esc_html( $l[1] ) );
			}
		}
		$on = isset( $sync['rewrite'] ) ? ! empty( $sync['rewrite'] ) : true;
		printf( '<label class="omgps-toggle"><input type="checkbox" name="omg_ps[sync][rewrite]" value="1" %s><i></i><span><b>Rewrite on source change</b><small>Regenerate unapproved fields if the source data changes</small></span></label>', checked( $on, true, false ) );
		echo '<p class="omgps-muted">Price, stock and key sync start once the Kinguin key is connected.</p></div>';
	}

	public function box_ai( $post ) {
		$id    = $post->ID;
		$voice = get_post_meta( $id, '_omg_ai_voice', true ) ?: 'default';
		$lang  = get_post_meta( $id, '_omg_ai_lang', true ) ?: 'en';
		?>
		<div class="omgps-aibox">
			<label for="omgps-voice">Brand voice</label>
			<select id="omgps-voice" name="omg_ps[voice]"><?php foreach ( OMG_PS::VOICES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $voice, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
			<label>Output language</label>
			<div class="omgps-langs"><?php foreach ( OMG_PS::LANGS as $k => $l ) : ?><label class="<?php echo $lang === $k ? 'is-on' : ''; ?>" title="<?php echo esc_attr( $l ); ?>"><input type="radio" name="omg_ps[lang]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $lang, $k ); ?>><?php echo esc_html( strtoupper( $k ) ); ?></label><?php endforeach; ?></div>
			<label for="omgps-extra">Extra instructions</label>
			<textarea id="omgps-extra" name="omg_ps[extra_instructions]" rows="3" placeholder="For example: mention the Season Pass, keep it short"><?php echo esc_textarea( get_post_meta( $id, '_omg_ai_extra', true ) ); ?></textarea>
		</div>
		<?php
	}

	/* ================================================================ saving */

	private function posted() {
		if ( empty( $_POST['omg_ps'] ) || empty( $_POST['omg_ps_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['omg_ps_nonce'] ) ), OMG_PS::NONCE ) ) {
			return null;
		}
		return wp_unslash( $_POST['omg_ps'] ); // phpcs:ignore -- sanitised per field below.
	}

	/** Title, short description, description and slug come from the studio fields. */
	public function post_data( $data, $postarr ) {
		if ( 'product' !== $data['post_type'] || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return $data;
		}
		$in = $this->posted();
		if ( ! $in || empty( $in['on'] ) || empty( $postarr['ID'] ) || ! current_user_can( 'edit_post', $postarr['ID'] ) ) {
			return $data;
		}
		if ( isset( $in['title'] ) && '' !== trim( $in['title'] ) ) {
			$data['post_title'] = wp_slash( sanitize_text_field( $in['title'] ) );
		}
		if ( isset( $in['short'] ) ) {
			$data['post_excerpt'] = wp_slash( wp_kses_post( $in['short'] ) );
		}
		if ( isset( $in['desc'] ) ) {
			$data['post_content'] = wp_slash( wp_kses_post( $in['desc'] ) );
		}
		if ( isset( $in['slug'] ) && '' !== trim( $in['slug'] ) ) {
			$slug = sanitize_title( $in['slug'] );
			if ( $slug && $slug !== $data['post_name'] ) {
				$data['post_name'] = wp_unique_post_slug( $slug, $postarr['ID'], $data['post_status'], 'product', (int) $data['post_parent'] );
			}
		}
		return $data;
	}

	public function save( $post_id ) {
		$in = $this->posted();
		if ( ! $in || empty( $in['on'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$kind = isset( $in['kind'] ) && isset( OMG_PS::kinds()[ $in['kind'] ] ) ? $in['kind'] : 'digital';
		update_post_meta( $post_id, '_omg_kind', $kind );
		if ( 'digital' === $kind ) {
			$l = isset( $in['layout'] ) ? sanitize_key( $in['layout'] ) : 'auto';
			if ( 'auto' !== $l && isset( OMG_PS::digital_layouts()[ $l ] ) ) {
				update_post_meta( $post_id, '_omg_layout', $l );
			} else {
				delete_post_meta( $post_id, '_omg_layout' );
			}
		} else {
			update_post_meta( $post_id, '_omg_layout', $kind );
		}

		// Digital products are virtual (no shipping address at checkout); physical ones ship.
		$p = wc_get_product( $post_id );
		if ( $p ) {
			$virtual = 'digital' === $kind;
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $cid ) {
					$c = wc_get_product( $cid );
					if ( $c && $c->get_virtual() !== $virtual ) {
						$c->set_virtual( $virtual );
						$c->save();
					}
				}
			} elseif ( ! $p->is_type( 'grouped' ) && ! $p->is_type( 'external' ) && $p->get_virtual() !== $virtual ) {
				$p->set_virtual( $virtual );
				$p->save();
			}
		}

		// Attributes: only the ones a person changed, so the Product data panel stays in charge otherwise.
		$changed = array();
		$new     = isset( $in['attr'][ $kind ] ) ? (array) $in['attr'][ $kind ] : array();
		$orig    = isset( $in['attr_orig'][ $kind ] ) ? (array) $in['attr_orig'][ $kind ] : array();
		foreach ( $new as $k => $v ) {
			if ( ! isset( $orig[ $k ] ) || (string) $orig[ $k ] !== (string) $v ) {
				$changed[ sanitize_key( $k ) ] = (string) $v;
			}
		}
		if ( $changed ) {
			OMG_PS::set_attrs( $post_id, $kind, $changed );
		}
		if ( 'digital' !== $kind && isset( $in['custom_specs'] ) ) {
			$rows = json_decode( (string) $in['custom_specs'], true );
			if ( is_array( $rows ) ) {
				$specs = OMG_PS::specs( $post_id );
				$specs['custom'] = array_map( function ( $r ) {
					return array( sanitize_text_field( isset( $r[0] ) ? $r[0] : '' ), sanitize_text_field( isset( $r[1] ) ? $r[1] : '' ) );
				}, $rows );
				OMG_PS::save_specs( $post_id, $specs );
			}
		}
		$src = json_decode( isset( $in['attr_src'] ) ? (string) $in['attr_src'] : '', true );
		if ( is_array( $src ) ) {
			update_post_meta( $post_id, '_omg_attr_src', wp_json_encode( array_map( 'sanitize_key', $src ) ) );
		}

		// Page sections.
		foreach ( OMG_PS::section_defs( $kind ) as $k => $def ) {
			if ( ! isset( $in['sec'][ $kind ][ $k ] ) ) {
				continue;
			}
			$items = json_decode( (string) $in['sec'][ $kind ][ $k ], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$current = OMG_PS::items_from_store( get_post_meta( $post_id, $def[1], true ), $def[2] );
			if ( wp_json_encode( array_values( $items ) ) === wp_json_encode( $current ) ) {
				continue; // unchanged: keep the stored markup as it is.
			}
			$v = OMG_PS::items_to_store( $items, $def[2] );
			'' === $v ? delete_post_meta( $post_id, $def[1] ) : update_post_meta( $post_id, $def[1], wp_slash( $v ) );
		}
		foreach ( OMG_PS::extra_defs( $kind ) as $k => $def ) {
			if ( isset( $in['extra'][ $k ] ) ) {
				$v = sanitize_text_field( $in['extra'][ $k ] );
				'' === $v ? delete_post_meta( $post_id, $def[1] ) : update_post_meta( $post_id, $def[1], $v );
			}
		}
		if ( isset( $in['trailer'] ) ) {
			$t = trim( (string) $in['trailer'] );
			if ( preg_match( '#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{6,20})#', $t, $m ) ) {
				$t = $m[1];
			}
			$t = preg_replace( '/[^A-Za-z0-9_-]/', '', $t );
			'' === $t ? delete_post_meta( $post_id, 'omg_trailer_youtube' ) : update_post_meta( $post_id, 'omg_trailer_youtube', $t );
		}
		foreach ( (array) ( isset( $in['alt'] ) ? $in['alt'] : array() ) as $mid => $alt ) {
			$mid = absint( $mid );
			if ( $mid && 'attachment' === get_post_type( $mid ) && current_user_can( 'edit_post', $mid ) ) {
				update_post_meta( $mid, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			}
		}

		// SEO.
		foreach ( array( 'seo_title' => '_omg_seo_title', 'seo_desc' => '_omg_seo_description', 'kw' => '_omg_seo_keyword' ) as $k => $meta ) {
			if ( isset( $in[ $k ] ) ) {
				$v = 'seo_desc' === $k ? sanitize_textarea_field( $in[ $k ] ) : sanitize_text_field( $in[ $k ] );
				'' === $v ? delete_post_meta( $post_id, $meta ) : update_post_meta( $post_id, $meta, $v );
			}
		}

		// Review state.
		$allowed = array_merge( OMG_PS::review_keys( $kind ), array( 'extras', 'alt' ) );
		foreach ( array( 'approved' => '_omg_ai_approved', 'written' => '_omg_ai_written' ) as $k => $meta ) {
			$list = json_decode( isset( $in[ $k ] ) ? (string) $in[ $k ] : '[]', true );
			$list = is_array( $list ) ? array_values( array_intersect( array_map( 'sanitize_key', $list ), $allowed ) ) : array();
			update_post_meta( $post_id, $meta, wp_json_encode( $list ) );
		}
		if ( isset( $in['source'] ) && strlen( (string) $in['source'] ) < 200000 ) {
			$s = json_decode( (string) $in['source'], true );
			if ( is_array( $s ) && $s ) {
				$prev = get_post_meta( $post_id, '_omg_source', true );
				$enc  = wp_json_encode( map_deep( $s, 'sanitize_textarea_field' ), JSON_UNESCAPED_UNICODE );
				if ( $enc !== $prev ) {
					update_post_meta( $post_id, '_omg_source', wp_slash( $enc ) );
				}
				if ( ! empty( $s['kinguin']['kinguin_id'] ) ) {
					update_post_meta( $post_id, 'omg_kinguin_id', absint( $s['kinguin']['kinguin_id'] ) );
				}
			}
		}
		if ( in_array( get_post_meta( $post_id, '_omg_ai_status', true ), array( 'written', 'error' ), true ) ) {
			update_post_meta( $post_id, '_omg_ai_status', 'reviewed' );
		}

		// Source identifiers.
		foreach ( array( 'mpn' => 'omg_mpn', 'mfr_url' => 'omg_mfr_url' ) as $k => $meta ) {
			if ( isset( $in[ $k ] ) ) {
				$v = 'mfr_url' === $k ? esc_url_raw( trim( $in[ $k ] ) ) : sanitize_text_field( $in[ $k ] );
				'' === $v ? delete_post_meta( $post_id, $meta ) : update_post_meta( $post_id, $meta, $v );
			}
		}
		if ( isset( $in['datasheet'] ) ) {
			$d = absint( $in['datasheet'] );
			$d && 'application/pdf' === get_post_mime_type( $d ) ? update_post_meta( $post_id, 'omg_datasheet', $d ) : delete_post_meta( $post_id, 'omg_datasheet' );
		}
		update_post_meta( $post_id, '_omg_sources', wp_json_encode( array_map( 'sanitize_key', isset( $in['sources'] ) ? (array) $in['sources'] : array() ) ) );

		// Pricing.
		$has_cost = isset( $in['cost'] ) && '' !== trim( (string) $in['cost'] );
		foreach ( array( 'cost' => 'omg_cost', 'pro_price' => 'omg_pro_price', 'margin' => 'omg_margin' ) as $k => $meta ) {
			if ( 'margin' === $k && ! $has_cost && '' === get_post_meta( $post_id, 'omg_margin', true ) ) {
				continue;
			}
			if ( isset( $in[ $k ] ) ) {
				$v = '' === trim( (string) $in[ $k ] ) ? '' : wc_format_decimal( $in[ $k ] );
				'' === $v ? delete_post_meta( $post_id, $meta ) : update_post_meta( $post_id, $meta, $v );
			}
		}
		$ends_now = (int) get_post_meta( $post_id, 'omg_sale_ends', true );
		if ( isset( $in['sale_ends'] ) && ( $ends_now ? wp_date( 'Y-m-d\\TH:i', $ends_now ) : '' ) !== (string) $in['sale_ends'] ) {
			$ts = '' !== trim( (string) $in['sale_ends'] ) ? ( new DateTime( sanitize_text_field( $in['sale_ends'] ), wp_timezone() ) )->getTimestamp() : 0;
			$ts ? update_post_meta( $post_id, 'omg_sale_ends', $ts ) : delete_post_meta( $post_id, 'omg_sale_ends' );
		}
		$sync = array();
		foreach ( array( 'sync_price', 'auto_deliver', 'sync_stock', 'price_watch', 'rewrite' ) as $k ) {
			$sync[ $k ] = empty( $in['sync'][ $k ] ) ? 0 : 1;
		}
		update_post_meta( $post_id, '_omg_sync', wp_json_encode( $sync ) );

		// AI settings for this product.
		update_post_meta( $post_id, '_omg_ai_voice', isset( $in['voice'], OMG_PS::VOICES[ $in['voice'] ] ) ? $in['voice'] : 'default' );
		update_post_meta( $post_id, '_omg_ai_lang', isset( $in['lang'], OMG_PS::LANGS[ $in['lang'] ] ) ? $in['lang'] : 'en' );
		update_post_meta( $post_id, '_omg_ai_tone', isset( $in['tone'], OMG_PS::TONES[ $in['tone'] ] ) ? $in['tone'] : 'informative' );
		update_post_meta( $post_id, '_omg_ai_extra', sanitize_textarea_field( isset( $in['extra_instructions'] ) ? $in['extra_instructions'] : '' ) );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
	}

	/* ================================================================ AJAX */

	private function ajax_guard( $cap_post = true ) {
		check_ajax_referer( OMG_PS::NONCE, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $cap_post ) {
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => 'You cannot edit this product.' ), 403 );
			}
		} elseif ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		return $post_id;
	}

	private function send( $r ) {
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public function ajax_source() {
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$this->ajax_guard( 'kinguin_search' !== $type || ! empty( $_POST['post_id'] ) );
		$v = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
		};
		switch ( $type ) {
			case 'os':
				$this->send( OMG_PS_Sources::os( absint( $_POST['post_id'] ?? 0 ), $v( 'name' ) ) ); // phpcs:ignore
				break;
			case 'kinguin_search':
				$this->send( OMG_PS_Sources::kinguin_search( $v( 'q' ), $v( 'platform' ) ) );
				break;
			case 'kinguin':
				$this->send( OMG_PS_Sources::kinguin_product( $v( 'id' ) ) );
				break;
			case 'steam':
				$this->send( OMG_PS_Sources::steam( $v( 'name' ), $v( 'app_id' ) ) );
				break;
			case 'icecat':
				$this->send( OMG_PS_Sources::icecat( $v( 'ean' ), $v( 'brand' ), $v( 'mpn' ) ) );
				break;
			case 'manufacturer':
				$this->send( OMG_PS_Sources::page( isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '' ) );
				break;
			case 'datasheet':
				$this->send( OMG_PS_Sources::datasheet( absint( $v( 'id' ) ) ) );
				break;
		}
		wp_send_json_error( array( 'message' => 'Unknown source.' ) );
	}

	public function ajax_generate() {
		$post_id = $this->ajax_guard();
		$kind    = isset( $_POST['kind'] ) && isset( OMG_PS::kinds()[ $_POST['kind'] ] ) ? sanitize_key( $_POST['kind'] ) : OMG_PS::kind( $post_id ); // phpcs:ignore
		$group   = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
		$doc     = json_decode( isset( $_POST['doc'] ) ? wp_unslash( $_POST['doc'] ) : '', true ); // phpcs:ignore
		$source  = json_decode( isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '', true ); // phpcs:ignore
		$doc     = is_array( $doc ) ? map_deep( $doc, 'wp_kses_post' ) : array();
		$source  = is_array( $source ) ? map_deep( $source, 'sanitize_textarea_field' ) : array();
		$r = OMG_PS::generate( $post_id, $kind, $group, array(
			'tone'   => isset( $_POST['tone'] ) ? sanitize_key( $_POST['tone'] ) : '', // phpcs:ignore
			'voice'  => isset( $_POST['voice'] ) ? sanitize_key( $_POST['voice'] ) : 'default', // phpcs:ignore
			'lang'   => isset( $_POST['lang'] ) ? sanitize_key( $_POST['lang'] ) : '', // phpcs:ignore
			'extra'  => isset( $_POST['extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra'] ) ) : '',
			'doc'    => $doc,
			'source' => $source,
		) );
		$this->send( $r );
	}

	public function ajax_test() {
		$this->ajax_guard( false );
		$t = OMG_PS::call_ai( 'Reply with the single word OK.', 'Connection test.', 20 );
		if ( is_wp_error( $t ) ) {
			wp_send_json_error( array( 'message' => $t->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'Connected. Reply: ' . mb_substr( trim( wp_strip_all_tags( $t ) ), 0, 40 ) ) );
	}

	/** Creates draft products from Kinguin IDs and queues the AI rewrite. */
	public function ajax_import() {
		$this->ajax_guard( false );
		$ids = isset( $_POST['ids'] ) ? array_slice( array_map( 'absint', (array) $_POST['ids'] ), 0, 20 ) : array();
		$s   = OMG_PS::settings();
		$out = array();
		foreach ( array_filter( $ids ) as $kid ) {
			$exists = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => 'omg_kinguin_id', 'meta_value' => $kid, 'fields' => 'ids', 'numberposts' => 1 ) );
			if ( $exists ) {
				$out[] = array( 'id' => $kid, 'ok' => false, 'msg' => 'Already imported', 'edit' => get_edit_post_link( $exists[0], 'raw' ) );
				continue;
			}
			$k = OMG_PS_Sources::kinguin_product( $kid );
			if ( is_wp_error( $k ) ) {
				$out[] = array( 'id' => $kid, 'ok' => false, 'msg' => $k->get_error_message() );
				continue;
			}
			$p = new WC_Product_Simple();
			$p->set_name( $k['name'] );
			$p->set_status( 'draft' );
			$p->set_virtual( true );
			$p->set_catalog_visibility( 'visible' );
			if ( $k['lowest_price'] > 0 ) {
				$p->set_regular_price( (string) self::round99( $k['lowest_price'] * ( 1 + $s['margin'] / 100 ) ) );
			}
			$p->set_stock_status( $k['stock'] > 0 ? 'instock' : 'outofstock' );
			$cat = get_term_by( 'slug', 'game-keys', 'product_cat' );
			if ( $cat ) {
				$p->set_category_ids( array( (int) $cat->term_id ) );
			}
			$pid = $p->save();
			update_post_meta( $pid, '_omg_kind', 'digital' );
			update_post_meta( $pid, 'omg_kinguin_id', $k['kinguin_id'] );
			update_post_meta( $pid, 'omg_cost', $k['lowest_price'] );
			update_post_meta( $pid, 'omg_margin', $s['margin'] );
			update_post_meta( $pid, '_omg_source', wp_slash( wp_json_encode( array( 'kinguin' => $k ), JSON_UNESCAPED_UNICODE ) ) );
			OMG_PS::set_attrs( $pid, 'digital', array(
				'platform'     => $k['platform'],
				'region'       => $k['region'],
				'genres'       => implode( ', ', $k['genres'] ),
				'publisher'    => implode( ', ', $k['publishers'] ),
				'developer'    => implode( ', ', $k['developers'] ),
				'languages'    => implode( ', ', array_slice( $k['languages'], 0, 25 ) ),
				'release_date' => $k['release_date'],
				'age_rating'   => $k['age_rating'],
				'kinguin_id'   => (string) $k['kinguin_id'],
			) );
			update_post_meta( $pid, '_omg_attr_src', wp_json_encode( array_fill_keys( array( 'platform', 'region', 'genres', 'publisher', 'developer', 'languages', 'release_date', 'age_rating', 'kinguin_id' ), 'kinguin' ) ) );
			$queued = $s['auto_rewrite'] && '' !== OMG_PS::api_key() && OMG_PS::queue_rewrite( $pid );
			$out[]  = array( 'id' => $kid, 'ok' => true, 'msg' => $queued ? 'Draft created, AI rewrite queued' : 'Draft created', 'edit' => get_edit_post_link( $pid, 'raw' ) );
		}
		wp_send_json_success( array( 'rows' => $out ) );
	}

	public function ajax_queue() {
		$this->ajax_guard( false );
		if ( '' === OMG_PS::api_key() ) {
			wp_send_json_error( array( 'message' => 'Connect an AI provider first.' ) );
		}
		$ids = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'draft',
			'fields'      => 'ids',
			'numberposts' => 500,
			'meta_query'  => array( 'relation' => 'OR', array( 'key' => '_omg_ai_status', 'compare' => 'NOT EXISTS' ), array( 'key' => '_omg_ai_status', 'value' => array( 'error' ), 'compare' => 'IN' ) ), // phpcs:ignore
		) );
		$n = 0;
		foreach ( $ids as $id ) {
			$n += OMG_PS::queue_rewrite( $id ) ? 1 : 0;
		}
		wp_send_json_success( array( 'message' => $n . ' drafts queued.' ) );
	}

	public static function round99( $v ) {
		$v = (float) $v;
		if ( $v <= 0 ) {
			return 0;
		}
		return max( 0.99, ceil( $v ) - 0.01 );
	}

	/* ================================================================ assets */

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		$is_product = $this->is_product_screen();
		if ( ! $is_product && ! in_array( $page, array( 'omg-ai-studio', 'omg-ps-import' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'omgps-admin', OMG_PS_URL . 'assets/admin.css', array(), OMG_PS_VERSION );
		$deps = array( 'jquery' );
		if ( $is_product ) {
			wp_enqueue_media();
			$deps[] = 'jquery-ui-sortable';
		}
		wp_enqueue_script( 'omgps-admin', OMG_PS_URL . 'assets/admin.js', $deps, OMG_PS_VERSION, true );
		$kinds = array();
		foreach ( array_keys( OMG_PS::kinds() ) as $k ) {
			$kinds[ $k ] = array( 'review' => OMG_PS::review_keys( $k ) );
		}
		wp_localize_script( 'omgps-admin', 'OMGPS', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( OMG_PS::NONCE ),
			'kinds'     => $kinds,
			'margin'    => (float) OMG_PS::settings()['margin'],
			'currency'  => html_entity_decode( get_woocommerce_currency_symbol() ),
			'importUrl' => admin_url( 'edit.php?post_type=product&page=omg-ps-import' ),
			'csvUrl'    => admin_url( 'edit.php?post_type=product&page=product_importer' ),
			'rule'      => OMG_PS::settings()['review_rule'],
		) );
	}
}
