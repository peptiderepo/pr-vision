<?php
/**
 * Inner probe loop executor split from PRV_Probe_Runner (v0.3.0).
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Executes one probe run's inner loop: peptides × intents × models.
 * P0: can_afford → probe → persist → update_cost → write_meta → [swallowed] capture_io.
 *
 * @see class-prv-capture-writer.php — Allowlist-only capture writer (P0).
 * @package PrVision
 */
class PRV_Probe_Run_Executor {
	/**
	 * Budget ledger for cost-cap enforcement.
	 *
	 * @var PRV_Cost_Ledger
	 */
	private PRV_Cost_Ledger $ledger;
	/**
	 * Per-call capture writer (best-effort; never in critical path).
	 *
	 * @var PRV_Capture_Writer
	 */
	private PRV_Capture_Writer $capture;

	/**
	 * Constructor.
	 *
	 * @param PRV_Cost_Ledger    $ledger  Budget ledger.
	 * @param PRV_Capture_Writer $capture Capture writer.
	 */
	public function __construct( PRV_Cost_Ledger $ledger, PRV_Capture_Writer $capture ) {
		$this->ledger  = $ledger;
		$this->capture = $capture;
	}

	/**
	 * Run the full peptide × intent × model loop.
	 *
	 * @param string $run_id     UUID for this run.
	 * @param int    $config_ver Config version.
	 * @return array{probed: int, skipped_budget: int, skipped_error: int, truncated: bool, run_id: string}
	 */
	public function execute(
		string $run_id,
		int $config_ver
	): array {
		$peptides       = PRV_Config::get_peptides();
		$intents        = PRV_Config::get_prompt_intents();
		$models         = PRV_Config::get_models();
		$model_outcomes = array();

		foreach ( $models as $slug ) {
			// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$model_outcomes[ $slug ] = array( 'probed' => 0, 'errors' => 0 );
		}

		$counts = array(
			'probed'         => 0,
			'skipped_budget' => 0,
			'skipped_error'  => 0,
			'truncated'      => false,
			'run_id'         => $run_id,
		);

		$budget_hit = false;

		foreach ( $peptides as $peptide ) {
			foreach ( $intents as $intent_tpl ) {
				$query = str_replace( '{peptide}', $peptide['label'], $intent_tpl );
				foreach ( $models as $model ) {
					$this->probe_one( $run_id, $peptide, $model, $intent_tpl, $query, $config_ver, $counts, $model_outcomes, $budget_hit );
				}
			}
		}

		if ( $budget_hit && $counts['probed'] > 0 ) {
			$counts['truncated'] = true;
		}

		update_option( 'prv_last_run_truncated', $counts['truncated'] ? 1 : 0 );
		if ( $counts['truncated'] ) {
			update_option( 'prv_last_run_truncated_at', current_time( 'mysql', true ) );
		}

		update_option( 'prv_last_run_at', current_time( 'mysql', true ) );
		update_option( 'prv_last_run_counts', $counts );

		PRV_Model_Registry::update_health( $run_id, $model_outcomes );
		$this->settle_api_key_status( $counts, $model_outcomes );

		return $counts;
	}

	/**
	 * Execute one probe combination: can_afford → probe → persist → capture (P0 order).
	 *
	 * @param string               $run_id         Run UUID.
	 * @param array<string,string> $peptide        Peptide config.
	 * @param string               $model          Model slug.
	 * @param string               $intent_tpl     Intent template.
	 * @param string               $query          Rendered query string.
	 * @param int                  $config_ver     Config version.
	 * @param array<string,mixed>  &$counts        Counts accumulator.
	 * @param array<string,array>  &$model_outcomes Per-model outcome map.
	 * @param bool                 &$budget_hit    Budget exhaustion flag.
	 * @return void
	 */
	private function probe_one(
		string $run_id,
		array $peptide,
		string $model,
		string $intent_tpl,
		string $query,
		int $config_ver,
		array &$counts,
		array &$model_outcomes,
		bool &$budget_hit
	): void {
		$provider = $this->resolve_provider( $model );

		if ( null === $provider || ! $provider->is_configured() ) {
			++$counts['skipped_error'];
			if ( isset( $model_outcomes[ $model ] ) ) {
				++$model_outcomes[ $model ]['errors'];
			}
			return;
		}

		$estimated = $this->get_estimated_cost( $model );
		if ( ! $this->ledger->can_afford( $estimated ) ) {
			++$counts['skipped_budget'];
			$budget_hit = true;
			return;
		}

		$start_ms = (int) round( microtime( true ) * 1000 );

		try {
			$result      = $provider->probe( $query );
			$latency_ms  = (int) round( microtime( true ) * 1000 ) - $start_ms;
			$http_status = 200;
		} catch ( \Exception $e ) {
			$latency_ms  = (int) round( microtime( true ) * 1000 ) - $start_ms;
			$http_status = $this->extract_http_status( $e );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'PRV: probe failed [%s / %s]: %s', $model, $peptide['slug'], $e->getMessage() ) );
			++$counts['skipped_error'];
			if ( isset( $model_outcomes[ $model ] ) ) {
				++$model_outcomes[ $model ]['errors'];
			}
			// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine, Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter
			$cid = $this->capture->write_meta(
				array(
					'visibility_row' => null,            'run_id' => $run_id,
					'peptide_slug'   => $peptide['slug'], 'model' => $model,
					'intent_label'   => $intent_tpl,     'tokens_in' => null,
					'tokens_out'     => null,            'cost_usd' => 0.0,
					'latency_ms'     => $latency_ms,     'cited' => null,
					'http_status'    => $http_status,    'config_version' => $config_ver,
				)
			); // phpcs:enable
			if ( $cid > 0 ) {
				try {
					$this->capture->write_io( $cid, $query, '' );
				} catch ( \Throwable $e2 ) {
					// Best-effort: I/O capture failure must not propagate (P0-4).
					unset( $e2 );
				}
			}
			return;
		}

		$row_id = $this->persist_result( $run_id, $peptide, $model, $intent_tpl, $result, $config_ver );
		if ( $row_id > 0 ) {
			$this->ledger->update_row_cost( $row_id, $result->get_cost_usd() );
		}

		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine, Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter
		$cid = $this->capture->write_meta(
			array(
				'visibility_row' => $row_id > 0 ? $row_id : null, 'run_id' => $run_id,
				'peptide_slug'   => $peptide['slug'],             'model' => $model,
				'intent_label'   => $intent_tpl,     'tokens_in' => $result->get_tokens_in(),
				'tokens_out'     => $result->get_tokens_out(),    'cost_usd' => $result->get_cost_usd(),
				'latency_ms'     => $latency_ms,     'cited' => $result->is_cited() ? 1 : 0,
				'http_status'    => $http_status,    'config_version' => $config_ver,
			)
		); // phpcs:enable
		if ( $cid > 0 ) {
			try {
				$this->capture->write_io( $cid, $query, $result->get_raw_excerpt() );
			} catch ( \Throwable $e2 ) {
				// Best-effort: I/O capture failure must not propagate (P0-4).
				unset( $e2 );
			}
		}

		++$counts['probed'];
		if ( isset( $model_outcomes[ $model ] ) ) {
			++$model_outcomes[ $model ]['probed'];
		}
	}

	/**
	 * Persist one probe result to prv_ai_visibility.
	 *
	 * @param string               $run_id     Run UUID.
	 * @param array<string,string> $peptide    Peptide config.
	 * @param string               $model      Model slug.
	 * @param string               $intent_tpl Intent template.
	 * @param PRV_Probe_Result     $result     Probe result.
	 * @param int                  $config_ver Config version.
	 * @return int Row ID, or 0 on failure.
	 */
	private function persist_result( string $run_id, array $peptide, string $model, string $intent_tpl, PRV_Probe_Result $result, int $config_ver ): int {
		global $wpdb;
		$table = PRV_Table_Manager::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'run_id'         => $run_id,
				'captured_at'    => current_time( 'mysql', true ),
				'peptide_slug'   => $peptide['slug'],
				'peptide_label'  => $peptide['label'],
				'model'          => $model,
				'prompt_intent'  => $intent_tpl,
				'cited'          => $result->is_cited() ? 1 : 0,
				'our_position'   => $result->get_our_position(),
				'source_domains' => wp_json_encode( $result->get_source_domains() ),
				'raw_excerpt'    => $result->get_raw_excerpt(),
				'cost_usd'       => number_format( $result->get_cost_usd(), 8, '.', '' ),
				'config_version' => $config_ver,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Extract HTTP status from a gateway exception message.
	 *
	 * @param \Exception $e Caught exception.
	 * @return int HTTP status, or 0 if unknown.
	 */
	private function extract_http_status( \Exception $e ): int {
		if ( preg_match( '/PR Vision gateway HTTP (\d+)/', $e->getMessage(), $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Resolve provider instance for a model slug.
	 *
	 * @param string $model Model slug.
	 * @return PRV_Probe_Provider|null Provider, or null if not configured.
	 */
	private function resolve_provider( string $model ): ?PRV_Probe_Provider {
		if ( 'perplexity/sonar' === $model ) {
			return new PRV_Perplexity_Provider();
		}
		return new PRV_OpenRouter_Provider( $model );
	}

	/**
	 * Conservative per-call cost estimate for budget pre-check.
	 *
	 * @param string $model Model slug.
	 * @return float Estimated USD per call.
	 */
	private function get_estimated_cost( string $model ): float {
		if ( 'perplexity/sonar' === $model ) {
			return PRV_Perplexity_Provider::ESTIMATED_COST_PER_PROBE;
		}
		return PRV_OpenRouter_Provider::ESTIMATED_COST_PER_PROBE;
	}

	/**
	 * Write the API-key status option based on run outcomes.
	 *
	 * @param array<string,mixed>                        $counts         Run counts.
	 * @param array<string,array{probed:int,errors:int}> $model_outcomes Model outcomes.
	 * @return void
	 */
	private function settle_api_key_status( array $counts, array $model_outcomes ): void {
		if ( $counts['probed'] > 0 ) {
			update_option( 'prv_api_key_status', 'ok' );
		} elseif ( $counts['skipped_error'] > 0 && 0 === $counts['probed'] ) {
			update_option( 'prv_api_key_status', 'failed' );
		}
		update_option( 'prv_api_key_last_check', current_time( 'mysql', true ) );
	}
}
