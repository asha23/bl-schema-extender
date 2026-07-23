<?php
/**
 * Uninstall routine for BrightLocal - AI Tools.
 *
 * Runs when the plugin is deleted from wp-admin (not on deactivation). Removes
 * everything the plugin persists: options, transients, the bl_entity CPT posts
 * (and their meta), and the static entitymap.json / entitymap.html files.
 *
 * Rewrite rules are already flushed by the deactivation hook, which WordPress
 * fires before deletion, so they need no handling here.
 *
 * Note: the plugin's classes are NOT loaded during uninstall, so any
 * `bl_entitymap_path` / `bl_entitymap_html_path` filters a site added won't
 * apply. We mirror the generator's default paths (and also try ABSPATH for
 * non-Bedrock layouts) so the files are cleaned up either way.
 */

// Bail unless invoked by WordPress' uninstall mechanism.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data for the current site.
 */
function bl_ai_uninstall_cleanup() {
	global $wpdb;

	// 1. Options (all persisted bl_em_* keys). Yoast's wpseo_titles is only
	//    ever read, never written, so it is intentionally left untouched.
	$options = array(
		'bl_em_publisher_name',
		'bl_em_publisher_url',
		'bl_em_publisher_sameas',
		'bl_em_base_url',
		'bl_em_version',
		'bl_em_schema_url',
		'bl_em_verification',
		'bl_em_profile',
		'bl_em_enable_json',
		'bl_em_enable_llms',
		'bl_em_enable_schema',
		'bl_em_enable_org',
		'bl_em_enable_perpage',
		'bl_em_static_ok',
		'bl_em_llms_ok',
		'bl_em_changed_gmt',
		'bl_em_backup_keep',
		'bl_em_entity_seq',
	);

	// Note: llms.txt is intentionally NOT deleted here — it is largely
	// hand-authored, and the plugin only ever owned a marked block within it.
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 2. Transients — the store + generator caches, and per-user verify reports.
	delete_transient( 'bl_entitymap_entities_v2' );
	delete_transient( 'bl_entitymap_json_v2' );

	// Per-user bl_em_verify_<id> transients: remove any still stored in the DB.
	// (With an external object cache these live outside the DB but expire in
	// minutes; the SQL below covers the common no-persistent-cache case.)
	$like = $wpdb->esc_like( '_transient_bl_em_verify_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	$like = $wpdb->esc_like( '_transient_timeout_bl_em_verify_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

	// 3. Entity CPT posts. force_delete removes each post's _bl_entity_* meta too.
	$entities = get_posts( array(
		'post_type'      => 'bl_entity',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'suppress_filters' => true,
	) );
	foreach ( $entities as $entity_id ) {
		wp_delete_post( $entity_id, true );
	}

	// 4. Static files in the webroot (default generator paths).
	$candidates = array(
		trailingslashit( dirname( ABSPATH ) ) . 'entitymap.json',
		trailingslashit( dirname( ABSPATH ) ) . 'entitymap.html',
		trailingslashit( ABSPATH ) . 'entitymap.json',
		trailingslashit( ABSPATH ) . 'entitymap.html',
	);
	foreach ( $candidates as $file ) {
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilentErrors.Discouraged
		}
	}

	// 5. Private backups directory in uploads (entitymap-*.json snapshots).
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['basedir'] ) ) {
		$backups = trailingslashit( $uploads['basedir'] ) . 'bl-ai-tools/entitymap-backups';
		if ( is_dir( $backups ) ) {
			foreach ( (array) glob( trailingslashit( $backups ) . '*' ) as $f ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilentErrors.Discouraged
			}
			@rmdir( $backups );                                    // phpcs:ignore WordPress.PHP.NoSilentErrors.Discouraged
			@rmdir( trailingslashit( $uploads['basedir'] ) . 'bl-ai-tools' ); // phpcs:ignore WordPress.PHP.NoSilentErrors.Discouraged
		}
	}
}

// Run cleanup on every site when network-active, otherwise just the current site.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		bl_ai_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	bl_ai_uninstall_cleanup();
}
