<?php
declare(strict_types=1);

/**
 * Main orchestrator for the Peptide GEO Monitor plugin.
 *
 * Boots sub-systems in the correct order, hooks into WP, and keeps
 * the top-level file under the 300-line limit by delegating all logic
 * to specialist classes.
 *
 * Who triggers: plugins_loaded action (peptide-geo-monitor.php).
 * Dependencies: PGM_Cron, PGM_Admin_Page, PGM_Collector_Registry.
 *
 * @see ARCHITECTURE.md — Boot sequence diagram.
 * @package PeptideGeoMonitor
 */
class PGM_Plugin {

	/**
	 * Wire up all WordPress hooks.
	 *
	 * Side effects: Registers admin menus, enqueues assets, and registers the
	 *               collector/panel registry.
	 *
	 * @return void
	 */
	public function init(): void {
		$cron = new PGM_Cron();
		$cron->register_hooks();

		if ( is_admin() ) {
			$page = new PGM_Admin_Page();
			$page->register_hooks();
		}

		// Register the v1 AI-visibility collector + panel.
		$registry = PGM_Collector_Registry::instance();
		$registry->register_collector( new PGM_Ai_Visibility_Collector() );
		$registry->register_panel( 'ai_visibility', new PGM_Ai_Visibility_Panel() );
	}
}
