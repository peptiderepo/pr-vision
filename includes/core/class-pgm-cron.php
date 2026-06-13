<?php
declare(strict_types=1);

/**
 * WP-Cron management for the weekly AI-visibility probe.
 *
 * Registers the pgm_weekly_probe hook, schedules/clears the event, and
 * dispatches to PGM_Probe_Runner on each tick.
 *
 * Who triggers: PGM_Plugin::init() (hook registration), PGM_Activator
 *               (schedule), PGM_Deactivator (clear).
 * Dependencies: PGM_Probe_Runner.
 *
 * @see class-pgm-probe-runner.php — Runner invoked on each cron tick.
 * @see class-pgm-activator.php    — Calls schedule_weekly() on activation.
 * @package PeptideGeoMonitor
 */
class PGM_Cron {

	/**
	 * Register WordPress action hooks.
	 *
	 * Side effects: Adds action for PGM_CRON_HOOK.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( PGM_CRON_HOOK, array( $this, 'handle_cron_tick' ) );
	}

	/**
	 * Callback fired by WP-Cron on the weekly schedule.
	 *
	 * Side effects: Instantiates and runs PGM_Probe_Runner.
	 *
	 * @return void
	 */
	public function handle_cron_tick(): void {
		$runner = new PGM_Probe_Runner();
		$runner->run();
	}

	/**
	 * Schedule the weekly cron event if not already scheduled.
	 *
	 * Safe to call on re-activation — no-op when already scheduled.
	 *
	 * Side effects: Adds a WP-Cron event.
	 *
	 * @return void
	 */
	public static function schedule_weekly(): void {
		if ( ! wp_next_scheduled( PGM_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', PGM_CRON_HOOK );
		}
	}

	/**
	 * Remove the weekly cron schedule.
	 *
	 * Called on deactivation. Does not purge data.
	 *
	 * Side effects: Removes the WP-Cron event.
	 *
	 * @return void
	 */
	public static function clear_schedule(): void {
		$timestamp = wp_next_scheduled( PGM_CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, PGM_CRON_HOOK );
		}
	}

	/**
	 * Check whether the weekly event is currently scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled(): bool {
		return false !== wp_next_scheduled( PGM_CRON_HOOK );
	}
}
