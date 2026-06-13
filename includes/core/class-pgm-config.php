<?php
declare(strict_types=1);

/**
 * Configuration helper: seed defaults and retrieve plugin settings.
 *
 * All mutable settings live in wp_options under the pgm_ prefix. This class
 * provides typed getters (no magic values in call sites) and seeds the initial
 * defaults on first activation.
 *
 * Who triggers: PGM_Activator::activate() for seeding; every component that
 *               needs a setting reads through here.
 * Dependencies: get_option(), update_option().
 *
 * @see CONTEXT.md          — Domain glossary: peptides, prompt intents, models.
 * @see ARCHITECTURE.md     — §Config (options + defaults).
 * @package PeptideGeoMonitor
 */
class PGM_Config {

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
		add_option( 'pgm_monthly_budget_usd', PGM_DEFAULT_MONTHLY_BUDGET_USD );
		add_option( 'pgm_peptides', self::default_peptides() );
		add_option( 'pgm_prompt_intents', self::default_prompt_intents() );
		add_option( 'pgm_models', self::default_models() );
	}

	/**
	 * Get the monthly budget cap in USD.
	 *
	 * @return float Positive float; falls back to the plugin default.
	 */
	public static function get_monthly_budget_usd(): float {
		$val = (float) get_option( 'pgm_monthly_budget_usd', PGM_DEFAULT_MONTHLY_BUDGET_USD );
		return $val > 0.0 ? $val : PGM_DEFAULT_MONTHLY_BUDGET_USD;
	}

	/**
	 * Get the list of peptide slugs/labels to probe.
	 *
	 * Each element is an associative array with 'slug' and 'label' keys.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function get_peptides(): array {
		$raw = get_option( 'pgm_peptides', self::default_peptides() );
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
		$raw = get_option( 'pgm_prompt_intents', self::default_prompt_intents() );
		return is_array( $raw ) ? $raw : self::default_prompt_intents();
	}

	/**
	 * Get the model identifiers to probe with.
	 *
	 * @return array<int, string>
	 */
	public static function get_models(): array {
		$raw = get_option( 'pgm_models', self::default_models() );
		return is_array( $raw ) ? $raw : self::default_models();
	}

	/**
	 * Default set of ~12 high-interest peptides (slug + human-readable label).
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function default_peptides(): array {
		return array(
			array( 'slug' => 'bpc-157', 'label' => 'BPC-157' ),
			array( 'slug' => 'tb-500', 'label' => 'TB-500' ),
			array( 'slug' => 'mk-677', 'label' => 'MK-677' ),
			array( 'slug' => 'cjc-1295', 'label' => 'CJC-1295' ),
			array( 'slug' => 'ghrp-6', 'label' => 'GHRP-6' ),
			array( 'slug' => 'ipamorelin', 'label' => 'Ipamorelin' ),
			array( 'slug' => 'semaglutide', 'label' => 'Semaglutide' ),
			array( 'slug' => 'tirzepatide', 'label' => 'Tirzepatide' ),
			array( 'slug' => 'selank', 'label' => 'Selank' ),
			array( 'slug' => 'semax', 'label' => 'Semax' ),
			array( 'slug' => 'nad-plus', 'label' => 'NAD+' ),
			array( 'slug' => 'aod-9604', 'label' => 'AOD-9604' ),
		);
	}

	/**
	 * Default prompt intent templates — three query styles per peptide.
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

	/**
	 * Default models: Perplexity sonar (primary citation signal) + two
	 * OpenRouter-routed search-capable models for breadth.
	 *
	 * @return array<int, string>
	 */
	private static function default_models(): array {
		return array(
			'perplexity/sonar',
			'openai/gpt-4o-search-preview',
			'google/gemini-2.0-flash-001',
		);
	}
}
