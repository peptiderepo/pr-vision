<?php
/**
 * HTML renderer for the PR Vision Costs admin page (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the Costs admin page in the dark "Assay" theme.
 *
 * Renders: cap progress card, summary bento (3 tiles), model breakdown,
 * and the cost drill-down table. All output is escaped at render.
 *
 * Who triggers: PRV_Costs_Page::render_page().
 * Dependencies: PRV_Admin_Page (subnav helper), no DB access.
 *
 * @see class-prv-costs-page.php        — Caller, passes data array.
 * @see class-prv-call-log-page.php     — Sibling sub-page.
 * @see ARCHITECTURE.md                 — §Admin surfaces v0.3.0.
 * @package PrVision
 */
class PRV_Costs_Renderer {

	/**
	 * Render the full Costs page.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<string, mixed> $data Keys: mtd_cost, total_calls, avg_cost, cap,
	 *                                   projected, models, drill, level, offset.
	 *
	 * @return void
	 */
	public function render( array $data ): void {
		$mtd_cost    = (float) $data['mtd_cost'];
		$total_calls = (int) $data['total_calls'];
		$avg_cost    = (float) $data['avg_cost'];
		$cap         = (float) $data['cap'];
		$projected   = (float) $data['projected'];
		$models      = (array) $data['models'];
		$drill       = (array) $data['drill'];
		$level       = (string) $data['level'];
		$offset      = (int) $data['offset'];

		$meter_pct       = $cap > 0 ? min( 100, round( ( $mtd_cost / $cap ) * 100 ) ) : 0;
		$meter_class     = $meter_pct >= 90 ? ' prv-meter--capped' : '';
		$projected_warn  = $projected > $cap;

		echo '<div class="wrap prv-page-wrap">';
		$this->render_subnav( 'costs' );

		echo '<div class="prv-page-header">';
		echo '<h1 class="prv-page-title">' . esc_html__( 'Cost Accountability', 'pr-vision' ) . '</h1>';
		echo '<p class="prv-page-sub">' . esc_html__( 'Per-call spend against the $5.00 monthly cap.', 'pr-vision' ) . '</p>';
		echo '</div>';

		// Cap progress card.
		echo '<div class="prv-card prv-card--cap">';
		echo '<div class="prv-card-head"><h2>' . esc_html__( 'Budget', 'pr-vision' ) . '</h2></div>';
		echo '<div class="prv-card-body">';
		echo '<div class="prv-cap-row">';
		echo '<div class="prv-cap-left">';
		echo '<span class="prv-cost-big">$' . esc_html( number_format( $mtd_cost, 2 ) ) . '</span>';
		echo '<span class="prv-cost-cap"> ' . esc_html( sprintf( /* translators: %s: cap amount */ __( 'of $%s', 'pr-vision' ), number_format( $cap, 2 ) ) ) . '</span>';
		echo '</div>';
		echo '<div class="prv-cap-right">';
		$proj_class = $projected_warn ? ' style="color:#FF9D4D"' : '';
		echo '<div class="prv-cap-projected"' . $proj_class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string
		echo '<span class="prv-cap-proj-label">' . esc_html__( 'Linear estimate', 'pr-vision' ) . '</span>';
		echo '<span class="prv-cap-proj-val">$' . esc_html( number_format( $projected, 2 ) ) . '</span>';
		echo '</div>';
		$days_left = (int) gmdate( 't' ) - (int) gmdate( 'j' );
		echo '<div class="prv-cap-days">' . esc_html( sprintf( /* translators: %d: days */ __( '%d days remaining', 'pr-vision' ), $days_left ) ) . '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div class="prv-meter' . esc_attr( $meter_class ) . '" role="progressbar" aria-valuenow="' . esc_attr( (string) $meter_pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		echo '<span style="width:' . esc_attr( $meter_pct . '%' ) . '"></span>';
		echo '</div>';
		if ( $total_calls > 0 ) {
			echo '<p class="prv-cap-reconcile">';
			echo esc_html(
				sprintf(
					/* translators: 1: cost, 2: call count */
					__( '$%1$s recorded across %2$d calls this month — approximately reconciles to the Dashboard MTD total.', 'pr-vision' ),
					number_format( $mtd_cost, 4 ),
					$total_calls
				)
			);
			echo '</p>';
		}
		echo '</div></div>';

		// Summary bento.
		echo '<div class="prv-bento">';
		$this->render_bento_tile( __( 'Total calls', 'pr-vision' ), (string) $total_calls, __( 'this month', 'pr-vision' ) );
		$this->render_bento_tile( __( 'Avg cost / call', 'pr-vision' ), '$' . number_format( $avg_cost, 4 ), '' );
		$top_model = ! empty( $models ) ? $models[0]['model'] . ' ($' . number_format( $models[0]['mtd_cost'], 4 ) . ')' : __( '—', 'pr-vision' );
		$this->render_bento_tile( __( 'Most expensive model', 'pr-vision' ), $top_model, '' );
		echo '</div>';

		// Model breakdown table.
		$this->render_model_table( $models );

		// Drill-down card.
		$this->render_drill_down( $drill, $level, $offset );

		echo '</div>'; // .wrap
	}

	/**
	 * Render the subnav tab strip (shared across all PR Vision pages).
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param string $active Active tab key: dashboard|costs|calls|settings.
	 *
	 * @return void
	 */
	public function render_subnav( string $active ): void {
		$tabs = array(
			'dashboard' => array(
				'label' => __( 'Dashboard', 'pr-vision' ),
				'slug'  => 'pr-vision',
			),
			'costs'     => array(
				'label' => __( 'Costs', 'pr-vision' ),
				'slug'  => 'pr-vision-costs',
			),
			'calls'     => array(
				'label' => __( 'Call Log', 'pr-vision' ),
				'slug'  => 'pr-vision-calls',
			),
			'settings'  => array(
				'label' => __( 'Settings', 'pr-vision' ),
				'slug'  => 'pr-vision-settings',
			),
		);
		echo '<nav class="prv-subnav" aria-label="' . esc_attr__( 'PR Vision navigation', 'pr-vision' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$is_active   = ( $key === $active );
			$aria_current = $is_active ? ' aria-current="page"' : '';
			$tab_class   = 'prv-subnav-tab' . ( $is_active ? ' prv-subnav-tab--active' : '' );
			$url         = admin_url( 'admin.php?page=' . rawurlencode( $tab['slug'] ) );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $tab_class ) . '"' . $aria_current . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aria-current is safe static string
			echo esc_html( $tab['label'] );
			echo '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Render a single bento tile.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param string $label Label text.
	 * @param string $value Primary value.
	 * @param string $sub   Sub-label (empty to omit).
	 *
	 * @return void
	 */
	private function render_bento_tile( string $label, string $value, string $sub ): void {
		echo '<div class="prv-tile">';
		echo '<div class="prv-tile-label">' . esc_html( $label ) . '</div>';
		echo '<div class="prv-tile-big prv-tile-big--sm">' . esc_html( $value ) . '</div>';
		if ( '' !== $sub ) {
			echo '<div class="prv-tile-sub">' . esc_html( $sub ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the per-model cost breakdown table.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<int, array<string, mixed>> $models Model rows.
	 *
	 * @return void
	 */
	private function render_model_table( array $models ): void {
		echo '<div class="prv-card"><div class="prv-card-head"><h2>';
		echo esc_html__( 'By Model', 'pr-vision' );
		echo '</h2></div><div class="prv-card-body">';

		if ( empty( $models ) ) {
			echo '<p class="prv-empty-msg">' . esc_html__( 'No cost data yet. Run a probe to begin recording per-call costs.', 'pr-vision' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<div class="prv-table-wrap"><table class="prv-standings" cellspacing="0">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Model', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Calls', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'MTD Cost', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Cost / Call', 'pr-vision' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $models as $row ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $row['model'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) (int) $row['calls'] ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $row['mtd_cost'], 6 ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $row['cost_per_call'], 6 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
		echo '</div></div>';
	}

	/**
	 * Render the drill-down card with level picker and results table.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<int, array<string, mixed>> $drill  Drill-down rows.
	 * @param string                           $level  Current aggregation level.
	 * @param int                              $offset Current pagination offset.
	 *
	 * @return void
	 */
	private function render_drill_down( array $drill, string $level, int $offset ): void {
		echo '<div class="prv-card"><div class="prv-card-head"><h2>';
		echo esc_html__( 'Cost Drill-Down', 'pr-vision' );
		echo '</h2></div><div class="prv-card-body">';

		$levels   = array( 'run', 'peptide', 'intent', 'model' );
		$base_url = admin_url( 'admin.php?page=pr-vision-costs' );

		echo '<div class="prv-seg" role="group" aria-label="' . esc_attr__( 'Aggregation level', 'pr-vision' ) . '">';
		foreach ( $levels as $lvl ) {
			$is_active = ( $lvl === $level );
			$cls       = 'prv-seg-btn' . ( $is_active ? ' prv-seg-btn--active' : '' );
			$url       = add_query_arg(
				array(
					'prv_level'  => $lvl,
					'prv_offset' => 0,
				),
				$base_url
			);
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '"' . ( $is_active ? ' aria-current="true"' : '' ) . '>';
			echo esc_html( ucfirst( $lvl ) );
			echo '</a>';
		}
		echo '</div>';

		if ( empty( $drill ) ) {
			echo '<p class="prv-empty-msg">' . esc_html__( 'No data for this level.', 'pr-vision' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<div class="prv-table-wrap"><table class="prv-standings" cellspacing="0">';
		echo '<thead><tr>';
		echo '<th>' . esc_html( ucfirst( $level ) ) . '</th>';
		echo '<th>' . esc_html__( 'Calls', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Cost', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'View', 'pr-vision' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $drill as $row ) {
			$filter_url = add_query_arg(
				array( $this->level_to_filter_key( $level ) => rawurlencode( (string) $row['group_key'] ) ),
				admin_url( 'admin.php?page=pr-vision-calls' )
			);
			echo '<tr>';
			echo '<td class="prv-mono">' . esc_html( (string) $row['group_key'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['calls'] ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $row['total_cost'], 6 ) ) . '</td>';
			echo '<td><a href="' . esc_url( $filter_url ) . '">' . esc_html__( 'View calls →', 'pr-vision' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '</div></div>';
	}

	/**
	 * Map drill-down level to call-log filter query param key.
	 *
	 * @param string $level Drill-down level: run|peptide|intent|model.
	 *
	 * @return string Filter parameter name.
	 */
	private function level_to_filter_key( string $level ): string {
		$map = array(
			'run'     => 'prv_filter_run',
			'peptide' => 'prv_filter_peptide',
			'intent'  => 'prv_filter_intent',
			'model'   => 'prv_filter_model',
		);
		return $map[ $level ] ?? 'prv_filter_run';
	}
}
