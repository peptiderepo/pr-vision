<?php
declare(strict_types=1);

/**
 * Cost ledger: per-call logging + hard monthly budget enforcement.
 *
 * Every paid API call goes through record_call(). Before each call the
 * runner should check can_afford() to abort gracefully at the cap without
 * throwing — a partial run that respects the budget is acceptable; an
 * over-spend is not.
 *
 * Costs are stored in the pgm_ai_visibility table (cost_usd column per row)
 * and summarized by summing the current calendar month's rows. No separate
 * ledger table is needed; the visibility table IS the ledger.
 *
 * Who triggers: PGM_Probe_Runner before and after each API call.
 * Dependencies: $wpdb, PGM_Table_Manager.
 *
 * @see class-pgm-probe-runner.php  — Calls can_afford() and record_call().
 * @see class-pgm-table-manager.php — Table where cost_usd is stored.
 * @see CONTEXT.md                  — "monthly cap" definition.
 * @package PeptideGeoMonitor
 */
class PGM_Cost_Ledger {

	/**
	 * Retrieve the total probe cost incurred in the current calendar month (UTC).
	 *
	 * Side effects: Database read.
	 *
	 * @return float Sum of cost_usd for rows in the current month, in USD.
	 */
	public function get_month_to_date_usd(): float {
		global $wpdb;

		$table = PGM_Table_Manager::get_table_name();
		$month = gmdate( 'Y-m' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COALESCE(SUM(cost_usd), 0) FROM {$table} WHERE DATE_FORMAT(captured_at, %s) = %s",
				'%Y-%m',
				$month
			)
		);

		return (float) $result;
	}

	/**
	 * Check whether there is remaining budget for another API call.
	 *
	 * Callers should invoke this BEFORE dispatching a probe to avoid
	 * overspend. Returns false when MTD spend is at or above the cap.
	 *
	 * @param float $estimated_cost_usd Estimated cost of the upcoming call.
	 *
	 * @return bool True when the call can proceed within budget.
	 */
	public function can_afford( float $estimated_cost_usd ): bool {
		$cap    = PGM_Config::get_monthly_budget_usd();
		$spent  = $this->get_month_to_date_usd();
		return ( $spent + $estimated_cost_usd ) <= $cap;
	}

	/**
	 * Check remaining budget headroom in USD.
	 *
	 * @return float Remaining USD; 0.0 when the cap is already hit.
	 */
	public function get_remaining_budget_usd(): float {
		$cap   = PGM_Config::get_monthly_budget_usd();
		$spent = $this->get_month_to_date_usd();
		return max( 0.0, $cap - $spent );
	}

	/**
	 * Record a completed API call's cost against a specific row ID.
	 *
	 * Updates the cost_usd column on the row created by PGM_Probe_Runner.
	 * The run is already written to the table before the cost is settled so
	 * even partial runs (budget abort) have their completed rows costed.
	 *
	 * Side effects: Database write.
	 *
	 * @param int   $row_id   Primary key of the pgm_ai_visibility row.
	 * @param float $cost_usd Actual cost in USD.
	 *
	 * @return bool True on success.
	 */
	public function update_row_cost( int $row_id, float $cost_usd ): bool {
		global $wpdb;

		$table = PGM_Table_Manager::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			array( 'cost_usd' => number_format( $cost_usd, 8, '.', '' ) ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}
}
