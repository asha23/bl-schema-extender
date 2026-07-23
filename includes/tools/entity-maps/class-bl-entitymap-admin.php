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
		'bl_em_enable_llms'      => 'bool',
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

		// Custom vocabulary (stored as arrays; edited as one-per-line textareas).
		register_setting( self::GROUP, 'bl_em_custom_types', array( 'type' => 'array', 'default' => array(), 'sanitize_callback' => array( $this, 'sanitize_vocab_list' ) ) );
		register_setting( self::GROUP, 'bl_em_custom_predicates', array( 'type' => 'array', 'default' => array(), 'sanitize_callback' => array( $this, 'sanitize_vocab_list' ) ) );
	}

	public function sanitize_bool( $val ) {
		return $val === '1' ? '1' : '0';
	}

	/**
	 * Sanitise a custom-vocabulary textarea (newline/comma separated) into a
	 * clean, de-duplicated string[]. Entries are limited to safe token
	 * characters so they can't corrupt the JSON/graph.
	 */
	public function sanitize_vocab_list( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n,]+/', (string) $raw );
		$clean = array();
		foreach ( (array) $items as $item ) {
			$item = preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( (string) $item ) );
			if ( $item !== '' ) {
				$clean[] = $item;
			}
		}
		return array_values( array_unique( $clean ) );
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

		$llms_on = get_option( 'bl_em_enable_llms', '0' ) === '1';
		if ( $llms_on ) {
			$files['llms'] = array(
				'label' => 'llms.txt',
				'desc'  => 'AI site guide, generated from the EntityMap.',
				'url'   => home_url( '/llms.txt' ),
				'path'  => $generator->static_llms_path(),
			);
		}
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

		<?php if ( ! $llms_on ) : ?>
			<p style="margin:1.25em 0;"><span class="dashicons dashicons-info-outline" style="color:#787c82;"></span> <code>llms.txt</code> generation is <strong>off</strong>. Turn on &ldquo;Generate llms.txt&rdquo; in <a href="<?php echo esc_url( self::tab_url( 'settings' ) ); ?>">Settings</a> to publish <code>/llms.txt</code> from the EntityMap.</p>
		<?php endif; ?>

		<form method="post" style="margin:1em 0;">
			<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
<p style="margin:0 0 1em;">
				<?php if ( ! BL_EntityMap_Sitemap::yoast_active() ) : ?>
					<span class="dashicons dashicons-info-outline" style="color:#787c82;"></span> <strong>XML sitemap:</strong> requires <strong>Yoast SEO</strong> (not detected). Everything else works without it.
				<?php elseif ( $enabled ) : ?>
					<span class="dashicons dashicons-yes" style="color:#008a20;"></span> <strong>XML sitemap</strong> registered &mdash; <a href="<?php echo esc_url( home_url( '/entitymap-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">entitymap-sitemap.xml</a>, listed in <a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener">sitemap_index.xml</a>. Lists <code>entitymap.html</code> only.
				<?php endif; ?>
			</p>
			<button class="button button-primary" name="bl_em_tool" value="regenerate">Regenerate now</button>
			<span class="description" style="margin-left:8px;">Rebuild the entitymap files<?php echo $llms_on ? ' and llms.txt' : ''; ?> from the current entities.</span>
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
		<p class="description" style="max-width:760px;">A snapshot of the map is saved automatically <strong>before every change</strong> &mdash; each entity save/delete, import, and restore &mdash; and you can take one anytime with the button below. <strong>Restore</strong> re-imports a snapshot into the database and regenerates the files, so it&rsquo;s a true undo of the whole map (the current state is snapshotted first, so a restore is itself reversible). The most recent <?php echo (int) BL_EntityMap_Backups::keep(); ?> are kept.</p>

		<form method="post" style="margin:0 0 1.25em;">
			<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
			<button class="button" name="bl_em_tool" value="backup">Create backup now</button>
			<span class="description" style="margin-left:8px;">Save the current map as a restore point.</span>
		</form>

		<?php if ( empty( $backups ) ) : ?>
			<p><em>No backups yet &mdash; use &ldquo;Create backup now&rdquo;, or one is saved automatically on your next change.</em></p>
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

				<tr><th scope="row">Generate <code>llms.txt</code></th>
					<td><input type="hidden" name="bl_em_enable_llms" value="0"><label><input type="checkbox" name="bl_em_enable_llms" value="1" <?php echo $chk( 'bl_em_enable_llms', '0' ); ?>> Generate <code>/llms.txt</code> from the EntityMap</label>
					<p class="description"><strong>Off by default.</strong> Writes a complete <code>llms.txt</code> to the webroot on every change &mdash; a title/summary, a machine-readable index, and the entities grouped by kind and linked to their pages. Uses the Canonical base URL for links. <strong>This overwrites any existing <code>llms.txt</code></strong>, so enable it only if the EntityMap is your source for that file.</p></td></tr>

				<tr><th scope="row"><label for="bl_em_backup_keep">Backups to keep</label></th>
					<td><input name="bl_em_backup_keep" id="bl_em_backup_keep" type="number" min="1" max="100" class="small-text" value="<?php echo esc_attr( get_option( 'bl_em_backup_keep', BL_EntityMap_Backups::KEEP_DEFAULT ) ); ?>">
					<p class="description">How many <code>entitymap.json</code> snapshots to retain before pruning the oldest.</p></td></tr>

<?php if ( BL_EntityMap_Schema::FEATURE_ENABLED ) : ?>
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
<?php endif; ?>
			</table>

			<h2 class="title">Vocabulary</h2>
			<p class="description" style="max-width:720px;">Extend the recognised entity types and relation predicates so enriched imports validate cleanly. <strong>Additive only</strong> &mdash; the built-in vocabulary is always recognised and can&rsquo;t be removed here. One entry per line. Types are <code>PascalCase</code> (e.g. <code>CreativeWork</code>); predicates are <code>UPPER_SNAKE</code> (e.g. <code>SPONSORS</code>).</p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="bl_em_custom_types">Additional entity types</label></th>
					<td>
						<textarea name="bl_em_custom_types" id="bl_em_custom_types" rows="4" class="large-text code" placeholder="One per line, e.g. CreativeWork"><?php echo esc_textarea( implode( "\n", (array) get_option( 'bl_em_custom_types', array() ) ) ); ?></textarea>
						<p class="description">Built-in (always recognised): <?php echo esc_html( implode( ', ', BL_EntityMap_Store::builtin_entity_types() ) ); ?></p>
					</td></tr>
				<tr><th scope="row"><label for="bl_em_custom_predicates">Additional relation predicates</label></th>
					<td>
						<textarea name="bl_em_custom_predicates" id="bl_em_custom_predicates" rows="4" class="large-text code" placeholder="One per line, e.g. SPONSORS"><?php echo esc_textarea( implode( "\n", (array) get_option( 'bl_em_custom_predicates', array() ) ) ); ?></textarea>
						<p class="description">Built-in (always recognised): <?php echo esc_html( implode( ', ', BL_EntityMap_Store::builtin_predicates() ) ); ?></p>
					</td></tr>
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
		} elseif ( $tool === 'backup' ) {
			$name = BL_EntityMap_Backups::archive( 'manual' );
			if ( $name ) {
				$this->notice( 'success', 'Backup created: ' . $name . '. Restore it any time below.' );
			} else {
				$this->notice( 'warning', 'Nothing to back up yet — add an entity first.' );
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
		} elseif ( $what === 'llms' ) {
			$path = $generator->static_llms_path();
			$body = file_exists( $path ) ? file_get_contents( $path ) : $generator->get_llms();
			$type = 'text/plain';
			$dl   = 'llms.txt';
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
		// Single source of truth: render docs/help.md. Tokens below resolve to the
		// live tab/file URLs so links work without duplicating content in PHP.
		$file = BL_AI_DIR . 'docs/help.md';
		if ( ! is_readable( $file ) ) {
			echo '<p>Help document not found.</p>';
			return;
		}

		$md = strtr( (string) file_get_contents( $file ), array(
			'{{tab:manage}}'   => self::tab_url( 'manage' ),
			'{{tab:files}}'    => self::tab_url( 'files' ),
			'{{tab:import}}'   => self::tab_url( 'import' ),
			'{{tab:settings}}' => self::tab_url( 'settings' ),
			'{{url:json}}'     => home_url( '/entitymap.json' ),
			'{{url:html}}'     => home_url( '/entitymap.html' ),
			'{{url:llms}}'     => home_url( '/llms.txt' ),
			'{{url:sitemap}}'  => home_url( '/entitymap-sitemap.xml' ),
			'{{changelog:5}}'  => $this->changelog_excerpt( 5 ),
		) );
		?>
		<style>
			.bl-md { max-width: 860px; }
			.bl-md h2 { margin: 1.6em 0 .4em; padding-top: .6em; border-top: 1px solid #dcdcde; font-size: 1.3em; }
			.bl-md h2:first-of-type { border-top: 0; padding-top: 0; }
			.bl-md h3 { margin: 1.2em 0 .3em; font-size: 1.05em; }
			.bl-md li { margin: .3em 0; }
			.bl-md code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; }
			.bl-md pre.bl-md-pre { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; overflow-x: auto; }
			.bl-md pre.bl-md-pre code { background: none; padding: 0; }
		</style>
		<div class="bl-md">
			<?php echo BL_AI_Markdown::to_html( $md ); // phpcs:ignore WordPress.Security.EscapeOutput — renderer escapes text and emits only whitelisted tags ?>
		</div>
		<?php
	}

	/**
	 * The N most recent CHANGELOG.md version sections, as Markdown, with their
	 * `##` headings demoted to `###` so they nest under the Help "Latest changes"
	 * heading. Keeps the Help doc's changelog in sync with docs/CHANGELOG.md
	 * automatically — no duplication.
	 */
	private function changelog_excerpt( $count ) {
		$file = BL_AI_DIR . 'docs/CHANGELOG.md';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$lines    = explode( "\n", str_replace( "\r\n", "\n", (string) file_get_contents( $file ) ) );
		$out      = array();
		$sections = 0;
		$capture  = false;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
				if ( ++$sections > $count ) {
					break;
				}
				$capture = true;
				$out[]   = '### ' . $m[1];
				continue;
			}
			if ( $capture ) {
				$out[] = $line;
			}
		}
		return trim( implode( "\n", $out ) );
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

		// Organization / Yoast checks only matter to the schema-mapping feature,
		// which is currently hidden (BL_EntityMap_Schema::FEATURE_ENABLED). Skip
		// them so the integrity report doesn't warn about an inactive feature.
		if ( BL_EntityMap_Schema::FEATURE_ENABLED ) {
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
		}

		return $issues;
	}
}
