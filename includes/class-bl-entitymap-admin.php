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
		'bl_em_base_url'         => 'url',
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
		add_submenu_page( self::PARENT, 'EntityMap Help & Docs', 'Help & Docs', 'edit_posts', 'bl-em-help', array( $this, 'render_help' ) );
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
					<tr><th scope="row"><label for="bl_em_base_url">Canonical base URL</label></th>
						<td>
							<input name="bl_em_base_url" id="bl_em_base_url" type="url" class="regular-text" value="<?php echo $val( 'bl_em_base_url' ); ?>" placeholder="<?php echo esc_attr( home_url() ); ?>">
							<p class="description">Used for schema <code>@id</code> references. <strong>Leave blank to use this site automatically</strong> (<?php echo esc_html( home_url() ); ?>). Per-page matching is host-agnostic and always works on any server regardless of this value.</p>
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
				? 'Regenerated. Static /entitymap.json written to the webroot.'
				: 'Regenerated cache. Static file not writable — serving /entitymap.json dynamically instead.' );
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
				$result = BL_EntityMap_Importer::import_array( $doc, true );
				$generator->regenerate();
				$report['imported'] = $result;
			} else {
				$report['blocked'] = true;
			}
		}

		set_transient( 'bl_em_verify_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
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
				<p style="color:#008a20;font-weight:600;margin:.25em 0;">✓ Imported: <?php echo (int) $report['imported']['created']; ?> created, <?php echo (int) $report['imported']['updated']; ?> updated, <?php echo (int) $report['imported']['removed']; ?> moved to Trash. /entitymap.json regenerated.</p>
			<?php elseif ( ! empty( $report['blocked'] ) ) : ?>
				<p style="color:#b32d2e;font-weight:600;margin:.25em 0;">Not imported — fix the errors below and try again.</p>
			<?php elseif ( $report['tool'] === 'verify' && $ok ) : ?>
				<p style="color:#008a20;font-weight:600;margin:.25em 0;">✓ Looks valid. Use “Verify &amp; import” to load it.</p>
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
				Machine-readable: <code><a href="<?php echo esc_url( home_url( '/entitymap.json' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/entitymap.json' ) ); ?></a></code><br>
				Human-readable: <code><a href="<?php echo esc_url( home_url( '/entitymap.html' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/entitymap.html' ) ); ?></a></code><br>
				Static files: <code><?php echo esc_html( $generator->static_path() ); ?></code> + <code>entitymap.html</code>
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

			<h2>Upload &amp; verify a JSON file</h2>
			<p class="description">Check a new EntityMap file for problems before it touches the database. “Verify &amp; import” only writes if there are zero errors.</p>
			<form method="post" enctype="multipart/form-data" style="margin:1em 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php wp_nonce_field( 'bl_em_tool', 'bl_em_tool_nonce' ); ?>
				<input type="file" name="bl_em_json" accept="application/json,.json" required>
				<button class="button" name="bl_em_tool" value="verify">Verify only</button>
				<button class="button button-primary" name="bl_em_tool" value="verify_import" onclick="return confirm('Verify and, if clean, import this file? Entities with matching IDs will be updated.');">Verify &amp; import</button>
			</form>
			<?php $this->render_verify_report(); ?>

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
	 * Help & documentation.
	 * ------------------------------------------------------------------- */

	public function render_help() {
		$home     = esc_html( home_url( '/entitymap.json' ) );
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
		<div class="wrap" style="max-width:900px;">
			<h1>EntityMap — How to use this plugin</h1>
			<p style="font-size:14px;color:#555;max-width:680px;">Everything you need to manage BrightLocal's EntityMap &mdash; the single, structured record of who we are, what we offer, and the concepts we're known for. Edit it here once, and it's published automatically for both AI tools and search engines. This guide walks through the day-to-day.</p>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>What this plugin does</h2>
				<p>It maintains one list of the <strong>things BrightLocal is about</strong> — our products, services, key concepts, and research — and publishes that in two places automatically:</p>
				<ol>
					<li><strong>An EntityMap file</strong> at <code><?php echo $home; ?></code> — a machine-readable index for <strong>AI tools</strong> (ChatGPT, Claude, etc.) so they understand and cite us accurately.</li>
					<li><strong>Structured data (Schema.org)</strong> added to Yoast on every page — this is what <strong>Google and other search engines</strong> read for rich results and knowledge panels.</li>
				</ol>
				<p style="margin-bottom:0;">You edit the list <em>once</em>, here in wp-admin. Both outputs update on their own. You never edit a file by hand.</p>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Key words (glossary)</h2>
				<table class="widefat striped">
					<tbody>
						<tr><td style="width:170px;"><strong>Entity</strong></td><td>One "thing" we're describing — a product, service, concept, or research report. Each lives under <strong>EntityMap &rarr; All Entities</strong>.</td></tr>
						<tr><td><strong>Type</strong></td><td>What kind of thing it is: Organization, Service, Platform, Concept, ProprietaryTerm (our own named things), Metric, etc.</td></tr>
						<tr><td><strong>Evidence chunk</strong></td><td>A short quote (1&ndash;5 sentences) from our site that backs up the entity, with a link to the source page.</td></tr>
						<tr><td><strong>Relation</strong></td><td>A link between two entities, e.g. BrightLocal <em>OFFERS</em> Citation Builder.</td></tr>
						<tr><td><strong>sameAs</strong></td><td>A link to the same thing on an authoritative site (usually Wikidata). Only add one you've verified points to the right thing.</td></tr>
						<tr><td><strong>knowsAbout / makesOffer</strong></td><td>Automatic Google output: the topics we're expert in, and the products/services we offer.</td></tr>
					</tbody>
				</table>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Everyday tasks</h2>

				<h3>Edit an existing entity</h3>
				<ol>
					<li>Go to <strong>EntityMap &rarr; All Entities</strong> and click one.</li>
					<li><strong>Title</strong> = the name. <strong>Body</strong> = the description (one clear sentence or two).</li>
					<li><strong>Entity Details</strong> (right side): the Type, an optional verified <em>sameAs</em> link, and <em>Attach to page URL</em> — the page this entity is "about" (its schema is added to that page).</li>
					<li><strong>Evidence Chunks</strong>: add quotes from our pages with their source links.</li>
					<li><strong>Relations</strong>: connect it to other entities (pick a predicate + the target entity).</li>
					<li>Click <strong>Update</strong>. The EntityMap file and Google schema refresh automatically.</li>
				</ol>

				<h3>Add a new entity</h3>
				<p>Same as above, via <strong>EntityMap &rarr; Add New</strong>. It gets an ID automatically on first save.</p>

				<h3>Upload a whole new map (bulk)</h3>
				<ol>
					<li>Go to <strong>EntityMap &rarr; Tools &rarr; Upload &amp; verify a JSON file</strong>.</li>
					<li><strong>Verify only</strong> checks the file and reports problems <em>without changing anything</em>.</li>
					<li><strong>Verify &amp; import</strong> loads it — but only if there are <strong>zero errors</strong> (warnings are OK).</li>
				</ol>
				<p style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 14px;">
					<strong>Important:</strong> an upload is treated as the <em>complete</em> map. Any existing entity that isn't in the uploaded file is moved to <strong>Trash</strong> (recoverable). So always upload the <em>whole</em> map, not a partial list. Uploaded files are stored privately in <code>uploads/bl-entitymap/</code> and are never added to the Media Library.
				</p>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Settings (one-time)</h2>
				<p>Under <strong>EntityMap &rarr; Settings</strong>:</p>
				<ul style="list-style:disc;margin-left:1.4em;">
					<li><strong>Publisher</strong> details and the spec fields — usually left as-is.</li>
					<li><strong>Canonical base URL</strong> — leave blank; it auto-uses the current site. Only set it to force a specific domain in the AI identifiers.</li>
					<li><strong>Output toggles</strong> — turn the EntityMap file, the Organization schema, and per-page schema on/off.</li>
				</ul>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Check it's working</h2>
				<p><strong>The AI file:</strong> open <code><?php echo $home; ?></code> in a browser — you should see all the entities.</p>
				<p><strong>Google schema:</strong> open any page, press <kbd>F12</kbd> &rarr; <strong>Console</strong>, paste this and press Enter:</p>
				<textarea readonly class="widefat code" rows="9" style="font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea( $devtools ); ?></textarea>
				<p>You'll get a table of the schema on that page, plus the <em>knowsAbout</em> and <em>makesOffer</em> counts. To formally validate, paste a page's structured data into <a href="https://validator.schema.org/" target="_blank" rel="noopener">validator.schema.org</a> or <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google's Rich Results Test</a> (use their "Code" box for staging sites that aren't publicly reachable).</p>
			</div>

			<div class="card" style="max-width:100%;padding:4px 20px 16px;">
				<h2>Requirement for Google schema</h2>
				<p style="margin-bottom:0;">The Organization schema only appears when <strong>Yoast SEO</strong> is active and, under <em>Yoast &rarr; Settings &rarr; Site representation</em>, the site represents an <strong>Organization</strong> with a <strong>name and a logo</strong> set. Without those, Google's Organization block (and our enrichment) won't render. The <strong>Tools</strong> page flags this automatically.</p>
			</div>
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
