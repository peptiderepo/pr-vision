<?php
declare(strict_types=1);

/**
 * Seam: contract for all PR Vision dashboard panels.
 *
 * Each panel renders one data category within the PR Vision admin page.
 * v1 ships only PRV_Ai_Visibility_Panel. Future panels (keyword rankings,
 * schema coverage…) implement this and register with PRV_Collector_Registry.
 *
 * Who triggers: PRV_Admin_Page when building the PR Vision page.
 * Dependencies: None — pure interface.
 *
 * @see class-prv-ai-visibility-panel.php  — v1 implementation.
 * @see class-prv-collector-registry.php   — Registration hub.
 * @see ARCHITECTURE.md                    — §Collector/Panel seam.
 * @package PrVision
 */
interface PRV_Dashboard_Panel {

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
