<?php
/**
 * Self-healing cron guard: re-schedules managed cron events when missing.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Idempotent guard that ensures all managed WP-Cron events are scheduled.
 *
 * WordPress fires the plugin activator only on activation, not on rsync
 * file-sync updates (our deploy method). This guard runs on `init` and
 * re-schedules any missing cron event by delegating to the canonical
 * scheduler method for that hook -- so recurrence and first-run offset are
 * always consistent with the activator.
 *
 * Managed hooks:
 *  - `prv_weekly_probe`  -- scheduled via PRV_Cron::schedule()
 *  - `prv_daily_prune`   -- scheduled via PRV_Prune_Cron::schedule()
 *
 * Hot-path cost: two `wp_next_scheduled()` checks per request. No DB
 * writes unless an event is actually missing.
 *
 * Who triggers: init action (registered in PRV_Plugin::init()).
 * Dependencies: PRV_Cron, PRV_Prune_Cron.
 *
 * @see class-prv-cron.php       -- Canonical weekly probe scheduler.
 * @see class-prv-prune-cron.php -- Canonical daily prune scheduler.
 * @see class-prv-activator.php  -- Original schedule call on activation.
 * @package PrVision
 */
class PRV_Cron_Guard {

	/**
	 * Register the guard on the `init` hook.
	 *
	 * Side effects: Adds action for `init`.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Ensure every managed cron hook is scheduled.
	 *
	 * For each managed hook, checks `wp_next_scheduled()`. If the event is
	 * missing, delegates to the canonical scheduler (same method the
	 * activator calls) so recurrence and offset are never duplicated here.
	 * Calling this when all events are already scheduled is a pure no-op.
	 *
	 * Side effects: May call PRV_Cron::schedule() or
	 *               PRV_Prune_Cron::schedule() when an event is missing.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( PRV_CRON_HOOK ) ) {
			PRV_Cron::schedule();
		}

		if ( ! wp_next_scheduled( PRV_Prune_Cron::PRUNE_HOOK ) ) {
			PRV_Prune_Cron::schedule();
		}
	}
}
