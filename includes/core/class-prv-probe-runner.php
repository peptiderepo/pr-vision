<?php
/**
 * Orchestrates a full probe run across peptides, intents, and models.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Orchestrates a full probe run: peptides x intents x models.
 *
 * For each combination the runner:
 * 1. Acquires the run-lock (fails fast if already locked).
 * 2. Checks the monthly budget cap (aborts gracefully when hit).
 * 3. Dispatches to the correct provider (resolved from PRV_Model_Registry).
 * 4. Writes a row to the prv_ai_visibility table with config_version stamp.
 * 5. Settles the actual cost against the ledger row.
 * 6. After the run, updates per-model health and releases the lock.
 *
 * Who triggers: PRV_Cron::handle_cron_tick() and PRV_Settings_Page (Run now).
 * Dependencies: PRV_Config, PRV_Cost_Ledger, PRV_Table_Manager,
 *               PRV_Run_Lock, PRV_Model_Registry, PRV_Config_Version, all providers.
 *
 * @see class-prv-cron.php          -- Calls run() on the weekly schedule.
 * @see class-prv-cost-ledger.php   -- Budget cap enforcement.
 * @see class-prv-run-lock.php      -- Concurrency guard.
 * @see class-prv-model-registry.php -- Per-model health updates.
 * @see ARCHITECTURE.md             -- Section Probe run flow.
 * @package PrVision
 */
class PRV_Probe_Runner {

	/**
	 * Budget ledger for cost-cap enforcement.
	 *
	 * @var PRV_Cost_Ledger
	 */
	private PRV_Cost_Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param PRV_Cost_Ledger|null $ledger Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PRV_Cost_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new PRV_Cost_Ledger();
	}

	/**
	 * Execute the full probe run.
	 *
	 * Acquires a run-lock so the cron and "Run now" cannot collide.
	 * Iterates peptides x prompt intents x models. Aborts at the budget cap
	 * without throwing -- partial results are kept. Each successful probe is
	 * persisted immediately so a mid-run failure doesn't lose data.
	 *
	 * Side effects: HTTP calls to LLM APIs, database writes, option writes (health).
	 *
	 * @return array{probed: int, skipped_budget: int, skipped_error: int, truncated: bool, run_id: string}
	 *         Summary counts plus truncation flag for the admin display.
	 */
	public function run(): array {
		$counts = array(
			'probed'         => 0,
			'skipped_budget' => 0,
			'skipped_error'  => 0,
			'truncated'      => false,
			'run_id'         => '',
		);

		if ( ! PRV_Run_Lock::acquire() ) {
			$counts['skipped_error'] = -1; // Sentinel: lock busy.
			return $counts;
		}

		try {
			$counts = $this->execute_run();
		} finally {
			PRV_Run_Lock::release();
		}

		return $counts;
	}

	/**
	 * Core run logic (called inside the lock).
	 *
	 * @return array{probed: int, skipped_budget: int, skipped_error: int, truncated: bool, run_id: string}
	 */
	private function execute_run(): array {
		$run_id        = $this->generate_run_id();
		$config_ver    = PRV_Config_Version::get_active_version();
		$peptides      = PRV_Config::get_peptides();
		$intents       = PRV_Config::get_prompt_intents();
		$models        = PRV_Config::get_models();
		$model_outcomes = array(); // Per-slug: {probed, errors}.

		foreach ( $models as $slug ) {
			$model_outcomes[ $slug ] = array(
				'probed' => 0,
				'errors' => 0,
			);
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
					$provider = $this->resolve_provider( $model );

					if ( null === $provider || ! $provider->is_configured() ) {
						++$counts['skipped_error'];
						if ( isset( $model_outcomes[ $model ] ) ) {
							++$model_outcomes[ $model ]['errors'];
						}
						continue;
					}

					// Budget pre-check.
					$estimated = $this->get_estimated_cost( $model );
					if ( ! $this->ledger->can_afford( $estimated ) ) {
						++$counts['skipped_budget'];
						$budget_hit = true;
						continue;
					}

					try {
						$result = $provider->probe( $query );
					} catch ( \Exception $e ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( sprintf( 'PRV: probe failed [%s / %s]: %s', $model, $peptide['slug'], $e->getMessage() ) );
						++$counts['skipped_error'];
						if ( isset( $model_outcomes[ $model ] ) ) {
							++$model_outcomes[ $model ]['errors'];
						}
						continue;
					}

					$row_id = $this->persist_result( $run_id, $peptide, $model, $intent_tpl, $result, $config_ver );
					if ( $row_id > 0 ) {
						$this->ledger->update_row_cost( $row_id, $result->get_cost_usd() );
					}

					++$counts['probed'];
					if ( isset( $model_outcomes[ $model ] ) ) {
						++$model_outcomes[ $model ]['probed'];
					}
				}
			}
		}

		if ( $budget_hit && $counts['probed'] > 0 ) {
			$counts['truncated'] = true;
		}

		// Record truncation state for dashboard display.
		if ( $counts['truncated'] ) {
			update_option( 'prv_last_run_truncated', 1 );
			update_option( 'prv_last_run_truncated_at', current_time( 'mysql', true ) );
		} else {
			update_option( 'prv_last_run_truncated', 0 );
		}

		// Persist run summary for dashboard.
		update_option( 'prv_last_run_at', current_time( 'mysql', true ) );
		update_option( 'prv_last_run_counts', $counts );

		// Update per-model run-health.
		PRV_Model_Registry::update_health( $run_id, $model_outcomes );

		// Update API-key status (derive from whether probes succeeded).
		$this->update_api_key_status( $counts, $model_outcomes );

		return $counts;
	}

	/**
	 * Persist a single probe result to the database with config_version stamp.
	 *
	 * @param string               $run_id     UUID for this run.
	 * @param array<string,string> $peptide    Peptide config with slug and label.
	 * @param string               $model      Model identifier.
	 * @param string               $intent_tpl Raw intent template string.
	 * @param PRV_Probe_Result     $result     Probe result.
	 * @param int                  $config_ver Config version at run time.
	 *
	 * @return int Inserted row ID, or 0 on failure.
	 */
	private function persist_result(
		string $run_id,
		array $peptide,
		string $model,
		string $intent_tpl,
		PRV_Probe_Result $result,
		int $config_ver
	): int {
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
	 * Derive + persist the API-key status from run outcomes.
	 *
	 * @param array<string, mixed>                           $counts         Run counts.
	 * @param array<string, array{probed: int, errors: int}> $model_outcomes Model outcomes.
	 *
	 * @return void
	 */
	private function update_api_key_status( array $counts, array $model_outcomes ): void {
		if ( $counts['probed'] > 0 ) {
			update_option( 'prv_api_key_status', 'ok' );
		} elseif ( $counts['skipped_error'] > 0 && 0 === $counts['probed'] ) {
			update_option( 'prv_api_key_status', 'failed' );
		}
		update_option( 'prv_api_key_last_check', current_time( 'mysql', true ) );
	}

	/**
	 * Instantiate the correct provider class for a model string.
	 *
	 * @param string $model OpenRouter model identifier.
	 *
	 * @return PRV_Probe_Provider|null Null when the model is unrecognised.
	 */
	private function resolve_provider( string $model ): ?PRV_Probe_Provider {
		if ( 'perplexity/sonar' === $model ) {
			return new PRV_Perplexity_Provider();
		}
		// All other models route through the generic OpenRouter provider.
		return new PRV_OpenRouter_Provider( $model );
	}

	/**
	 * Conservative per-call cost estimate used for the budget pre-check.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return float Estimated USD.
	 */
	private function get_estimated_cost( string $model ): float {
		if ( 'perplexity/sonar' === $model ) {
			return PRV_Perplexity_Provider::ESTIMATED_COST_PER_PROBE;
		}
		return PRV_OpenRouter_Provider::ESTIMATED_COST_PER_PROBE;
	}

	/**
	 * Generate a UUID v4 run identifier.
	 *
	 * @return string
	 */
	private function generate_run_id(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}
}
