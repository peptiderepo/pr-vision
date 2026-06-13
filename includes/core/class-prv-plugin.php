<?php
declare(strict_types=1);

/**
 * Main orchestrator for the PR Vision plugin.
 *
 * Boots sub-systems in the correct order, hooks into WP, and keeps
 * the top-level file under the 300-line limit by delegating all logic
 * to specialist classes.
 *
 * Who triggers: plugins_loaded action (pr-vision.php).
 * Dependencies: PRV_Cron, PRV_Admin_Page, PRV_Collector_Registry.
 *
 * @see ARCHITECTURE.md — Boot sequence diagram.
 * @package PrVision
 */
class PRV_Plugin {

	/**
	 * Wire up all WordPress hooks.
	 *
	 * Side effects: Registers admin menus, enqueues assets, and registers the
	 *               collector/panel registry.
	 *
	 * @return void
	 */
	public function init(): void {
		$cron = new PRV_Cron();
		$cron->register_hooks();

		if ( is_admin() ) {
			$page = new PRV_Admin_Page();
			$page->register_hooks();
		}

		// Register the v1 AI-visibility collector + panel.
		$registry = PRV_Collector_Registry::instance();
		$registry->register_collector( new PRV_Ai_Visibility_Collector() );
		$registry->register_panel( 'ai_visibility', new PRV_Ai_Visibility_Panel() );
	}
}
