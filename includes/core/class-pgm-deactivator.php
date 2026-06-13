<?php
declare(strict_types=1);

/**
 * Plugin deactivation handler.
 *
 * Clears the scheduled WP-Cron event so probes stop running when the plugin
 * is deactivated. Does NOT purge data — that is uninstall's job.
 *
 * Who triggers: register_deactivation_hook() in peptide-geo-monitor.php.
 * Dependencies: PGM_Cron.
 *
 * @see class-pgm-cron.php      — Cron registration + clearing.
 * @see uninstall.php           — Full data purge on uninstall.
 * @package PeptideGeoMonitor
 */
class PGM_Deactivator {

	/**
	 * Clear the weekly cron schedule.
	 *
	 * Side effects: Removes the pgm_weekly_probe event from WP-Cron.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		PGM_Cron::clear_schedule();
	}
}
