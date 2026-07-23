<?php
/**
 * BrightLocal MCP — dedicated MCP server.
 *
 * Stands up a dedicated, authenticated MCP server via the WordPress MCP Adapter,
 * exposing the BrightLocal abilities as MCP tools over HTTP transport at
 * /wp-json/brightlocal/mcp. The MCP Adapter is a feature-level dependency: if it
 * isn't active, this no-ops (no fatal) and the admin screen signposts it.
 *
 * @since 2.25.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_MCP_Server {

	const SERVER_ID = 'brightlocal';
	const NAMESPACE  = 'brightlocal'; // REST namespace segment
	const ROUTE      = 'mcp';         // REST route segment  -> /wp-json/brightlocal/mcp
	const VERSION    = '1.0.0';

	/** Adapter class names (fully-qualified; the adapter is namespaced). */
	const ADAPTER_CLASS       = '\\WP\\MCP\\Core\\McpAdapter';
	const TRANSPORT_HTTP      = '\\WP\\MCP\\Transport\\HttpTransport';
	const ERROR_HANDLER       = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
	const OBSERVABILITY_NULL  = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';

	public function __construct() {
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
	}

	/** True when the MCP Adapter + Abilities API are both available. */
	public static function available() {
		return class_exists( self::ADAPTER_CLASS )
			&& class_exists( self::TRANSPORT_HTTP )
			&& function_exists( 'wp_register_ability' );
	}

	/** Public URL of the MCP endpoint. */
	public static function endpoint_url() {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	/** The tools (ability names) this server exposes. */
	public static function tools() {
		$tools = array( 'brightlocal/search_content', 'brightlocal/get_content' );
		// EntityMap tools ride along when the Entity Maps tool is present.
		if ( class_exists( 'BL_EntityMap_Store' ) ) {
			$tools[] = 'brightlocal/search_entities';
			$tools[] = 'brightlocal/get_entity';
		}
		return $tools;
	}

	/**
	 * Create the server. Fired on mcp_adapter_init with the McpAdapter instance.
	 *
	 * @param object $adapter The McpAdapter instance.
	 */
	public function register_server( $adapter ) {
		if ( ! self::available() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			'BrightLocal MCP',
			'Search and read BrightLocal\'s published content.',
			self::VERSION,
			array( self::TRANSPORT_HTTP ),
			self::ERROR_HANDLER,
			self::OBSERVABILITY_NULL,
			self::tools(),
			array(), // resources
			array(), // prompts
			array( __CLASS__, 'transport_permission' )
		);
	}

	/**
	 * Transport-level auth: the endpoint requires an authenticated user with the
	 * configured capability (default 'read'). Clients authenticate with an
	 * Application Password over HTTPS. Read-only tools only expose public content.
	 *
	 * @return bool
	 */
	public static function transport_permission( $context = null ) {
		return current_user_can( BL_MCP_Abilities::capability() );
	}
}
