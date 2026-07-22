<?php
/**
 * EntityMap Store
 *
 * The single point that reads EntityMap data out of the database (the bl_entity
 * custom post type + its meta) and normalises it into the exact array shape used
 * by entitymap.json. Everything else in the plugin (JSON generator, Yoast schema,
 * validator, admin previews) consumes this — so there is one source of truth.
 *
 * Results are cached in a transient and invalidated whenever an entity is saved,
 * deleted, or its status changes.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Store {

	const CPT       = 'bl_entity';
	const CACHE_KEY = 'bl_entitymap_entities_v2';

	/* ---------------------------------------------------------------------
	 * Controlled vocabularies (surfaced in the admin as dropdowns).
	 * ------------------------------------------------------------------- */

	/** @return string[] schema.org-ish entity types used by the EntityMap. */
	public static function entity_types() {
		return array(
			'Organization', 'Concept', 'Platform', 'Service',
			'SoftwareProduct', 'ProprietaryTerm', 'Metric',
		);
	}

	/** Entity types that should surface as schema.org DefinedTerm nodes. */
	public static function definedterm_types() {
		return array( 'Concept', 'ProprietaryTerm' );
	}

	/** Entity types that represent an offering (Organization -> makesOffer). */
	public static function offer_types() {
		return array( 'Platform', 'Service', 'SoftwareProduct' );
	}

	/** @return string[] the relation predicate vocabulary. */
	public static function predicates() {
		return array(
			'OFFERS', 'INCLUDES', 'PART_OF', 'COVERS', 'ENABLES', 'DEPENDS_ON',
			'ACHIEVES', 'IMPROVES', 'MEASURES', 'PRODUCED_BY', 'DESCRIBED_BY',
			'RELATED_TO',
		);
	}

	public static function content_types() {
		return array( 'definition', 'evidence', 'statistic', 'procedure' );
	}

	public static function audience_types() {
		return array( 'general', 'technical', 'executive' );
	}

	public static function confidence_levels() {
		return array( '', 'stated', 'inferred' );
	}

	public static function maturity_levels() {
		return array( '', 'established', 'emerging', 'deprecated' );
	}

	/**
	 * Map an EntityMap @type to the schema.org type used for an offered item.
	 */
	public static function offer_schema_type( $entity_type ) {
		return in_array( $entity_type, array( 'Platform', 'SoftwareProduct' ), true )
			? 'SoftwareApplication'
			: 'Service';
	}

	/* ---------------------------------------------------------------------
	 * Reading.
	 * ------------------------------------------------------------------- */

	/**
	 * Get all published entities, normalised to entitymap.json shape.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array
	 */
	public static function get_entities( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$posts = get_posts( array(
			'post_type'        => self::CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'meta_value',
			'meta_key'         => '_bl_entity_id',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );

		// First pass: id -> name, so relations can carry a resolved targetName.
		$names = array();
		foreach ( $posts as $post ) {
			$eid = get_post_meta( $post->ID, '_bl_entity_id', true );
			if ( $eid ) {
				$names[ $eid ] = get_the_title( $post );
			}
		}

		// Second pass: build the full entity objects.
		$entities = array();
		foreach ( $posts as $post ) {
			$entities[] = self::build_entity( $post, $names );
		}

		set_transient( self::CACHE_KEY, $entities, DAY_IN_SECONDS );

		return $entities;
	}

	/**
	 * Build one normalised entity array from a post + its meta.
	 *
	 * @param WP_Post  $post
	 * @param string[] $names entityId => name map for resolving relation targets.
	 * @return array
	 */
	public static function build_entity( $post, $names = array() ) {
		$id = get_post_meta( $post->ID, '_bl_entity_id', true );

		$entity = array(
			'entityId'    => $id ? $id : 'e_' . $post->ID,
			'@type'       => get_post_meta( $post->ID, '_bl_type', true ) ?: 'Concept',
			'name'        => get_the_title( $post ),
			'description' => trim( wp_strip_all_tags( $post->post_content ) ),
		);

		$alt = get_post_meta( $post->ID, '_bl_alternate_name', true );
		if ( $alt !== '' ) {
			$entity['alternateName'] = $alt;
		}

		$canon = get_post_meta( $post->ID, '_bl_canonical_label', true );
		if ( $canon !== '' ) {
			$entity['canonicalLabel'] = $canon;
		}

		$entity['hasChunks'] = self::build_chunks( $post );
		$entity['relations'] = self::build_relations( $post, $names );

		$same = get_post_meta( $post->ID, '_bl_same_as', true );
		if ( $same !== '' ) {
			$entity['sameAs'] = $same;
		}

		$maturity = get_post_meta( $post->ID, '_bl_maturity', true );
		if ( $maturity !== '' ) {
			$entity['maturityStatus'] = $maturity;
		}

		return $entity;
	}

	/**
	 * Normalise the stored chunk rows into hasChunks[].
	 */
	private static function build_chunks( $post ) {
		$rows = get_post_meta( $post->ID, '_bl_chunks', true );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$publisher = get_option( 'bl_em_publisher_name', 'BrightLocal' );
		$out       = array();
		$i         = 0;

		foreach ( $rows as $row ) {
			$text = isset( $row['text'] ) ? trim( $row['text'] ) : '';
			if ( $text === '' ) {
				continue;
			}
			$i++;

			$chunk = array(
				'chunkId'  => ! empty( $row['chunkId'] ) ? $row['chunkId'] : 'c_' . $post->ID . '_' . $i,
				'text'     => $text,
			);

			if ( ! empty( $row['sourceUrl'] ) ) {
				$chunk['sourceUrl'] = esc_url_raw( $row['sourceUrl'] );
			}
			if ( ! empty( $row['pageTitle'] ) ) {
				$chunk['pageTitle'] = $row['pageTitle'];
			}

			$chunk['publisher'] = $publisher;

			if ( ! empty( $row['retrieved'] ) ) {
				$chunk['retrieved'] = $row['retrieved'];
			}
			if ( ! empty( $row['contentType'] ) ) {
				$chunk['contentType'] = $row['contentType'];
			}
			if ( ! empty( $row['audienceType'] ) ) {
				$chunk['audienceType'] = $row['audienceType'];
			}

			$out[] = $chunk;
		}

		return $out;
	}

	/**
	 * Normalise the stored relation rows into relations[], resolving targetName.
	 */
	private static function build_relations( $post, $names ) {
		$rows = get_post_meta( $post->ID, '_bl_relations', true );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$predicate = isset( $row['predicate'] ) ? $row['predicate'] : '';
			$target    = isset( $row['targetId'] ) ? $row['targetId'] : '';

			if ( $predicate === '' || $target === '' ) {
				continue;
			}

			$relation = array(
				'predicate' => $predicate,
				'targetId'  => $target,
			);

			if ( isset( $names[ $target ] ) ) {
				$relation['targetName'] = $names[ $target ];
			}

			if ( ! empty( $row['confidence'] ) ) {
				$relation['confidence'] = $row['confidence'];
			}
			if ( ! empty( $row['condition'] ) ) {
				$relation['context'] = array( 'condition' => $row['condition'] );
			}

			$out[] = $relation;
		}

		return $out;
	}

	/**
	 * Find the Organization entity (used to drive Yoast's Organization node).
	 *
	 * @return array|null
	 */
	public static function get_organization() {
		foreach ( self::get_entities() as $entity ) {
			if ( isset( $entity['@type'] ) && $entity['@type'] === 'Organization' ) {
				return $entity;
			}
		}
		return null;
	}

	/**
	 * Entities whose canonical page URL matches the given URL (for per-page schema).
	 *
	 * @param string $url
	 * @return array
	 */
	public static function get_entities_for_url( $url ) {
		$url   = self::normalise_url( $url );
		$match = array();

		$posts = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_key'    => '_bl_page_url',
			'meta_value'  => '',
			'meta_compare' => '!=',
			'suppress_filters' => false,
		) );

		if ( empty( $posts ) ) {
			return $match;
		}

		$names = array();
		foreach ( self::get_entities() as $e ) {
			$names[ $e['entityId'] ] = $e['name'];
		}

		foreach ( $posts as $post ) {
			$page = self::normalise_url( get_post_meta( $post->ID, '_bl_page_url', true ) );
			if ( $page !== '' && $page === $url ) {
				$match[] = self::build_entity( $post, $names );
			}
		}

		return $match;
	}

	/**
	 * Normalise a URL to a host-agnostic path for comparison.
	 *
	 * Per-page matching compares the PATH only, never the host, so an entity
	 * authored with a production URL (https://www.brightlocal.com/learn/x/) still
	 * matches the same page on staging or local (https://site.test/learn/x/).
	 * Accepts either a full URL or a bare path ("/learn/x/").
	 */
	public static function normalise_url( $url ) {
		if ( ! $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( $path === '' && empty( $parts['host'] ) ) {
			$path = $url; // bare string with no scheme/host — treat as a path
		}
		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Canonical base URL used for schema @id references. Defaults to this site's
	 * URL (so it auto-adapts to any server); can be overridden in Settings to pin
	 * a canonical domain (e.g. production) across environments.
	 */
	public static function base_url() {
		$base = get_option( 'bl_em_base_url', '' );
		return untrailingslashit( $base !== '' ? $base : home_url() );
	}

	/** Option holding the monotonic entity-id high-water mark. */
	const SEQ_OPTION = 'bl_em_entity_seq';

	/**
	 * Highest e_NNN sequence number currently stored in the DB (0 if none).
	 */
	private static function max_existing_seq() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_bl_entity_id'
		) );

		$max = 0;
		foreach ( (array) $ids as $id ) {
			if ( preg_match( '/^e_(\d+)$/', $id, $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}
		return $max;
	}

	/**
	 * Raise the monotonic id counter to at least the highest id in the DB.
	 *
	 * Call after any path that writes _bl_entity_id directly (e.g. the importer)
	 * so a later allocation can never collide with or reuse an existing id.
	 */
	public static function reconcile_seq() {
		$seq = max( (int) get_option( self::SEQ_OPTION, 0 ), self::max_existing_seq() );
		update_option( self::SEQ_OPTION, $seq );
	}

	/**
	 * Next available e_NNN entity id.
	 *
	 * Backed by a monotonic high-water mark (self::SEQ_OPTION) so an id is never
	 * reused: deleting the highest-numbered entity does not free its id for the
	 * next new one. The counter is reconciled with the DB on every allocation, so
	 * it also stays correct after an import that writes ids directly.
	 */
	public static function next_entity_id() {
		self::reconcile_seq();
		$seq = (int) get_option( self::SEQ_OPTION, 0 ) + 1;
		update_option( self::SEQ_OPTION, $seq );
		return sprintf( 'e_%03d', $seq );
	}

	/* ---------------------------------------------------------------------
	 * Writing (shared by the classic CPT editor and the Manage Entities screen,
	 * so both sanitise and persist identically — one source of truth for saves
	 * as well as reads).
	 *
	 * Callers must pass values already unslashed (e.g. wp_unslash( $_POST )); the
	 * sanitisers below do not unslash, so they are safe for both $_POST and
	 * JSON-decoded input.
	 * ------------------------------------------------------------------- */

	/**
	 * Persist an entity's meta fields (NOT the post title/content — the caller
	 * owns the WP_Post). Reads editable input, writes the _bl_* meta keys.
	 *
	 * @param int   $post_id
	 * @param array $in Keys: type, alternate_name, canonical_label, same_as,
	 *                  maturity, page_url, chunks[], relations[].
	 */
	public static function save_entity_meta( $post_id, array $in ) {
		$type = isset( $in['type'] ) && $in['type'] !== '' ? $in['type'] : 'Concept';

		update_post_meta( $post_id, '_bl_type', sanitize_text_field( $type ) );
		update_post_meta( $post_id, '_bl_alternate_name', sanitize_text_field( isset( $in['alternate_name'] ) ? $in['alternate_name'] : '' ) );
		update_post_meta( $post_id, '_bl_canonical_label', sanitize_text_field( isset( $in['canonical_label'] ) ? $in['canonical_label'] : '' ) );
		update_post_meta( $post_id, '_bl_maturity', sanitize_text_field( isset( $in['maturity'] ) ? $in['maturity'] : '' ) );
		update_post_meta( $post_id, '_bl_same_as', isset( $in['same_as'] ) ? esc_url_raw( $in['same_as'] ) : '' );
		update_post_meta( $post_id, '_bl_page_url', isset( $in['page_url'] ) ? esc_url_raw( $in['page_url'] ) : '' );
		update_post_meta( $post_id, '_bl_chunks', self::sanitize_chunks( isset( $in['chunks'] ) ? (array) $in['chunks'] : array() ) );
		update_post_meta( $post_id, '_bl_relations', self::sanitize_relations( isset( $in['relations'] ) ? (array) $in['relations'] : array() ) );
	}

	/** Sanitise chunk rows. Empty-text rows are dropped. */
	public static function sanitize_chunks( $rows ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = isset( $row['text'] ) ? sanitize_textarea_field( $row['text'] ) : '';
			if ( $text === '' ) {
				continue;
			}
			$clean[] = array(
				'chunkId'      => isset( $row['chunkId'] ) ? sanitize_text_field( $row['chunkId'] ) : '',
				'text'         => $text,
				'sourceUrl'    => isset( $row['sourceUrl'] ) ? esc_url_raw( $row['sourceUrl'] ) : '',
				'pageTitle'    => isset( $row['pageTitle'] ) ? sanitize_text_field( $row['pageTitle'] ) : '',
				'contentType'  => isset( $row['contentType'] ) ? sanitize_text_field( $row['contentType'] ) : '',
				'audienceType' => isset( $row['audienceType'] ) ? sanitize_text_field( $row['audienceType'] ) : '',
			);
		}
		return array_values( $clean );
	}

	/** Sanitise relation rows. Rows missing a predicate or target are dropped. */
	public static function sanitize_relations( $rows ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$predicate = isset( $row['predicate'] ) ? sanitize_text_field( $row['predicate'] ) : '';
			$target    = isset( $row['targetId'] ) ? sanitize_text_field( $row['targetId'] ) : '';
			if ( $predicate === '' || $target === '' ) {
				continue;
			}
			$clean[] = array(
				'predicate'  => $predicate,
				'targetId'   => $target,
				'confidence' => isset( $row['confidence'] ) ? sanitize_text_field( $row['confidence'] ) : '',
				'condition'  => isset( $row['condition'] ) ? sanitize_text_field( $row['condition'] ) : '',
			);
		}
		return array_values( $clean );
	}

	/**
	 * Read one entity's RAW, editable meta (unlike build_entity(), which returns
	 * the normalised output shape with empties dropped and condition remapped to
	 * context). Used to populate the editor. Returns null if the post is missing
	 * or not a bl_entity.
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public static function get_entity_for_edit( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			return null;
		}

		$chunks = get_post_meta( $post_id, '_bl_chunks', true );
		$rels   = get_post_meta( $post_id, '_bl_relations', true );

		return array(
			'postId'          => $post_id,
			'entityId'        => get_post_meta( $post_id, '_bl_entity_id', true ),
			'name'            => $post->post_title,
			'description'     => $post->post_content,
			'type'            => get_post_meta( $post_id, '_bl_type', true ) ?: 'Concept',
			'alternate_name'  => get_post_meta( $post_id, '_bl_alternate_name', true ),
			'canonical_label' => get_post_meta( $post_id, '_bl_canonical_label', true ),
			'same_as'         => get_post_meta( $post_id, '_bl_same_as', true ),
			'maturity'        => get_post_meta( $post_id, '_bl_maturity', true ),
			'page_url'        => get_post_meta( $post_id, '_bl_page_url', true ),
			'chunks'          => is_array( $chunks ) ? array_values( $chunks ) : array(),
			'relations'       => is_array( $rels ) ? array_values( $rels ) : array(),
		);
	}

	/**
	 * Invalidate the cache and regenerate the static JSON file.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		do_action( 'bl_entitymap_changed' );
	}
}
