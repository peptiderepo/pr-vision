<?php
/**
 * Database schema for per-call cost/metadata table (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Manages the prv_call_meta table: per-call cost + metadata kept indefinitely.
 *
 * Two tables separate concerns introduced in v0.3.0:
 *   prv_call_meta  — cost + metadata KEPT (this class).
 *   prv_call_io    — rendered prompt + raw response PRUNED (PRV_Call_Io_Table).
 *
 * Schema is forward-only: dbDelta adds columns; no destructive ALTER.
 * SCHEMA_VERSION bumped to 3 to trigger the migration guard in PRV_Upgrader.
 *
 * Who triggers: PRV_Upgrader::run() on every plugins_loaded (idempotent).
 * Dependencies: $wpdb, dbDelta(), PRV_Table_Manager (existing table retained).
 *
 * @see class-prv-call-io-table.php   — Companion prunable I/O table.
 * @see class-prv-upgrader.php        — Calls create_table() on upgrade.
 * @see class-prv-capture-writer.php  — Writes rows to this table.
 * @see ARCHITECTURE.md               — §Storage v0.3.0.
 * @package PrVision
 */
class PRV_Call_Meta_Table {

	/**
	 * Table base name (without $wpdb->prefix).
	 */
	const TABLE_BASE = 'prv_call_meta';

	/**
	 * Schema version introduced by this table.
	 */
	const SCHEMA_VERSION = 3;

	/**
	 * Create or upgrade the call-meta table via dbDelta.
	 *
	 * Safe to call on every plugins_loaded — dbDelta is idempotent.
	 * Side effects: Database write; may update prv_schema_version option.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE_BASE;
		$vis_table       = $wpdb->prefix . 'prv_ai_visibility';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			visibility_row BIGINT(20) UNSIGNED NULL     DEFAULT NULL,
			run_id         VARCHAR(36)         NOT NULL DEFAULT '',
			peptide_slug   VARCHAR(200)        NOT NULL DEFAULT '',
			model          VARCHAR(200)        NOT NULL DEFAULT '',
			intent_label   VARCHAR(200)        NOT NULL DEFAULT '',
			tokens_in      INT(11)             NULL     DEFAULT NULL,
			tokens_out     INT(11)             NULL     DEFAULT NULL,
			cost_usd       DECIMAL(12,8)       NOT NULL DEFAULT 0.00000000,
			latency_ms     INT(11)             NULL     DEFAULT NULL,
			cited          TINYINT(1)          NULL     DEFAULT NULL,
			http_status    INT(11)             NOT NULL DEFAULT 200,
			captured_at    DATETIME            NOT NULL,
			config_version INT(11)             NULL     DEFAULT NULL,
			io_captured    TINYINT(1)          NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY visibility_row (visibility_row),
			KEY run_id (run_id),
			KEY peptide_slug (peptide_slug),
			KEY model (model),
			KEY captured_at (captured_at)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );

		update_option( 'prv_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * Get the full table name including WP prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Drop the table. Called from uninstall.php only.
	 *
	 * Side effects: Permanently destroys all call metadata.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
