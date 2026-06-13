<?php
declare(strict_types=1);

/**
 * Plugin activation handler.
 *
 * Creates the custom database table via dbDelta and registers the weekly
 * WP-Cron probe schedule. Safe to call on re-activation — dbDelta is
 * idempotent and the cron schedule is only added when absent.
 *
 * Who triggers: register_activation_hook() in peptide-geo-monitor.php.
 * Dependencies: PGM_Table_Manager, PGM_Cron, PGM_Config.
 *
 * @see class-pgm-table-manager.php — Database schema creation.
 * @see class-pgm-cron.php          — Cron scheduling.
 * @package PeptideGeoMonitor
 */
class PGM_Activator {

	/**
	 * Run all activation tasks.
	 *
	 * Side effects: Creates DB table, writes default options, schedules cron.
	 *
	 * @return void
	 */
	public static function activate(): void {
		PGM_Table_Manager::create_table();
		PGM_Config::seed_defaults();
		PGM_Cron::schedule_weekly();
	}
}
