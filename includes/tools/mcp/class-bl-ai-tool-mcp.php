<?php
/**
 * BrightLocal MCP — a BL AI Tools module.
 *
 * Exposes BrightLocal content to AI assistants over the Model Context Protocol
 * (MCP), using the WordPress Abilities API + MCP Adapter. Self-contained: wires
 * the abilities, the dedicated MCP server, and the admin screen into the shared
 * BL AI Tools menu. The MCP Adapter plugin is a feature-level dependency — the
 * tool degrades gracefully (and says so) when it isn't active.
 *
 * @since 2.25.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-bl-mcp-abilities.php';
require_once __DIR__ . '/class-bl-mcp-server.php';
require_once __DIR__ . '/class-bl-mcp-admin.php';

class BL_AI_Tool_MCP extends BL_AI_Tool {

	/**
	 * Master kill-switch. While false, the tool is NOT registered or booted at all
	 * — no menu, no abilities, no MCP server, and this plugin does not register the
	 * shared `brightlocal` ability category. The code is retained; flip to true to
	 * deploy it. Kept off for now so only the Entity Maps tool ships.
	 */
	const ENABLED = false;

	const MENU_SLUG = 'bl-mcp';

	/** @var BL_MCP_Admin */
	private $admin;

	public function id() {
		return 'brightlocal-mcp';
	}

	public function label() {
		return 'BrightLocal MCP';
	}

	public function description() {
		return 'Expose BrightLocal content to AI assistants over the Model Context Protocol (MCP), so LLMs can search and read the site directly.';
	}

	public function icon() {
		return 'dashicons-rest-api';
	}

	public function menu_slug() {
		return self::MENU_SLUG;
	}

	/** Wire runtime hooks: abilities, the MCP server, and the admin screen. */
	public function register() {
		new BL_MCP_Abilities();
		new BL_MCP_Server();
		$this->admin = new BL_MCP_Admin();
	}

	/** Add the BrightLocal MCP screen under the BL AI Tools menu. */
	public function register_admin( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			'BrightLocal MCP',
			'BrightLocal MCP',
			'manage_options',
			self::MENU_SLUG,
			array( $this->admin, 'render_page' )
		);
	}
}
