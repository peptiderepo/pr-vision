<?php
declare(strict_types=1);

/**
 * Registry: holds all registered collectors and panels.
 *
 * PRV_Plugin::init() registers the v1 AI-visibility collector + panel.
 * Future collectors/panels call register_collector()/register_panel()
 * without touching the dashboard shell.
 *
 * Who triggers: PRV_Plugin::init() on plugins_loaded.
 * Dependencies: PRV_Data_Collector, PRV_Dashboard_Panel interfaces.
 *
 * @see interface-prv-data-collector.php  — Collector contract.
 * @see interface-prv-dashboard-panel.php — Panel contract.
 * @see class-prv-plugin.php              — Calls register_* on boot.
 * @see ARCHITECTURE.md                   — §Collector/Panel seam.
 * @package PrVision
 */
class PRV_Collector_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var PRV_Collector_Registry|null
	 */
	private static ?PRV_Collector_Registry $instance = null;

	/**
	 * Registered collectors keyed by their collector key.
	 *
	 * @var array<string, PRV_Data_Collector>
	 */
	private array $collectors = array();

	/**
	 * Registered panels keyed by their panel key (matches collector key).
	 *
	 * @var array<string, PRV_Dashboard_Panel>
	 */
	private array $panels = array();

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return static
	 */
	public static function instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Register a data collector.
	 *
	 * @param PRV_Data_Collector $collector
	 *
	 * @return void
	 */
	public function register_collector( PRV_Data_Collector $collector ): void {
		$this->collectors[ $collector->get_key() ] = $collector;
	}

	/**
	 * Register a dashboard panel.
	 *
	 * @param string               $key   Panel key (must match a collector key).
	 * @param PRV_Dashboard_Panel  $panel
	 *
	 * @return void
	 */
	public function register_panel( string $key, PRV_Dashboard_Panel $panel ): void {
		$this->panels[ $key ] = $panel;
	}

	/**
	 * Get all registered collectors.
	 *
	 * @return array<string, PRV_Data_Collector>
	 */
	public function get_collectors(): array {
		return $this->collectors;
	}

	/**
	 * Get the panel for a given key (null when not registered).
	 *
	 * @param string $key Panel key.
	 *
	 * @return PRV_Dashboard_Panel|null
	 */
	public function get_panel( string $key ): ?PRV_Dashboard_Panel {
		return $this->panels[ $key ] ?? null;
	}

	/**
	 * Reset the registry — test use only.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_for_testing(): void {
		static::$instance = null;
	}
}
