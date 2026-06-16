<?php
/**
 * AI-visibility data collector for the PR Vision dashboard.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * AI-visibility data collector — v1 implementation of PRV_Data_Collector.
 *
 * Reads the prv_ai_visibility table and returns structured data for:
 * - Trendline: per-run visibility score (% cited, position-weighted) with config_version stamp.
 * - Standings: per-peptide latest cited status, our position, competing domains.
 * - Run metadata: last run time, MTD cost vs cap.
 * - Per-model run-health from PRV_Model_Registry (last_run_counts).
 * - All config-version records for trendline break annotations (config_versions).
 *
 * Score formula (documented in CONTEXT.md):
 *   base_score = cited_probes / total_probes  (range 0–1)
 *   position_bonus = Σ (1 / our_position) for cited probes / total_probes
 *   visibility_score = round((base_score + position_bonus) / 2, 4)
 *
 * Who triggers: PRV_Admin_Page calls the registered collector via the registry.
 * Dependencies: $wpdb, PRV_Table_Manager, PRV_Cost_Ledger, PRV_Config,
 *               PRV_Model_Registry, PRV_Config_Version.
 *
 * @see interface-prv-data-collector.php  — Interface this implements.
 * @see class-prv-ai-visibility-panel.php — Renders the data returned here.
 * @see class-prv-model-registry.php      — Source of per-model health data.
 * @see class-prv-config-version.php      — Source of config-version records.
 * @see CONTEXT.md                        — Score formula + glossary.
 * @see ARCHITECTURE.md                   — §Storage, §Score.
 * @package PrVision
 */
class PRV_Ai_Visibility_Collector implements PRV_Data_Collector {

	/**
	 * Budget ledger for MTD cost lookup.
	 *
	 * @var PRV_Cost_Ledger
	 */
	private PRV_Cost_Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param PRV_Cost_Ledger|null $ledger Injected for testing.
	 */
	public function __construct( ?PRV_Cost_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new PRV_Cost_Ledger();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{
	 *     trendline: array<int, array{run_id: string, captured_at: string, score: float, config_version: int|null}>,
	 *     standings: array<string, array{label: string, cited: bool, our_position: int|null, top_domains: string[], model_count: int}>,
	 *     last_run_at: string|null,
	 *     mtd_cost_usd: float,
	 *     monthly_cap_usd: float,
	 *     last_run_counts: array<string, array{health_status: string, health_probed: int, health_errors: int}>,
	 *     config_versions: array<int, array<string, mixed>>,
	 * }
	 */
	public function collect(): array {
		global $wpdb;

		$table = PRV_Table_Manager::get_table_name();

		// ── Trendline: one score per run_id, with config_version ───────
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$run_rows = $wpdb->get_results(
			"SELECT run_id, MIN(captured_at) AS captured_at,
			        SUM(cited) AS cited_count,
			        COUNT(*) AS total_count,
			        SUM(CASE WHEN our_position IS NOT NULL THEN 1.0/our_position ELSE 0 END) AS position_sum,
			        MIN(config_version) AS config_version
			 FROM {$table}
			 GROUP BY run_id
			 ORDER BY MIN(captured_at) ASC
			 LIMIT 52",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$trendline = array();
		foreach ( (array) $run_rows as $row ) {
			$trendline[] = array(
				'run_id'         => (string) $row['run_id'],
				'captured_at'    => (string) $row['captured_at'],
				'score'          => $this->compute_score(
					(int) $row['cited_count'],
					(int) $row['total_count'],
					(float) $row['position_sum']
				),
				'config_version' => isset( $row['config_version'] ) ? (int) $row['config_version'] : null,
			);
		}

		// ── Standings: latest result per peptide ────────────────────────
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$standing_rows = $wpdb->get_results(
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$standings = array();
		foreach ( (array) $standing_rows as $row ) {
			$top_domains                                = $this->extract_top_domains( (string) ( $row['domains_json_list'] ?? '' ) );
			$standings[ (string) $row['peptide_slug'] ] = array(
				'label'        => (string) $row['peptide_label'],
				'cited'        => (bool) (int) $row['cited'],
				'our_position' => null !== $row['our_position'] ? (int) $row['our_position'] : null,
				'top_domains'  => $top_domains,
				'model_count'  => (int) $row['model_count'],
			);
		}

		// ── Run metadata ────────────────────────────────────────────────
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last_run = $wpdb->get_var( "SELECT MAX(captured_at) FROM {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// ── Per-model health from PRV_Model_Registry ────────────────────
		$last_run_counts = $this->collect_model_health();

		// ── Config-version records for trendline break annotations ──────
		$config_versions = PRV_Config_Version::get_all_versions();

		return array(
			'trendline'       => $trendline,
			'standings'       => $standings,
			'last_run_at'     => $last_run ? (string) $last_run : null,
			'mtd_cost_usd'    => $this->ledger->get_month_to_date_usd(),
			'monthly_cap_usd' => PRV_Config::get_monthly_budget_usd(),
			'last_run_counts' => $last_run_counts,
			'config_versions' => $config_versions,
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
		$base_score     = $cited_count / $total_count;
		$position_bonus = $position_sum / $total_count;
		return round( ( $base_score + $position_bonus ) / 2.0, 4 );
	}

	/**
	 * Collect per-model health data from PRV_Model_Registry for the dashboard pill.
	 *
	 * Returns a keyed map of model slug -> health fields. Models with
	 * health_status='disabled' are included so the pill reflects all registered
	 * models, not just the active ones.
	 *
	 * @return array<string, array{health_status: string, health_probed: int, health_errors: int}>
	 */
	private function collect_model_health(): array {
		$models = PRV_Model_Registry::get_all();
		$out    = array();
		foreach ( $models as $m ) {
			$slug = (string) ( $m['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'health_status' => (string) ( $m['health_status'] ?? 'unknown' ),
				'health_probed' => (int) ( $m['health_probed'] ?? 0 ),
				'health_errors' => (int) ( $m['health_errors'] ?? 0 ),
			);
		}
		return $out;
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

		$all    = array();
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
		return array_values( array_filter( $top, fn( $d ) => PRV_TARGET_DOMAIN !== $d ) );
	}
}
