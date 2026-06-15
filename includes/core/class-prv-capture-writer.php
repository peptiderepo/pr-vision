<?php
/**
 * Best-effort per-call capture writer (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Writes per-call cost/metadata and I/O records after a probe completes.
 *
 * Security contract (P0):
 *   - Accepts only the rendered request BODY (messages + model + params).
 *   - NEVER receives $headers, $parsed_args, Authorization, or api keys.
 *   - Allowlist: prompt_text, response_text, model, peptide_slug, intent_label,
 *     tokens_in, tokens_out, cost_usd, latency_ms, cited, http_status,
 *     config_version, visibility_row, run_id, captured_at, io_captured.
 *
 * Capture is BEST-EFFORT: capture_io() is wrapped in a swallowing try/catch
 * by the caller (PRV_Probe_Runner). A write failure must never reach the probe
 * path or corrupt cap accounting.
 *
 * Who triggers: PRV_Probe_Runner — capture_io() is called LAST, after
 *               persist_result() and update_row_cost() complete.
 * Dependencies: $wpdb, PRV_Call_Meta_Table, PRV_Call_Io_Table.
 *
 * @see class-prv-call-meta-table.php  — Metadata table (kept indefinitely).
 * @see class-prv-call-io-table.php    — I/O table (pruned on retention window).
 * @see class-prv-probe-runner.php     — Caller; wraps capture_io() in try/catch.
 * @see ARCHITECTURE.md               — §Capture flow v0.3.0.
 * @package PrVision
 */
class PRV_Capture_Writer {

	/**
	 * Write one metadata row to prv_call_meta.
	 *
	 * Called inside the probe loop BEFORE capture_io() so the metadata row
	 * exists even when the I/O write fails. Returns the inserted row ID.
	 * io_captured is always 0 here; call set_io_captured() after write_io() succeeds.
	 *
	 * Side effects: Database write to prv_call_meta.
	 *
	 * @param array<string, mixed> $meta Allowlisted fields: visibility_row (int|null),
	 *     run_id (string), peptide_slug (string), model (string), intent_label (string),
	 *     tokens_in (int|null), tokens_out (int|null), cost_usd (float),
	 *     latency_ms (int|null), cited (int|null), http_status (int),
	 *     config_version (int|null).
	 *
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function write_meta( array $meta ): int {
		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = PRV_Call_Meta_Table::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'visibility_row' => isset( $meta['visibility_row'] ) ? (int) $meta['visibility_row'] : null,
				'run_id'         => (string) ( $meta['run_id'] ?? '' ),
				'peptide_slug'   => (string) ( $meta['peptide_slug'] ?? '' ),
				'model'          => (string) ( $meta['model'] ?? '' ),
				'intent_label'   => (string) ( $meta['intent_label'] ?? '' ),
				'tokens_in'      => isset( $meta['tokens_in'] ) ? (int) $meta['tokens_in'] : null,
				'tokens_out'     => isset( $meta['tokens_out'] ) ? (int) $meta['tokens_out'] : null,
				'cost_usd'       => number_format( (float) ( $meta['cost_usd'] ?? 0.0 ), 8, '.', '' ),
				'latency_ms'     => isset( $meta['latency_ms'] ) ? (int) $meta['latency_ms'] : null,
				'cited'          => isset( $meta['cited'] ) ? (int) $meta['cited'] : null,
				'http_status'    => (int) ( $meta['http_status'] ?? 200 ),
				'captured_at'    => $now,
				'config_version' => isset( $meta['config_version'] ) ? (int) $meta['config_version'] : null,
				'io_captured'    => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Write one I/O row to prv_call_io (best-effort; caller must catch).
	 *
	 * Security: only prompt_text and response_text are stored — never
	 * headers, Authorization, or any raw HTTP request object.
	 *
	 * On success, sets io_captured = 1 on the matching prv_call_meta row.
	 * The meta update is a best-effort follow-up; a failure here is not
	 * propagated (caller already swallows this entire method).
	 *
	 * Side effects: Database writes to prv_call_io and prv_call_meta.
	 *
	 * @param int    $call_id       FK to prv_call_meta.id.
	 * @param string $prompt_text   Rendered plain-text prompt (messages joined).
	 * @param string $response_text Raw text content from LLM response.
	 *
	 * @return bool True on success.
	 */
	public function write_io( int $call_id, string $prompt_text, string $response_text ): bool {
		global $wpdb;

		$table = PRV_Call_Io_Table::get_table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'call_id'       => $call_id,
				'prompt_text'   => $prompt_text,
				'response_text' => $response_text,
				'captured_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false !== $result ) {
			$this->set_io_captured( $call_id );
			return true;
		}

		return false;
	}

	/**
	 * Update cost_usd on an existing prv_call_meta row.
	 *
	 * Called after the probe returns its actual cost, mirroring the pattern
	 * already used by PRV_Cost_Ledger::update_row_cost() for prv_ai_visibility.
	 *
	 * Side effects: Database write to prv_call_meta.
	 *
	 * @param int   $call_id  PK of the prv_call_meta row.
	 * @param float $cost_usd Settled cost in USD.
	 *
	 * @return bool True on success.
	 */
	public function update_meta_cost( int $call_id, float $cost_usd ): bool {
		global $wpdb;

		$table = PRV_Call_Meta_Table::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array( 'cost_usd' => number_format( $cost_usd, 8, '.', '' ) ),
			array( 'id' => $call_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Mark io_captured = 1 on the prv_call_meta row after a successful I/O write.
	 *
	 * Best-effort: called internally from write_io(); failure is non-fatal.
	 * Legacy/pre-feature rows retain io_captured = 0 (the column default).
	 *
	 * Side effects: Database write to prv_call_meta.
	 *
	 * @param int $call_id PK of the prv_call_meta row.
	 *
	 * @return void
	 */
	private function set_io_captured( int $call_id ): void {
		global $wpdb;

		$table = PRV_Call_Meta_Table::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'io_captured' => 1 ),
			array( 'id' => $call_id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}
