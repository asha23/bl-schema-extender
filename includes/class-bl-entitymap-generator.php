<?php
/**
 * EntityMap document generator + /entitymap.json endpoint.
 *
 * Assembles the full entitymap.json document from settings + the Store, and
 * publishes it two ways for maximum compatibility:
 *   1. Writes a static file to the webroot on every change (served directly by
 *      the webserver — fastest, no WP bootstrap).
 *   2. Registers a rewrite so /entitymap.json is also served dynamically by WP
 *      if the static file is absent (e.g. read-only webroot on the host).
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Generator {

	const CACHE_KEY = 'bl_entitymap_json_v2';
	const QUERY_VAR = 'bl_entitymap';

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );

		// Regenerate outputs whenever entities/settings change.
		add_action( 'bl_entitymap_changed', array( $this, 'regenerate' ) );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^entitymap\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Absolute path to the static file in the Bedrock webroot. */
	public function static_path() {
		$path = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json';
		return apply_filters( 'bl_entitymap_path', $path );
	}

	/* ---------------------------------------------------------------------
	 * Document assembly.
	 * ------------------------------------------------------------------- */

	public function get_document() {
		$publisher = array(
			'name' => get_option( 'bl_em_publisher_name', 'BrightLocal' ),
			'url'  => get_option( 'bl_em_publisher_url', home_url( '/' ) ),
		);
		$pub_same = get_option( 'bl_em_publisher_sameas', '' );
		if ( $pub_same !== '' ) {
			$publisher['sameAs'] = $pub_same;
		}

		return array(
			'version'            => get_option( 'bl_em_version', '1.0' ),
			'schema'             => get_option( 'bl_em_schema_url', 'https://entitymap.org/spec/v1.0' ),
			'publisher'          => $publisher,
			'generated'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'verificationStatus' => get_option( 'bl_em_verification', 'self-declared' ),
			'profile'            => get_option( 'bl_em_profile', 'core' ),
			'entities'           => BL_EntityMap_Store::get_entities(),
		);
	}

	/** Pretty JSON string, cached. */
	public function get_json( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}

		$json = wp_json_encode(
			$this->get_document(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		set_transient( self::CACHE_KEY, $json, DAY_IN_SECONDS );
		return $json;
	}

	/* ---------------------------------------------------------------------
	 * Publishing.
	 * ------------------------------------------------------------------- */

	/** Rebuild the cache and (re)write the static file. */
	public function regenerate() {
		delete_transient( self::CACHE_KEY );

		$entities = BL_EntityMap_Store::get_entities( true );
		$json     = $this->get_json( true );

		// Safety: never overwrite an existing map with an empty one. This keeps a
		// seed file (which the importer reads) intact while the database is still
		// empty — e.g. on first activation before Tools -> Import has been run.
		if ( empty( $entities ) ) {
			return;
		}

		$this->write_static_file( $json );
	}

	/**
	 * Try to write the static file via WP_Filesystem. Records success so the
	 * admin can warn when the webroot isn't writable (dynamic fallback covers it).
	 */
	public function write_static_file( $json ) {
		if ( get_option( 'bl_em_enable_json', '1' ) !== '1' ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			update_option( 'bl_em_static_ok', '0' );
			return false;
		}

		$ok = $wp_filesystem->put_contents( $this->static_path(), $json, FS_CHMOD_FILE );
		update_option( 'bl_em_static_ok', $ok ? '1' : '0' );
		return (bool) $ok;
	}

	/* ---------------------------------------------------------------------
	 * Dynamic endpoint (fallback when no static file exists).
	 * ------------------------------------------------------------------- */

	public function maybe_serve() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( get_option( 'bl_em_enable_json', '1' ) !== '1' ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Robots-Tag: index, follow' );
		echo $this->get_json(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
