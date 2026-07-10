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
	 * @param bool   $replace Trash entities not present in the file (full sync).
	 * @return array|WP_Error  [created, updated, removed] counts on success.
	 */
	public static function import_file( $path, $replace = false ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'no_file', 'entitymap.json not found at: ' . $path );
		}

		$raw     = file_get_contents( $path );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['entities'] ) ) {
			return new WP_Error( 'bad_json', 'File is not a valid EntityMap document.' );
		}

		return self::import_array( $decoded, $replace );
	}

	/**
	 * Import from a decoded document array.
	 *
	 * @param array $doc
	 * @param bool  $replace When true, the document is treated as the complete,
	 *                       authoritative map: any existing entity whose entityId
	 *                       is absent from $doc is moved to Trash (recoverable),
	 *                       keeping the set tidy. When false, it is a merge/upsert.
	 * @return array  [created, updated, removed]
	 */
	public static function import_array( $doc, $replace = false ) {
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
		$removed = 0;
		$seen    = array();

		foreach ( $doc['entities'] as $entity ) {
			if ( empty( $entity['entityId'] ) || empty( $entity['name'] ) ) {
				continue;
			}

			$seen[ $entity['entityId'] ] = true;

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

		// Full-sync: trash any entity not present in the authoritative document.
		if ( $replace ) {
			$all = get_posts( array(
				'post_type'   => BL_EntityMap_Store::CPT,
				'post_status' => array( 'publish', 'draft', 'pending' ),
				'numberposts' => -1,
				'fields'      => 'ids',
			) );
			foreach ( $all as $pid ) {
				$eid = get_post_meta( $pid, '_bl_entity_id', true );
				if ( $eid && ! isset( $seen[ $eid ] ) ) {
					wp_trash_post( $pid );
					$removed++;
				}
			}
		}

		BL_EntityMap_Store::flush_cache();

		return array( 'created' => $created, 'updated' => $updated, 'removed' => $removed );
	}

	private static function find_by_entity_id( $entity_id ) {
		// Include 'trash' explicitly — WP's 'any' excludes it, which would cause a
		// previously-removed entity to be recreated as a duplicate on re-import
		// instead of being restored/updated in place.
		$posts = get_posts( array(
			'post_type'   => BL_EntityMap_Store::CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
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

	/**
	 * Validate a decoded document WITHOUT touching the database (dry run).
	 *
	 * @param mixed $doc  Decoded JSON (or null if it failed to parse).
	 * @return array  [ 'errors' => [], 'warnings' => [], 'stats' => [] ]
	 */
	public static function validate_document( $doc ) {
		$errors   = array();
		$warnings = array();
		$stats    = array( 'entities' => 0, 'chunks' => 0, 'relations' => 0 );

		if ( ! is_array( $doc ) ) {
			$errors[] = 'File is not valid JSON, or is not a JSON object.';
			return compact( 'errors', 'warnings', 'stats' );
		}
		if ( empty( $doc['entities'] ) || ! is_array( $doc['entities'] ) ) {
			$errors[] = 'Document has no "entities" array.';
			return compact( 'errors', 'warnings', 'stats' );
		}

		$known_types      = BL_EntityMap_Store::entity_types();
		$known_predicates = BL_EntityMap_Store::predicates();

		$ids       = array();
		$chunk_ids = array();

		// First pass: entities, required fields, ids, chunks.
		foreach ( $doc['entities'] as $i => $e ) {
			$label = ! empty( $e['entityId'] ) ? $e['entityId'] : '#' . $i;

			foreach ( array( 'entityId', '@type', 'name', 'description' ) as $req ) {
				if ( empty( $e[ $req ] ) ) {
					$errors[] = "Entity $label is missing required field \"$req\".";
				}
			}

			if ( ! empty( $e['entityId'] ) ) {
				if ( isset( $ids[ $e['entityId'] ] ) ) {
					$errors[] = "Duplicate entityId \"{$e['entityId']}\".";
				}
				$ids[ $e['entityId'] ] = true;
			}

			if ( ! empty( $e['@type'] ) && ! in_array( $e['@type'], $known_types, true ) ) {
				$warnings[] = "Entity $label uses an unrecognised @type \"{$e['@type']}\".";
			}

			if ( ! empty( $e['sameAs'] ) && ! filter_var( $e['sameAs'], FILTER_VALIDATE_URL ) ) {
				$warnings[] = "Entity $label has a sameAs that is not a valid URL.";
			}

			if ( ! empty( $e['hasChunks'] ) && is_array( $e['hasChunks'] ) ) {
				foreach ( $e['hasChunks'] as $c ) {
					$stats['chunks']++;
					if ( empty( $c['text'] ) ) {
						$warnings[] = "A chunk in entity $label has no text.";
					}
					if ( ! empty( $c['chunkId'] ) ) {
						if ( isset( $chunk_ids[ $c['chunkId'] ] ) ) {
							$errors[] = "Duplicate chunkId \"{$c['chunkId']}\".";
						}
						$chunk_ids[ $c['chunkId'] ] = true;
					}
				}
			}
		}

		// Second pass: relation integrity (needs the full id set).
		foreach ( $doc['entities'] as $e ) {
			$label = ! empty( $e['entityId'] ) ? $e['entityId'] : '?';
			if ( empty( $e['relations'] ) || ! is_array( $e['relations'] ) ) {
				continue;
			}
			foreach ( $e['relations'] as $r ) {
				$stats['relations']++;
				if ( empty( $r['predicate'] ) || empty( $r['targetId'] ) ) {
					$warnings[] = "Entity $label has an incomplete relation (missing predicate or target).";
					continue;
				}
				if ( ! isset( $ids[ $r['targetId'] ] ) ) {
					$errors[] = "Entity $label has a relation to a missing entity \"{$r['targetId']}\".";
				}
				if ( ! in_array( $r['predicate'], $known_predicates, true ) ) {
					$warnings[] = "Entity $label uses an unrecognised predicate \"{$r['predicate']}\".";
				}
			}
		}

		$stats['entities'] = count( $doc['entities'] );

		return compact( 'errors', 'warnings', 'stats' );
	}
}
