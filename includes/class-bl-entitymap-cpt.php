<?php
/**
 * EntityMap custom post type + meta boxes.
 *
 * Each bl_entity post is one EntityMap entity. Post title = entity name,
 * post content = description. Everything else lives in meta and is edited
 * through the meta boxes below (native UI, no ACF dependency).
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_CPT {

	const CPT   = 'bl_entity';
	const NONCE = 'bl_entity_meta_nonce';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_footer', array( $this, 'repeater_js' ) );

		// Cache invalidation whenever entities change.
		add_action( 'save_post_' . self::CPT, array( 'BL_EntityMap_Store', 'flush_cache' ) );
		add_action( 'deleted_post', array( $this, 'maybe_flush' ) );
		add_action( 'trashed_post', array( $this, 'maybe_flush' ) );

		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );

		// Default the All Entities list to entity-ID order, and make that column sortable.
		add_action( 'pre_get_posts', array( $this, 'default_admin_order' ) );
		add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', array( $this, 'sortable_columns' ) );
	}

	/**
	 * Order the admin list by entity ID (e_001, e_002, …) by default, instead of
	 * by date. IDs are zero-padded so a string sort is numerically correct.
	 */
	public function default_admin_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== self::CPT ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		// Apply when no sort is chosen, or when the user clicks the Entity ID column.
		if ( $orderby === '' || $orderby === 'bl_eid' ) {
			$query->set( 'meta_key', '_bl_entity_id' );
			$query->set( 'orderby', 'meta_value' );
			if ( $orderby === '' ) {
				$query->set( 'order', 'ASC' );
			}
		}
	}

	public function sortable_columns( $cols ) {
		$cols['bl_eid'] = 'bl_eid';
		return $cols;
	}

	public function maybe_flush( $post_id ) {
		if ( get_post_type( $post_id ) === self::CPT ) {
			BL_EntityMap_Store::flush_cache();
		}
	}

	public function register_post_type() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'EntityMap',
				'singular_name' => 'Entity',
				'menu_name'     => 'EntityMap',
				'add_new_item'  => 'Add New Entity',
				'edit_item'     => 'Edit Entity',
				'all_items'     => 'All Entities',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-networking',
			'menu_position' => 58,
			'supports'     => array( 'title', 'editor', 'page-attributes' ),
			'has_archive'  => false,
			'rewrite'      => false,
			'show_in_rest' => false,
		) );
	}

	public function add_meta_boxes() {
		add_meta_box( 'bl_entity_details', 'Entity Details', array( $this, 'box_details' ), self::CPT, 'side', 'high' );
		add_meta_box( 'bl_entity_chunks', 'Evidence Chunks', array( $this, 'box_chunks' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'bl_entity_relations', 'Relations', array( $this, 'box_relations' ), self::CPT, 'normal', 'default' );
	}

	/* ------------------------------------------------------------------ */

	public function box_details( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$type      = get_post_meta( $post->ID, '_bl_type', true ) ?: 'Concept';
		$eid       = get_post_meta( $post->ID, '_bl_entity_id', true );
		$alt       = get_post_meta( $post->ID, '_bl_alternate_name', true );
		$canon     = get_post_meta( $post->ID, '_bl_canonical_label', true );
		$same      = get_post_meta( $post->ID, '_bl_same_as', true );
		$maturity  = get_post_meta( $post->ID, '_bl_maturity', true );
		$page_url  = get_post_meta( $post->ID, '_bl_page_url', true );
		?>
		<p>
			<label><strong>Entity ID</strong></label><br>
			<input type="text" class="widefat" value="<?php echo esc_attr( $eid ?: '(assigned on save)' ); ?>" readonly>
		</p>
		<p>
			<label for="bl_type"><strong>Type</strong></label><br>
			<select name="bl_type" id="bl_type" class="widefat">
				<?php foreach ( BL_EntityMap_Store::entity_types() as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="bl_alternate_name"><strong>Alternate name</strong></label><br>
			<input type="text" class="widefat" id="bl_alternate_name" name="bl_alternate_name" value="<?php echo esc_attr( $alt ); ?>">
		</p>
		<p>
			<label for="bl_canonical_label"><strong>Canonical label</strong> <span class="description">(for proprietary terms)</span></label><br>
			<input type="text" class="widefat" id="bl_canonical_label" name="bl_canonical_label" value="<?php echo esc_attr( $canon ); ?>">
		</p>
		<p>
			<label for="bl_same_as"><strong>sameAs URL</strong> <span class="description">(verified Wikidata etc.)</span></label><br>
			<input type="url" class="widefat" id="bl_same_as" name="bl_same_as" value="<?php echo esc_attr( $same ); ?>" placeholder="https://www.wikidata.org/wiki/...">
		</p>
		<p>
			<label for="bl_maturity"><strong>Maturity status</strong></label><br>
			<select name="bl_maturity" id="bl_maturity" class="widefat">
				<?php foreach ( BL_EntityMap_Store::maturity_levels() as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $maturity, $m ); ?>><?php echo $m === '' ? '— none —' : esc_html( $m ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="bl_page_url"><strong>Attach to page URL</strong> <span class="description">(where per-page schema is injected)</span></label><br>
			<input type="url" class="widefat" id="bl_page_url" name="bl_page_url" value="<?php echo esc_attr( $page_url ); ?>" placeholder="https://www.brightlocal.com/...">
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ */

	public function box_chunks( $post ) {
		$rows = get_post_meta( $post->ID, '_bl_chunks', true );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		?>
		<p class="description">Short evidence passages (1&ndash;5 sentences) with their source. Publisher is set from EntityMap Settings.</p>
		<div id="bl-chunks-repeater" class="bl-repeater" data-name="bl_chunks">
			<?php foreach ( $rows as $i => $row ) : ?>
				<?php $this->chunk_row( $i, $row ); ?>
			<?php endforeach; ?>
		</div>
		<script type="text/template" id="bl-chunks-template"><?php $this->chunk_row( '__i__', array() ); ?></script>
		<p><button type="button" class="button bl-repeater-add" data-target="bl-chunks-repeater" data-template="bl-chunks-template">+ Add chunk</button></p>
		<?php
	}

	private function chunk_row( $i, $row ) {
		$text    = isset( $row['text'] ) ? $row['text'] : '';
		$url     = isset( $row['sourceUrl'] ) ? $row['sourceUrl'] : '';
		$title   = isset( $row['pageTitle'] ) ? $row['pageTitle'] : '';
		$ctype   = isset( $row['contentType'] ) ? $row['contentType'] : '';
		$atype   = isset( $row['audienceType'] ) ? $row['audienceType'] : '';
		$cid     = isset( $row['chunkId'] ) ? $row['chunkId'] : '';
		$n       = "bl_chunks[$i]";
		?>
		<div class="bl-repeater-row" style="border:1px solid #dcdcde;padding:10px 12px;margin-bottom:10px;background:#fff;">
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[chunkId]" value="<?php echo esc_attr( $cid ); ?>">
			<p style="margin-top:0;">
				<label><strong>Text</strong></label>
				<textarea name="<?php echo esc_attr( $n ); ?>[text]" class="widefat" rows="2"><?php echo esc_textarea( $text ); ?></textarea>
			</p>
			<div style="display:flex;gap:10px;flex-wrap:wrap;">
				<p style="flex:2;min-width:220px;">
					<label>Source URL</label>
					<input type="url" class="widefat" name="<?php echo esc_attr( $n ); ?>[sourceUrl]" value="<?php echo esc_attr( $url ); ?>">
				</p>
				<p style="flex:2;min-width:220px;">
					<label>Page title</label>
					<input type="text" class="widefat" name="<?php echo esc_attr( $n ); ?>[pageTitle]" value="<?php echo esc_attr( $title ); ?>">
				</p>
			</div>
			<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
				<p style="flex:1;min-width:160px;">
					<label>Content type</label>
					<select name="<?php echo esc_attr( $n ); ?>[contentType]" class="widefat">
						<option value="">—</option>
						<?php foreach ( BL_EntityMap_Store::content_types() as $ct ) : ?>
							<option value="<?php echo esc_attr( $ct ); ?>" <?php selected( $ctype, $ct ); ?>><?php echo esc_html( $ct ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="flex:1;min-width:160px;">
					<label>Audience</label>
					<select name="<?php echo esc_attr( $n ); ?>[audienceType]" class="widefat">
						<option value="">—</option>
						<?php foreach ( BL_EntityMap_Store::audience_types() as $at ) : ?>
							<option value="<?php echo esc_attr( $at ); ?>" <?php selected( $atype, $at ); ?>><?php echo esc_html( $at ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="flex:0;">
					<button type="button" class="button-link-delete bl-repeater-remove" style="color:#b32d2e;">Remove</button>
				</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */

	public function box_relations( $post ) {
		$rows = get_post_meta( $post->ID, '_bl_relations', true );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$targets = $this->target_options( $post->ID );
		?>
		<p class="description">Typed edges to other entities. targetName is resolved automatically.</p>
		<div id="bl-relations-repeater" class="bl-repeater" data-name="bl_relations">
			<?php foreach ( $rows as $i => $row ) : ?>
				<?php $this->relation_row( $i, $row, $targets ); ?>
			<?php endforeach; ?>
		</div>
		<script type="text/template" id="bl-relations-template"><?php $this->relation_row( '__i__', array(), $targets ); ?></script>
		<p><button type="button" class="button bl-repeater-add" data-target="bl-relations-repeater" data-template="bl-relations-template">+ Add relation</button></p>
		<?php
	}

	private function relation_row( $i, $row, $targets ) {
		$predicate = isset( $row['predicate'] ) ? $row['predicate'] : '';
		$target    = isset( $row['targetId'] ) ? $row['targetId'] : '';
		$conf      = isset( $row['confidence'] ) ? $row['confidence'] : '';
		$cond      = isset( $row['condition'] ) ? $row['condition'] : '';
		$n         = "bl_relations[$i]";
		?>
		<div class="bl-repeater-row" style="border:1px solid #dcdcde;padding:10px 12px;margin-bottom:10px;background:#fff;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
			<p style="flex:1;min-width:150px;margin:0;">
				<label>Predicate</label>
				<select name="<?php echo esc_attr( $n ); ?>[predicate]" class="widefat">
					<option value="">—</option>
					<?php foreach ( BL_EntityMap_Store::predicates() as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $predicate, $p ); ?>><?php echo esc_html( $p ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="flex:2;min-width:200px;margin:0;">
				<label>Target entity</label>
				<select name="<?php echo esc_attr( $n ); ?>[targetId]" class="widefat">
					<option value="">—</option>
					<?php foreach ( $targets as $tid => $tname ) : ?>
						<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $target, $tid ); ?>><?php echo esc_html( $tname . ' (' . $tid . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="flex:1;min-width:130px;margin:0;">
				<label>Confidence</label>
				<select name="<?php echo esc_attr( $n ); ?>[confidence]" class="widefat">
					<?php foreach ( BL_EntityMap_Store::confidence_levels() as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $conf, $c ); ?>><?php echo $c === '' ? '— stated —' : esc_html( $c ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p style="flex:2;min-width:200px;margin:0;">
				<label>Condition <span class="description">(if inferred)</span></label>
				<input type="text" class="widefat" name="<?php echo esc_attr( $n ); ?>[condition]" value="<?php echo esc_attr( $cond ); ?>">
			</p>
			<p style="flex:0;margin:0;">
				<button type="button" class="button-link-delete bl-repeater-remove" style="color:#b32d2e;">Remove</button>
			</p>
		</div>
		<?php
	}

	/** entityId => name for all entities except the one being edited. */
	private function target_options( $current_post_id ) {
		$posts = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => array( 'publish', 'draft', 'pending' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
			'exclude'     => array( $current_post_id ),
		) );

		$out = array();
		foreach ( $posts as $p ) {
			$eid = get_post_meta( $p->ID, '_bl_entity_id', true );
			if ( $eid ) {
				$out[ $eid ] = get_the_title( $p );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Assign a stable entity id once.
		$eid = get_post_meta( $post_id, '_bl_entity_id', true );
		if ( ! $eid ) {
			update_post_meta( $post_id, '_bl_entity_id', BL_EntityMap_Store::next_entity_id() );
		}

		$this->save_text( $post_id, '_bl_type', 'bl_type' );
		$this->save_text( $post_id, '_bl_alternate_name', 'bl_alternate_name' );
		$this->save_text( $post_id, '_bl_canonical_label', 'bl_canonical_label' );
		$this->save_text( $post_id, '_bl_maturity', 'bl_maturity' );

		update_post_meta( $post_id, '_bl_same_as', isset( $_POST['bl_same_as'] ) ? esc_url_raw( $_POST['bl_same_as'] ) : '' );
		update_post_meta( $post_id, '_bl_page_url', isset( $_POST['bl_page_url'] ) ? esc_url_raw( $_POST['bl_page_url'] ) : '' );

		update_post_meta( $post_id, '_bl_chunks', $this->sanitize_chunks( isset( $_POST['bl_chunks'] ) ? (array) $_POST['bl_chunks'] : array() ) );
		update_post_meta( $post_id, '_bl_relations', $this->sanitize_relations( isset( $_POST['bl_relations'] ) ? (array) $_POST['bl_relations'] : array() ) );
	}

	private function save_text( $post_id, $meta, $field ) {
		update_post_meta( $post_id, $meta, isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '' );
	}

	private function sanitize_chunks( $rows ) {
		$clean = array();
		foreach ( $rows as $row ) {
			$text = isset( $row['text'] ) ? sanitize_textarea_field( wp_unslash( $row['text'] ) ) : '';
			if ( $text === '' ) {
				continue;
			}
			$clean[] = array(
				'chunkId'      => isset( $row['chunkId'] ) ? sanitize_text_field( $row['chunkId'] ) : '',
				'text'         => $text,
				'sourceUrl'    => isset( $row['sourceUrl'] ) ? esc_url_raw( wp_unslash( $row['sourceUrl'] ) ) : '',
				'pageTitle'    => isset( $row['pageTitle'] ) ? sanitize_text_field( wp_unslash( $row['pageTitle'] ) ) : '',
				'contentType'  => isset( $row['contentType'] ) ? sanitize_text_field( $row['contentType'] ) : '',
				'audienceType' => isset( $row['audienceType'] ) ? sanitize_text_field( $row['audienceType'] ) : '',
			);
		}
		return array_values( $clean );
	}

	private function sanitize_relations( $rows ) {
		$clean = array();
		foreach ( $rows as $row ) {
			$predicate = isset( $row['predicate'] ) ? sanitize_text_field( $row['predicate'] ) : '';
			$target    = isset( $row['targetId'] ) ? sanitize_text_field( $row['targetId'] ) : '';
			if ( $predicate === '' || $target === '' ) {
				continue;
			}
			$clean[] = array(
				'predicate'  => $predicate,
				'targetId'   => $target,
				'confidence' => isset( $row['confidence'] ) ? sanitize_text_field( $row['confidence'] ) : '',
				'condition'  => isset( $row['condition'] ) ? sanitize_text_field( wp_unslash( $row['condition'] ) ) : '',
			);
		}
		return array_values( $clean );
	}

	/* ------------------------------------------------------------------ */

	public function columns( $cols ) {
		$new = array();
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['bl_type'] = 'Type';
				$new['bl_eid']  = 'Entity ID';
			}
		}
		return $new;
	}

	public function column_content( $col, $post_id ) {
		if ( $col === 'bl_type' ) {
			echo esc_html( get_post_meta( $post_id, '_bl_type', true ) );
		} elseif ( $col === 'bl_eid' ) {
			echo esc_html( get_post_meta( $post_id, '_bl_entity_id', true ) );
		}
	}

	/* ------------------------------------------------------------------ */

	/** Inline vanilla-JS repeater (add/remove rows), only on the entity editor. */
	public function repeater_js() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== self::CPT ) {
			return;
		}
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'bl-repeater-add' ) ) {
					e.preventDefault();
					var wrap = document.getElementById( e.target.dataset.target );
					var tpl  = document.getElementById( e.target.dataset.template ).innerHTML;
					var idx  = wrap.querySelectorAll( '.bl-repeater-row' ).length + '_' + Date.now();
					var html = tpl.replace( /__i__/g, idx );
					var div  = document.createElement( 'div' );
					div.innerHTML = html.trim();
					wrap.appendChild( div.firstChild );
				}
				if ( e.target.classList.contains( 'bl-repeater-remove' ) ) {
					e.preventDefault();
					var row = e.target.closest( '.bl-repeater-row' );
					if ( row ) { row.parentNode.removeChild( row ); }
				}
			} );
		} )();
		</script>
		<?php
	}
}
