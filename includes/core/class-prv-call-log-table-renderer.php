<?php
/**
 * Renders the Call Log table and filter bar (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Renders the paginated Call Log table, filter bar, and result count.
 *
 * Does not render the detail drawer — that is PRV_Call_Drawer_Renderer.
 * All output is escaped at render. Cited% 0 renders muted; >0 renders lime.
 * Status/verdict are never signalled by colour alone (chip + label).
 *
 * Who triggers: PRV_Call_Log_Page::render_page().
 * Dependencies: PRV_Costs_Renderer (for subnav helper), PRV_Config.
 *
 * @see class-prv-call-log-page.php    — Caller, passes data array.
 * @see class-prv-call-drawer-renderer.php — Drawer renderer (emitted below table).
 * @see class-prv-costs-renderer.php   — render_subnav() shared helper.
 * @package PrVision
 */
class PRV_Call_Log_Table_Renderer {

	/**
	 * Render the full Call Log page including table and drawer.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<string, mixed> $data Keys: rows, total, pages, page, filters.
	 *
	 * @return void
	 */
	public function render( array $data ): void {
		$rows    = (array) $data['rows'];
		$total   = (int) $data['total'];
		$pages   = (int) $data['pages'];
		$page    = (int) $data['page'];
		$filters = (array) $data['filters'];

		$subnav = new PRV_Costs_Renderer();

		echo '<div class="wrap prv-page-wrap">';
		$subnav->render_subnav( 'calls' );

		echo '<div class="prv-page-header">';
		echo '<h1 class="prv-page-title">' . esc_html__( 'Call Log', 'pr-vision' ) . '</h1>';

		$retention_days = PRV_Config::get_io_retention_days();
		echo '<p class="prv-retention-note">';
		echo esc_html(
			sprintf(
				/* translators: %d: retention days */
				__( 'Prompt + response kept %d days. Cost + metadata kept indefinitely.', 'pr-vision' ),
				$retention_days
			)
		);
		echo '</p>';
		echo '</div>';

		$this->render_filter_bar( $filters );

		echo '<div class="prv-results-count" aria-live="polite">';
		if ( $total > 0 ) {
			$from = ( ( $page - 1 ) * PRV_Call_Log_Query::PAGE_SIZE ) + 1;
			$to   = min( $page * PRV_Call_Log_Query::PAGE_SIZE, $total );
			echo esc_html(
				sprintf(
					/* translators: 1: from, 2: to, 3: total */
					__( 'Showing %1$d–%2$d of %3$d calls', 'pr-vision' ),
					$from,
					$to,
					$total
				)
			);
		} else {
			echo esc_html__( 'No calls recorded yet.', 'pr-vision' );
		}
		echo '</div>';

		$this->render_table( $rows );
		$this->render_pagination( $page, $pages, $filters );

		// Emit drawer (hidden until a row is clicked).
		$drawer = new PRV_Call_Drawer_Renderer();
		$drawer->render_container();

		echo '</div>'; // .wrap
	}

	/**
	 * Render the filter bar with 4 select controls and Clear button.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<string, string> $filters Active filter values.
	 *
	 * @return void
	 */
	private function render_filter_bar( array $filters ): void {
		$base_url = admin_url( 'admin.php?page=pr-vision-calls' );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="prv-filter-bar">';
		echo '<input type="hidden" name="page" value="pr-vision-calls">';

		// Model filter.
		echo '<label for="prv-f-model" class="prv-sr-only">' . esc_html__( 'Model', 'pr-vision' ) . '</label>';
		echo '<select id="prv-f-model" name="prv_filter_model" class="prv-filter-select">';
		echo '<option value="">' . esc_html__( 'All models', 'pr-vision' ) . '</option>';
		foreach ( PRV_Config::get_models() as $model_slug ) {
			$sel = ( $filters['model'] === $model_slug ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $model_slug ) . '"' . $sel . '>' . esc_html( $model_slug ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sel is safe
		}
		echo '</select>';

		// Peptide filter.
		echo '<label for="prv-f-peptide" class="prv-sr-only">' . esc_html__( 'Peptide', 'pr-vision' ) . '</label>';
		echo '<select id="prv-f-peptide" name="prv_filter_peptide" class="prv-filter-select">';
		echo '<option value="">' . esc_html__( 'All peptides', 'pr-vision' ) . '</option>';
		foreach ( PRV_Config::get_peptides() as $p ) {
			$slug = (string) $p['slug'];
			$sel  = ( $filters['peptide'] === $slug ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $slug ) . '"' . $sel . '>' . esc_html( $p['label'] ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sel is safe
		}
		echo '</select>';

		// Date range.
		echo '<label for="prv-f-from" class="prv-sr-only">' . esc_html__( 'From date', 'pr-vision' ) . '</label>';
		echo '<input type="date" id="prv-f-from" name="prv_filter_from" class="prv-filter-date" value="' . esc_attr( $filters['date_from'] ) . '">';
		echo '<label for="prv-f-to" class="prv-sr-only">' . esc_html__( 'To date', 'pr-vision' ) . '</label>';
		echo '<input type="date" id="prv-f-to" name="prv_filter_to" class="prv-filter-date" value="' . esc_attr( $filters['date_to'] ) . '">';

		// Status filter.
		$status_opts = array(
			''          => __( 'All statuses', 'pr-vision' ),
			'cited'     => __( 'Cited', 'pr-vision' ),
			'not_cited' => __( 'Not cited', 'pr-vision' ),
			'error'     => __( 'Error', 'pr-vision' ),
		);
		echo '<label for="prv-f-status" class="prv-sr-only">' . esc_html__( 'Status', 'pr-vision' ) . '</label>';
		echo '<select id="prv-f-status" name="prv_filter_status" class="prv-filter-select">';
		foreach ( $status_opts as $val => $label ) {
			$sel = ( $filters['status'] === $val ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sel is safe
		}
		echo '</select>';

		echo '<button type="submit" class="prv-filter-apply button">' . esc_html__( 'Filter', 'pr-vision' ) . '</button>';
		echo '<a href="' . esc_url( $base_url ) . '" class="prv-filter-clear button">' . esc_html__( 'Clear', 'pr-vision' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Render the calls table.
	 *
	 * Side effects: Outputs HTML.
	 *
	 * @param array<int, array<string, mixed>> $rows Table rows from query.
	 *
	 * @return void
	 */
	private function render_table( array $rows ): void {
		echo '<div class="prv-table-wrap" style="overflow-x:auto">';
		echo '<table class="prv-standings prv-callog-table" cellspacing="0" aria-label="' . esc_attr__( 'Call log', 'pr-vision' ) . '">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Peptide', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Intent', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Model', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Tokens', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Latency', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Cost', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Cited', 'pr-vision' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'pr-vision' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="9" class="prv-empty-msg">' . esc_html__( 'No calls recorded yet.', 'pr-vision' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$id         = (int) $row['id'];
			$is_error   = ( (int) $row['http_status'] >= 400 );
			$cited_null = ( null === $row['cited'] );
			$is_cited   = ! $cited_null && (bool) $row['cited'];
			$latency_ms = isset( $row['latency_ms'] ) ? (int) $row['latency_ms'] : null;
			$lat_warn   = ( null !== $latency_ms && $latency_ms > 8000 ) ? ' style="color:#FFB36E"' : '';

			$status_chip = $is_error ? '<span class="prv-chip prv-chip--error">&#x2715; Error</span>' : '<span class="prv-chip prv-chip--ok">OK</span>';

			if ( $is_error ) {
				$cited_chip = '<span class="prv-status prv-status--not-yet">&#x2013; Error</span>';
			} elseif ( $cited_null ) {
				$cited_chip = '<span class="prv-status prv-status--not-yet">&#x2013; —</span>';
			} elseif ( $is_cited ) {
				$cited_chip = '<span class="prv-status prv-status--cited">&#x2713; Cited</span>';
			} else {
				$cited_chip = '<span class="prv-status prv-status--not-yet">&#x2013; Not yet</span>';
			}

			echo '<tr id="call-row-' . esc_attr( (string) $id ) . '" class="prv-call-row" tabindex="0" role="button" data-call-id="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr( sprintf( /* translators: %d: call ID */ __( 'Call #%d details', 'pr-vision' ), $id ) ) . '">';
			echo '<td class="prv-tabnum">' . esc_html( (string) $row['captured_at'] ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $row['peptide_slug'] ) . '</strong></td>';
			echo '<td class="prv-muted">' . esc_html( (string) $row['intent_label'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['model'] ) . '</code></td>';

			$tok_in  = isset( $row['tokens_in'] ) && null !== $row['tokens_in'] ? (int) $row['tokens_in'] : null;
			$tok_out = isset( $row['tokens_out'] ) && null !== $row['tokens_out'] ? (int) $row['tokens_out'] : null;
			$tok_str = ( null !== $tok_in && null !== $tok_out )
				? '&#x2191;' . esc_html( number_format( $tok_in ) ) . ' &#x2193;' . esc_html( number_format( $tok_out ) )
				: '—';
			echo '<td class="prv-tabnum prv-muted">' . $tok_str . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- arrows are HTML entities; numbers are escaped above

			echo '<td class="prv-tabnum"' . $lat_warn . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static style attr
			echo null !== $latency_ms ? esc_html( number_format( $latency_ms ) ) . 'ms' : '—';
			echo '</td>';

			echo '<td class="prv-tabnum">$' . esc_html( number_format( (float) $row['cost_usd'], 6 ) ) . '</td>';
			echo '<td>' . $cited_chip . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from safe parts above
			echo '<td>' . $status_chip . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from safe parts above
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render pagination bar.
	 *
	 * Disabled buttons use colour/border, not opacity (WCAG AA).
	 * Side effects: Outputs HTML.
	 *
	 * @param int                   $page    Current page (1-based).
	 * @param int                   $pages   Total pages.
	 * @param array<string, string> $filters Active filters for link building.
	 *
	 * @return void
	 */
	private function render_pagination( int $page, int $pages, array $filters ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$base   = admin_url( 'admin.php' );
		$params = array( 'page' => 'pr-vision-calls' );
		foreach ( $filters as $k => $v ) {
			if ( '' !== $v ) {
				$params[ 'prv_filter_' . ltrim( $k, 'prv_filter_' ) ] = $v;
			}
		}

		echo '<div class="prv-pagination" role="navigation" aria-label="' . esc_attr__( 'Pagination', 'pr-vision' ) . '">';

		// Prev.
		if ( $page > 1 ) {
			$prev_url = add_query_arg( array_merge( $params, array( 'prv_page' => $page - 1 ) ), $base );
			echo '<a href="' . esc_url( $prev_url ) . '" class="prv-page-btn">&#x2190; ' . esc_html__( 'Prev', 'pr-vision' ) . '</a>';
		} else {
			echo '<span class="prv-page-btn prv-page-btn--disabled" aria-disabled="true">&#x2190; ' . esc_html__( 'Prev', 'pr-vision' ) . '</span>';
		}

		echo '<span class="prv-page-info">' . esc_html( sprintf( /* translators: 1: current, 2: total */ __( 'Page %1$d of %2$d', 'pr-vision' ), $page, $pages ) ) . '</span>';

		// Next.
		if ( $page < $pages ) {
			$next_url = add_query_arg( array_merge( $params, array( 'prv_page' => $page + 1 ) ), $base );
			echo '<a href="' . esc_url( $next_url ) . '" class="prv-page-btn">' . esc_html__( 'Next', 'pr-vision' ) . ' &#x2192;</a>';
		} else {
			echo '<span class="prv-page-btn prv-page-btn--disabled" aria-disabled="true">' . esc_html__( 'Next', 'pr-vision' ) . ' &#x2192;</span>';
		}

		echo '<span class="prv-page-per">' . esc_html( sprintf( /* translators: %d: rows per page */ __( '%d per page', 'pr-vision' ), PRV_Call_Log_Query::PAGE_SIZE ) ) . '</span>';
		echo '</div>';
	}
}
