<?php
/**
 * Registry + shared admin menu for BL AI Tools.
 *
 * Holds the set of BL_AI_Tool modules, boots them, and owns the single
 * top-level "BL AI Tools" menu they all live under. Adding a new tool is one
 * line — register an instance — and the menu, dashboard card, and booting are
 * handled here.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BL_AI_Tools_Registry {

	/** Top-level menu slug. CPTs can attach via show_in_menu => this value. */
	const MENU_SLUG = 'bl-ai-tools';

	/** Capability required to see the BL AI Tools menu + dashboard. */
	const CAP = 'edit_posts';

	/** @var BL_AI_Tool[] keyed by tool id. */
	private $tools = array();

	/** Register a tool instance. Chainable. */
	public function add( BL_AI_Tool $tool ) {
		$this->tools[ $tool->id() ] = $tool;
		return $this;
	}

	/** @return BL_AI_Tool[] */
	public function tools() {
		return $this->tools;
	}

	/** Boot every tool and wire the shared menu. Call once on plugins_loaded. */
	public function boot() {
		foreach ( $this->tools as $tool ) {
			$tool->register();
		}
		// Priority 9 so the top-level menu exists before CPT menus (built at the
		// default priority 10) that set show_in_menu => self::MENU_SLUG.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 9 );
	}

	/** Run each tool's activation work. Call from the activation hook. */
	public function activate() {
		foreach ( $this->tools as $tool ) {
			$tool->activate();
		}
	}

	public function admin_menu() {
		add_menu_page(
			'BL AI Tools',
			'BL AI Tools',
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			self::menu_icon(),
			76 // just below Tools (75); Settings is 80.
		);

		// The first submenu of a top-level menu duplicates it; relabel to "Dashboard".
		add_submenu_page( self::MENU_SLUG, 'BL AI Tools', 'Dashboard', self::CAP, self::MENU_SLUG, array( $this, 'render_dashboard' ) );

		foreach ( $this->tools as $tool ) {
			$tool->register_admin( self::MENU_SLUG );
		}
	}

	public function render_dashboard() {
		?>
		<div class="wrap">
			<h1>BL AI Tools</h1>
			<p class="description" style="font-size:14px;max-width:680px;">A collection of AI-facing tools for BrightLocal. Each tool is self-contained &mdash; more can be bolted in over time.</p>
			<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:1.5em;">
				<?php foreach ( $this->tools as $tool ) : ?>
					<div class="card" style="width:340px;max-width:100%;padding:8px 20px 18px;">
						<h2 style="display:flex;align-items:center;gap:8px;">
							<span class="dashicons <?php echo esc_attr( $tool->icon() ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $tool->label() ); ?>
						</h2>
						<?php if ( $tool->description() ) : ?>
							<p style="min-height:3em;"><?php echo esc_html( $tool->description() ); ?></p>
						<?php endif; ?>
						<?php if ( $tool->has_admin() ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( self::tool_url( $tool ) ); ?>">Open <?php echo esc_html( $tool->label() ); ?></a></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Top-level menu icon. Uses the custom SVG at the plugin's assets/icon.svg
	 * (base64 data URI) when present; otherwise falls back to a Dashicon. To
	 * re-brand, replace that file with your own — a small, single-colour SVG with
	 * a square-ish viewBox works best. Filterable via `bl_ai_menu_icon` for a URL
	 * or Dashicon instead.
	 */
	private static function menu_icon() {
		$default = 'dashicons-superhero';
		$svg     = defined( 'BL_AI_DIR' ) ? BL_AI_DIR . 'assets/icon.svg' : '';

		if ( $svg && is_readable( $svg ) ) {
			$data = file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_string( $data ) && $data !== '' ) {
				$default = 'data:image/svg+xml;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}

		return apply_filters( 'bl_ai_menu_icon', $default );
	}

	/** Resolve a tool's primary admin URL from its menu_slug(). */
	private static function tool_url( BL_AI_Tool $tool ) {
		$slug = $tool->menu_slug();
		// A slug containing ".php" is a full admin page (e.g. a CPT list).
		return admin_url( false !== strpos( $slug, '.php' ) ? $slug : 'admin.php?page=' . $slug );
	}
}
