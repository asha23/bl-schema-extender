<?php
/**
 * Entity Maps — the first BL AI Tools module.
 *
 * Wraps the existing EntityMap feature (the bl_entity CPT, generator, Yoast
 * schema, importer, backups, and admin) as a self-contained tool that plugs
 * into the shared BL AI Tools menu. All the real work still lives in the
 * BL_EntityMap_* classes; this class only wires them together and places the
 * tool in the menu.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-bl-entitymap-store.php';
require_once __DIR__ . '/class-bl-entitymap-cpt.php';
require_once __DIR__ . '/class-bl-entitymap-generator.php';
require_once __DIR__ . '/class-bl-entitymap-schema.php';
require_once __DIR__ . '/class-bl-entitymap-importer.php';
require_once __DIR__ . '/class-bl-entitymap-backups.php';
require_once __DIR__ . '/class-bl-entitymap-manager.php';
require_once __DIR__ . '/class-bl-entitymap-admin.php';

class BL_AI_Tool_EntityMaps extends BL_AI_Tool {

	/** Page slug of the tabbed Entity Maps hub. */
	const HUB_SLUG = 'bl-em-entity-maps';

	/** @var BL_EntityMap_Admin */
	private $admin;

	public function id() {
		return 'entity-maps';
	}

	public function label() {
		return 'Entity Maps';
	}

	public function description() {
		return 'Curate the entities BrightLocal is known for and publish them as entitymap.json / entitymap.html. Optionally feeds Yoast Schema.org.';
	}

	public function icon() {
		return 'dashicons-networking';
	}

	/** The dashboard card opens the tabbed hub. */
	public function menu_slug() {
		return self::HUB_SLUG;
	}

	/** Wire runtime hooks (front-end schema, CPT, save handlers, admin logic). */
	public function register() {
		new BL_EntityMap_CPT();
		new BL_EntityMap_Generator();
		new BL_EntityMap_Schema();
		$this->admin = new BL_EntityMap_Admin();
	}

	/** Add the Entity Maps hub under the BL AI Tools menu. */
	public function register_admin( $parent_slug ) {
		add_submenu_page(
			$parent_slug,
			'Entity Maps',
			'Entity Maps',
			'manage_options',
			self::HUB_SLUG,
			array( $this->admin, 'render_hub' )
		);
	}

	/** Activation: register the CPT + rewrite endpoints (rules flushed centrally). */
	public function activate() {
		( new BL_EntityMap_CPT() )->register_post_type();
		( new BL_EntityMap_Generator() )->add_rewrite();
		// Provision the private entitymap-backups folder up front (it is also
		// created on demand, but wiring it into activation guarantees it exists).
		BL_EntityMap_Backups::dir();
	}
}
