<?php
/**
 * Configuration getters and default-seeding for PR Vision.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Configuration helper: seed defaults and retrieve plugin settings.
 *
 * All mutable settings live in wp_options under the prv_ prefix. This class
 * provides typed getters (no magic values in call sites) and seeds the initial
 * defaults on first activation.  Models are delegated to PRV_Model_Registry
 * which owns the v2 rich-object format; get_models() returns the flat slug
 * array for backward compat with PRV_Probe_Runner.
 *
 * Who triggers: PRV_Activator::activate() for seeding; every component that
 *               needs a setting reads through here.
 * Dependencies: get_option(), update_option(), PRV_Model_Registry.
 *
 * @see CONTEXT.md          -- Domain glossary: peptides, prompt intents, models.
 * @see ARCHITECTURE.md     -- Section Config (options + defaults).
 * @see class-prv-model-registry.php -- Owns prv_models v2 format.
 * @package PrVision
 */
class PRV_Config {

	/**
	 * Cadence option key.
	 */
	const CADENCE_KEY = 'prv_cadence';

	/**
	 * Seed the default option values on first activation.
	 *
	 * Uses add_option() so existing values are never overwritten on re-activation.
	 *
	 * Side effects: Writes multiple wp_options rows on first run.
	 *
	 * @return void
	 */
	public static function seed_defaults(): void {
		add_option( 'prv_monthly_budget_usd', PRV_DEFAULT_MONTHLY_BUDGET_USD );
		add_option( 'prv_peptides', self::default_peptides() );
		add_option( 'prv_prompt_intents', self::default_prompt_intents() );
		add_option( self::CADENCE_KEY, 'weekly' );
		// Note: prv_models seeded by PRV_Model_Registry::run_migration_v2() via
		// PRV_Upgrader::run() -- not here -- so the v2 schema is always in place.
	}

	/**
	 * Get the monthly budget cap in USD.
	 *
	 * @return float Positive float; falls back to the plugin default.
	 */
	public static function get_monthly_budget_usd(): float {
		$val = (float) get_option( 'prv_monthly_budget_usd', PRV_DEFAULT_MONTHLY_BUDGET_USD );
		return $val > 0.0 ? $val : PRV_DEFAULT_MONTHLY_BUDGET_USD;
	}

	/**
	 * Get the list of peptide slugs/labels to probe.
	 *
	 * Each element is an associative array with 'slug' and 'label' keys.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function get_peptides(): array {
		$raw = get_option( 'prv_peptides', self::default_peptides() );
		return is_array( $raw ) ? $raw : self::default_peptides();
	}

	/**
	 * Get the prompt intent templates.
	 *
	 * Each string may contain {peptide} as a placeholder.
	 *
	 * @return array<int, string>
	 */
	public static function get_prompt_intents(): array {
		$raw = get_option( 'prv_prompt_intents', self::default_prompt_intents() );
		return is_array( $raw ) ? $raw : self::default_prompt_intents();
	}

	/**
	 * Get the enabled model slugs (flat array, backward compat).
	 *
	 * Delegates to PRV_Model_Registry which owns the v2 model format.
	 *
	 * @return array<int, string>
	 */
	public static function get_models(): array {
		return PRV_Model_Registry::get_enabled_slugs();
	}

	/**
	 * Get the probe cadence identifier.
	 *
	 * @return string WP-Cron recurrence name (e.g. 'weekly', 'daily').
	 */
	public static function get_cadence(): string {
		$cadence = (string) get_option( self::CADENCE_KEY, 'weekly' );
		return in_array( $cadence, array( 'weekly', 'daily', 'twicedaily' ), true ) ? $cadence : 'weekly';
	}

	/**
	 * Compute the projected per-run and per-month cost.
	 *
	 * Formula: enabled_models x peptides x intents x estimated_cost_per_probe x cadence_runs_per_month.
	 * Uses conservative high-end estimates to never understate cost.
	 *
	 * @return array{per_run_usd: float, per_month_usd: float, probe_count: int, over_cap: bool}
	 */
	public static function get_projected_cost(): array {
		$models        = PRV_Model_Registry::get_all();
		$enabled_count = count( array_filter( $models, static fn( $m ) => ! empty( $m['enabled'] ) ) );
		$peptide_count = count( self::get_peptides() );
		$intent_count  = count( self::get_prompt_intents() );
		$probe_count   = $enabled_count * $peptide_count * $intent_count;

		// Conservative per-probe estimate (use the higher Perplexity estimate).
		$per_probe = 0.005;
		$per_run   = round( $probe_count * $per_probe, 4 );

		$cadence        = self::get_cadence();
		$runs_per_month = 'weekly' === $cadence ? 4 : ( 'daily' === $cadence ? 30 : 8 );
		$per_month      = round( $per_run * $runs_per_month, 4 );
		$cap            = self::get_monthly_budget_usd();

		return array(
			'per_run_usd'   => $per_run,
			'per_month_usd' => $per_month,
			'probe_count'   => $probe_count,
			'over_cap'      => $per_month > $cap,
		);
	}


	/**
	 * Get the I/O retention window in days.
	 *
	 * Raw prompt + response text in prv_call_io is pruned after this many days.
	 * Cost/metadata in prv_call_meta is kept indefinitely.
	 *
	 * @return int Days; minimum 1, default PRV_IO_RETENTION_DEFAULT_DAYS.
	 */
	public static function get_io_retention_days(): int {
		$val = (int) get_option( 'prv_io_retention_days', PRV_IO_RETENTION_DEFAULT_DAYS );
		return max( 1, $val );
	}

	/**
	 * Default set of ~12 high-interest peptides (slug + human-readable label).
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function default_peptides(): array {
		return array(
			array(
				'slug'  => 'bpc-157',
				'label' => 'BPC-157',
			),
			array(
				'slug'  => 'tb-500',
				'label' => 'TB-500',
			),
			array(
				'slug'  => 'mk-677',
				'label' => 'MK-677',
			),
			array(
				'slug'  => 'cjc-1295',
				'label' => 'CJC-1295',
			),
			array(
				'slug'  => 'ghrp-6',
				'label' => 'GHRP-6',
			),
			array(
				'slug'  => 'ipamorelin',
				'label' => 'Ipamorelin',
			),
			array(
				'slug'  => 'semaglutide',
				'label' => 'Semaglutide',
			),
			array(
				'slug'  => 'tirzepatide',
				'label' => 'Tirzepatide',
			),
			array(
				'slug'  => 'selank',
				'label' => 'Selank',
			),
			array(
				'slug'  => 'semax',
				'label' => 'Semax',
			),
			array(
				'slug'  => 'nad-plus',
				'label' => 'NAD+',
			),
			array(
				'slug'  => 'aod-9604',
				'label' => 'AOD-9604',
			),
		);
	}

	/**
	 * Default prompt intent templates -- three query styles per peptide.
	 *
	 * @return array<int, string>
	 */
	private static function default_prompt_intents(): array {
		return array(
			'what is {peptide}',
			'{peptide} benefits and dosage',
			'{peptide} reconstitution guide',
		);
	}
}
