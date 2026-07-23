<?php
/**
 * BrightLocal MCP — abilities.
 *
 * Registers the read-only "abilities" (via the WordPress Abilities API) that the
 * BrightLocal MCP server exposes as MCP tools:
 *   - brightlocal/search_content : search published content, return matches.
 *   - brightlocal/get_content    : fetch one item's full readable text.
 *
 * Abilities are the unit of work; BL_MCP_Server exposes them over MCP. This class
 * only registers them and provides the execute/permission callbacks. It no-ops
 * cleanly when the Abilities API isn't present (WordPress < 6.9).
 *
 * @since 2.25.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_MCP_Abilities {

	const CATEGORY = 'brightlocal';

	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/** Capability required to use the MCP tools (filterable). Read-only, so 'read'. */
	public static function capability() {
		return apply_filters( 'bl_mcp_capability', 'read' );
	}

	public static function can_use() {
		return current_user_can( self::capability() );
	}

	/* ------------------------------------------------------------------ */

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category( self::CATEGORY, array(
			'label'       => __( 'BrightLocal', 'bl-ai-tools' ),
			'description' => __( 'BrightLocal content and knowledge for AI assistants.', 'bl-ai-tools' ),
		) );
	}

	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability( 'brightlocal/search_content', array(
			'label'               => __( 'Search BrightLocal content', 'bl-ai-tools' ),
			'description'         => __( 'Search BrightLocal\'s published content (guides, blog posts, pages) and return matching titles, URLs, and short excerpts. Use get_content to read a result in full.', 'bl-ai-tools' ),
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array( 'type' => 'string', 'description' => 'Search terms.' ),
					'type'  => array( 'type' => 'string', 'description' => 'Optional post type to restrict to (e.g. "post", "page").' ),
					'limit' => array( 'type' => 'integer', 'description' => 'Maximum results to return (1–25).', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ),
				),
				'required'   => array( 'query' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'title'   => array( 'type' => 'string' ),
								'url'     => array( 'type' => 'string' ),
								'excerpt' => array( 'type' => 'string' ),
								'type'    => array( 'type' => 'string' ),
								'date'    => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'execute_callback'    => array( __CLASS__, 'search_content' ),
			'meta'                => array( 'show_in_rest' => true ),
		) );

		wp_register_ability( 'brightlocal/get_content', array(
			'label'               => __( 'Get BrightLocal content', 'bl-ai-tools' ),
			'description'         => __( 'Fetch one published BrightLocal page or post in full (readable text), by URL or ID.', 'bl-ai-tools' ),
			'category'            => self::CATEGORY,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array( 'type' => 'string', 'description' => 'The full URL of the page/post to fetch.' ),
					'id'  => array( 'type' => 'integer', 'description' => 'The post ID to fetch (alternative to url).' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title'   => array( 'type' => 'string' ),
					'url'     => array( 'type' => 'string' ),
					'type'    => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'execute_callback'    => array( __CLASS__, 'get_content' ),
			'meta'                => array( 'show_in_rest' => true ),
		) );
	}

	/* ------------------------------------------------------------------ */

	/** Public post types we search over (attachments excluded). Filterable. */
	private static function searchable_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return apply_filters( 'bl_mcp_searchable_types', array_values( $types ) );
	}

	/**
	 * search_content — return published matches for a query.
	 *
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function search_content( $input ) {
		$query = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		if ( $query === '' ) {
			return new WP_Error( 'bl_mcp_no_query', 'A search query is required.' );
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit = max( 1, min( 25, $limit ) );

		$types = self::searchable_types();
		if ( ! empty( $input['type'] ) && in_array( $input['type'], $types, true ) ) {
			$types = array( $input['type'] );
		}

		$q = new WP_Query( array(
			's'                   => $query,
			'post_type'           => $types,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		) );

		$results = array();
		foreach ( $q->posts as $post ) {
			$results[] = array(
				'title'   => get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'excerpt' => self::excerpt( $post ),
				'type'    => get_post_type( $post ),
				'date'    => (string) get_post_time( 'c', true, $post ),
			);
		}
		wp_reset_postdata();

		return array( 'results' => $results );
	}

	/**
	 * get_content — return one published item's full readable text.
	 *
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function get_content( $input ) {
		$post = null;

		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['url'] ) ) {
			$id = url_to_postid( esc_url_raw( (string) $input['url'] ) );
			$post = $id ? get_post( $id ) : null;
		} else {
			return new WP_Error( 'bl_mcp_no_target', 'Provide a url or an id.' );
		}

		// Only ever expose published, public content — never drafts/private.
		if ( ! $post || $post->post_status !== 'publish' ) {
			return new WP_Error( 'bl_mcp_not_found', 'No published content found for that url/id.' );
		}
		$type_obj = get_post_type_object( $post->post_type );
		if ( ! $type_obj || empty( $type_obj->public ) ) {
			return new WP_Error( 'bl_mcp_not_public', 'That content is not public.' );
		}

		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );

		// Guard against very large payloads.
		$max = (int) apply_filters( 'bl_mcp_max_content_chars', 20000 );
		if ( mb_strlen( $text ) > $max ) {
			$text = mb_substr( $text, 0, $max ) . "\n\n… [truncated]";
		}

		return array(
			'title'   => get_the_title( $post ),
			'url'     => (string) get_permalink( $post ),
			'type'    => $post->post_type,
			'content' => $text,
		);
	}

	/** A short plain-text excerpt for a post. */
	private static function excerpt( $post ) {
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) );
		return wp_trim_words( $excerpt, 55, '…' );
	}
}
