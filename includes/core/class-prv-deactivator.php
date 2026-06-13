<?php
declare(strict_types=1);

/**
 * Plugin deactivation handler.
 *
 * Clears the scheduled WP-Cron event so probes stop running when the plugin
 * is deactivated. Does NOT purge data — that is uninstall's job.
 *
 * Who triggers: register_deactivation_hook() in pr-vision.php.
 * Dependencies: PRV_Cron.
 *
 * @see class-prv-cron.php      — Cron registration + clearing.
 * @see uninstall.php           — Full data purge on uninstall.
 * @package PrVision
 */
class PRV_Deactivator {

	/**
	 * Clear the weekly cron schedule.
	 *
	 * Side effects: Removes the prv_weekly_probe event from WP-Cron.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		PRV_Cron::clear_schedule();
	}
}
