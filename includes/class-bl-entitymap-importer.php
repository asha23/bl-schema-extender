<?php
/**
 * EntityMap importer.
 *
 * Seeds the bl_entity CPT (and the publisher/root settings) from an existing
 * entitymap.json file, so the curated, verified data becomes the DB source of
 * truth. Idempotent: entities are upserted by entityId, so re-running updates
 * rather than duplicates.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Importer {

	/**
	 * Import from a JSON file path.
	 *
	 * @param string $path
	 * @return array|WP_Error  [created, updated, skipped] counts on success.
	 */
	public static function import_file( $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'no_file', 'entitymap.json not found at: ' . $path );
		}

		$raw     = file_get_contents( $path );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['entities'] ) ) {
			return new WP_Error( 'bad_json', 'File is not a valid EntityMap document.' );
		}

		return self::import_array( $decoded );
	}

	/**
	 * Import from a decoded document array.
	 *
	 * @param array $doc
	 * @return array
	 */
	public static function import_array( $doc ) {
		// Root / publisher settings.
		if ( ! empty( $doc['version'] ) ) {
			update_option( 'bl_em_version', sanitize_text_field( $doc['version'] ) );
		}
		if ( ! empty( $doc['schema'] ) ) {
			update_option( 'bl_em_schema_url', esc_url_raw( $doc['schema'] ) );
		}
		if ( ! empty( $doc['verificationStatus'] ) ) {
			update_option( 'bl_em_verification', sanitize_text_field( $doc['verificationStatus'] ) );
		}
		if ( ! empty( $doc['profile'] ) ) {
			update_option( 'bl_em_profile', sanitize_text_field( $doc['profile'] ) );
		}
		if ( ! empty( $doc['publisher']['name'] ) ) {
			update_option( 'bl_em_publisher_name', sanitize_text_field( $doc['publisher']['name'] ) );
		}
		if ( ! empty( $doc['publisher']['url'] ) ) {
			update_option( 'bl_em_publisher_url', esc_url_raw( $doc['publisher']['url'] ) );
		}
		update_option( 'bl_em_publisher_sameas', ! empty( $doc['publisher']['sameAs'] ) ? esc_url_raw( $doc['publisher']['sameAs'] ) : '' );

		$created = 0;
		$updated = 0;

		foreach ( $doc['entities'] as $entity ) {
			if ( empty( $entity['entityId'] ) || empty( $entity['name'] ) ) {
				continue;
			}

			$existing = self::find_by_entity_id( $entity['entityId'] );

			$postarr = array(
				'post_type'    => BL_EntityMap_Store::CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_strip_all_tags( $entity['name'] ),
				'post_content' => isset( $entity['description'] ) ? $entity['description'] : '',
			);

			if ( $existing ) {
				$postarr['ID'] = $existing;
				wp_update_post( $postarr );
				$post_id = $existing;
				$updated++;
			} else {
				$post_id = wp_insert_post( $postarr );
				$created++;
			}

			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, '_bl_entity_id', sanitize_text_field( $entity['entityId'] ) );
			update_post_meta( $post_id, '_bl_type', isset( $entity['@type'] ) ? sanitize_text_field( $entity['@type'] ) : 'Concept' );
			update_post_meta( $post_id, '_bl_alternate_name', isset( $entity['alternateName'] ) ? sanitize_text_field( $entity['alternateName'] ) : '' );
			update_post_meta( $post_id, '_bl_canonical_label', isset( $entity['canonicalLabel'] ) ? sanitize_text_field( $entity['canonicalLabel'] ) : '' );
			update_post_meta( $post_id, '_bl_same_as', isset( $entity['sameAs'] ) ? esc_url_raw( $entity['sameAs'] ) : '' );
			update_post_meta( $post_id, '_bl_maturity', isset( $entity['maturityStatus'] ) ? sanitize_text_field( $entity['maturityStatus'] ) : '' );
			update_post_meta( $post_id, '_bl_page_url', self::primary_url( $entity ) );
			update_post_meta( $post_id, '_bl_chunks', self::map_chunks( $entity ) );
			update_post_meta( $post_id, '_bl_relations', self::map_relations( $entity ) );
		}

		BL_EntityMap_Store::flush_cache();

		return array( 'created' => $created, 'updated' => $updated );
	}

	private static function find_by_entity_id( $entity_id ) {
		$posts = get_posts( array(
			'post_type'   => BL_EntityMap_Store::CPT,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_bl_entity_id',
			'meta_value'  => $entity_id,
		) );
		return $posts ? (int) $posts[0] : 0;
	}

	/** Choose the entity's canonical page: first 'definition' chunk URL, else first chunk URL. */
	private static function primary_url( $entity ) {
		if ( empty( $entity['hasChunks'] ) || ! is_array( $entity['hasChunks'] ) ) {
			return '';
		}
		$first = '';
		foreach ( $entity['hasChunks'] as $chunk ) {
			if ( empty( $chunk['sourceUrl'] ) ) {
				continue;
			}
			if ( $first === '' ) {
				$first = $chunk['sourceUrl'];
			}
			if ( isset( $chunk['contentType'] ) && $chunk['contentType'] === 'definition' ) {
				return esc_url_raw( $chunk['sourceUrl'] );
			}
		}
		return $first ? esc_url_raw( $first ) : '';
	}

	private static function map_chunks( $entity ) {
		if ( empty( $entity['hasChunks'] ) || ! is_array( $entity['hasChunks'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $entity['hasChunks'] as $chunk ) {
			if ( empty( $chunk['text'] ) ) {
				continue;
			}
			$out[] = array(
				'chunkId'      => isset( $chunk['chunkId'] ) ? sanitize_text_field( $chunk['chunkId'] ) : '',
				'text'         => sanitize_textarea_field( $chunk['text'] ),
				'sourceUrl'    => isset( $chunk['sourceUrl'] ) ? esc_url_raw( $chunk['sourceUrl'] ) : '',
				'pageTitle'    => isset( $chunk['pageTitle'] ) ? sanitize_text_field( $chunk['pageTitle'] ) : '',
				'contentType'  => isset( $chunk['contentType'] ) ? sanitize_text_field( $chunk['contentType'] ) : '',
				'audienceType' => isset( $chunk['audienceType'] ) ? sanitize_text_field( $chunk['audienceType'] ) : '',
			);
		}
		return $out;
	}

	private static function map_relations( $entity ) {
		if ( empty( $entity['relations'] ) || ! is_array( $entity['relations'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $entity['relations'] as $rel ) {
			if ( empty( $rel['predicate'] ) || empty( $rel['targetId'] ) ) {
				continue;
			}
			$out[] = array(
				'predicate'  => sanitize_text_field( $rel['predicate'] ),
				'targetId'   => sanitize_text_field( $rel['targetId'] ),
				'confidence' => isset( $rel['confidence'] ) ? sanitize_text_field( $rel['confidence'] ) : '',
				'condition'  => isset( $rel['context']['condition'] ) ? sanitize_text_field( $rel['context']['condition'] ) : '',
			);
		}
		return $out;
	}
}
