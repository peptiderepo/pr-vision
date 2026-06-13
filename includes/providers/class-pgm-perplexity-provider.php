<?php
declare(strict_types=1);

/**
 * Perplexity sonar provider — primary citation signal.
 *
 * Routes through the Cloudflare AI Gateway (openrouter path) because
 * OpenRouter proxies Perplexity models. Perplexity's sonar returns
 * an explicit `citations` array — the most faithful citation signal
 * available (real web retrieval vs. training-time knowledge).
 *
 * Required WP-config constant:
 *   PGM_OPENROUTER_API_KEY — OpenRouter key (sk-or-…)
 *
 * Who triggers: PGM_Probe_Runner via the PGM_Probe_Provider interface.
 * Dependencies: PGM_Gateway_Client, PGM_Citation_Detector, PGM_Probe_Result.
 *
 * @see interface-pgm-probe-provider.php    — Interface this implements.
 * @see class-pgm-gateway-client.php        — Shared HTTP + retry logic.
 * @see class-pgm-citation-detector.php     — Domain extraction + detection.
 * @see ARCHITECTURE.md                     — §Provider implementations.
 * @see CONTEXT.md                          — "Perplexity sonar", "citations".
 * @package PeptideGeoMonitor
 */
class PGM_Perplexity_Provider implements PGM_Probe_Provider {

	/**
	 * Model identifier passed to OpenRouter for Perplexity routing.
	 */
	const MODEL = 'perplexity/sonar';

	/**
	 * Estimated cost per probe call in USD (sonar is ~$0.005 / 1k tokens).
	 * Used by the ledger's can_afford() pre-check. Actual cost settled post-call.
	 */
	const ESTIMATED_COST_PER_PROBE = 0.005;

	/**
	 * @var PGM_Gateway_Client
	 */
	private PGM_Gateway_Client $gateway;

	/**
	 * @var PGM_Citation_Detector
	 */
	private PGM_Citation_Detector $detector;

	/**
	 * @param PGM_Gateway_Client|null    $gateway  Injected for testing; auto-created otherwise.
	 * @param PGM_Citation_Detector|null $detector Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PGM_Gateway_Client $gateway = null, ?PGM_Citation_Detector $detector = null ) {
		$this->gateway  = $gateway  ?? new PGM_Gateway_Client();
		$this->detector = $detector ?? new PGM_Citation_Detector();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Sends the query as a user message to perplexity/sonar via OpenRouter.
	 * Parses the citations[] array from the OpenRouter response envelope.
	 *
	 * @param string $query
	 *
	 * @return PGM_Probe_Result
	 * @throws \RuntimeException On permanent API failure.
	 */
	public function probe( string $query ): PGM_Probe_Result {
		$api_key = $this->resolve_api_key();

		$body = array(
			'model'    => self::MODEL,
			'messages' => array(
				array( 'role' => 'user', 'content' => $query ),
			),
			'max_tokens' => 512,
		);

		$data = $this->gateway->post_to_gateway( 'openrouter', $body, $api_key );

		return $this->parse_response( $data );
	}

	/**
	 * Parse the OpenRouter/Perplexity response into a PGM_Probe_Result.
	 *
	 * Side effects: None.
	 *
	 * @param array<string, mixed> $data Decoded API response.
	 *
	 * @return PGM_Probe_Result
	 */
	public function parse_response( array $data ): PGM_Probe_Result {
		// Extract text excerpt from the first choice.
		$content = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$content = (string) $data['choices'][0]['message']['content'];
		}
		$raw_excerpt = mb_substr( $content, 0, 500 );

		// Perplexity returns citations at the top level of the response envelope.
		$raw_citations = array();
		if ( isset( $data['citations'] ) && is_array( $data['citations'] ) ) {
			$raw_citations = $data['citations'];
		}

		$domains      = $this->detector->parse_domains( $raw_citations );
		$cited        = $this->detector->is_cited( $domains );
		$our_position = $this->detector->get_our_position( $domains );

		// Cost from usage data when available; fall back to estimate.
		$cost_usd = self::ESTIMATED_COST_PER_PROBE;
		if ( isset( $data['usage']['total_tokens'] ) ) {
			// Sonar: ~$1 per 1M input, $1 per 1M output tokens. Avg ~$0.001/1k.
			$cost_usd = (float) $data['usage']['total_tokens'] * 0.000001;
		}

		return new PGM_Probe_Result( $raw_excerpt, $domains, $cited, $our_position, $cost_usd );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Perplexity sonar';
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
