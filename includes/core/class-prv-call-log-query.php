<?php
/**
 * Call log queries for the Call Log admin page (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Executes bounded, filterable call-log queries across prv_call_meta.
 *
 * All queries are LIMIT/OFFSET paginated on the captured_at index.
 * Filters: model, peptide_slug, date_from, date_to, status (ok/error/pruned).
 * Returns structured arrays; rendering is delegated to PRV_Call_Log_Table_Renderer.
 *
 * Who triggers: PRV_Call_Log_Page::render_page().
 * Dependencies: $wpdb, PRV_Call_Meta_Table, PRV_Call_Io_Table.
 *
 * @see class-prv-call-meta-table.php          — Primary source table.
 * @see class-prv-call-io-table.php            — Joined for pruned detection.
 * @see class-prv-call-log-table-renderer.php  — Renders query results.
 * @package PrVision
 */
class PRV_Call_Log_Query {

	/**
	 * Default rows per page.
	 */
	const PAGE_SIZE = 50;

	/**
	 * Fetch a paginated, filtered page of call-log rows.
	 *
	 * Returns metadata rows only; I/O content is fetched separately per-call.
	 * Side effects: Database read.
	 *
	 * @param array<string, string> $filters  Keys: model, peptide, date_from,
	 *                                        date_to, status (ok|error|cited|not_cited).
	 * @param int                   $page     1-based page number.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function get_page( array $filters, int $page = 1 ): array {
		global $wpdb;

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$table  = PRV_Call_Meta_Table::get_table_name();

		list( $where_sql, $where_args ) = $this->build_where( $filters );

		// Count query.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} {$where_sql}",
				...$where_args
			)
		);
		// phpcs:enable

		$pages = $total > 0 ? (int) ceil( $total / self::PAGE_SIZE ) : 1;

		// Data query.
		$data_args   = $where_args;
		$data_args[] = self::PAGE_SIZE;
		$data_args[] = $offset;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, visibility_row, run_id, peptide_slug, model, intent_label,
				        tokens_in, tokens_out, cost_usd, latency_ms, cited,
				        http_status, captured_at, config_version
				   FROM {$table}
				   {$where_sql}
				   ORDER BY captured_at DESC
				   LIMIT %d OFFSET %d",
				...$data_args
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * Fetch a single call's I/O record from prv_call_io.
	 *
	 * Returns null when the row has been pruned (aged out).
	 * Side effects: Database read.
	 *
	 * @param int $call_id PK of prv_call_meta row.
	 *
	 * @return array{prompt_text: string, response_text: string}|null Null when pruned.
	 */
	public function get_call_io( int $call_id ): ?array {
		global $wpdb;

		$table = PRV_Call_Io_Table::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT prompt_text, response_text FROM {$table} WHERE call_id = %d LIMIT 1",
				$call_id
			),
			ARRAY_A
		);

		if ( null === $row || ! is_array( $row ) ) {
			return null;
		}

		return array(
			'prompt_text'   => (string) $row['prompt_text'],
			'response_text' => (string) $row['response_text'],
		);
	}

	/**
	 * Build the WHERE clause and parameter list from filter array.
	 *
	 * Returns a two-element array: [sql_string, args_array].
	 *
	 * @param array<string, string> $filters See get_page() docblock.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where( array $filters ): array {
		$clauses = array();
		$args    = array();

		if ( ! empty( $filters['model'] ) ) {
			$clauses[] = 'model = %s';
			$args[]    = sanitize_text_field( $filters['model'] );
		}

		if ( ! empty( $filters['peptide'] ) ) {
			$clauses[] = 'peptide_slug = %s';
			$args[]    = sanitize_text_field( $filters['peptide'] );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$clauses[] = 'captured_at >= %s';
			$args[]    = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$clauses[] = 'captured_at <= %s';
			$args[]    = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
		}

		if ( ! empty( $filters['status'] ) ) {
			switch ( $filters['status'] ) {
				case 'error':
					$clauses[] = 'http_status >= %d';
					$args[]    = 400;
					break;
				case 'cited':
					$clauses[] = 'cited = %d';
					$args[]    = 1;
					break;
				case 'not_cited':
					$clauses[] = 'cited = %d';
					$args[]    = 0;
					break;
			}
		}

		$where_sql = empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses );

		return array( $where_sql, $args );
	}
}
