<?php
/**
 * Generic OpenRouter provider for GPT-search and Gemini-search models.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Generic OpenRouter provider — parameterised for GPT-search and Gemini-search.
 *
 * One class handles any OpenRouter-routed model. The model identifier is set
 * at construction time; the runner instantiates one provider per model from
 * PRV_Config::get_models().
 *
 * API key is resolved through PRV_Key_Store::get_key() (constant → admin
 * option → none), never read directly from the constant.
 *
 * Who triggers: PRV_Probe_Runner via PRV_Probe_Provider interface.
 *               PRV_Key_Test_Ajax (probe_with_key for test path).
 * Dependencies: PRV_Key_Store, PRV_Gateway_Client, PRV_Citation_Detector,
 *               PRV_Probe_Result.
 *
 * @see interface-prv-probe-provider.php  — Interface this implements.
 * @see class-prv-key-store.php           — Single key resolver.
 * @see class-prv-gateway-client.php      — Shared HTTP + retry logic.
 * @see class-prv-citation-detector.php   — Domain extraction + detection.
 * @see ARCHITECTURE.md                   — §Provider implementations, §Key management.
 * @package PrVision
 */
class PRV_OpenRouter_Provider implements PRV_Probe_Provider {

	/**
	 * Estimated cost per probe call in USD (conservative cross-model default).
	 */
	const ESTIMATED_COST_PER_PROBE = 0.003;

	/**
	 * OpenRouter model identifier (e.g. "openai/gpt-4o-search-preview").
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * HTTP gateway client.
	 *
	 * @var PRV_Gateway_Client
	 */
	private PRV_Gateway_Client $gateway;

	/**
	 * Citation domain detector.
	 *
	 * @var PRV_Citation_Detector
	 */
	private PRV_Citation_Detector $detector;

	/**
	 * Constructor.
	 *
	 * @param string                     $model    OpenRouter model identifier.
	 * @param PRV_Gateway_Client|null    $gateway  Injected for testing.
	 * @param PRV_Citation_Detector|null $detector Injected for testing.
	 */
	public function __construct( string $model, ?PRV_Gateway_Client $gateway = null, ?PRV_Citation_Detector $detector = null ) {
		$this->model    = $model;
		$this->gateway  = $gateway ?? new PRV_Gateway_Client();
		$this->detector = $detector ?? new PRV_Citation_Detector();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Resolves the key via PRV_Key_Store (constant → option → none).
	 *
	 * @param string $query The search query to probe.
	 *
	 * @return PRV_Probe_Result
	 * @throws \RuntimeException On permanent API failure or no key configured.
	 */
	public function probe( string $query ): PRV_Probe_Result {
		$api_key = PRV_Key_Store::get_key();
		if ( '' === $api_key ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception, not HTML
			throw new \RuntimeException( 'PRV_OPENROUTER_API_KEY is not configured. Set it in wp-config.php or via Settings.' );
		}
		return $this->probe_with_key( $query, $api_key );
	}

	/**
	 * Probe using a caller-supplied key (used by PRV_Key_Test_Ajax).
	 *
	 * Accepts the key as a parameter so the test path can call this without
	 * re-resolving from the store. The key is passed only to the gateway
	 * (as an Authorization header); it is never logged, stored, or returned.
	 *
	 * @param string $query   The search query to probe.
	 * @param string $api_key Plaintext API key (server-side only; never exposed).
	 *
	 * @return PRV_Probe_Result
	 * @throws \RuntimeException On permanent API failure.
	 */
	public function probe_with_key( string $query, string $api_key ): PRV_Probe_Result {
		$body = array(
			'model'      => $this->model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $query,
				),
			),
			'max_tokens' => 512,
		);

		$data = $this->gateway->post_to_gateway( 'openrouter', $body, $api_key );

		return $this->parse_response( $data );
	}

	/**
	 * Parse the OpenRouter response into a PRV_Probe_Result.
	 *
	 * Attempts to extract citations from:
	 * 1. `annotations` array (some models include structured source references).
	 * 2. URLs found inline in the response text (regex fallback).
	 *
	 * @param array<string, mixed> $data Decoded API response.
	 *
	 * @return PRV_Probe_Result
	 */
	public function parse_response( array $data ): PRV_Probe_Result {
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

		return new PRV_Probe_Result( $raw_excerpt, $domains, $cited, $our_position, $cost_usd );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'OpenRouter/' . $this->model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * True when any source (constant or admin option) provides a non-empty key.
	 */
	public function is_configured(): bool {
		return '' !== PRV_Key_Store::get_key();
	}
}
