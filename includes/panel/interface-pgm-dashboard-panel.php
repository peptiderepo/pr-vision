<?php
declare(strict_types=1);

/**
 * Seam: contract for all GEO-monitor dashboard panels.
 *
 * Each panel renders one data category within the GEO Monitor admin page.
 * v1 ships only PGM_Ai_Visibility_Panel. Future panels (keyword rankings,
 * schema coverage…) implement this and register with PGM_Collector_Registry.
 *
 * Who triggers: PGM_Admin_Page when building the GEO Monitor page.
 * Dependencies: None — pure interface.
 *
 * @see class-pgm-ai-visibility-panel.php  — v1 implementation.
 * @see class-pgm-collector-registry.php   — Registration hub.
 * @see ARCHITECTURE.md                    — §Collector/Panel seam.
 * @package PeptideGeoMonitor
 */
interface PGM_Dashboard_Panel {

	/**
	 * Render the panel HTML into the current output buffer.
	 *
	 * Implementors MUST escape all output and MUST NOT echo raw DB values.
	 *
	 * @param array<string, mixed> $data Payload returned by the matching collector.
	 *
	 * @return void
	 */
	public function render( array $data ): void;

	/**
	 * Short human-readable title for the panel tab / section heading.
	 *
	 * @return string
	 */
	public function get_title(): string;
}
