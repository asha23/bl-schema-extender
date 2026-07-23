<?php
/**
 * Entity Maps admin — the tabbed hub under the BL AI Tools menu.
 *
 * One page (BL_AI_Tool_EntityMaps::HUB_SLUG) with four tabs:
 *   Files    — manage the published entitymap.json / entitymap.html: status,
 *              view, download, regenerate, and timestamped backups + restore.
 *   Import   — import from the webroot file, or upload + verify a JSON file,
 *              and check the current data's integrity.
 *   Settings — publisher/root metadata, output + schema toggles, backup retention.
 *   Help     — the in-admin usage guide.
 *
 * The menu itself is registered by the BL_AI_Tool_EntityMaps module; this class
 * owns the settings, the tool actions, and all the rendering.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_EntityMap_Admin {

	const GROUP = 'bl_em_settings';

	/** @var BL_EntityMap_Manager */
	private $manager;

	private $options = array(
		'bl_em_publisher_name'   => 'text',
		'bl_em_publisher_url'    => 'url',
		'bl_em_publisher_sameas' => 'url',
		'bl_em_base_url'         => 'url',
		'bl_em_version'          => 'text',
		'bl_em_schema_url'       => 'url',
		'bl_em_verification'     => 'text',
		'bl_em_profile'          => 'text',
		'bl_em_enable_json'      => 'bool',
		'bl_em_enable_schema'    => 'bool',
		'bl_em_enable_org'       => 'bool',
		'bl_em_enable_perpage'   => 'bool',
		'bl_em_backup_keep'      => 'int',
	);

	public function __construct() {
		$this->manager = new BL_EntityMap_Manager();
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_tools' ) );
		add_action( 'admin_init', array( $this, 'handle_download' ) );
		add_action( 'admin_init', array( $this, 'maybe_regenerate_after_save' ) );
	}

	/** URL of a hub tab. */
	public static function tab_url( $tab ) {
		return add_query_arg(
			array( 'page' => BL_AI_Tool_EntityMaps::HUB_SLUG, 'tab' => $tab ),
			admin_url( 'admin.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------- */

	public function register_settings() {
		foreach ( $this->options as $key => $type ) {
			$sanitize = 'sanitize_text_field';
			if ( $type === 'url' ) {
				$sanitize = 'esc_url_raw';
			} elseif ( $type === 'bool' ) {
				$sanitize = array( $this, 'sanitize_bool' );
			} elseif ( $type === 'int' ) {
				$sanitize = 'absint';
			}
			register_setting( self::GROUP, $key, array( 'sanitize_callback' => $sanitize ) );
		}
	}

	public function sanitize_bool( $val ) {
		return $val === '1' ? '1' : '0';
	}

	public function maybe_regenerate_after_save() {
		if (
			isset( $_GET['page'], $_GET['settings-updated'] )
			&& $_GET['page'] === BL_AI_Tool_EntityMaps::HUB_SLUG
			&& $_GET['settings-updated']
		) {
			BL_EntityMap_Store::flush_cache();
			( new BL_EntityMap_Generator() )->regenerate();
		}
	}

	/* ---------------------------------------------------------------------
	 * The tabbed hub.
	 * ------------------------------------------------------------------- */

	public function render_hub() {
		$tabs = array(
			'manage'   => 'Manage Entities',
			'files'    => 'Files',
			'import'   => 'Import',
			'settings' => 'Settings',
			'help'     => 'Help',
		);
		$active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'manage';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'manage';
		}
		?>
		<div class="wrap">
			<h1>Entity Maps</h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $slug ) ); ?>" class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<div style="margin-top:1.5em;">
				<?php
				switch ( $active ) {
					case 'files':
						$this->tab_files();
						break;
					case 'import':
						$this->tab_import();
						break;
					case 'settings':
						$this->tab_settings();
						break;
					case 'help':
						$this->tab_help();
						break;
					default:
						$this->manager->render();
				}
				?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Files tab — manage the published files + backups.
	 * ------------------------------------------------------------------- */

	private function tab_files() {
		$generator = new BL_EntityMap_Generator();
		$static_ok = get_option( 'bl_em_static_ok', '' );
		$enabled   = get_option( 'bl_em_enable_json', '1' ) === '1';

		$files = array(
			'json' => array(
				'label' => 'entitymap.json',
				'desc'  => 'Machine-readable catalogue &mdash; the source-of-truth export.',
				'url'   => home_url( '/entitymap.json' ),
				'path'  => $generator->static_path(),
			),
			'html' => array(
				'label' => 'entitymap.html',
				'desc'  => 'Human-readable rendering of the same data.',
				'url'   => home_url( '/entitymap.html' ),
				'path'  => $generator->static_html_path(),
			),
		);
		?>
		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 1.5em;"><p>Publishing is turned <strong>off</strong> in <a href="<?php echo esc_url( self::tab_url( 'settings' ) ); ?>">Settings</a> &mdash; the files below are not served. Turn on &ldquo;Publish EntityMap files&rdquo; to enable them.</p></div>
		<?php endif; ?>

		<div style="display:flex;flex-wrap:wrap;gap:16px;">
			<?php foreach ( $files as $key => $f ) :
				$exists = file_exists( $f['path'] );
				$mtime  = $exists ? filemtime( $f['path'] ) : 0;
				$size   = $exists ? size_format( filesize( $f['path'] ), 1 ) : '&mdash;';
				?>
				<div class="card" style="width:420px;max-width:100%;padding:8px 20px 16px;">
					<h2 style="display:flex;align-items:center;gap:8px;"><span class="dashicons dashicons-media-code" aria-hidden="true"></span> <?php echo esc_html( $f['label'] ); ?></h2>
					<p class="description" style="margin-top:-.4em;"><?php echo wp_kses_post( $f['desc'] ); ?></p>
					<table class="widefat striped" style="margin:.5em 0 1em;">
						<tbody>
							<tr><td style="width:110px;">URL</td><td><a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $f['url'] ); ?></a></td></tr>
							<tr><td>Last built</td><td><?php echo $mtime ? esc_html( self::human_time( $mtime ) ) : '<em>not yet generated</em>'; ?></td></tr>
							<tr><td>Size</td><td><?php echo wp_kses_post( $size ); ?></td></tr>
						</tbody>
					</table>
					<p>
						<a class="button" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener">View</a>
						<a class="button" href="<?php echo esc_url( $this->download_url( $key ) ); ?>">Download</a>
					</p>
				</div>
			<?php endforeach; ?>
		</div>

		<p style="margin:1.25em 0;">
			<?php if ( $static_ok === '1' ) : ?>
				<span class="dashicons dashicons-yes" style="color:#008a20;"></span> Static files are writable and served directly.
			<?php elseif ( $static_ok === '0' ) : ?>
				<span class="dashicons dashicons-warning" style="color:#b26200;"></span> Webroot not writable &mdash; files are served dynamically through WordPress instead. Path: <code><?php echo esc_html( $generator->static_path() ); ?></code>
			<?php else : ?>
				<em>Files have not been generated yet.</em>
			<?php endif; ?>
		</p>

		<form method="post" style="margin:1em 0;">
			<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
			<button class="button button-primary" name="bl_em_tool" value="regenerate">Regenerate now</button>
			<span class="description" style="margin-left:8px;">Rebuild both files from the current entities.</span>
		</form>

		<?php $this->render_backups(); ?>

		<h2 style="margin-top:2em;">Preview: entitymap.json</h2>
		<textarea readonly class="widefat code" rows="14" style="font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea( $generator->get_json( true ) ); ?></textarea>
		<?php
	}

	/** The backups + restore table. */
	private function render_backups() {
		$backups = BL_EntityMap_Backups::all();
		?>
		<h2 style="margin-top:2em;">Backups &amp; restore</h2>
		<p class="description" style="max-width:720px;">A snapshot of <code>entitymap.json</code> is saved automatically before every import or restore, so you can always undo. <strong>Restore</strong> re-imports a snapshot into the database and regenerates the files. The most recent <?php echo (int) BL_EntityMap_Backups::keep(); ?> are kept.</p>

		<?php if ( empty( $backups ) ) : ?>
			<p><em>No backups yet. One is created the first time you import or restore.</em></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr><th>Snapshot</th><th style="width:120px;">Size</th><th style="width:140px;">Reason</th><th style="width:280px;">Actions</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $backups as $b ) : ?>
					<tr>
						<td><code><?php echo esc_html( $b['name'] ); ?></code><br><span class="description"><?php echo esc_html( self::human_time( $b['time'] ) ); ?></span></td>
						<td><?php echo esc_html( size_format( $b['size'], 1 ) ); ?></td>
						<td><?php echo esc_html( $b['reason'] ? $b['reason'] : '—' ); ?></td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
								<input type="hidden" name="bl_em_backup" value="<?php echo esc_attr( $b['name'] ); ?>">
								<button class="button button-small button-primary" name="bl_em_tool" value="restore" onclick="return confirm('Restore this snapshot? The current map is backed up first, then replaced.');">Restore</button>
							</form>
							<a class="button button-small" href="<?php echo esc_url( $this->download_url( 'backup', $b['name'] ) ); ?>">Download</a>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
								<input type="hidden" name="bl_em_backup" value="<?php echo esc_attr( $b['name'] ); ?>">
								<button class="button button-small button-link-delete" name="bl_em_tool" value="delete_backup" onclick="return confirm('Delete this backup permanently?');" style="color:#b32d2e;">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Import tab — webroot import, upload/verify, and integrity check.
	 * ------------------------------------------------------------------- */

	private function tab_import() {
		$webroot = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json';
		$has_web = file_exists( $webroot );
		?>
		<h2>Upload &amp; verify a JSON file</h2>
		<p class="description" style="max-width:720px;">Check a new EntityMap file for problems before it touches the database. &ldquo;Verify &amp; import&rdquo; only writes if there are zero errors. The current map is backed up first, so you can undo from the <a href="<?php echo esc_url( self::tab_url( 'files' ) ); ?>">Files</a> tab.</p>
		<div style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 14px;max-width:720px;margin:1em 0;">
			<strong>Note:</strong> an upload is treated as the <em>complete</em> map. Any existing entity not in the file is moved to Trash (recoverable). Always upload the whole map, not a partial list.
		</div>
		<form method="post" enctype="multipart/form-data" style="margin:1em 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
			<input type="file" name="bl_em_json" accept="application/json,.json" required>
			<button class="button" name="bl_em_tool" value="verify">Verify only</button>
			<button class="button button-primary" name="bl_em_tool" value="verify_import" onclick="return confirm('Verify and, if clean, import this file? Entities with matching IDs will be updated.');">Verify &amp; import</button>
		</form>
		<?php $this->render_verify_report(); ?>

		<h2 style="margin-top:2em;">Import from the webroot</h2>
		<p class="description">Import the <code>entitymap.json</code> currently in the site root (<code><?php echo esc_html( $webroot ); ?></code>).</p>
		<form method="post" style="margin:1em 0;">
			<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
			<button class="button" name="bl_em_tool" value="import" <?php disabled( ! $has_web ); ?> onclick="return confirm('Import from the entitymap.json in the webroot? The current map is backed up first. Existing entities with matching IDs are updated.');">Import from webroot</button>
			<?php if ( ! $has_web ) : ?><span class="description" style="margin-left:8px;">No file found in the webroot.</span><?php endif; ?>
		</form>

		<h2 style="margin-top:2em;">Data integrity</h2>
		<table class="widefat striped" style="max-width:900px;">
			<tbody>
			<?php foreach ( self::validate() as $issue ) :
				$color = $issue['level'] === 'error' ? '#b32d2e' : ( $issue['level'] === 'warn' ? '#b26200' : '#008a20' ); ?>
				<tr>
					<td style="width:90px;color:<?php echo esc_attr( $color ); ?>;font-weight:600;text-transform:uppercase;"><?php echo esc_html( $issue['level'] ); ?></td>
					<td><?php echo esc_html( $issue['msg'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings tab.
	 * ------------------------------------------------------------------- */

	private function tab_settings() {
		$val = function( $k, $d = '' ) { return esc_attr( get_option( $k, $d ) ); };
		$chk = function( $k, $d = '1' ) { return checked( get_option( $k, $d ), '1', false ); };
		?>
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
				<tr><th scope="row"><label for="bl_em_base_url">Canonical base URL</label></th>
					<td>
						<input name="bl_em_base_url" id="bl_em_base_url" type="url" class="regular-text" value="<?php echo $val( 'bl_em_base_url' ); ?>" placeholder="<?php echo esc_attr( home_url() ); ?>">
						<p class="description">Used for schema <code>@id</code> references <strong>and for the absolute URLs baked into <code>entitymap.html</code> / <code>entitymap.json</code></strong> (canonical link, JSON-LD, alternate links). <strong>Set this to your production URL</strong> so files regenerated on staging still emit production URLs. Leave blank to use this site automatically (<?php echo esc_html( home_url() ); ?>). Per-page matching is host-agnostic regardless of this value.</p>
					</td></tr>
				<tr><th scope="row"><label for="bl_em_version">Spec version</label></th>
					<td><input name="bl_em_version" id="bl_em_version" type="text" class="small-text" value="<?php echo $val( 'bl_em_version', '1.0' ); ?>"></td></tr>
				<tr><th scope="row"><label for="bl_em_schema_url">Schema URL</label></th>
					<td><input name="bl_em_schema_url" id="bl_em_schema_url" type="url" class="regular-text" value="<?php echo $val( 'bl_em_schema_url', 'https://entitymap.org/spec/v1.0' ); ?>"></td></tr>
				<tr><th scope="row"><label for="bl_em_verification">Verification status</label></th>
					<td><input name="bl_em_verification" id="bl_em_verification" type="text" class="regular-text" value="<?php echo $val( 'bl_em_verification', 'self-declared' ); ?>"></td></tr>
				<tr><th scope="row"><label for="bl_em_profile">Profile</label></th>
					<td><input name="bl_em_profile" id="bl_em_profile" type="text" class="regular-text" value="<?php echo $val( 'bl_em_profile', 'core' ); ?>"></td></tr>
			</table>

			<h2 class="title">Output</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Publish EntityMap files</th>
					<td><input type="hidden" name="bl_em_enable_json" value="0"><label><input type="checkbox" name="bl_em_enable_json" value="1" <?php echo $chk( 'bl_em_enable_json' ); ?>> Serve <code>/entitymap.json</code> and <code>/entitymap.html</code></label>
					<p class="description">A curated, portable catalogue of your entities that you control.</p></td></tr>

				<tr><th scope="row"><label for="bl_em_backup_keep">Backups to keep</label></th>
					<td><input name="bl_em_backup_keep" id="bl_em_backup_keep" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( get_option( 'bl_em_backup_keep', BL_EntityMap_Backups::KEEP_DEFAULT ) ); ?>">
					<p class="description">How many <code>entitymap.json</code> snapshots to retain before pruning the oldest.</p></td></tr>

				<tr><th scope="row">Add EntityMap data to Yoast schema</th>
					<td>
						<input type="hidden" name="bl_em_enable_schema" value="0">
						<label><input type="checkbox" name="bl_em_enable_schema" value="1" <?php echo $chk( 'bl_em_enable_schema', '0' ); ?>> <strong>Inject EntityMap data into Yoast Schema.org output</strong></label>
						<p class="description"><strong>Off by default.</strong> This is the on-page structured data that Google&rsquo;s Knowledge Graph and AI engines actually read today. The two options below only apply when this is on.</p>
					</td></tr>
				<tr><th scope="row" style="padding-left:2em;">↳ Organization enrichment</th>
					<td><input type="hidden" name="bl_em_enable_org" value="0"><label><input type="checkbox" name="bl_em_enable_org" value="1" <?php echo $chk( 'bl_em_enable_org' ); ?>> Add sameAs / knowsAbout / makesOffer to Yoast&rsquo;s Organization node</label></td></tr>
				<tr><th scope="row" style="padding-left:2em;">↳ Per-page nodes</th>
					<td><input type="hidden" name="bl_em_enable_perpage" value="0"><label><input type="checkbox" name="bl_em_enable_perpage" value="1" <?php echo $chk( 'bl_em_enable_perpage' ); ?>> Inject DefinedTerm / Service nodes on each entity&rsquo;s attached page</label></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tool actions.
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
			BL_EntityMap_Backups::archive( 'import (webroot)' );
			$path   = trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json';
			$result = BL_EntityMap_Importer::import_file( $path, true );
			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
			} else {
				$generator->regenerate();
				$this->notice( 'success', sprintf( 'Import complete: %d created, %d updated, %d moved to Trash.', $result['created'], $result['updated'], $result['removed'] ) );
			}
		} elseif ( $tool === 'regenerate' ) {
			$generator->regenerate();
			$ok = get_option( 'bl_em_static_ok', '0' ) === '1';
			$this->notice( $ok ? 'success' : 'warning', $ok
				? 'Regenerated. Static entitymap.json / entitymap.html written to the webroot.'
				: 'Regenerated cache. Static files not writable — serving dynamically instead.' );
		} elseif ( $tool === 'restore' ) {
			$name   = isset( $_POST['bl_em_backup'] ) ? sanitize_text_field( wp_unslash( $_POST['bl_em_backup'] ) ) : '';
			$result = BL_EntityMap_Backups::restore( $name );
			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
			} else {
				$this->notice( 'success', sprintf( 'Restored %s: %d created, %d updated, %d moved to Trash. Files regenerated.', $name, $result['created'], $result['updated'], $result['removed'] ) );
			}
		} elseif ( $tool === 'delete_backup' ) {
			$name = isset( $_POST['bl_em_backup'] ) ? sanitize_text_field( wp_unslash( $_POST['bl_em_backup'] ) ) : '';
			BL_EntityMap_Backups::delete( $name );
			$this->notice( 'success', 'Backup deleted.' );
		} elseif ( $tool === 'verify' || $tool === 'verify_import' ) {
			$this->handle_upload( $tool, $generator );
		}
	}

	/**
	 * Handle an uploaded JSON file: validate (dry run), and import only when
	 * "verify & import" was chosen and there are zero errors.
	 */
	private function handle_upload( $tool, $generator ) {
		if ( empty( $_FILES['bl_em_json']['name'] ) || ! isset( $_FILES['bl_em_json']['tmp_name'] ) ) {
			$this->notice( 'error', 'No file was uploaded.' );
			return;
		}
		if ( (int) $_FILES['bl_em_json']['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $_FILES['bl_em_json']['tmp_name'] ) ) {
			$this->notice( 'error', 'Upload failed. Try a smaller file or check server upload limits.' );
			return;
		}

		$name = sanitize_file_name( $_FILES['bl_em_json']['name'] );
		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'json' ) {
			$this->notice( 'error', 'Please upload a .json file.' );
			return;
		}

		$raw    = file_get_contents( $_FILES['bl_em_json']['tmp_name'] );
		$doc    = json_decode( $raw, true );
		$report = BL_EntityMap_Importer::validate_document( $doc );

		$report['tool']     = $tool;
		$report['filename'] = $name;
		$report['imported'] = null;

		if ( $tool === 'verify_import' ) {
			if ( empty( $report['errors'] ) ) {
				BL_EntityMap_Backups::archive( 'import (upload)' );
				$result = BL_EntityMap_Importer::import_array( $doc, true );
				$generator->regenerate();
				$report['imported'] = $result;
			} else {
				$report['blocked'] = true;
			}
		}

		set_transient( 'bl_em_verify_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Stream a file for download (current JSON/HTML, or a backup), gated by a
	 * nonce + capability.
	 */
	public function handle_download() {
		if ( ! isset( $_GET['bl_em_dl'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bl_em_dl' ) ) {
			return;
		}

		$what      = sanitize_key( $_GET['bl_em_dl'] );
		$generator = new BL_EntityMap_Generator();

		if ( $what === 'json' ) {
			$path = $generator->static_path();
			$body = file_exists( $path ) ? file_get_contents( $path ) : $generator->get_json( true );
			$type = 'application/json';
			$dl   = 'entitymap.json';
		} elseif ( $what === 'html' ) {
			$path = $generator->static_html_path();
			$body = file_exists( $path ) ? file_get_contents( $path ) : $generator->get_html();
			$type = 'text/html';
			$dl   = 'entitymap.html';
		} elseif ( $what === 'backup' ) {
			$name = isset( $_GET['name'] ) ? wp_unslash( $_GET['name'] ) : '';
			$body = BL_EntityMap_Backups::read( $name );
			if ( $body === '' ) {
				wp_die( 'Backup not found.' );
			}
			$type = 'application/json';
			$dl   = basename( $name );
		} else {
			return;
		}

		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $dl . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function download_url( $what, $name = '' ) {
		$args = array( 'bl_em_dl' => $what );
		if ( $name !== '' ) {
			$args['name'] = $name;
		}
		return wp_nonce_url( add_query_arg( $args, self::tab_url( 'files' ) ), 'bl_em_dl' );
	}

	private function notice( $type, $msg ) {
		add_action( 'admin_notices', function () use ( $type, $msg ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
		} );
	}

	/** Render the result of the most recent upload verification, if any. */
	private function render_verify_report() {
		$key    = 'bl_em_verify_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! $report ) {
			return;
		}
		delete_transient( $key );

		$errors   = $report['errors'];
		$warnings = $report['warnings'];
		$stats    = $report['stats'];
		$ok       = empty( $errors );
		$border   = $ok ? '#008a20' : '#b32d2e';
		?>
		<div style="border-left:4px solid <?php echo esc_attr( $border ); ?>;background:#fff;padding:12px 16px;margin:0 0 1.5em;max-width:900px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
			<p style="margin:0 0 .5em;">
				<strong><?php echo esc_html( $report['filename'] ); ?></strong>
				&mdash; <?php echo (int) $stats['entities']; ?> entities, <?php echo (int) $stats['chunks']; ?> chunks, <?php echo (int) $stats['relations']; ?> relations.
			</p>

			<?php if ( ! empty( $report['imported'] ) ) : ?>
				<p style="color:#008a20;font-weight:600;margin:.25em 0;">✓ Imported: <?php echo (int) $report['imported']['created']; ?> created, <?php echo (int) $report['imported']['updated']; ?> updated, <?php echo (int) $report['imported']['removed']; ?> moved to Trash. Files regenerated.</p>
			<?php elseif ( ! empty( $report['blocked'] ) ) : ?>
				<p style="color:#b32d2e;font-weight:600;margin:.25em 0;">Not imported — fix the errors below and try again.</p>
			<?php elseif ( $report['tool'] === 'verify' && $ok ) : ?>
				<p style="color:#008a20;font-weight:600;margin:.25em 0;">✓ Looks valid. Use &ldquo;Verify &amp; import&rdquo; to load it.</p>
			<?php endif; ?>

			<?php if ( $errors ) : ?>
				<p style="color:#b32d2e;font-weight:600;margin:.75em 0 .25em;">Errors (<?php echo count( $errors ); ?>)</p>
				<ul style="margin:0 0 .5em 1.2em;list-style:disc;color:#b32d2e;">
					<?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $warnings ) : ?>
				<p style="color:#b26200;font-weight:600;margin:.75em 0 .25em;">Warnings (<?php echo count( $warnings ); ?>)</p>
				<ul style="margin:0 0 .5em 1.2em;list-style:disc;color:#b26200;">
					<?php foreach ( $warnings as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! $errors && ! $warnings ) : ?>
				<p style="color:#008a20;margin:.25em 0;">No issues found.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Help tab.
	 * ------------------------------------------------------------------- */

	private function tab_help() {
		$json_url = esc_html( home_url( '/entitymap.json' ) );
		$html_url = esc_html( home_url( '/entitymap.html' ) );
		$devtools = <<<'JS'
(() => {
  const blocks = [...document.querySelectorAll('script[type="application/ld+json"]')]
    .map(s => { try { return JSON.parse(s.textContent); } catch { return null; } })
    .filter(Boolean);
  const graph = blocks.flatMap(b => b['@graph'] || [b]);
  const org = graph.find(n => [].concat(n['@type']).includes('Organization'));
  console.table(graph.map(n => ({ type: [].concat(n['@type']).join('/'), name: n.name || '' })));
  if (org) console.log('knowsAbout:', (org.knowsAbout||[]).length, ' makesOffer:', (org.makesOffer||[]).length);
})();
JS;
		?>
		<div style="max-width:900px;">
			<p style="font-size:14px;color:#555;max-width:680px;">Curate the entities BrightLocal is known for &mdash; products, services, key concepts, and research &mdash; in one place, and publish them as portable <code>entitymap.json</code> / <code>entitymap.html</code> files. Optionally, the same data can enrich your Yoast Schema.org markup.</p>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>What this tool does</h2>
				<p>It maintains one list of the <strong>things BrightLocal is about</strong> and publishes it two ways:</p>
				<ol>
					<li><strong>Files</strong> at <code><?php echo $json_url; ?></code> and <code><?php echo $html_url; ?></code> &mdash; a curated, portable catalogue you control. Manage them under the <a href="<?php echo esc_url( self::tab_url( 'files' ) ); ?>">Files</a> tab.</li>
					<li><strong>On-page Schema.org</strong> (via Yoast) &mdash; the structured data Google&rsquo;s Knowledge Graph and AI engines actually read today. <em>Off by default;</em> enable it under <a href="<?php echo esc_url( self::tab_url( 'settings' ) ); ?>">Settings</a>.</li>
				</ol>
				<p style="margin-bottom:0;">You edit the list <em>once</em>, here in wp-admin. Both outputs update on their own. You never edit a file by hand.</p>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Key words (glossary)</h2>
				<table class="widefat striped">
					<tbody>
						<tr><td style="width:170px;"><strong>Entity</strong></td><td>One &ldquo;thing&rdquo; we&rsquo;re describing &mdash; a product, service, concept, or research report. Curate them all on the <strong>Manage Entities</strong> tab.</td></tr>
						<tr><td><strong>Type</strong></td><td>What kind of thing it is: Organization, Service, Platform, Concept, ProprietaryTerm, Metric, etc.</td></tr>
						<tr><td><strong>Evidence chunk</strong></td><td>A short quote (1&ndash;5 sentences) from our site that backs up the entity, with a link to the source page.</td></tr>
						<tr><td><strong>Relation</strong></td><td>A link between two entities, e.g. BrightLocal <em>OFFERS</em> Citation Builder.</td></tr>
						<tr><td><strong>sameAs</strong></td><td>A link to the same thing on an authoritative site (usually Wikidata). Only add one you&rsquo;ve verified. This is the single most useful field for helping AI resolve who/what an entity is.</td></tr>
					</tbody>
				</table>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Everyday tasks</h2>
				<h3>Edit or add an entity</h3>
				<ol>
					<li>Open the <a href="<?php echo esc_url( self::tab_url( 'manage' ) ); ?>">Manage Entities</a> tab.</li>
					<li>Click an entity in the left-hand list to edit it, or <strong>＋ Add entity</strong> to create one.</li>
					<li>Set the <strong>Name</strong> and <strong>Description</strong>, then the <strong>Type</strong>, an optional verified <em>sameAs</em>, and the page to <em>Attach to</em> (search by title).</li>
					<li>Add <strong>Evidence chunks</strong> and <strong>Relations</strong> as needed, then <strong>Save entity</strong>. The files regenerate automatically.</li>
				</ol>
				<h3>Upload a whole new map</h3>
				<ol>
					<li>Go to the <a href="<?php echo esc_url( self::tab_url( 'import' ) ); ?>">Import</a> tab.</li>
					<li><strong>Verify only</strong> checks a file without changing anything; <strong>Verify &amp; import</strong> loads it if there are zero errors.</li>
					<li>The current map is backed up first &mdash; undo any time from the <a href="<?php echo esc_url( self::tab_url( 'files' ) ); ?>">Files</a> tab.</li>
				</ol>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Check it&rsquo;s working</h2>
				<p><strong>The files:</strong> open <code><?php echo $json_url; ?></code> &mdash; you should see all the entities.</p>
				<p><strong>On-page schema</strong> (if enabled): open any page, press <kbd>F12</kbd> &rarr; <strong>Console</strong>, paste this and press Enter:</p>
				<textarea readonly class="widefat code" rows="9" style="font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea( $devtools ); ?></textarea>
				<p>To formally validate, paste a page&rsquo;s structured data into <a href="https://validator.schema.org/" target="_blank" rel="noopener">validator.schema.org</a> or <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google&rsquo;s Rich Results Test</a>.</p>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Requirement for Yoast schema</h2>
				<p style="margin-bottom:0;">The Schema.org output is <strong>off by default</strong> &mdash; enable it under <a href="<?php echo esc_url( self::tab_url( 'settings' ) ); ?>">Settings</a>. Once on, the Organization schema only appears when <strong>Yoast SEO</strong> is active and, under <em>Yoast &rarr; Settings &rarr; Site representation</em>, the site represents an <strong>Organization</strong> with a <strong>name and logo</strong>. The <a href="<?php echo esc_url( self::tab_url( 'import' ) ); ?>">Import</a> tab flags this under Data integrity.</p>
			</div>
		</div>
		<?php
	}

	/** Format a unix timestamp in the site's timezone with a relative hint. */
	private static function human_time( $ts ) {
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return sprintf( '%s (%s ago)', wp_date( $fmt, $ts ), human_time_diff( $ts, time() ) );
	}

	/* ---------------------------------------------------------------------
	 * Validation (structural integrity of the current DB entities).
	 * ------------------------------------------------------------------- */

	public static function validate() {
		$entities = BL_EntityMap_Store::get_entities( true );
		$issues   = array();

		if ( empty( $entities ) ) {
			return array( array( 'level' => 'warn', 'msg' => 'No published entities yet. Use the Import tab to seed from entitymap.json.' ) );
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
