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
 *   PRV_OPENROUTER_API_KEY — OpenRouter key (sk-or-…)
 *
 * Who triggers: PRV_Probe_Runner via the PRV_Probe_Provider interface.
 * Dependencies: PRV_Gateway_Client, PRV_Citation_Detector, PRV_Probe_Result.
 *
 * @see interface-prv-probe-provider.php    — Interface this implements.
 * @see class-prv-gateway-client.php        — Shared HTTP + retry logic.
 * @see class-prv-citation-detector.php     — Domain extraction + detection.
 * @see ARCHITECTURE.md                     — §Provider implementations.
 * @see CONTEXT.md                          — "Perplexity sonar", "citations".
 * @package PrVision
 */
class PRV_Perplexity_Provider implements PRV_Probe_Provider {

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
	 * @var PRV_Gateway_Client
	 */
	private PRV_Gateway_Client $gateway;

	/**
	 * @var PRV_Citation_Detector
	 */
	private PRV_Citation_Detector $detector;

	/**
	 * @param PRV_Gateway_Client|null    $gateway  Injected for testing; auto-created otherwise.
	 * @param PRV_Citation_Detector|null $detector Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PRV_Gateway_Client $gateway = null, ?PRV_Citation_Detector $detector = null ) {
		$this->gateway  = $gateway  ?? new PRV_Gateway_Client();
		$this->detector = $detector ?? new PRV_Citation_Detector();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Sends the query as a user message to perplexity/sonar via OpenRouter.
	 * Parses the citations[] array from the OpenRouter response envelope.
	 *
	 * @param string $query
	 *
	 * @return PRV_Probe_Result
	 * @throws \RuntimeException On permanent API failure.
	 */
	public function probe( string $query ): PRV_Probe_Result {
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
	 * Parse the OpenRouter/Perplexity response into a PRV_Probe_Result.
	 *
	 * Side effects: None.
	 *
	 * @param array<string, mixed> $data Decoded API response.
	 *
	 * @return PRV_Probe_Result
	 */
	public function parse_response( array $data ): PRV_Probe_Result {
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

		return new PRV_Probe_Result( $raw_excerpt, $domains, $cited, $our_position, $cost_usd );
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
		return defined( 'PRV_OPENROUTER_API_KEY' ) && '' !== PRV_OPENROUTER_API_KEY;
	}

	/**
	 * Retrieve the OpenRouter API key from wp-config constants.
	 *
	 * @return string
	 * @throws \RuntimeException When the constant is not defined or empty.
	 */
	private function resolve_api_key(): string {
		if ( ! defined( 'PRV_OPENROUTER_API_KEY' ) || '' === PRV_OPENROUTER_API_KEY ) {
			throw new \RuntimeException(
				__( 'PRV_OPENROUTER_API_KEY constant is not defined. Add it to wp-config.php.', 'pr-vision' )
			);
		}
		return PRV_OPENROUTER_API_KEY;
	}
}
