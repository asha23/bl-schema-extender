<?php
/**
 * EntityMap admin: Settings + Tools pages under the EntityMap menu.
 *
 * Settings — publisher/root metadata and feature toggles.
 * Tools     — import from entitymap.json, regenerate outputs, validate integrity,
 *             and preview both the generated JSON and the Organization JSON-LD.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Admin {

	const PARENT = 'edit.php?post_type=bl_entity';
	const GROUP  = 'bl_em_settings';

	private $options = array(
		'bl_em_publisher_name'   => 'text',
		'bl_em_publisher_url'    => 'url',
		'bl_em_publisher_sameas' => 'url',
		'bl_em_version'          => 'text',
		'bl_em_schema_url'       => 'url',
		'bl_em_verification'     => 'text',
		'bl_em_profile'          => 'text',
		'bl_em_enable_json'      => 'bool',
		'bl_em_enable_org'       => 'bool',
		'bl_em_enable_perpage'   => 'bool',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_tools' ) );
		add_action( 'admin_init', array( $this, 'maybe_regenerate_after_save' ) );
	}

	public function menu() {
		add_submenu_page( self::PARENT, 'EntityMap Settings', 'Settings', 'manage_options', 'bl-em-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::PARENT, 'EntityMap Tools', 'Tools', 'manage_options', 'bl-em-tools', array( $this, 'render_tools' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------- */

	public function register_settings() {
		foreach ( $this->options as $key => $type ) {
			register_setting( self::GROUP, $key, array(
				'sanitize_callback' => $type === 'url'
					? 'esc_url_raw'
					: ( $type === 'bool' ? array( $this, 'sanitize_bool' ) : 'sanitize_text_field' ),
			) );
		}
	}

	public function sanitize_bool( $val ) {
		return $val === '1' ? '1' : '0';
	}

	public function maybe_regenerate_after_save() {
		if ( isset( $_GET['page'], $_GET['settings-updated'] ) && $_GET['page'] === 'bl-em-settings' && $_GET['settings-updated'] ) {
			BL_EntityMap_Store::flush_cache();
			( new BL_EntityMap_Generator() )->regenerate();
		}
	}

	public function render_settings() {
		$val = function( $k, $d = '' ) { return esc_attr( get_option( $k, $d ) ); };
		$chk = function( $k, $d = '1' ) { return checked( get_option( $k, $d ), '1', false ); };
		?>
		<div class="wrap">
			<h1>EntityMap Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<h2 class="title">Publisher &amp; root</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="bl_em_publisher_name">Publisher name</label></th>
						<td><input name="bl_em_publisher_name" id="bl_em_publisher_name" type="text" class="regular-text" value="<?php echo $val( 'bl_em_publisher_name', 'BrightLocal' ); ?>"></td></tr>
					<tr><th scope="row"><label for="bl_em_publisher_url">Publisher URL</label></th>
						<td><input name="bl_em_publisher_url" id="bl_em_publisher_url" type="url" class="regular-text" value="<?php echo $val( 'bl_em_publisher_url', home_url( '/' ) ); ?>"></td></tr>
					<tr><th scope="row"><label for="bl_em_publisher_sameas">Publisher sameAs</label></th>
						<td><input name="bl_em_publisher_sameas" id="bl_em_publisher_sameas" type="url" class="regular-text" value="<?php echo $val( 'bl_em_publisher_sameas' ); ?>" placeholder="Verified Wikidata URL (leave blank if none)"></td></tr>
					<tr><th scope="row"><label for="bl_em_version">Spec version</label></th>
						<td><input name="bl_em_version" id="bl_em_version" type="text" class="small-text" value="<?php echo $val( 'bl_em_version', '1.0' ); ?>"></td></tr>
					<tr><th scope="row"><label for="bl_em_schema_url">Schema URL</label></th>
						<td><input name="bl_em_schema_url" id="bl_em_schema_url" type="url" class="regular-text" value="<?php echo $val( 'bl_em_schema_url', 'https://entitymap.org/spec/v1.0' ); ?>"></td></tr>
					<tr><th scope="row"><label for="bl_em_verification">Verification status</label></th>
						<td><input name="bl_em_verification" id="bl_em_verification" type="text" class="regular-text" value="<?php echo $val( 'bl_em_verification', 'self-declared' ); ?>"></td></tr>
					<tr><th scope="row"><label for="bl_em_profile">Profile</label></th>
						<td><input name="bl_em_profile" id="bl_em_profile" type="text" class="regular-text" value="<?php echo $val( 'bl_em_profile', 'core' ); ?>"></td></tr>
				</table>

				<h2 class="title">Output toggles</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Serve /entitymap.json</th>
						<td><input type="hidden" name="bl_em_enable_json" value="0"><label><input type="checkbox" name="bl_em_enable_json" value="1" <?php echo $chk( 'bl_em_enable_json' ); ?>> Publish the machine-readable EntityMap file</label></td></tr>
					<tr><th scope="row">Enrich Organization schema</th>
						<td><input type="hidden" name="bl_em_enable_org" value="0"><label><input type="checkbox" name="bl_em_enable_org" value="1" <?php echo $chk( 'bl_em_enable_org' ); ?>> Add sameAs / knowsAbout / makesOffer to Yoast's Organization node</label></td></tr>
					<tr><th scope="row">Per-page schema nodes</th>
						<td><input type="hidden" name="bl_em_enable_perpage" value="0"><label><input type="checkbox" name="bl_em_enable_perpage" value="1" <?php echo $chk( 'bl_em_enable_perpage' ); ?>> Inject DefinedTerm / Service nodes on each entity's attached page</label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools.
	 * ------------------------------------------------------------------- */

	public function handle_tools() {
		if ( ! isset( $_POST['bl_em_tool'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['bl_em_tool_nonce'] ) || ! wp_verify_nonce( $_POST['bl_em_tool_nonce'], 'bl_em_tool' ) ) {
			return;
		}

		$tool      = sanitize_text_field( $_POST['bl_em_tool'] );
		$generator = new BL_EntityMap_Generator();

		if ( $tool === 'import' ) {
			$path   = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json';
			$result = BL_EntityMap_Importer::import_file( $path );
			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
			} else {
				$generator->regenerate();
				$this->notice( 'success', sprintf( 'Import complete: %d created, %d updated.', $result['created'], $result['updated'] ) );
			}
		} elseif ( $tool === 'regenerate' ) {
			$generator->regenerate();
			$ok = get_option( 'bl_em_static_ok', '0' ) === '1';
			$this->notice( $ok ? 'success' : 'warning', $ok
				? 'Regenerated. Static /entitymap.json written to the webroot.'
				: 'Regenerated cache. Static file not writable — serving /entitymap.json dynamically instead.' );
		}
	}

	private function notice( $type, $msg ) {
		add_action( 'admin_notices', function () use ( $type, $msg ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
		} );
	}

	public function render_tools() {
		$generator = new BL_EntityMap_Generator();
		$issues    = self::validate();
		$json      = $generator->get_json( true );
		$static_ok = get_option( 'bl_em_static_ok', '' );
		?>
		<div class="wrap">
			<h1>EntityMap Tools</h1>

			<h2>Publishing status</h2>
			<p>
				Endpoint: <code><?php echo esc_html( home_url( '/entitymap.json' ) ); ?></code><br>
				Static file: <code><?php echo esc_html( $generator->static_path() ); ?></code>
				<?php if ( $static_ok === '1' ) : ?>
					— <span style="color:#008a20;">writable ✓ (served statically)</span>
				<?php elseif ( $static_ok === '0' ) : ?>
					— <span style="color:#b26200;">not writable (served dynamically via WP)</span>
				<?php else : ?>
					— <em>not yet generated</em>
				<?php endif; ?>
			</p>

			<form method="post" style="margin:1em 0;display:flex;gap:8px;flex-wrap:wrap;">
				<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
				<button class="button button-primary" name="bl_em_tool" value="regenerate">Regenerate now</button>
				<button class="button" name="bl_em_tool" value="import" onclick="return confirm('Import from the entitymap.json in the webroot? Existing entities with matching IDs will be updated.');">Import from entitymap.json</button>
			</form>

			<h2>Validation</h2>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
				<?php foreach ( $issues as $issue ) :
					$color = $issue['level'] === 'error' ? '#b32d2e' : ( $issue['level'] === 'warn' ? '#b26200' : '#008a20' ); ?>
					<tr>
						<td style="width:90px;color:<?php echo esc_attr( $color ); ?>;font-weight:600;text-transform:uppercase;"><?php echo esc_html( $issue['level'] ); ?></td>
						<td><?php echo esc_html( $issue['msg'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em;">Generated entitymap.json</h2>
			<textarea readonly class="widefat code" rows="18" style="font-family:monospace;font-size:12px;"><?php echo esc_textarea( $json ); ?></textarea>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Validation (structural integrity of the current DB entities).
	 * ------------------------------------------------------------------- */

	public static function validate() {
		$entities = BL_EntityMap_Store::get_entities( true );
		$issues   = array();

		if ( empty( $entities ) ) {
			return array( array( 'level' => 'warn', 'msg' => 'No published entities yet. Use “Import from entitymap.json” to seed.' ) );
		}

		$ids        = array();
		$names      = array();
		$dupe_ids   = array();
		$chunk_ids  = array();
		$dupe_chunk = array();

		foreach ( $entities as $e ) {
			$id = $e['entityId'];
			if ( isset( $ids[ $id ] ) ) {
				$dupe_ids[] = $id;
			}
			$ids[ $id ]   = true;
			$names[ $id ] = $e['name'];

			foreach ( $e['hasChunks'] as $c ) {
				if ( isset( $chunk_ids[ $c['chunkId'] ] ) ) {
					$dupe_chunk[] = $c['chunkId'];
				}
				$chunk_ids[ $c['chunkId'] ] = true;
			}
		}

		$org_count = 0;
		$bad_refs  = array();
		$missing   = array();
		$no_page   = array();

		foreach ( $entities as $e ) {
			if ( isset( $e['@type'] ) && $e['@type'] === 'Organization' ) {
				$org_count++;
			}
			if ( empty( $e['description'] ) ) {
				$missing[] = $e['entityId'];
			}
			foreach ( $e['relations'] as $r ) {
				if ( ! isset( $ids[ $r['targetId'] ] ) ) {
					$bad_refs[] = $e['entityId'] . ' → ' . $r['targetId'];
				}
			}
		}

		$issues[] = array( 'level' => 'ok', 'msg' => count( $entities ) . ' published entities, ' . count( $chunk_ids ) . ' chunks.' );

		$issues[] = $dupe_ids
			? array( 'level' => 'error', 'msg' => 'Duplicate entity IDs: ' . implode( ', ', array_unique( $dupe_ids ) ) )
			: array( 'level' => 'ok', 'msg' => 'Entity IDs are unique.' );

		$issues[] = $dupe_chunk
			? array( 'level' => 'error', 'msg' => 'Duplicate chunk IDs: ' . implode( ', ', array_unique( $dupe_chunk ) ) )
			: array( 'level' => 'ok', 'msg' => 'Chunk IDs are unique.' );

		$issues[] = $bad_refs
			? array( 'level' => 'error', 'msg' => 'Relations pointing to missing entities: ' . implode( ', ', $bad_refs ) )
			: array( 'level' => 'ok', 'msg' => 'All relation targets resolve.' );

		$issues[] = $missing
			? array( 'level' => 'warn', 'msg' => 'Entities missing a description: ' . implode( ', ', $missing ) )
			: array( 'level' => 'ok', 'msg' => 'All entities have a description.' );

		if ( $org_count === 0 ) {
			$issues[] = array( 'level' => 'warn', 'msg' => 'No Organization entity — sitewide Organization enrichment will have no sameAs/makesOffer source.' );
		} elseif ( $org_count > 1 ) {
			$issues[] = array( 'level' => 'warn', 'msg' => 'More than one Organization entity; the first is used for enrichment.' );
		} else {
			$issues[] = array( 'level' => 'ok', 'msg' => 'Exactly one Organization entity.' );
		}

		// Yoast site-representation prerequisite for Organization output.
		if ( function_exists( 'YoastSEO' ) ) {
			$rep = get_option( 'wpseo_titles' );
			if ( is_array( $rep ) && isset( $rep['company_or_person'] ) && $rep['company_or_person'] !== 'company' ) {
				$issues[] = array( 'level' => 'warn', 'msg' => 'Yoast Site Representation is not “Organization/company” — the Organization schema node will not render, so enrichment is inert. Fix under Yoast → Settings → Site basics.' );
			} else {
				$issues[] = array( 'level' => 'ok', 'msg' => 'Yoast Site Representation is set to a company.' );
			}
		}

		return $issues;
	}
}
