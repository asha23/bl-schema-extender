<?php
/**
 * Yoast Schema.org integration (DB-driven).
 *
 * Reads the Store and layers EntityMap data into Yoast's @graph:
 *   - enrich_organization(): adds sameAs / knowsAbout / makesOffer to the
 *     sitewide Organization node (fires only when Yoast site representation is
 *     "company"; that node isn't generated otherwise).
 *   - inject_page_nodes(): adds a DefinedTerm / Service / SoftwareApplication
 *     node to the specific page each entity is attached to (via its page URL).
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Schema {

	public function __construct() {
		if ( is_admin() ) {
			return;
		}
		if ( get_option( 'bl_em_enable_org', '1' ) === '1' ) {
			add_filter( 'wpseo_schema_organization', array( $this, 'enrich_organization' ), 11, 1 );
		}
		if ( get_option( 'bl_em_enable_perpage', '1' ) === '1' ) {
			add_filter( 'wpseo_schema_graph', array( $this, 'inject_page_nodes' ), 11, 2 );
		}
	}

	/** Base for @id fragments, e.g. https://site/entitymap.json#e_003 */
	private function id_base() {
		return BL_EntityMap_Store::base_url() . '/entitymap.json#';
	}

	/* ---------------------------------------------------------------------
	 * Sitewide Organization enrichment.
	 * ------------------------------------------------------------------- */

	public function enrich_organization( $data ) {
		$entities = BL_EntityMap_Store::get_entities();
		if ( empty( $entities ) ) {
			return $data;
		}

		$by_id = array();
		$org   = null;
		foreach ( $entities as $entity ) {
			$by_id[ $entity['entityId'] ] = $entity;
			if ( $org === null && isset( $entity['@type'] ) && $entity['@type'] === 'Organization' ) {
				$org = $entity;
			}
		}

		$base = $this->id_base();

		// sameAs (verified identifiers on the Organization entity).
		if ( $org !== null && ! empty( $org['sameAs'] ) ) {
			$existing = array();
			if ( isset( $data['sameAs'] ) ) {
				$existing = is_array( $data['sameAs'] ) ? $data['sameAs'] : array( $data['sameAs'] );
			}
			$existing[]     = $org['sameAs'];
			$data['sameAs'] = array_values( array_unique( $existing ) );
		}

		// knowsAbout (concepts / proprietary terms).
		$knows = array();
		foreach ( $entities as $entity ) {
			if ( ! isset( $entity['@type'] ) || ! in_array( $entity['@type'], BL_EntityMap_Store::definedterm_types(), true ) ) {
				continue;
			}
			$term = array(
				'@type' => 'DefinedTerm',
				'@id'   => $base . $entity['entityId'],
				'name'  => $entity['name'],
			);
			if ( ! empty( $entity['description'] ) ) {
				$term['description'] = $entity['description'];
			}
			if ( ! empty( $entity['sameAs'] ) ) {
				$term['sameAs'] = $entity['sameAs'];
			}
			$knows[] = $term;
		}
		if ( ! empty( $knows ) ) {
			$data['knowsAbout'] = $knows;
		}

		// makesOffer (things the Organization OFFERS).
		if ( $org !== null && ! empty( $org['relations'] ) ) {
			$offers = array();
			foreach ( $org['relations'] as $relation ) {
				if ( ! isset( $relation['predicate'] ) || $relation['predicate'] !== 'OFFERS' ) {
					continue;
				}
				$target = isset( $relation['targetId'], $by_id[ $relation['targetId'] ] ) ? $by_id[ $relation['targetId'] ] : null;
				if ( $target === null ) {
					continue;
				}
				$item = array(
					'@type' => BL_EntityMap_Store::offer_schema_type( isset( $target['@type'] ) ? $target['@type'] : '' ),
					'@id'   => $base . $target['entityId'],
					'name'  => $target['name'],
				);
				if ( ! empty( $target['description'] ) ) {
					$item['description'] = $target['description'];
				}
				$offers[] = array(
					'@type'       => 'Offer',
					'itemOffered' => $item,
				);
			}
			if ( ! empty( $offers ) ) {
				$data['makesOffer'] = $offers;
			}
		}

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Per-page nodes.
	 * ------------------------------------------------------------------- */

	public function inject_page_nodes( $graph, $context = null ) {
		$url = $this->current_url();
		if ( $url === '' ) {
			return $graph;
		}

		$entities = BL_EntityMap_Store::get_entities_for_url( $url );
		if ( empty( $entities ) ) {
			return $graph;
		}

		$base = $this->id_base();

		foreach ( $entities as $entity ) {
			$type = isset( $entity['@type'] ) ? $entity['@type'] : '';

			// Organization entities are already represented by Yoast's sitewide
			// Organization node (and enriched there), so skip them here to avoid
			// emitting a redundant, unlinked duplicate node on their own page.
			if ( $type === 'Organization' ) {
				continue;
			}

			if ( in_array( $type, BL_EntityMap_Store::definedterm_types(), true ) ) {
				$schema_type = 'DefinedTerm';
			} elseif ( in_array( $type, BL_EntityMap_Store::offer_types(), true ) ) {
				$schema_type = BL_EntityMap_Store::offer_schema_type( $type );
			} else {
				$schema_type = 'Thing';
			}

			$node = array(
				'@type' => $schema_type,
				'@id'   => $base . $entity['entityId'],
				'name'  => $entity['name'],
			);
			if ( ! empty( $entity['description'] ) ) {
				$node['description'] = $entity['description'];
			}
			if ( ! empty( $entity['sameAs'] ) ) {
				$node['sameAs'] = $entity['sameAs'];
			}
			if ( ! empty( $entity['alternateName'] ) ) {
				$node['alternateName'] = $entity['alternateName'];
			}

			$graph[] = $node;
		}

		return $graph;
	}

	/** Best-effort canonical URL of the page currently being rendered. */
	private function current_url() {
		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}

		$qo = get_queried_object();

		if ( $qo instanceof WP_Post ) {
			return get_permalink( $qo );
		}
		if ( $qo instanceof WP_Term ) {
			$link = get_term_link( $qo );
			return is_wp_error( $link ) ? '' : $link;
		}

		return '';
	}
}
