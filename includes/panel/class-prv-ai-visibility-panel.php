<?php
/**
 * AI-visibility dashboard panel renderer.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * AI-visibility dashboard panel — orchestrates the PR Vision admin page.
 *
 * Renders five sections by delegating the visual heavy-lifting to helper classes:
 * 1. Proxy-metric disclaimer note.
 * 2. KPI bento bar (score tile with run-health pill, cost tile, last-run tile)
 *    — via PRV_Dashboard_Renderer::render_bento().
 * 3. Chart.js trendline with config-change vertical markers
 *    — via PRV_Dashboard_Renderer::render_trendline().
 * 4. Per-peptide standings table.
 *
 * The run-health pill (score tile) and config-change markers (trendline) are
 * the two v0.2.1 additions.
 *
 * Chart.js is enqueued via PRV_Admin_Page. The chart sits in a fixed-height box
 * and degrades gracefully: if Chart.js is unavailable the typeof guard adds
 * .prv-noscript, hiding the canvas and showing a fallback note in the same space.
 *
 * Who triggers: PRV_Admin_Page::render_page() via PRV_Collector_Registry.
 * Dependencies: PRV_Ai_Visibility_Collector (data format), PRV_Dashboard_Renderer.
 *
 * @see interface-prv-dashboard-panel.php      — Interface this implements.
 * @see class-prv-ai-visibility-collector.php  — Produces the $data array.
 * @see class-prv-dashboard-renderer.php       — Renders bento + trendline.
 * @see class-prv-admin-page.php               — Enqueues Chart.js; calls render().
 * @package PrVision
 */
class PRV_Ai_Visibility_Panel implements PRV_Dashboard_Panel {

	/**
	 * Visual renderer for the bento tiles and trendline.
	 *
	 * @var PRV_Dashboard_Renderer
	 */
	private PRV_Dashboard_Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param PRV_Dashboard_Renderer|null $renderer Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PRV_Dashboard_Renderer $renderer = null ) {
		$this->renderer = $renderer ?? new PRV_Dashboard_Renderer();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $data Payload from PRV_Ai_Visibility_Collector.
	 *
	 * @return void
	 */
	public function render( array $data ): void {
		$trendline       = is_array( $data['trendline'] ?? null ) ? $data['trendline'] : array();
		$standings       = is_array( $data['standings'] ?? null ) ? $data['standings'] : array();
		$last_run_at     = isset( $data['last_run_at'] ) && $data['last_run_at'] ? esc_html( (string) $data['last_run_at'] ) : esc_html__( 'Never', 'pr-vision' );
		$mtd_cost        = isset( $data['mtd_cost_usd'] ) ? (float) $data['mtd_cost_usd'] : 0.0;
		$cap             = isset( $data['monthly_cap_usd'] ) ? (float) $data['monthly_cap_usd'] : PRV_DEFAULT_MONTHLY_BUDGET_USD;
		$last_run_counts = is_array( $data['last_run_counts'] ?? null ) ? $data['last_run_counts'] : array();

		$this->render_proxy_note();
		$this->render_bento_band( $trendline, $standings, $last_run_at, $mtd_cost, $cap, $last_run_counts );
		$this->renderer->render_trendline( $trendline );
		$this->render_standings( $standings );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'AI Visibility', 'pr-vision' );
	}

	/**
	 * Render the proxy-metric disclaimer note.
	 *
	 * @return void
	 */
	private function render_proxy_note(): void {
		echo '<div class="notice notice-info prv-proxy-note"><p>';
		echo '<strong>' . esc_html__( 'Note:', 'pr-vision' ) . '</strong> ';
		echo esc_html__( 'Scores are measured via API probes — a directional proxy, not the consumer ChatGPT/Gemini apps (different retrieval, personalisation, and system prompts).', 'pr-vision' );
		echo '</p></div>';
	}

	/**
	 * Prepare bento-band data and delegate rendering to PRV_Dashboard_Renderer.
	 *
	 * @param array  $trendline       Trendline data points from PRV_Ai_Visibility_Collector.
	 * @param array  $standings       Per-peptide standings map.
	 * @param string $last_run_at     Formatted run timestamp or "Never".
	 * @param float  $mtd_cost        Month-to-date spend in USD.
	 * @param float  $cap             Monthly budget cap in USD.
	 * @param array  $last_run_counts Per-model health map (slug → {health_status}).
	 *
	 * @return void
	 */
	private function render_bento_band(
		array $trendline,
		array $standings,
		string $last_run_at,
		float $mtd_cost,
		float $cap,
		array $last_run_counts
	): void {
		$current_score = empty( $trendline ) ? 0.0 : (float) end( $trendline )['score'];
		$prev_score    = count( $trendline ) >= 2 ? (float) $trendline[ count( $trendline ) - 2 ]['score'] : null;
		$cited_count   = 0;
		$total_count   = count( $standings );

		foreach ( $standings as $row ) {
			if ( ! empty( $row['cited'] ) ) {
				++$cited_count;
			}
		}

		$health    = $this->derive_health_pill_state( $last_run_counts );
		$pct       = $cap > 0 ? min( 100.0, round( $mtd_cost / $cap * 100, 1 ) ) : 0;
		$truncated = (bool) get_option( 'prv_last_run_truncated', false );

		$this->renderer->render_bento(
			$current_score,
			$prev_score,
			$cited_count,
			$total_count,
			$health,
			$mtd_cost,
			$cap,
			$pct,
			$truncated,
			$last_run_at,
			$total_count
		);
	}

	/**
	 * Render the per-peptide current standings table.
	 *
	 * @param array<string, array{label: string, cited: bool, our_position: int|null, top_domains: string[], model_count: int}> $standings Per-peptide standing data.
	 *
	 * @return void
	 */
	private function render_standings( array $standings ): void {
		echo '<div class="prv-card"><div class="prv-card-head"><h2>' . esc_html__( 'Current Standings', 'pr-vision' ) . '</h2></div><div class="prv-card-body">';

		if ( empty( $standings ) ) {
			echo '<p>' . esc_html__( 'No standings data yet.', 'pr-vision' ) . '</p></div></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped prv-standings"><thead><tr>';
		echo '<th>' . esc_html__( 'Peptide', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Cited?', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Our Position', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Top Competing Domains', 'pr-vision' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $standings as $row ) {
			$is_cited    = ! empty( $row['cited'] );
			$cited_dot   = $is_cited ? '&#x25CF;' : '&#x25CB;';
			$cited_label = $is_cited ? esc_html__( 'Cited', 'pr-vision' ) : esc_html__( 'Not yet', 'pr-vision' );
			$cited_class = $is_cited ? 'prv-status prv-status--cited' : 'prv-status prv-status--not-yet';
			$position    = null !== $row['our_position'] ? '#' . (int) $row['our_position'] : '—';
			$domains     = implode( ', ', array_map( 'esc_html', (array) $row['top_domains'] ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dot is a fixed HTML entity; label is escaped by esc_html__() above; class is esc_attr'd
			echo '<td><span class="' . esc_attr( $cited_class ) . '">' . $cited_dot . ' ' . $cited_label . '</span></td>';
			echo '<td>' . esc_html( $position ) . '</td>';
			echo '<td>' . wp_kses_post( $domains ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Derive the run-health pill state and label from per-model health data.
	 *
	 * States: 'ok' (all healthy/disabled), 'warn' (any retired), 'neutral' (no data yet).
	 *
	 * @param array<string, array{health_status: string}> $last_run_counts Per-model health map.
	 *
	 * @return array{state: string, label: string}
	 */
	private function derive_health_pill_state( array $last_run_counts ): array {
		if ( empty( $last_run_counts ) ) {
			return array(
				'state' => 'neutral',
				'label' => __( 'No run yet', 'pr-vision' ),
			);
		}

		$retired_count = 0;
		$healthy_count = 0;
		foreach ( $last_run_counts as $model ) {
			$status = (string) ( $model['health_status'] ?? 'unknown' );
			if ( 'retired' === $status ) {
				++$retired_count;
			} elseif ( 'healthy' === $status ) {
				++$healthy_count;
			}
		}

		if ( $retired_count > 0 ) {
			return array(
				'state' => 'warn',
				'label' => sprintf(
					/* translators: %d: number of degraded models */
					_n( '%d model degraded', '%d models degraded', $retired_count, 'pr-vision' ),
					$retired_count
				),
			);
		}

		if ( 0 === $healthy_count ) {
			return array(
				'state' => 'neutral',
				'label' => __( 'No run yet', 'pr-vision' ),
			);
		}

		return array(
			'state' => 'ok',
			'label' => __( 'All models healthy', 'pr-vision' ),
		);
	}
}
