<?php
/**
 * Plugin Name: OMG AI Product Studio
 * Description: Product editor for OMGGamer. Choose the product type and page layout, pull source data (OMGGamer OS for games, Steam by title for gaps; Icecat, manufacturer page, datasheet PDF for hardware) and write every text field with AI through Anthropic (Claude), OpenAI, OpenRouter or any OpenAI-compatible API such as OmniRouter. Also renders the hardware and accessory product page.
 * Version: 2.3.0
 * Author: OMGGamer
 * Requires PHP: 7.4
 * Text Domain: omg-ai-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OMG_PS_VERSION', '2.3.0' );
define( 'OMG_PS_FILE', __FILE__ );
define( 'OMG_PS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMG_PS_URL', plugin_dir_url( __FILE__ ) );

require_once OMG_PS_DIR . 'includes/core.php';
require_once OMG_PS_DIR . 'includes/sources.php';
require_once OMG_PS_DIR . 'includes/admin.php';
require_once OMG_PS_DIR . 'includes/front.php';
require_once OMG_PS_DIR . 'includes/drawer.php';
require_once OMG_PS_DIR . 'includes/checkout.php';

add_action( 'plugins_loaded', function () {
	OMG_PS_Admin::init();
	OMG_PS_Front::init();
	OMG_PS_Drawer::init();
	OMG_PS_Checkout::init();
	OMG_PS::init_queue();
} );
