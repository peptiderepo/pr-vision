<?php
/**
 * PR Vision — full data teardown on uninstall.
 *
 * Drops the custom prv_ai_visibility table and deletes every wp_options
 * row whose name starts with "prv_". WP-Cron events are already cleared
 * by PRV_Deactivator on deactivation; this runs after that.
 *
 * @see ARCHITECTURE.md — §Uninstall specification.
 * @see class-prv-deactivator.php — Clears cron on deactivation.
 * @package PrVision
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* ── 1. Drop the custom table ─────────────────────────────────────── */

$table = $wpdb->prefix . 'prv_ai_visibility';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

/* ── 2. Delete all prv_ prefixed options ──────────────────────────── */

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'prv\_%'"
);

/* ── 3. Clear any remaining scheduled cron events ─────────────────── */

wp_clear_scheduled_hook( 'prv_weekly_probe' );
