<?php
/**
 * Entity Maps — the "Manage Entities" screen.
 *
 * A single master–detail editor for the whole map: the entity list on the left,
 * the full editor for the selected entity on the right, add/save/delete inline
 * via AJAX. It is the primary way to curate the map; each save upserts a
 * bl_entity post and regenerates entitymap.json / entitymap.html, so the classic
 * per-post editor is no longer needed in the menu.
 *
 * Storage is unchanged — every entity is still a bl_entity post, saved through
 * the same BL_EntityMap_Store::save_entity_meta() path the classic editor uses,
 * so importer / backups / Yoast schema / caching all keep working untouched.
 *
 * @since 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Manager {

	const NONCE   = 'bl_em_manage';
	const CAP     = 'manage_options';
	const HANDLE  = 'bl-em-manage';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_bl_em_save_entity', array( $this, 'ajax_save_entity' ) );
		add_action( 'wp_ajax_bl_em_delete_entity', array( $this, 'ajax_delete_entity' ) );
		add_action( 'wp_ajax_bl_em_search_pages', array( $this, 'ajax_search_pages' ) );
		add_action( 'wp_ajax_bl_em_search_wikidata', array( $this, 'ajax_search_wikidata' ) );
	}

	/** Are we on the Manage Entities screen (hub page, manage tab or default)? */
	private function is_manage_screen() {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== BL_AI_Tool_EntityMaps::HUB_SLUG ) {
			return false;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'manage';
		return $tab === 'manage';
	}

	/* ---------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------- */

	public function enqueue( $hook ) {
		if ( ! $this->is_manage_screen() || ! current_user_can( self::CAP ) ) {
			return;
		}

		$base = plugins_url( 'assets/', __FILE__ );

		wp_enqueue_style( self::HANDLE, $base . 'manage.css', array(), BL_AI_VERSION );
		wp_enqueue_script( self::HANDLE, $base . 'manage.js', array(), BL_AI_VERSION, true );

		wp_localize_script( self::HANDLE, 'BL_EM', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'entities'   => $this->all_entities_for_edit(),
			'validation' => BL_EntityMap_Admin::validate(),
			'vocab'      => array(
				'types'        => BL_EntityMap_Store::entity_types(),
				'predicates'   => BL_EntityMap_Store::predicates(),
				'contentTypes' => BL_EntityMap_Store::content_types(),
				'audience'     => BL_EntityMap_Store::audience_types(),
				'confidence'   => BL_EntityMap_Store::confidence_levels(),
				'maturity'     => BL_EntityMap_Store::maturity_levels(),
			),
			'files'      => array(
				'json' => home_url( '/entitymap.json' ),
				'html' => home_url( '/entitymap.html' ),
			),
			'i18n'       => array(
				'confirmDelete'   => __( 'Delete this entity? It is moved to Trash (recoverable) and the files are regenerated.', 'bl-ai-tools' ),
				'confirmDiscard'  => __( 'You have unsaved changes. Discard them?', 'bl-ai-tools' ),
				'unloadWarning'   => __( 'You have unsaved changes.', 'bl-ai-tools' ),
				'saving'          => __( 'Saving…', 'bl-ai-tools' ),
				'saved'           => __( 'Saved · files updated', 'bl-ai-tools' ),
				'savedDynamic'    => __( 'Saved · webroot not writable, served dynamically', 'bl-ai-tools' ),
			),
		) );
	}

	/** Every published entity in raw editable form, ordered by entity id. */
	private function all_entities_for_edit() {
		$posts = get_posts( array(
			'post_type'        => BL_EntityMap_Store::CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'orderby'          => 'meta_value',
			'meta_key'         => '_bl_entity_id',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'fields'           => 'ids',
		) );

		$out = array();
		foreach ( $posts as $pid ) {
			$e = BL_EntityMap_Store::get_entity_for_edit( $pid );
			if ( $e ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Rendering (called by the hub for the "manage" tab).
	 * ------------------------------------------------------------------- */

	public function render() {
		$enabled = get_option( 'bl_em_enable_json', '1' ) === '1';
		?>
		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 1.25em;"><p>Publishing is turned <strong>off</strong> in <a href="<?php echo esc_url( BL_EntityMap_Admin::tab_url( 'settings' ) ); ?>">Settings</a> — edits are saved to the database but the files are not written. Turn on &ldquo;Publish EntityMap files&rdquo; to publish.</p></div>
		<?php endif; ?>

		<div id="bl-em-manager" class="bl-em-manager">
			<div class="bl-em-loading"><span class="spinner is-active" style="float:none;"></span> Loading entities…</div>
		</div>

		<noscript>
			<div class="notice notice-error inline"><p>The Manage Entities screen needs JavaScript. With JS disabled, edit entities individually via the database or re-enable JavaScript.</p></div>
		</noscript>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX: save (create or update) an entity.
	 * ------------------------------------------------------------------- */

	public function ajax_save_entity() {
		$this->check();

		$raw  = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => 'Malformed request.' ) );
		}

		$name = isset( $data['name'] ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
		if ( $name === '' ) {
			wp_send_json_error( array( 'message' => 'An entity needs a name.' ) );
		}

		$post_id = isset( $data['postId'] ) ? absint( $data['postId'] ) : 0;

		// Snapshot the current published map before this change, so every edit is
		// undoable from Files → Backups. Runs before any mutation to capture the
		// true pre-edit state; no-ops if no file exists yet.
		BL_EntityMap_Backups::archive( $post_id ? 'edit' : 'create' );

		$postarr = array(
			'post_type'    => BL_EntityMap_Store::CPT,
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
		);

		if ( $post_id ) {
			// Guard against editing the wrong post type via a forged id.
			if ( get_post_type( $post_id ) !== BL_EntityMap_Store::CPT ) {
				wp_send_json_error( array( 'message' => 'Entity not found.' ) );
			}
			$postarr['ID'] = $post_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$post_id = (int) $result;

		// Assign a stable entity id once (never reused — see Store::next_entity_id()).
		if ( ! get_post_meta( $post_id, '_bl_entity_id', true ) ) {
			update_post_meta( $post_id, '_bl_entity_id', BL_EntityMap_Store::next_entity_id() );
		}

		// Payload is JSON-decoded (already unslashed), so hand straight to the
		// shared writer without unslashing again.
		BL_EntityMap_Store::save_entity_meta( $post_id, array(
			'type'            => isset( $data['type'] ) ? $data['type'] : '',
			'alternate_name'  => isset( $data['alternate_name'] ) ? $data['alternate_name'] : '',
			'canonical_label' => isset( $data['canonical_label'] ) ? $data['canonical_label'] : '',
			'same_as'         => isset( $data['same_as'] ) ? $data['same_as'] : '',
			'maturity'        => isset( $data['maturity'] ) ? $data['maturity'] : '',
			'page_url'        => isset( $data['page_url'] ) ? $data['page_url'] : '',
			'chunks'          => isset( $data['chunks'] ) ? $data['chunks'] : array(),
			'relations'       => isset( $data['relations'] ) ? $data['relations'] : array(),
		) );

		// Flush + regenerate AFTER meta is written. (wp_insert_post fired the
		// save_post auto-flush before meta existed; this run reflects the save.)
		BL_EntityMap_Store::flush_cache();

		wp_send_json_success( array(
			'entity'     => BL_EntityMap_Store::get_entity_for_edit( $post_id ),
			'staticOk'   => get_option( 'bl_em_static_ok', '' ),
			'validation' => BL_EntityMap_Admin::validate(),
		) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: delete (trash) an entity.
	 * ------------------------------------------------------------------- */

	public function ajax_delete_entity() {
		$this->check();

		$post_id = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;
		if ( ! $post_id || get_post_type( $post_id ) !== BL_EntityMap_Store::CPT ) {
			wp_send_json_error( array( 'message' => 'Entity not found.' ) );
		}

		BL_EntityMap_Backups::archive( 'delete' ); // undoable from Files → Backups
		wp_trash_post( $post_id ); // fires trashed_post → flush_cache → regenerate

		wp_send_json_success( array(
			'validation' => BL_EntityMap_Admin::validate(),
			'staticOk'   => get_option( 'bl_em_static_ok', '' ),
		) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: search published content for the "Attach to page" picker.
	 * ------------------------------------------------------------------- */

	public function ajax_search_pages() {
		$this->check();

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		$posts = get_posts( array(
			'post_type'        => array_values( $types ),
			'post_status'      => 'publish',
			'numberposts'      => 20,
			's'                => $q,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );

		$results = array();
		foreach ( $posts as $p ) {
			$results[] = array(
				'title' => get_the_title( $p ),
				'url'   => get_permalink( $p ),
				'type'  => get_post_type_object( $p->post_type )->labels->singular_name,
			);
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: search Wikidata for the "Find on Wikidata" sameAs picker.
	 *
	 * Proxied server-side (via wp_remote_get) rather than called from the browser,
	 * so it works regardless of cross-origin policy and keeps the request tidy.
	 * ------------------------------------------------------------------- */

	public function ajax_search_wikidata() {
		$this->check();

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$url = add_query_arg( array(
			'action'   => 'wbsearchentities',
			'search'   => rawurlencode( $q ),
			'language' => 'en',
			'uselang'  => 'en',
			'type'     => 'item',
			'limit'    => 10,
			'format'   => 'json',
		), 'https://www.wikidata.org/w/api.php' );

		$resp = wp_remote_get( $url, array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/json',
				// Wikimedia asks clients to identify themselves.
				'User-Agent' => 'BrightLocal-AI-Tools/' . BL_AI_VERSION . ' (WordPress; entitymap sameAs lookup)',
			),
		) );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => 'Could not reach Wikidata.' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$results = array();

		if ( is_array( $body ) && ! empty( $body['search'] ) ) {
			foreach ( $body['search'] as $item ) {
				if ( empty( $item['id'] ) ) {
					continue;
				}
				$results[] = array(
					'id'          => $item['id'],
					'label'       => isset( $item['label'] ) ? $item['label'] : $item['id'],
					'description' => isset( $item['description'] ) ? $item['description'] : '',
					'url'         => 'https://www.wikidata.org/wiki/' . $item['id'],
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/* ---------------------------------------------------------------------
	 * Shared request guard.
	 * ------------------------------------------------------------------- */

	private function check() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to do this.' ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session expired — reload the page and try again.' ), 400 );
		}
	}
}
