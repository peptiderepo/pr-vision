<?php
declare(strict_types=1);

/**
 * Value object: the result of a single LLM probe call.
 *
 * Returned by every PGM_Probe_Provider implementation. Immutable — all
 * fields are set in the constructor and read via typed getters.
 *
 * Who triggers: PGM_Perplexity_Provider and PGM_OpenRouter_Provider.
 * Dependencies: None.
 *
 * @see interface-pgm-probe-provider.php — Provider interface that returns this.
 * @see class-pgm-probe-runner.php       — Consumes the result for storage.
 * @see CONTEXT.md                       — "probe result", "cited", "our_position".
 * @package PeptideGeoMonitor
 */
class PGM_Probe_Result {

	/**
	 * @param string        $raw_excerpt    First ~500 chars of the LLM's response.
	 * @param string[]      $source_domains Domains extracted from the provider's citations.
	 * @param bool          $cited          Whether peptiderepo.com appears in source_domains.
	 * @param int|null      $our_position   1-based position of peptiderepo.com in sources, or null.
	 * @param float         $cost_usd       Estimated call cost in USD.
	 */
	public function __construct(
		private readonly string $raw_excerpt,
		private readonly array $source_domains,
		private readonly bool $cited,
		private readonly ?int $our_position,
		private readonly float $cost_usd
	) {}

	/**
	 * @return string
	 */
	public function get_raw_excerpt(): string {
		return $this->raw_excerpt;
	}

	/**
	 * @return string[]
	 */
	public function get_source_domains(): array {
		return $this->source_domains;
	}

	/**
	 * @return bool
	 */
	public function is_cited(): bool {
		return $this->cited;
	}

	/**
	 * @return int|null 1-based position or null when not cited.
	 */
	public function get_our_position(): ?int {
		return $this->our_position;
	}

	/**
	 * @return float Estimated cost in USD.
	 */
	public function get_cost_usd(): float {
		return $this->cost_usd;
	}
}
