<?php
declare(strict_types=1);

/**
 * Seam: contract for all GEO-monitor data collectors.
 *
 * v1 implements only PGM_Ai_Visibility_Collector. Future collectors
 * (keyword rankings, schema coverage, technical-SEO) implement this
 * interface and register themselves with PGM_Collector_Registry without
 * changing the dashboard shell.
 *
 * Who triggers: PGM_Collector_Registry dispatches registered collectors.
 * Dependencies: None — pure interface.
 *
 * @see class-pgm-ai-visibility-collector.php — v1 implementation.
 * @see class-pgm-collector-registry.php      — Registration hub.
 * @see ARCHITECTURE.md                       — §Collector/Panel seam.
 * @package PeptideGeoMonitor
 */
interface PGM_Data_Collector {

	/**
	 * Collect and return the data payload for this category.
	 *
	 * The return shape is collector-specific; the dashboard panel for this
	 * category receives the same array and is responsible for rendering it.
	 *
	 * @return array<string, mixed> Collected data.
	 */
	public function collect(): array;

	/**
	 * Short machine key identifying this collector (e.g. "ai_visibility").
	 *
	 * Used as the registry key and as the panel lookup key.
	 *
	 * @return string
	 */
	public function get_key(): string;
}
