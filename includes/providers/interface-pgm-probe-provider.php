<?php
declare(strict_types=1);

/**
 * Contract for all LLM probe providers.
 *
 * Each provider implementation wraps one LLM backend (Perplexity sonar,
 * OpenRouter GPT-search, OpenRouter Gemini-search, …). Swapping providers
 * requires only a new class — the runner, ledger, and storage are agnostic.
 *
 * Who triggers: PGM_Probe_Runner for each (model, query) combination.
 * Dependencies: None — pure interface.
 *
 * @see class-pgm-perplexity-provider.php  — Perplexity sonar implementation.
 * @see class-pgm-openrouter-provider.php  — OpenRouter generic implementation.
 * @see class-pgm-probe-runner.php         — Dispatches to providers via this interface.
 * @see CONTEXT.md                         — "provider", "probe".
 * @package PeptideGeoMonitor
 */
interface PGM_Probe_Provider {

	/**
	 * Send a search/retrieval query and return a structured probe result.
	 *
	 * Implementations MUST:
	 * - Extract cited source domains from the model's response.
	 * - Detect whether PGM_TARGET_DOMAIN appears in those sources.
	 * - Record our 1-based position (null when not cited).
	 * - Return the call's estimated USD cost.
	 * - Throw \RuntimeException on unrecoverable failure (caller logs + skips).
	 *
	 * @param string $query The natural-language query to send.
	 *
	 * @return PGM_Probe_Result
	 *
	 * @throws \RuntimeException When the API call fails after retries.
	 */
	public function probe( string $query ): PGM_Probe_Result;

	/**
	 * Get a short human-readable name for this provider (e.g. "Perplexity sonar").
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Check whether the required credentials/constants are configured.
	 *
	 * Returns false (never throws) so the runner can skip unconfigured
	 * providers without aborting the whole run.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;
}
