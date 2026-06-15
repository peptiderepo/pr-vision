<?php
/**
 * Main orchestrator for the PR Vision plugin.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Main orchestrator for the PR Vision plugin.
 *
 * Boots sub-systems in the correct order, runs pending data migrations,
 * hooks into WP, and keeps the top-level file under the 300-line limit by
 * delegating all logic to specialist classes.
 *
 * Who triggers: plugins_loaded action (pr-vision.php).
 * Dependencies: PRV_Upgrader, PRV_Cron, PRV_Admin_Page, PRV_Settings_Page,
 *               PRV_Settings_Controller, PRV_Costs_Page, PRV_Call_Log_Page,
 *               PRV_Call_Detail_Ajax, PRV_Prune_Cron, PRV_Cron_Guard,
 *               PRV_Collector_Registry.
 *
 * @see ARCHITECTURE.md             -- Boot sequence diagram.
 * @see class-prv-upgrader.php      -- Runs migrations on every boot.
 * @see class-prv-costs-page.php    -- [v0.3.0] Costs admin sub-page.
 * @see class-prv-call-log-page.php -- [v0.3.0] Call Log admin sub-page.
 * @see class-prv-prune-cron.php    -- [v0.3.0] Daily prune cron.
 * @see class-prv-cron-guard.php    -- [v0.3.1] Self-healing cron guard.
 * @package PrVision
 */
class PRV_Plugin {

	/**
	 * Wire up all WordPress hooks.
	 *
	 * Migrations run before anything reads from prv_models so the v2 format
	 * is always in place regardless of whether the plugin was freshly activated
	 * or just upgraded from v0.1.x while live.
	 *
	 * Side effects: Registers admin menus, enqueues assets, registers the
	 *               collector/panel registry, runs data migrations.
	 *
	 * @return void
	 */
	public function init(): void {
		// Migrations first -- must run before any config read.
		PRV_Upgrader::run();

		$cron = new PRV_Cron();
		$cron->register_hooks();

		$prune = new PRV_Prune_Cron();
		$prune->register_hooks();

		$guard = new PRV_Cron_Guard();
		$guard->register_hooks();

		if ( is_admin() ) {
			$page = new PRV_Admin_Page();
			$page->register_hooks();

			$settings = new PRV_Settings_Page();
			$settings->register_hooks();

			$costs = new PRV_Costs_Page();
			$costs->register_hooks();

			$call_log = new PRV_Call_Log_Page();
			$call_log->register_hooks();

			$ajax = new PRV_Call_Detail_Ajax();
			$ajax->register_hooks();
		}

		// Register the v1 AI-visibility collector + panel.
		$registry = PRV_Collector_Registry::instance();
		$registry->register_collector( new PRV_Ai_Visibility_Collector() );
		$registry->register_panel( 'ai_visibility', new PRV_Ai_Visibility_Panel() );
	}
}
