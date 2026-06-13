<?php
declare(strict_types=1);

/**
 * Generic OpenRouter provider — parameterised for GPT-search and Gemini-search.
 *
 * One class handles any OpenRouter-routed model. The model identifier is set
 * at construction time; the runner instantiates one provider per model from
 * PGM_Config::get_models().
 *
 * GPT-search (openai/gpt-4o-search-preview) and Gemini-search
 * (google/gemini-2.0-flash-001) do not return a structured citations array
 * via OpenRouter — citations are parsed from URLs embedded in the response
 * text or from any 'annotations' key the model may include.
 *
 * Required WP-config constant:
 *   PGM_OPENROUTER_API_KEY — OpenRouter key (sk-or-…)
 *
 * Who triggers: PGM_Probe_Runner via PGM_Probe_Provider interface.
 * Dependencies: PGM_Gateway_Client, PGM_Citation_Detector, PGM_Probe_Result.
 *
 * @see interface-pgm-probe-provider.php  — Interface this implements.
 * @see class-pgm-gateway-client.php      — Shared HTTP + retry logic.
 * @see class-pgm-citation-detector.php   — Domain extraction + detection.
 * @see ARCHITECTURE.md                   — §Provider implementations.
 * @package PeptideGeoMonitor
 */
class PGM_OpenRouter_Provider implements PGM_Probe_Provider {

	/**
	 * Estimated cost per probe call in USD (conservative cross-model default).
	 */
	const ESTIMATED_COST_PER_PROBE = 0.003;

	/**
	 * @var string OpenRouter model identifier (e.g. "openai/gpt-4o-search-preview").
	 */
	private string $model;

	/**
	 * @var PGM_Gateway_Client
	 */
	private PGM_Gateway_Client $gateway;

	/**
	 * @var PGM_Citation_Detector
	 */
	private PGM_Citation_Detector $detector;

	/**
	 * @param string                 $model    OpenRouter model identifier.
	 * @param PGM_Gateway_Client|null    $gateway  Injected for testing.
	 * @param PGM_Citation_Detector|null $detector Injected for testing.
	 */
	public function __construct( string $model, ?PGM_Gateway_Client $gateway = null, ?PGM_Citation_Detector $detector = null ) {
		$this->model    = $model;
		$this->gateway  = $gateway  ?? new PGM_Gateway_Client();
		$this->detector = $detector ?? new PGM_Citation_Detector();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query
	 * @return PGM_Probe_Result
	 * @throws \RuntimeException On permanent API failure.
	 */
	public function probe( string $query ): PGM_Probe_Result {
		$api_key = $this->resolve_api_key();

		$body = array(
			'model'      => $this->model,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $query ),
			),
			'max_tokens' => 512,
		);

		$data = $this->gateway->post_to_gateway( 'openrouter', $body, $api_key );

		return $this->parse_response( $data );
	}

	/**
	 * Parse the OpenRouter response into a PGM_Probe_Result.
	 *
	 * Attempts to extract citations from:
	 * 1. `annotations` array (some models include structured source references).
	 * 2. URLs found inline in the response text (regex fallback).
	 *
	 * @param array<string, mixed> $data Decoded API response.
	 *
	 * @return PGM_Probe_Result
	 */
	public function parse_response( array $data ): PGM_Probe_Result {
		$content = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$content = (string) $data['choices'][0]['message']['content'];
		}
		$raw_excerpt = mb_substr( $content, 0, 500 );

		// 1. Prefer structured annotations when present.
		$raw_citations = array();
		if ( isset( $data['choices'][0]['message']['annotations'] ) && is_array( $data['choices'][0]['message']['annotations'] ) ) {
			foreach ( $data['choices'][0]['message']['annotations'] as $ann ) {
				if ( isset( $ann['url_citation']['url'] ) ) {
					$raw_citations[] = $ann['url_citation']['url'];
				}
			}
		}

		// 2. Regex fallback: extract URLs from response text.
		if ( empty( $raw_citations ) && '' !== $content ) {
			preg_match_all( '#https?://[^\s\)\]\"\'<>]+#i', $content, $matches );
			$raw_citations = $matches[0] ?? array();
		}

		$domains      = $this->detector->parse_domains( $raw_citations );
		$cited        = $this->detector->is_cited( $domains );
		$our_position = $this->detector->get_our_position( $domains );

		// Cost estimation from usage data.
		$cost_usd = self::ESTIMATED_COST_PER_PROBE;
		if ( isset( $data['usage']['total_tokens'] ) ) {
			// Rough blended rate ~$2/1M tokens across GPT-4o and Gemini Flash.
			$cost_usd = (float) $data['usage']['total_tokens'] * 0.000002;
		}

		return new PGM_Probe_Result( $raw_excerpt, $domains, $cited, $our_position, $cost_usd );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'OpenRouter/' . $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return defined( 'PGM_OPENROUTER_API_KEY' ) && '' !== PGM_OPENROUTER_API_KEY;
	}

	/**
	 * Retrieve the OpenRouter API key from wp-config constants.
	 *
	 * @return string
	 * @throws \RuntimeException When the constant is not defined or empty.
	 */
	private function resolve_api_key(): string {
		if ( ! defined( 'PGM_OPENROUTER_API_KEY' ) || '' === PGM_OPENROUTER_API_KEY ) {
			throw new \RuntimeException(
				__( 'PGM_OPENROUTER_API_KEY constant is not defined. Add it to wp-config.php.', 'peptide-geo-monitor' )
			);
		}
		return PGM_OPENROUTER_API_KEY;
	}
}
