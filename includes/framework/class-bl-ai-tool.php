<?php
/**
 * Base class for a BL AI Tools module.
 *
 * A "tool" is a self-contained feature that plugs into the shared BL AI Tools
 * admin menu. To add one: subclass this, implement id() + label(), require the
 * class, and register an instance with BL_AI_Tools_Registry in bl_ai_boot().
 * The registry boots every tool and gives each a place in the menu, so new
 * tools can be bolted in without touching the existing ones.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class BL_AI_Tool {

	/** Unique, kebab-case identifier, e.g. "entity-maps". */
	abstract public function id();

	/** Human-readable name, shown in the menu and on the dashboard card. */
	abstract public function label();

	/** One-line summary for the dashboard card. */
	public function description() {
		return '';
	}

	/** Dashicon class for the dashboard card, e.g. "dashicons-networking". */
	public function icon() {
		return 'dashicons-admin-generic';
	}

	/**
	 * Admin slug of this tool's primary screen — where the dashboard card links.
	 * Defaults to the tool id; override if the screen uses a different page slug
	 * or a CPT list (return e.g. "edit.php?post_type=bl_entity").
	 */
	public function menu_slug() {
		return $this->id();
	}

	/** Whether this tool exposes an admin screen to link to. */
	public function has_admin() {
		return true;
	}

	/**
	 * Wire up runtime hooks: front-end output, save handlers, settings, etc.
	 * Called once on plugins_loaded. Default: nothing.
	 */
	public function register() {}

	/**
	 * Add this tool's submenu page(s) under the BL AI Tools menu. Called on
	 * admin_menu, after the top-level menu exists. Default: nothing.
	 *
	 * @param string $parent_slug The BL AI Tools top-level menu slug.
	 */
	public function register_admin( $parent_slug ) {}

	/**
	 * Work to run on plugin activation (register CPTs, rewrites, seed options).
	 * Default: nothing. Rewrite flushing is handled centrally by the plugin.
	 */
	public function activate() {}
}
