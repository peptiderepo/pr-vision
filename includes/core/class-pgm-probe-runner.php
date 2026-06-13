<?php
declare(strict_types=1);

/**
 * Orchestrates a full probe run: peptides × intents × models.
 *
 * For each combination the runner:
 * 1. Checks the monthly budget cap (aborts gracefully when hit).
 * 2. Caches responses for identical (peptide, intent, model) within the run.
 * 3. Dispatches to the correct provider (resolved from PGM_Config::get_models()).
 * 4. Writes a row to the pgm_ai_visibility table.
 * 5. Settles the actual cost against the ledger row.
 *
 * Who triggers: PGM_Cron::handle_cron_tick() and PGM_Admin_Page (Run now action).
 * Dependencies: PGM_Config, PGM_Cost_Ledger, PGM_Table_Manager, all providers.
 *
 * @see class-pgm-cron.php             — Calls run() on the weekly schedule.
 * @see class-pgm-cost-ledger.php      — Budget cap enforcement.
 * @see class-pgm-perplexity-provider.php
 * @see class-pgm-openrouter-provider.php
 * @see ARCHITECTURE.md                — §Probe run flow.
 * @package PeptideGeoMonitor
 */
class PGM_Probe_Runner {

	/**
	 * @var PGM_Cost_Ledger
	 */
	private PGM_Cost_Ledger $ledger;

	/**
	 * In-run response cache: key = "{peptide_slug}|{intent}|{model}" → PGM_Probe_Result.
	 *
	 * @var array<string, PGM_Probe_Result>
	 */
	private array $cache = array();

	/**
	 * @param PGM_Cost_Ledger|null $ledger Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PGM_Cost_Ledger $ledger = null ) {
		$this->ledger = $ledger ?? new PGM_Cost_Ledger();
	}

	/**
	 * Execute the full probe run.
	 *
	 * Iterates peptides × prompt intents × models. Aborts at the budget cap
	 * without throwing — partial results are kept. Each successful probe is
	 * persisted immediately so a mid-run failure doesn't lose data.
	 *
	 * Side effects: HTTP calls to LLM APIs, database writes.
	 *
	 * @return array{probed: int, skipped_budget: int, skipped_error: int}
	 *         Summary counts for the admin log.
	 */
	public function run(): array {
		$run_id   = $this->generate_run_id();
		$peptides = PGM_Config::get_peptides();
		$intents  = PGM_Config::get_prompt_intents();
		$models   = PGM_Config::get_models();

		$counts = array(
			'probed'          => 0,
			'skipped_budget'  => 0,
			'skipped_error'   => 0,
		);

		foreach ( $peptides as $peptide ) {
			foreach ( $intents as $intent_tpl ) {
				$query = str_replace( '{peptide}', $peptide['label'], $intent_tpl );

				foreach ( $models as $model ) {
					$provider = $this->resolve_provider( $model );

					if ( null === $provider || ! $provider->is_configured() ) {
						$counts['skipped_error']++;
						continue;
					}

					// Budget pre-check.
					$estimated = $this->get_estimated_cost( $model );
					if ( ! $this->ledger->can_afford( $estimated ) ) {
						$counts['skipped_budget']++;
						continue;
					}

					// In-run cache check.
					$cache_key = $peptide['slug'] . '|' . $intent_tpl . '|' . $model;
					if ( isset( $this->cache[ $cache_key ] ) ) {
						$result = $this->cache[ $cache_key ];
					} else {
						try {
							$result = $provider->probe( $query );
							$this->cache[ $cache_key ] = $result;
						} catch ( \Exception $e ) {
							error_log( sprintf( 'PGM: probe failed [%s / %s]: %s', $model, $peptide['slug'], $e->getMessage() ) );
							$counts['skipped_error']++;
							continue;
						}
					}

					$row_id = $this->persist_result( $run_id, $peptide, $model, $intent_tpl, $result );
					if ( $row_id > 0 ) {
						$this->ledger->update_row_cost( $row_id, $result->get_cost_usd() );
					}

					$counts['probed']++;
				}
			}
		}

		$this->cache = array(); // Reset cache after run.
		return $counts;
	}

	/**
	 * Persist a single probe result to the database.
	 *
	 * @param string               $run_id      UUID for this run.
	 * @param array{slug:string, label:string} $peptide Peptide config.
	 * @param string               $model       Model identifier.
	 * @param string               $intent_tpl  Raw intent template string.
	 * @param PGM_Probe_Result     $result      Probe result.
	 *
	 * @return int Inserted row ID, or 0 on failure.
	 */
	private function persist_result( string $run_id, array $peptide, string $model, string $intent_tpl, PGM_Probe_Result $result ): int {
		global $wpdb;

		$table = PGM_Table_Manager::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'run_id'        => $run_id,
				'captured_at'   => current_time( 'mysql', true ),
				'peptide_slug'  => $peptide['slug'],
				'peptide_label' => $peptide['label'],
				'model'         => $model,
				'prompt_intent' => $intent_tpl,
				'cited'         => $result->is_cited() ? 1 : 0,
				'our_position'  => $result->get_our_position(),
				'source_domains' => wp_json_encode( $result->get_source_domains() ),
				'raw_excerpt'   => $result->get_raw_excerpt(),
				'cost_usd'      => number_format( $result->get_cost_usd(), 8, '.', '' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Instantiate the correct provider class for a model string.
	 *
	 * @param string $model OpenRouter model identifier.
	 *
	 * @return PGM_Probe_Provider|null Null when the model is unrecognised.
	 */
	private function resolve_provider( string $model ): ?PGM_Probe_Provider {
		if ( 'perplexity/sonar' === $model ) {
			return new PGM_Perplexity_Provider();
		}
		// All other models route through the generic OpenRouter provider.
		return new PGM_OpenRouter_Provider( $model );
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
			return PGM_Perplexity_Provider::ESTIMATED_COST_PER_PROBE;
		}
		return PGM_OpenRouter_Provider::ESTIMATED_COST_PER_PROBE;
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
