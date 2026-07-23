<?php
/**
 * EntityMap document generator + /entitymap.json and /entitymap.html endpoints.
 *
 * Assembles the EntityMap from settings + the Store and publishes it as:
 *   - entitymap.json : machine-readable file for AI systems.
 *   - entitymap.html : human-readable rendering of the same data (with a
 *                      Schema.org JSON-LD DefinedTerm index in its head).
 *
 * Both are (a) written to the webroot as static files on every change (fast,
 * served directly by the webserver) and (b) available via a WP rewrite as a
 * dynamic fallback when the webroot is not writable. Both are generated from
 * the same source, so they can never drift from each other or from the DB.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Generator {

	const CACHE_KEY      = 'bl_entitymap_json_v2';
	const QUERY_VAR      = 'bl_entitymap';       // json
	const QUERY_VAR_HTML = 'bl_entitymap_html';  // html

	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );

		// Sitewide machine-readable pointer to the map, in every page's <head>.
		add_action( 'wp_head', array( $this, 'head_alternate_link' ) );

		// Regenerate outputs whenever entities/settings change.
		add_action( 'bl_entitymap_changed', array( $this, 'regenerate' ) );
	}

	public function add_rewrite() {
		add_rewrite_rule( '^entitymap\.json$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^entitymap\.html$', 'index.php?' . self::QUERY_VAR_HTML . '=1', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_HTML;
		return $vars;
	}

	/**
	 * Emit a sitewide <head> alternate link pointing at entitymap.json — the
	 * cleanest machine-readable "the map exists, here it is" signal for AI systems
	 * and crawlers. Uses the canonical base URL so the href is stable across hosts.
	 * Suppressed when publishing is off, and on the map's own endpoints (which
	 * carry their own links).
	 */
	public function head_alternate_link() {
		if ( get_option( 'bl_em_enable_json', '1' ) !== '1' ) {
			return;
		}
		if ( get_query_var( self::QUERY_VAR ) || get_query_var( self::QUERY_VAR_HTML ) ) {
			return;
		}

		printf(
			'<link rel="alternate" type="application/json" href="%s" title="%s" />' . "\n",
			esc_url( BL_EntityMap_Store::base_url() . '/entitymap.json' ),
			esc_attr( get_option( 'bl_em_publisher_name', 'BrightLocal' ) . ' EntityMap' )
		);
	}

	/** Absolute path to the static JSON file in the Bedrock webroot. */
	public function static_path() {
		$path = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json';
		return apply_filters( 'bl_entitymap_path', $path );
	}

	/** Absolute path to the static HTML file in the Bedrock webroot. */
	public function static_html_path() {
		$path = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.html';
		return apply_filters( 'bl_entitymap_html_path', $path );
	}

	/* ---------------------------------------------------------------------
	 * Document assembly.
	 * ------------------------------------------------------------------- */

	public function get_document() {
		$publisher = array(
			'name' => get_option( 'bl_em_publisher_name', 'BrightLocal' ),
			// Fall back to the canonical base URL (not the generating host) so the
			// published files stay environment-independent even if left unset.
			'url'  => get_option( 'bl_em_publisher_url', BL_EntityMap_Store::base_url() . '/' ),
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
	 * Human-readable HTML rendering.
	 * ------------------------------------------------------------------- */

	public function get_html( $doc = null ) {
		if ( $doc === null ) {
			$doc = $this->get_document();
		}

		$entities = isset( $doc['entities'] ) ? $doc['entities'] : array();
		$pub_name = isset( $doc['publisher']['name'] ) ? $doc['publisher']['name'] : 'Publisher';
		$pub_url  = isset( $doc['publisher']['url'] ) ? $doc['publisher']['url'] : BL_EntityMap_Store::base_url() . '/';

		// Canonical base for absolute self-URLs. Uses the pinned Canonical base URL
		// (bl_em_base_url) when set, so the file is environment-independent — a file
		// regenerated on staging still emits production URLs, not the staging host.
		$base      = BL_EntityMap_Store::base_url();
		$html_url  = $base . '/entitymap.html';
		$json_url  = $base . '/entitymap.json';

		// Root-relative link so it resolves on any host.
		$json_link = '/entitymap.json';

		// Schema.org JSON-LD index for the page head.
		$has_part = array();
		foreach ( $entities as $e ) {
			$term = array(
				'@type'    => 'DefinedTerm',
				'name'     => $e['name'],
				'termCode' => $e['entityId'],
			);
			if ( ! empty( $e['description'] ) ) {
				$term['description'] = $e['description'];
			}
			if ( ! empty( $e['sameAs'] ) ) {
				$term['sameAs'] = $e['sameAs'];
			}
			$has_part[] = $term;
		}
		$ld = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'WebPage',
			'name'         => $pub_name . ' EntityMap',
			'url'          => $html_url,
			'publisher'    => array( '@type' => 'Organization', 'name' => $pub_name, 'url' => $pub_url ),
			'dateModified' => isset( $doc['generated'] ) ? $doc['generated'] : '',
			'hasPart'      => $has_part,
		);
		if ( ! empty( $doc['publisher']['sameAs'] ) ) {
			$ld['publisher']['sameAs'] = $doc['publisher']['sameAs'];
		}
		$ld_json = wp_json_encode( $ld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $pub_name ); ?> &mdash; EntityMap</title>
<meta name="description" content="<?php echo esc_attr( 'A structured, entity-first index of what ' . $pub_name . ' knows, published per the EntityMap open standard for AI systems and retrieval pipelines.' ); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo esc_url( $html_url ); ?>">
<link rel="alternate" type="application/json" href="<?php echo esc_url( $json_url ); ?>" title="<?php echo esc_attr( $pub_name . ' EntityMap' ); ?>">
<script type="application/ld+json">
<?php echo $ld_json; // phpcs:ignore WordPress.Security.EscapeOutput ?>

</script>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 860px; margin: 0 auto; padding: 2rem 1.25rem 5rem; color: #1a1a1a; line-height: 1.55; }
  header { border-bottom: 2px solid #0fd03b; padding-bottom: 1.25rem; margin-bottom: 2rem; }
  header h1 { margin-bottom: 0.25rem; }
  .meta { font-size: 0.85rem; color: #777; }
  nav.toc { background: #f7f8f9; border-radius: 8px; padding: 1rem 1.5rem; margin-bottom: 2.5rem; }
  nav.toc h2 { font-size: 1rem; margin-top: 0; }
  nav.toc ul { columns: 2; padding-left: 1.1rem; }
  nav.toc a { color: #0a7d2c; text-decoration: none; font-size: 0.92rem; }
  nav.toc a:hover { text-decoration: underline; }
  .entity { border-top: 1px solid #e5e5e5; padding: 1.75rem 0; }
  .entity h2 { font-size: 1.3rem; margin-bottom: 0.3rem; }
  .entity .type { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; background: #eef7ee; color: #0a7d2c; padding: 2px 8px; border-radius: 4px; margin-left: 0.5rem; }
  .badge { font-size: 0.7rem; text-transform: uppercase; background: #fff3cd; color: #8a6d00; padding: 2px 8px; border-radius: 4px; margin-left: 0.4rem; }
  .alt { font-size: 0.85rem; color: #666; margin: 0.15rem 0; }
  .desc { margin: 0.75rem 0 1rem; }
  .entity h3 { font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.03em; color: #444; margin: 1.25rem 0 0.5rem; }
  blockquote.chunk { margin: 0 0 0.9rem; padding: 0.6rem 1rem; background: #fafafa; border-left: 3px solid #0fd03b; }
  blockquote.chunk p { margin: 0 0 0.35rem; font-style: italic; }
  blockquote.chunk cite { font-size: 0.8rem; color: #777; font-style: normal; }
  ul.relations { list-style: none; padding: 0; }
  ul.relations li { padding: 0.35rem 0; border-bottom: 1px dotted #eee; font-size: 0.92rem; }
  .pred { font-family: monospace; background: #f0f0f0; padding: 1px 6px; border-radius: 3px; font-size: 0.82rem; }
  .conf { color: #888; font-size: 0.8rem; }
  .ctx { font-size: 0.8rem; color: #777; margin-top: 0.15rem; }
  footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #e5e5e5; font-size: 0.85rem; color: #777; }
</style>
</head>
<body>

<header>
  <h1><?php echo esc_html( $pub_name ); ?> &mdash; EntityMap</h1>
  <p>A structured index of the entities, products, and research <?php echo esc_html( $pub_name ); ?> publishes, generated per the <a href="<?php echo esc_url( isset( $doc['schema'] ) ? $doc['schema'] : 'https://entitymap.org/spec/v1.0' ); ?>" target="_blank" rel="noopener">EntityMap</a> open standard.</p>
  <p class="meta">Generated: <?php echo esc_html( substr( (string) ( $doc['generated'] ?? '' ), 0, 10 ) ); ?> &middot; Status: <?php echo esc_html( $doc['verificationStatus'] ?? '' ); ?> &middot; Profile: <?php echo esc_html( $doc['profile'] ?? '' ); ?> &middot; Machine-readable source: <a href="<?php echo esc_url( $json_link ); ?>">entitymap.json</a></p>
</header>

<nav class="toc">
  <h2>Entities in this map (<?php echo count( $entities ); ?>)</h2>
  <ul>
	<?php foreach ( $entities as $e ) : ?>
	<li><a href="#<?php echo esc_attr( $e['entityId'] ); ?>"><?php echo esc_html( $e['name'] ); ?></a></li>
	<?php endforeach; ?>
  </ul>
</nav>

<?php foreach ( $entities as $e ) : ?>
<section class="entity" id="<?php echo esc_attr( $e['entityId'] ); ?>">
  <h2><?php echo esc_html( $e['name'] ); ?> <span class="type"><?php echo esc_html( $e['@type'] ?? '' ); ?></span><?php if ( ! empty( $e['maturityStatus'] ) ) : ?> <span class="badge"><?php echo esc_html( $e['maturityStatus'] ); ?></span><?php endif; ?></h2>
	<?php if ( ! empty( $e['alternateName'] ) ) : ?>
	<div class="alt">Also known as: <?php echo esc_html( $e['alternateName'] ); ?></div>
	<?php endif; ?>
	<?php if ( ! empty( $e['canonicalLabel'] ) ) : ?>
	<div class="alt">Canonical concept: <?php echo esc_html( $e['canonicalLabel'] ); ?></div>
	<?php endif; ?>
  <p class="desc"><?php echo esc_html( $e['description'] ?? '' ); ?></p>
	<?php if ( ! empty( $e['sameAs'] ) ) : ?>
	<div class="alt"><a href="<?php echo esc_url( $e['sameAs'] ); ?>" target="_blank" rel="noopener">External reference</a></div>
	<?php endif; ?>
	<?php if ( ! empty( $e['hasChunks'] ) ) : ?>
  <h3>Evidence</h3>
		<?php foreach ( $e['hasChunks'] as $c ) : ?>
  <blockquote class="chunk">
	<p>&ldquo;<?php echo esc_html( $c['text'] ); ?>&rdquo;</p>
			<?php if ( ! empty( $c['sourceUrl'] ) ) : ?>
	<cite><a href="<?php echo esc_url( $c['sourceUrl'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( ! empty( $c['pageTitle'] ) ? $c['pageTitle'] : $c['sourceUrl'] ); ?></a><?php echo ! empty( $c['publisher'] ) ? ' &mdash; ' . esc_html( $c['publisher'] ) : ''; ?></cite>
			<?php elseif ( ! empty( $c['pageTitle'] ) ) : ?>
	<cite><?php echo esc_html( $c['pageTitle'] ); ?></cite>
			<?php endif; ?>
  </blockquote>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php if ( ! empty( $e['relations'] ) ) : ?>
  <h3>Relations</h3>
  <ul class="relations">
		<?php foreach ( $e['relations'] as $r ) : ?>
	<li><span class="pred"><?php echo esc_html( $r['predicate'] ); ?></span> &rarr; <?php echo esc_html( $r['targetName'] ?? $r['targetId'] ); ?><?php if ( ! empty( $r['confidence'] ) ) : ?> <span class="conf">(<?php echo esc_html( $r['confidence'] ); ?>)</span><?php endif; ?><?php if ( ! empty( $r['context']['condition'] ) ) : ?><div class="ctx">Condition: <?php echo esc_html( $r['context']['condition'] ); ?></div><?php endif; ?></li>
		<?php endforeach; ?>
  </ul>
	<?php endif; ?>
</section>
<?php endforeach; ?>

<footer>
  <p>This page is generated from <a href="<?php echo esc_url( $json_link ); ?>">entitymap.json</a> and kept in sync with it. Published by <?php echo esc_html( $pub_name ); ?> per the EntityMap open standard.</p>
</footer>

</body>
</html>
		<?php
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Publishing.
	 * ------------------------------------------------------------------- */

	/** Rebuild the caches and (re)write both static files. */
	public function regenerate() {
		delete_transient( self::CACHE_KEY );

		$entities = BL_EntityMap_Store::get_entities( true );
		$doc      = $this->get_document();
		$json     = $this->get_json( true );

		// Safety: never overwrite existing maps with empty ones. This keeps a seed
		// file (which the importer reads) intact while the database is still empty
		// — e.g. on first activation before Tools -> Import has been run.
		if ( empty( $entities ) ) {
			return;
		}

		$this->write_static_files( $json, $this->get_html( $doc ) );
		$this->write_llms();
	}

	/**
	 * Write both static files via WP_Filesystem. Records success so the admin can
	 * warn when the webroot isn't writable (the dynamic fallback covers it).
	 */
	public function write_static_files( $json, $html ) {
		if ( get_option( 'bl_em_enable_json', '1' ) !== '1' ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			update_option( 'bl_em_static_ok', '0' );
			return false;
		}

		$ok_json = $wp_filesystem->put_contents( $this->static_path(), $json, FS_CHMOD_FILE );
		$ok_html = $wp_filesystem->put_contents( $this->static_html_path(), $html, FS_CHMOD_FILE );

		update_option( 'bl_em_static_ok', ( $ok_json && $ok_html ) ? '1' : '0' );
		return (bool) ( $ok_json && $ok_html );
	}

	/** Back-compat: write only the JSON file. */
	public function write_static_file( $json ) {
		return $this->write_static_files( $json, $this->get_html() );
	}

	/* ---------------------------------------------------------------------
	 * llms.txt — a managed pointer block spliced into the site's llms.txt.
	 *
	 * The plugin owns ONLY the content between its markers; any hand-authored
	 * llms.txt content around them is preserved. If the file has no markers the
	 * block is appended; if there is no file, a minimal one is created.
	 * ------------------------------------------------------------------- */

	const LLMS_BEGIN = '<!-- BEGIN BL AI Tools: EntityMap (auto-generated — do not edit between these markers) -->';
	const LLMS_END   = '<!-- END BL AI Tools: EntityMap -->';

	/** Absolute path to the site's llms.txt (webroot). Filterable. */
	public function static_llms_path() {
		$path = trailingslashit( dirname( ABSPATH ) ) . 'llms.txt';
		return apply_filters( 'bl_entitymap_llms_path', $path );
	}

	/**
	 * The managed block (markers included). Uses the canonical base URL so the
	 * links are environment-independent, like the rest of the generated output.
	 */
	public function llms_block() {
		$base      = BL_EntityMap_Store::base_url();
		$publisher = get_option( 'bl_em_publisher_name', 'BrightLocal' );

		$lines   = array();
		$lines[] = self::LLMS_BEGIN;
		$lines[] = '';
		$lines[] = '## Machine-readable index';
		$lines[] = '';
		$lines[] = '- [' . $publisher . ' EntityMap (HTML)](' . $base . '/entitymap.html) — entity-first index of what ' . $publisher . ' knows, for AI systems and retrieval pipelines';
		$lines[] = '- [' . $publisher . ' EntityMap (JSON)](' . $base . '/entitymap.json) — machine-readable source';
		$lines[] = '';
		$lines[] = self::LLMS_END;

		return implode( "\n", $lines );
	}

	/**
	 * Splice the managed block into llms.txt, preserving everything else.
	 * Gated by the "Add EntityMap pointer to llms.txt" setting (off by default).
	 *
	 * @return bool True on a successful write.
	 */
	public function write_llms() {
		if ( get_option( 'bl_em_enable_llms', '0' ) !== '1' ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			update_option( 'bl_em_llms_ok', '0' );
			return false;
		}

		$path    = $this->static_llms_path();
		$block   = $this->llms_block();
		$current = $wp_filesystem->exists( $path ) ? (string) $wp_filesystem->get_contents( $path ) : '';

		$begin = strpos( $current, self::LLMS_BEGIN );
		$end   = strpos( $current, self::LLMS_END );

		if ( $begin !== false && $end !== false && $end > $begin ) {
			// Replace the existing managed region in place (markers included).
			$end_pos  = $end + strlen( self::LLMS_END );
			$contents = substr_replace( $current, $block, $begin, $end_pos - $begin );
		} elseif ( trim( $current ) !== '' ) {
			// File exists but has no markers yet — append the block.
			$contents = rtrim( $current, "\n" ) . "\n\n" . $block . "\n";
		} else {
			// No file — create a minimal llms.txt around the block.
			$contents = '# ' . get_option( 'bl_em_publisher_name', 'BrightLocal' ) . "\n\n" . $block . "\n";
		}

		$ok = $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
		update_option( 'bl_em_llms_ok', $ok ? '1' : '0' );
		return (bool) $ok;
	}

	/* ---------------------------------------------------------------------
	 * Dynamic endpoints (fallback when no static file exists).
	 * ------------------------------------------------------------------- */

	public function maybe_serve() {
		$is_json = (bool) get_query_var( self::QUERY_VAR );
		$is_html = (bool) get_query_var( self::QUERY_VAR_HTML );

		if ( ! $is_json && ! $is_html ) {
			return;
		}

		if ( get_option( 'bl_em_enable_json', '1' ) !== '1' ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'X-Robots-Tag: index, follow' );

		if ( $is_html ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			echo $this->get_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo $this->get_json(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}
}
