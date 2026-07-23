<?php
/**
 * EntityMap XML sitemap (Yoast integration).
 *
 * Registers a dedicated /entitymap-sitemap.xml through Yoast's legacy sitemap
 * engine and lists it in sitemap_index.xml, so crawlers discover entitymap.html
 * via the normal sitemap route. Verified against Yoast SEO 28.1
 * (inc/sitemaps/): register_sitemap() + the wpseo_sitemap_index filter.
 *
 * Feature-level dependency: this is the ONLY part of the plugin that needs
 * Yoast. It no-ops entirely when Yoast is absent (no fatal, no notices); the
 * files / llms.txt / Manage Entities features are unaffected. The Files tab
 * shows whether it is active.
 *
 * Only entitymap.html is listed — a .json <loc> is non-standard and search
 * engines ignore/warn on it; the JSON stays signposted via the <head> alternate
 * link and llms.txt.
 *
 * @since 2.17.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Sitemap {

	const NAME = 'entitymap'; // -> /entitymap-sitemap.xml

	public function __construct() {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_filter( 'wpseo_sitemap_index', array( $this, 'add_to_index' ) );
		// Keep the sitemap fresh: drop Yoast's cache for our entry on any change.
		add_action( 'bl_entitymap_changed', array( $this, 'clear_cache' ) );
	}

	/** Is Yoast's sitemap engine present? (Static so the UI can check without
	 *  instantiating this class, which would double-register its hooks.) */
	public static function yoast_active() {
		return class_exists( 'WPSEO_Sitemaps_Renderer' );
	}

	/** Active only when publishing is on AND Yoast's sitemap engine is present. */
	public function is_active() {
		return get_option( 'bl_em_enable_json', '1' ) === '1' && self::yoast_active();
	}

	/** Register the /entitymap-sitemap.xml handler with Yoast. */
	public function register() {
		global $wpseo_sitemaps;
		if ( $this->is_active() && $wpseo_sitemaps ) {
			$wpseo_sitemaps->register_sitemap( self::NAME, array( $this, 'build' ) );
		}
	}

	/** Build and hand the <urlset> back to Yoast. */
	public function build() {
		global $wpseo_sitemaps;
		if ( ! $this->is_active() || ! $wpseo_sitemaps ) {
			return;
		}
		$renderer = new WPSEO_Sitemaps_Renderer();
		$links    = array(
			array(
				'loc' => BL_EntityMap_Store::base_url() . '/entitymap.html',
				'mod' => $this->lastmod(),
			),
		);
		$wpseo_sitemaps->set_sitemap( $renderer->get_sitemap( $links, self::NAME, 0 ) );
	}

	/** Append our child sitemap to sitemap_index.xml. */
	public function add_to_index( $xml ) {
		if ( ! $this->is_active() ) {
			return $xml;
		}
		$loc = esc_url( BL_EntityMap_Store::base_url() . '/entitymap-sitemap.xml' );
		return $xml . '<sitemap><loc>' . $loc . '</loc><lastmod>' . $this->lastmod() . "</lastmod></sitemap>\n";
	}

	/** Invalidate our cached sitemap so a changed map re-renders with a fresh mod. */
	public function clear_cache() {
		if ( class_exists( 'WPSEO_Sitemaps_Cache' ) ) {
			WPSEO_Sitemaps_Cache::clear( array( self::NAME ) );
		}
	}

	/**
	 * Accurate <lastmod> from the generated entitymap.html mtime. Mirrors the
	 * generator's filtered path WITHOUT instantiating it (a second Generator
	 * would double-register its hooks). Also satisfies the sitemap-<lastmod>
	 * half of the discoverability freshness item.
	 */
	private function lastmod() {
		$path = apply_filters( 'bl_entitymap_html_path', trailingslashit( dirname( ABSPATH ) ) . 'entitymap.html' );
		$ts   = file_exists( $path ) ? filemtime( $path ) : time();
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
