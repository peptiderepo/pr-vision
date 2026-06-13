<?php
declare(strict_types=1);

/**
 * AI-visibility data collector — v1 implementation of PGM_Data_Collector.
 *
 * Reads the pgm_ai_visibility table and returns structured data for:
 * - Trendline: per-run visibility score (% cited, position-weighted).
 * - Standings: per-peptide latest cited status, our position, competing domains.
 * - Run metadata: last run time, MTD cost vs cap.
 *
 * Score formula (documented in CONTEXT.md):
 *   base_score = cited_probes / total_probes  (range 0–1)
 *   position_bonus = Σ (1 / our_position) for cited probes / total_probes
 *   visibility_score = round((base_score + position_bonus) / 2, 4)
 *
 * Who triggers: PGM_Admin_Page calls the registered collector via the registry.
 * Dependencies: $wpdb, PGM_Table_Manager, PGM_Cost_Ledger, PGM_Config.
 *
 * @see interface-pgm-data-collector.php  — Interface this implements.
 * @see class-pgm-ai-visibility-panel.php — Renders the data returned here.
 * @see CONTEXT.md                        — Score formula + glossary.
 * @see ARCHITECTURE.md                   — §Storage, §Score.
 * @package PeptideGeoMonitor
 */
class PGM_Ai_Visibility_Collector implements PGM_Data_Collector {

	/**
	 * @var PGM_Cost_Ledger
	 */
	private PGM_Cost_Ledger $ledger;

	/**
	 * @param PGM_Cost_Ledger|null $ledger Injected for testing.
	 */
	public function __construct( ?PGM_Cost_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new PGM_Cost_Ledger();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{
	 *     trendline: array<int, array{run_id: string, captured_at: string, score: float}>,
	 *     standings: array<string, array{label: string, cited: bool, our_position: int|null, top_domains: string[], model_count: int}>,
	 *     last_run_at: string|null,
	 *     mtd_cost_usd: float,
	 *     monthly_cap_usd: float,
	 * }
	 */
	public function collect(): array {
		global $wpdb;

		$table = PGM_Table_Manager::get_table_name();

		// ── Trendline: one score per run_id ────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$run_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT run_id, MIN(captured_at) AS captured_at,
			        SUM(cited) AS cited_count,
			        COUNT(*) AS total_count,
			        SUM(CASE WHEN our_position IS NOT NULL THEN 1.0/our_position ELSE 0 END) AS position_sum
			 FROM {$table}
			 GROUP BY run_id
			 ORDER BY MIN(captured_at) ASC
			 LIMIT 52",
			ARRAY_A
		);

		$trendline = array();
		foreach ( (array) $run_rows as $row ) {
			$trendline[] = array(
				'run_id'      => (string) $row['run_id'],
				'captured_at' => (string) $row['captured_at'],
				'score'       => $this->compute_score(
					(int) $row['cited_count'],
					(int) $row['total_count'],
					(float) $row['position_sum']
				),
			);
		}

		// ── Standings: latest result per peptide ────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$standing_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT peptide_slug, peptide_label,
			        MAX(cited) AS cited,
			        MIN(CASE WHEN cited=1 THEN our_position ELSE NULL END) AS our_position,
			        GROUP_CONCAT(DISTINCT source_domains ORDER BY captured_at DESC SEPARATOR '|||') AS domains_json_list,
			        COUNT(DISTINCT model) AS model_count
			 FROM {$table}
			 WHERE run_id = (SELECT run_id FROM {$table} ORDER BY captured_at DESC LIMIT 1)
			 GROUP BY peptide_slug, peptide_label",
			ARRAY_A
		);

		$standings = array();
		foreach ( (array) $standing_rows as $row ) {
			$top_domains = $this->extract_top_domains( (string) ( $row['domains_json_list'] ?? '' ) );
			$standings[ (string) $row['peptide_slug'] ] = array(
				'label'        => (string) $row['peptide_label'],
				'cited'        => (bool) (int) $row['cited'],
				'our_position' => null !== $row['our_position'] ? (int) $row['our_position'] : null,
				'top_domains'  => $top_domains,
				'model_count'  => (int) $row['model_count'],
			);
		}

		// ── Run metadata ────────────────────────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last_run = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MAX(captured_at) FROM {$table}"
		);

		return array(
			'trendline'       => $trendline,
			'standings'       => $standings,
			'last_run_at'     => $last_run ? (string) $last_run : null,
			'mtd_cost_usd'    => $this->ledger->get_month_to_date_usd(),
			'monthly_cap_usd' => PGM_Config::get_monthly_budget_usd(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_key(): string {
		return 'ai_visibility';
	}

	/**
	 * Compute the visibility score from a run's aggregate stats.
	 *
	 * See CONTEXT.md for the full formula explanation.
	 *
	 * @param int   $cited_count   Number of probes where we were cited.
	 * @param int   $total_count   Total probes in the run.
	 * @param float $position_sum  Sum of (1/our_position) for cited probes.
	 *
	 * @return float Visibility score in range [0, 1].
	 */
	public function compute_score( int $cited_count, int $total_count, float $position_sum ): float {
		if ( 0 === $total_count ) {
			return 0.0;
		}
		$base_score      = $cited_count / $total_count;
		$position_bonus  = $position_sum / $total_count;
		return round( ( $base_score + $position_bonus ) / 2.0, 4 );
	}

	/**
	 * Extract and deduplicate the top domain names from a GROUP_CONCAT result.
	 *
	 * @param string $domains_json_list Pipe-separated JSON arrays from GROUP_CONCAT.
	 *
	 * @return string[] Up to 5 top domains.
	 */
	private function extract_top_domains( string $domains_json_list ): array {
		if ( '' === $domains_json_list ) {
			return array();
		}

		$all = array();
		$chunks = explode( '|||', $domains_json_list );
		foreach ( $chunks as $chunk ) {
			$decoded = json_decode( $chunk, true );
			if ( is_array( $decoded ) ) {
				$all = array_merge( $all, $decoded );
			}
		}

		$counts = array_count_values( $all );
		arsort( $counts );
		$top = array_slice( array_keys( $counts ), 0, 5 );
		return array_values( array_filter( $top, fn( $d ) => PGM_TARGET_DOMAIN !== $d ) );
	}
}
