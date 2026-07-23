<?php
/**
* Plugin Name: BrightLocal - AI Tools
* Plugin URI: https://brightlocal.com
* Description: BrightLocal AI Tools — a modular collection of AI-related website tools. Currently: Entity Maps - Manage entitymap.json / entitymap.html / llms.txt.
* Version: 2.16.1
* Author: Ash Whiting for BrightLocal
* Author URI: https://brightlocal.com
* Text Domain: bl-ai-tools
* GitHub Plugin URI: https://github.com/asha23/bl-ai-tools
* Primary Branch: master
* Release Asset: true
**/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BL_AI_VERSION', '2.16.1' );
define( 'BL_AI_FILE', __FILE__ );
define( 'BL_AI_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Load the modular framework, then each tool module. To add a new tool: require
 * its module file here and register it in bl_ai_registry() below.
 */
require_once BL_AI_DIR . 'includes/framework/class-bl-ai-tool.php';
require_once BL_AI_DIR . 'includes/framework/class-bl-ai-tools-registry.php';
require_once BL_AI_DIR . 'includes/tools/entity-maps/class-bl-ai-tool-entity-maps.php';

/**
 * The registry of tools, built once. Register additional BL_AI_Tool modules
 * here and they slot into the shared "BL AI Tools" menu automatically.
 *
 * @return BL_AI_Tools_Registry
 */
function bl_ai_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new BL_AI_Tools_Registry();
		$registry->add( new BL_AI_Tool_EntityMaps() );
	}
	return $registry;
}

/**
 * Boot every registered tool.
 */
function bl_ai_boot() {
	bl_ai_registry()->boot();
}
add_action( 'plugins_loaded', 'bl_ai_boot' );

/**
 * Activation: let each tool register its CPTs / rewrite endpoints, then flush
 * rewrite rules once so /entitymap.json resolves.
 */
function bl_ai_activate() {
	bl_ai_registry()->activate();
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
