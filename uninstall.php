<?php
/**
 * Peptide GEO Monitor — full data teardown on uninstall.
 *
 * Drops the custom pgm_ai_visibility table and deletes every wp_options
 * row whose name starts with "pgm_". WP-Cron events are already cleared
 * by PGM_Deactivator on deactivation; this runs after that.
 *
 * @see ARCHITECTURE.md — §Uninstall specification.
 * @see class-pgm-deactivator.php — Clears cron on deactivation.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* ── 1. Drop the custom table ─────────────────────────────────────── */

$table = $wpdb->prefix . 'pgm_ai_visibility';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

/* ── 2. Delete all pgm_ prefixed options ──────────────────────────── */

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pgm\_%'"
);

/* ── 3. Clear any remaining scheduled cron events ─────────────────── */

wp_clear_scheduled_hook( 'pgm_weekly_probe' );
