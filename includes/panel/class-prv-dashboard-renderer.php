<?php
/**
 * Dashboard visual renderer: bento tiles + trendline chart for the AI-visibility panel.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the KPI bento bar and the Chart.js trendline for PRV_Ai_Visibility_Panel.
 *
 * Split from PRV_Ai_Visibility_Panel to keep each file under 300 lines.
 * Handles the v0.2.1 additions: run-health pill on the score tile, config-change
 * vertical dashed markers on the trendline, and the budget-truncation badge on
 * the cost tile.
 *
 * Who triggers: PRV_Ai_Visibility_Panel::render() — called directly.
 * Dependencies: none (all data passed as arguments).
 *
 * @see class-prv-ai-visibility-panel.php  — Orchestrator that calls this renderer.
 * @see class-prv-ai-visibility-collector.php — Produces the data consumed here.
 * @see design/pr-vision/design-proposal.md    — Palette + layout specification.
 * @package PrVision
 */
class PRV_Dashboard_Renderer {

	/**
	 * Render the three-tile KPI bento band.
	 *
	 * @param float      $current_score Current visibility score 0–1.
	 * @param float|null $prev_score    Previous-run score, null if first run.
	 * @param int        $cited_count   Number of cited peptides.
	 * @param int        $total_count   Total peptides tracked.
	 * @param array      $health        Run-health pill state + label ({state, label}).
	 * @param float      $mtd_cost      Month-to-date spend USD.
	 * @param float      $cap           Monthly budget cap USD.
	 * @param float      $pct           Percentage of cap consumed.
	 * @param bool       $truncated     Whether the last run hit the cap.
	 * @param string     $last_run_at   Formatted run timestamp or "Never".
	 * @param int        $row_count     Total peptide row count.
	 *
	 * @return void
	 */
	public function render_bento(
		float $current_score,
		?float $prev_score,
		int $cited_count,
		int $total_count,
		array $health,
		float $mtd_cost,
		float $cap,
		float $pct,
		bool $truncated,
		string $last_run_at,
		int $row_count
	): void {
		echo '<div class="prv-bento">';
		$this->render_score_tile( $current_score, $prev_score, $cited_count, $total_count, $health );
		$this->render_cost_tile( $mtd_cost, $cap, $pct, $truncated );
		$this->render_lastrun_tile( $last_run_at, $row_count );
		echo '</div>';
	}

	/**
	 * Render the Chart.js trendline with config-change vertical markers.
	 *
	 * The chart lives in a fixed-height .prv-chartbox (300px) and is created
	 * inside a typeof-Chart guard. If Chart.js is unavailable the guard adds
	 * .prv-noscript, hiding the canvas and showing an in-box fallback note
	 * without reflow or throw.
	 *
	 * Config-change markers are detected from consecutive config_version values
	 * in the trendline. An inline configMarker Chart.js plugin draws a vertical
	 * dashed orange line + pin circle at each boundary index.
	 *
	 * @param array<int, array{run_id: string, captured_at: string, score: float, config_version?: int}> $trendline Trendline data points.
	 *
	 * @return void
	 */
	public function render_trendline( array $trendline ): void {
		echo '<div class="prv-card prv-card--chart">';
		echo '<div class="prv-card-head"><h2>' . esc_html__( 'Visibility Score — Trend', 'pr-vision' ) . '</h2></div>';

		if ( empty( $trendline ) ) {
			echo '<div class="prv-card-body"><p>' . esc_html__( 'No probe runs recorded yet. Click "Run now" to collect the first data point.', 'pr-vision' ) . '</p></div></div>';
			return;
		}

		$labels         = array();
		$scores         = array();
		$marker_indices = array();
		$prev_version   = null;

		foreach ( $trendline as $idx => $point ) {
			$labels[] = gmdate( 'M j', (int) strtotime( (string) $point['captured_at'] ) );
			$scores[] = round( (float) $point['score'] * 100, 2 );

			$cv = isset( $point['config_version'] ) ? (int) $point['config_version'] : null;
			if ( null !== $prev_version && null !== $cv && $cv !== $prev_version ) {
				$marker_indices[] = $idx;
			}
			$prev_version = $cv;
		}

		$labels_json         = wp_json_encode( $labels );
		$scores_json         = wp_json_encode( $scores );
		$marker_indices_json = wp_json_encode( $marker_indices );
		$has_markers         = ! empty( $marker_indices );

		echo '<div class="prv-chartbox">';
		echo '<canvas id="prv-trendline-chart" aria-label="' . esc_attr__( 'Visibility score over time', 'pr-vision' ) . '" role="img"></canvas>';
		echo '<div class="prv-chart-fallback">' . esc_html__( 'Trend chart needs Chart.js — the data still reads in the standings table below.', 'pr-vision' ) . '</div>';
		echo '</div>';

		echo '<div class="prv-chartcap">';
		echo '<span class="prv-chartcap-item"><span class="prv-leg-solid"></span>' . esc_html__( 'Visibility score (%)', 'pr-vision' ) . '</span>';
		echo '<span class="prv-chartcap-item"><span class="prv-leg-dash"></span>' . esc_html__( 'First-citation goal', 'pr-vision' ) . '</span>';
		if ( $has_markers ) {
			echo '<span class="prv-chartcap-item"><span class="prv-leg-cfg"></span>' . esc_html__( 'Config changed', 'pr-vision' ) . '</span>';
		}
		echo '</div>';

		if ( $has_markers ) {
			echo '<div class="prv-cfg-note"><span class="prv-cfg-note-icon" aria-hidden="true">&#x2139;</span>';
			echo '<span><b>' . esc_html__( 'Scored config changed', 'pr-vision' ) . '</b> — ';
			echo esc_html__( 'segments either side of the orange markers are separate baselines; the line does not represent one continuous trend across breaks.', 'pr-vision' );
			echo '</span></div>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>(function(){';
		echo 'var labels=' . $labels_json . ';';
		echo 'var scores=' . $scores_json . ';';
		echo 'var markerIdx=' . $marker_indices_json . ';';
		echo 'var box=document.getElementById("prv-trendline-chart");';
		echo 'if(!box){return;}';
		echo 'var wrap=box.closest?box.closest(".prv-chartbox"):box.parentNode;';
		echo 'if(typeof Chart==="undefined"){if(wrap){wrap.classList.add("prv-noscript");}return;}';
		$this->echo_chart_js_plugin();
		echo 'var ctx=box.getContext("2d");';
		echo 'var grad=ctx.createLinearGradient(0,0,0,300);';
		echo 'grad.addColorStop(0,"rgba(52,192,202,0.28)");grad.addColorStop(1,"rgba(52,192,202,0)");';
		echo 'new Chart(ctx,{plugins:[configMarker],type:"line",data:{labels:labels,datasets:[';
		echo '{label:' . wp_json_encode( __( 'Visibility Score (%)', 'pr-vision' ) ) . ',data:scores,';
		echo 'borderColor:"#34C0CA",backgroundColor:grad,tension:0.3,fill:true,';
		echo 'pointBackgroundColor:"#34C0CA",pointBorderColor:"#14181C",pointBorderWidth:1,pointRadius:4},';
		echo '{label:' . wp_json_encode( __( 'First-citation goal (5%)', 'pr-vision' ) ) . ',data:scores.map(function(){return 5;}),';
		echo 'borderColor:"#B6F25A",borderDash:[6,4],borderWidth:1.5,pointRadius:0,fill:false}';
		echo ']},options:{maintainAspectRatio:false,interaction:{mode:"index",intersect:false},';
		echo 'scales:{x:{grid:{color:"#2C353E"},ticks:{color:"#9AA7B2",font:{size:11}}},';
		echo 'y:{min:0,max:100,grid:{color:"#2C353E"},ticks:{color:"#9AA7B2",font:{size:11}},';
		echo 'title:{display:true,text:"Score %",color:"#9AA7B2",font:{size:11}}}},';
		echo 'plugins:{legend:{display:false},tooltip:{backgroundColor:"#232B33",borderColor:"#3A4651",borderWidth:1,';
		echo 'titleColor:"#EEF2F5",bodyColor:"#C2CCD6",callbacks:{afterBody:function(items){';
		echo 'var idx=items[0].dataIndex;return markerIdx.indexOf(idx)>=0?["⚑ Scored config changed here"]:[];';
		echo '}}}}';
		echo '}});})();</script>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';
	}

	/**
	 * Emit the configMarker inline Chart.js plugin (no external CDN).
	 *
	 * Draws a vertical dashed orange line + pin circle at each marker index.
	 * Must be called inside a <script> block after markerIdx is defined.
	 *
	 * @return void
	 */
	private function echo_chart_js_plugin(): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'var configMarker={id:"configMarker",afterDraw:function(chart){';
		echo 'if(!markerIdx.length){return;}';
		echo 'var c=chart.ctx,xA=chart.scales.x,yA=chart.scales.y;';
		echo 'c.save();';
		echo 'markerIdx.forEach(function(idx){';
		echo 'var xP=xA.getPixelForValue(idx);';
		echo 'c.strokeStyle="#FFB36E";c.lineWidth=2;c.setLineDash([5,4]);';
		echo 'c.beginPath();c.moveTo(xP,yA.top);c.lineTo(xP,yA.bottom);c.stroke();';
		echo 'c.setLineDash([]);c.fillStyle="#FF9D4D";c.strokeStyle="#14181C";c.lineWidth=1.5;';
		echo 'c.beginPath();c.arc(xP,yA.top+8,5,0,Math.PI*2);c.fill();c.stroke();';
		echo '});c.restore();';
		echo '}};';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the visibility score KPI tile with run-health pill.
	 *
	 * @param float      $score      Current visibility score 0–1.
	 * @param float|null $prev_score Previous run score, null if first run.
	 * @param int        $cited      Number of cited peptides.
	 * @param int        $total      Total peptides tracked.
	 * @param array      $health     Run-health pill state ({state, label}).
	 *
	 * @return void
	 */
	private function render_score_tile( float $score, ?float $prev_score, int $cited, int $total, array $health ): void {
		$score_pct  = round( $score * 100, 1 );
		$delta_html = $this->build_delta_html( $score, $prev_score );

		echo '<div class="prv-tile prv-tile--score">';
		echo '<span class="prv-health-pill prv-health--' . esc_attr( $health['state'] ) . '" aria-label="' . esc_attr__( 'Run health', 'pr-vision' ) . '">';
		echo '<span class="prv-health-dot" aria-hidden="true"></span>';
		echo esc_html( $health['label'] );
		echo '</span>';
		echo '<div class="prv-tile-label">' . esc_html__( 'Visibility Score', 'pr-vision' ) . '</div>';
		echo '<div class="prv-tile-big">' . esc_html( (string) $score_pct ) . '<small>%</small></div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by build_delta_html; esc_html() applied on all user values inside
		echo $delta_html;
		echo '<div class="prv-tile-sub">' . esc_html(
			sprintf(
				/* translators: 1: cited count, 2: total peptide count */
				__( '%1$d of %2$d peptides cited', 'pr-vision' ),
				$cited,
				$total
			)
		) . '</div>';
		echo '</div>';
	}

	/**
	 * Render the MTD cost KPI tile with budget meter and optional truncation badge.
	 *
	 * @param float $mtd_cost  Month-to-date spend USD.
	 * @param float $cap       Monthly cap USD.
	 * @param float $pct       Percentage of cap consumed.
	 * @param bool  $truncated Whether the last run was truncated by the cap.
	 *
	 * @return void
	 */
	private function render_cost_tile( float $mtd_cost, float $cap, float $pct, bool $truncated ): void {
		$meter_class = $pct >= 100 ? 'prv-meter prv-meter--capped' : 'prv-meter';
		echo '<div class="prv-tile prv-tile--cost">';
		echo '<div class="prv-tile-label">' . esc_html__( 'MTD Cost', 'pr-vision' ) . '</div>';
		echo '<div class="prv-cost-row">';
		echo '<span class="prv-cost-big">$' . esc_html( number_format( $mtd_cost, 4 ) ) . '</span>';
		echo '<span class="prv-cost-cap"> / $' . esc_html( number_format( $cap, 2 ) ) . '</span>';
		echo '</div>';
		echo '<div class="' . esc_attr( $meter_class ) . '" role="progressbar" aria-valuenow="' . esc_attr( (string) $pct ) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . esc_attr__( 'Budget used', 'pr-vision' ) . '"><span style="width:' . esc_attr( $pct ) . '%"></span></div>';
		if ( $truncated ) {
			echo '<div class="prv-trunc-badge"><span class="prv-trunc-icon" aria-hidden="true">&#x26A0;</span>';
			echo '<span><b>' . esc_html__( 'Last run truncated', 'pr-vision' ) . '</b> — ';
			echo esc_html__( 'hit the cap before all probes completed.', 'pr-vision' ) . '</span></div>';
		}
		echo '</div>';
	}

	/**
	 * Render the last-run KPI tile.
	 *
	 * @param string $last_run_at Formatted run timestamp or "Never".
	 * @param int    $row_count   Peptide count tracked.
	 *
	 * @return void
	 */
	private function render_lastrun_tile( string $last_run_at, int $row_count ): void {
		echo '<div class="prv-tile prv-tile--lastrun">';
		echo '<div class="prv-tile-label">' . esc_html__( 'Last Run', 'pr-vision' ) . '</div>';
		echo '<div class="prv-tile-ts">' . esc_html( $last_run_at ) . '</div>';
		if ( $row_count > 0 ) {
			echo '<div class="prv-tile-meta">' . esc_html(
				sprintf(
					/* translators: %d: peptide row count */
					__( '%d peptides tracked', 'pr-vision' ),
					$row_count
				)
			) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Build a pre-escaped delta indicator HTML string for the score tile.
	 *
	 * @param float      $current  Current score 0–1.
	 * @param float|null $previous Previous run score, null if first run.
	 *
	 * @return string Pre-escaped HTML, or empty string when no previous score.
	 */
	public function build_delta_html( float $current, ?float $previous ): string {
		if ( null === $previous ) {
			return '';
		}

		$diff = round( ( $current - $previous ) * 100, 2 );

		if ( $diff > 0 ) {
			return '<div class="prv-tile-delta prv-delta--up">&#x25B2; +' . esc_html( number_format( $diff, 2 ) ) . '%</div>';
		}
		if ( $diff < 0 ) {
			return '<div class="prv-tile-delta prv-delta--down">&#x25BC; ' . esc_html( number_format( $diff, 2 ) ) . '%</div>';
		}

		return '<div class="prv-tile-delta prv-delta--flat">&#x2014; ' . esc_html__( 'no change', 'pr-vision' ) . '</div>';
	}
}
