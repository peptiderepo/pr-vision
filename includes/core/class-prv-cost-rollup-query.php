<?php
/**
 * Cost rollup queries for the Costs admin page (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Executes cost rollup queries across run / peptide / intent / model / call.
 *
 * All queries are bounded (LIMIT/OFFSET) and parameterised via $wpdb->prepare.
 * The MTD total from this class reconciles to PRV_Cost_Ledger::get_month_to_date_usd()
 * because both sum cost_usd on prv_call_meta filtered by current calendar month.
 *
 * Who triggers: PRV_Costs_Page::render_page().
 * Dependencies: $wpdb, PRV_Call_Meta_Table, PRV_Config.
 *
 * @see class-prv-call-meta-table.php — Source table.
 * @see class-prv-cost-ledger.php     — MTD total (reconciliation reference).
 * @see class-prv-costs-page.php      — Consumer of rollup data.
 * @package PrVision
 */
class PRV_Cost_Rollup_Query {

	/**
	 * Rows per page for the drill-down table.
	 */
	const PAGE_SIZE = 50;

	/**
	 * Fetch MTD totals: total cost, total calls, avg cost/call for the month.
	 *
	 * Side effects: Database read.
	 *
	 * @return array{total_cost: float, total_calls: int, avg_cost: float}
	 */
	public function get_mtd_summary(): array {
		global $wpdb;

		$table = PRV_Call_Meta_Table::get_table_name();
		$month = gmdate( 'Y-m' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cost_usd),0) AS total_cost, COUNT(*) AS total_calls
				   FROM {$table}
				  WHERE DATE_FORMAT(captured_at, %s) = %s",
				'%Y-%m',
				$month
			),
			ARRAY_A
		);
		// phpcs:enable

		$total_cost  = (float) ( $row['total_cost'] ?? 0.0 );
		$total_calls = (int) ( $row['total_calls'] ?? 0 );
		$avg_cost    = $total_calls > 0 ? $total_cost / $total_calls : 0.0;

		return array(
			'total_cost'  => $total_cost,
			'total_calls' => $total_calls,
			'avg_cost'    => $avg_cost,
		);
	}

	/**
	 * Fetch per-model MTD cost breakdown, ordered by cost descending.
	 *
	 * Side effects: Database read.
	 *
	 * @return array<int, array{model: string, calls: int, mtd_cost: float, cost_per_call: float}>
	 */
	public function get_model_breakdown(): array {
		global $wpdb;

		$table = PRV_Call_Meta_Table::get_table_name();
		$month = gmdate( 'Y-m' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT model,
				        COUNT(*) AS calls,
				        COALESCE(SUM(cost_usd),0) AS mtd_cost
				   FROM {$table}
				  WHERE DATE_FORMAT(captured_at, %s) = %s
				  GROUP BY model
				  ORDER BY mtd_cost DESC
				  LIMIT 50",
				'%Y-%m',
				$month
			),
			ARRAY_A
		);
		// phpcs:enable

		$result = array();
		foreach ( (array) $rows as $row ) {
			$calls    = (int) $row['calls'];
			$mtd_cost = (float) $row['mtd_cost'];
			$result[] = array(
				'model'         => (string) $row['model'],
				'calls'         => $calls,
				'mtd_cost'      => $mtd_cost,
				'cost_per_call' => $calls > 0 ? $mtd_cost / $calls : 0.0,
			);
		}
		return $result;
	}

	/**
	 * Fetch cost drill-down by aggregation level (run/peptide/intent/model).
	 *
	 * Side effects: Database read.
	 *
	 * @param string $level  One of: run, peptide, intent, model.
	 * @param int    $offset Pagination offset.
	 *
	 * @return array<int, array<string, mixed>> Rows appropriate for the level.
	 */
	public function get_drill_down( string $level, int $offset = 0 ): array {
		global $wpdb;

		$table     = PRV_Call_Meta_Table::get_table_name();
		$month     = gmdate( 'Y-m' );
		$page_size = self::PAGE_SIZE;
		$offset    = max( 0, $offset );

		$allowed_levels = array( 'run', 'peptide', 'intent', 'model' );
		if ( ! in_array( $level, $allowed_levels, true ) ) {
			$level = 'run';
		}

		switch ( $level ) {
			case 'peptide':
				$group_col = 'peptide_slug';
				break;
			case 'intent':
				$group_col = 'intent_label';
				break;
			case 'model':
				$group_col = 'model';
				break;
			default:
				$group_col = 'run_id';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$group_col} AS group_key,
				        COUNT(*) AS calls,
				        COALESCE(SUM(cost_usd),0) AS total_cost,
				        MIN(captured_at) AS first_at,
				        MAX(captured_at) AS last_at
				   FROM {$table}
				  WHERE DATE_FORMAT(captured_at, %s) = %s
				  GROUP BY {$group_col}
				  ORDER BY total_cost DESC
				  LIMIT %d OFFSET %d",
				'%Y-%m',
				$month,
				$page_size,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[] = array(
				'group_key'  => (string) $row['group_key'],
				'calls'      => (int) $row['calls'],
				'total_cost' => (float) $row['total_cost'],
				'first_at'   => (string) $row['first_at'],
				'last_at'    => (string) $row['last_at'],
			);
		}
		return $result;
	}

	/**
	 * Compute the linear month-end projection from MTD spend and days elapsed.
	 *
	 * Side effects: None.
	 *
	 * @param float $mtd_cost MTD cost in USD.
	 *
	 * @return float Projected full-month cost.
	 */
	public function project_month_end( float $mtd_cost ): float {
		$days_in_month = (int) gmdate( 't' );
		$day_of_month  = (int) gmdate( 'j' );

		if ( $day_of_month < 1 ) {
			return $mtd_cost;
		}

		return $mtd_cost * ( $days_in_month / $day_of_month );
	}
}
