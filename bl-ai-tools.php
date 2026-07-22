<?php
/**
* Plugin Name: BrightLocal - AI Tools
* Plugin URI: https://brightlocal.com
* Description: BrightLocal AI Tools. Manage an EntityMap in wp-admin as the single source of truth. Auto-generates /entitymap.json and drives Yoast Schema.org output (sitewide Organization enrichment + per-page DefinedTerm/Service nodes). Also extends product schema with reviews on flagged pages.
* Version: 2.5.0
* Author: Ash Whiting for BrightLocal
* Author URI: https://brightlocal.com
* Text Domain: bl-ai-tools
* GitHub Plugin URI: https://github.com/asha23/bl-ai-tools
* Primary Branch: master
* Release Asset: true
**/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BL_AI_VERSION', '2.5.0' );
define( 'BL_AI_FILE', __FILE__ );
define( 'BL_AI_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load the plugin's classes.
 */
require_once BL_AI_DIR . 'includes/class-bl-entitymap-store.php';
require_once BL_AI_DIR . 'includes/class-bl-entitymap-cpt.php';
require_once BL_AI_DIR . 'includes/class-bl-entitymap-generator.php';
require_once BL_AI_DIR . 'includes/class-bl-entitymap-schema.php';
require_once BL_AI_DIR . 'includes/class-bl-entitymap-importer.php';
require_once BL_AI_DIR . 'includes/class-bl-entitymap-admin.php';
require_once BL_AI_DIR . 'includes/class-bl-product-review-schema.php';

/**
 * Boot everything.
 */
function bl_ai_boot() {
	new BL_EntityMap_CPT();
	new BL_EntityMap_Generator();
	new BL_EntityMap_Schema();
	new BL_EntityMap_Admin();
	new BL_Product_Review_Schema();
}
add_action( 'plugins_loaded', 'bl_ai_boot' );

/**
 * Activation: register the CPT + rewrite endpoint, then flush rewrite rules
 * once so /entitymap.json resolves.
 */
function bl_ai_activate() {
	( new BL_EntityMap_CPT() )->register_post_type();
	( new BL_EntityMap_Generator() )->add_rewrite();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bl_ai_activate' );

/**
 * Deactivation: clean up rewrite rules.
 */
function bl_ai_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bl_ai_deactivate' );
