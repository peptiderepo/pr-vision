<?php
declare(strict_types=1);

/**
 * AI-visibility dashboard panel — renders the GEO Monitor admin page.
 *
 * Renders four sections:
 * 1. Proxy-metric disclaimer note.
 * 2. Chart.js trendline of visibility score across runs.
 * 3. Per-peptide standings table (cited, position, top competitor domains).
 * 4. MTD cost vs cap + last-run time.
 *
 * All output is escaped. Chart.js is enqueued via PGM_Admin_Page.
 *
 * Who triggers: PGM_Admin_Page::render_page() via PGM_Collector_Registry.
 * Dependencies: PGM_Ai_Visibility_Collector (data format contract).
 *
 * @see interface-pgm-dashboard-panel.php      — Interface this implements.
 * @see class-pgm-ai-visibility-collector.php  — Produces the $data array.
 * @see class-pgm-admin-page.php               — Enqueues Chart.js; calls render().
 * @package PeptideGeoMonitor
 */
class PGM_Ai_Visibility_Panel implements PGM_Dashboard_Panel {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $data Payload from PGM_Ai_Visibility_Collector.
	 *
	 * @return void
	 */
	public function render( array $data ): void {
		$trendline       = is_array( $data['trendline'] ?? null ) ? $data['trendline'] : array();
		$standings       = is_array( $data['standings'] ?? null ) ? $data['standings'] : array();
		$last_run_at     = isset( $data['last_run_at'] ) && $data['last_run_at'] ? esc_html( (string) $data['last_run_at'] ) : esc_html__( 'Never', 'peptide-geo-monitor' );
		$mtd_cost        = isset( $data['mtd_cost_usd'] ) ? (float) $data['mtd_cost_usd'] : 0.0;
		$cap             = isset( $data['monthly_cap_usd'] ) ? (float) $data['monthly_cap_usd'] : PGM_DEFAULT_MONTHLY_BUDGET_USD;

		$this->render_proxy_note();
		$this->render_meta_bar( $last_run_at, $mtd_cost, $cap );
		$this->render_trendline( $trendline );
		$this->render_standings( $standings );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'AI Visibility', 'peptide-geo-monitor' );
	}

	/**
	 * Render the proxy-metric disclaimer note.
	 *
	 * @return void
	 */
	private function render_proxy_note(): void {
		echo '<div class="notice notice-info pgm-proxy-note"><p>';
		echo '<strong>' . esc_html__( 'Note:', 'peptide-geo-monitor' ) . '</strong> ';
		echo esc_html__( 'Scores are measured via API probes — a directional proxy, not the consumer ChatGPT/Gemini apps (different retrieval, personalisation, and system prompts).', 'peptide-geo-monitor' );
		echo '</p></div>';
	}

	/**
	 * Render the run metadata bar (last run, cost, cap).
	 *
	 * @param string $last_run_at Formatted last-run timestamp or "Never".
	 * @param float  $mtd_cost    Month-to-date spend in USD.
	 * @param float  $cap         Monthly cap in USD.
	 *
	 * @return void
	 */
	private function render_meta_bar( string $last_run_at, float $mtd_cost, float $cap ): void {
		$pct = $cap > 0 ? min( 100.0, round( $mtd_cost / $cap * 100, 1 ) ) : 0;
		echo '<div class="pgm-meta-bar">';
		echo '<span class="pgm-meta-item"><strong>' . esc_html__( 'Last run:', 'peptide-geo-monitor' ) . '</strong> ' . esc_html( $last_run_at ) . '</span>';
		echo '<span class="pgm-meta-item"><strong>' . esc_html__( 'MTD cost:', 'peptide-geo-monitor' ) . '</strong> $' . esc_html( number_format( $mtd_cost, 4 ) ) . ' / $' . esc_html( number_format( $cap, 2 ) ) . ' (' . esc_html( $pct ) . '% of cap)</span>';
		echo '</div>';
	}

	/**
	 * Render the Chart.js visibility score trendline.
	 *
	 * @param array<int, array{run_id: string, captured_at: string, score: float}> $trendline
	 *
	 * @return void
	 */
	private function render_trendline( array $trendline ): void {
		echo '<div class="pgm-card"><h2>' . esc_html__( 'Visibility Score — Trend', 'peptide-geo-monitor' ) . '</h2>';

		if ( empty( $trendline ) ) {
			echo '<p>' . esc_html__( 'No probe runs recorded yet. Click "Run now" to collect the first data point.', 'peptide-geo-monitor' ) . '</p></div>';
			return;
		}

		$labels = array();
		$scores = array();
		foreach ( $trendline as $point ) {
			$labels[] = esc_js( date( 'M j', strtotime( $point['captured_at'] ) ) );
			$scores[] = (float) $point['score'];
		}

		$labels_json = wp_json_encode( $labels );
		$scores_json = wp_json_encode( $scores );

		echo '<canvas id="pgm-trendline-chart" height="120"></canvas>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){';
		echo 'var ctx=document.getElementById("pgm-trendline-chart").getContext("2d");';
		echo 'new Chart(ctx,{type:"line",data:{labels:' . $labels_json . ',datasets:[{label:"Visibility Score",data:' . $scores_json . ',borderColor:"#2271b1",tension:0.3,fill:false}]},options:{scales:{y:{min:0,max:1,title:{display:true,text:"Score"}}},plugins:{legend:{display:false}}}});';
		echo '});</script>';
		echo '</div>';
	}

	/**
	 * Render the per-peptide current standings table.
	 *
	 * @param array<string, array{label: string, cited: bool, our_position: int|null, top_domains: string[], model_count: int}> $standings
	 *
	 * @return void
	 */
	private function render_standings( array $standings ): void {
		echo '<div class="pgm-card"><h2>' . esc_html__( 'Current Standings', 'peptide-geo-monitor' ) . '</h2>';

		if ( empty( $standings ) ) {
			echo '<p>' . esc_html__( 'No standings data yet.', 'peptide-geo-monitor' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped pgm-standings">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Peptide', 'peptide-geo-monitor' ) . '</th>';
		echo '<th>' . esc_html__( 'Cited?', 'peptide-geo-monitor' ) . '</th>';
		echo '<th>' . esc_html__( 'Our Position', 'peptide-geo-monitor' ) . '</th>';
		echo '<th>' . esc_html__( 'Top Competing Domains', 'peptide-geo-monitor' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $standings as $row ) {
			$cited_icon = $row['cited'] ? '&#x2705;' : '&#x274C;';
			$position   = null !== $row['our_position'] ? '#' . (int) $row['our_position'] : '—';
			$domains    = implode( ', ', array_map( 'esc_html', (array) $row['top_domains'] ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . $cited_icon . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- only emoji
			echo '<td>' . esc_html( $position ) . '</td>';
			echo '<td>' . wp_kses_post( $domains ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
