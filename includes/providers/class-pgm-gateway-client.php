<?php
declare(strict_types=1);

/**
 * Cloudflare AI Gateway HTTP client — shared by all providers.
 *
 * Builds the gateway URL, injects the Authorization header, applies
 * exponential-backoff retry, and delegates cURL auth injection (for
 * hosts that strip the Authorization header) — mirroring the approach
 * used in PRAutoBlogger's OpenRouter provider.
 *
 * Gateway URL shape:
 *   https://gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openrouter
 *
 * Required WP-config constants (documented in README.md):
 *   PGM_CF_ACCOUNT_ID   — Cloudflare account ID
 *   PGM_CF_GATEWAY_ID   — AI Gateway name (e.g. "peptiderepo-prod")
 *   PGM_OPENROUTER_API_KEY — OpenRouter API key (sk-or-…)
 *
 * Who triggers: PGM_Perplexity_Provider, PGM_OpenRouter_Provider.
 * Dependencies: wp_remote_post(), add_action(), curl_setopt().
 *
 * @see class-pgm-perplexity-provider.php  — Uses post_to_gateway().
 * @see class-pgm-openrouter-provider.php  — Uses post_to_gateway().
 * @see ARCHITECTURE.md                    — §External API integrations.
 * @package PeptideGeoMonitor
 */
class PGM_Gateway_Client {

	/**
	 * Base URL for the Cloudflare AI Gateway (without trailing slash).
	 */
	private const GATEWAY_BASE = 'https://gateway.ai.cloudflare.com/v1';

	/**
	 * Fallback to direct OpenRouter when the gateway is not configured.
	 */
	private const OPENROUTER_BASE = 'https://openrouter.ai/api/v1';

	/**
	 * Send a chat-completion POST request through the gateway (or direct).
	 *
	 * Retries with exponential backoff on transient failures (5xx, timeout).
	 * Throws on permanent failure after exhausting retries.
	 *
	 * Side effects: HTTP request; logs errors to PHP error log.
	 *
	 * @param string               $provider_slug Gateway provider path segment (e.g. "openrouter", "perplexity").
	 * @param array<string, mixed> $body          JSON request body.
	 * @param string               $api_key       Bearer token.
	 * @param string               $endpoint      Path appended after the provider base (default "/chat/completions").
	 *
	 * @return array<string, mixed> Decoded JSON response body.
	 *
	 * @throws \RuntimeException On permanent HTTP failure or parse error.
	 */
	public function post_to_gateway( string $provider_slug, array $body, string $api_key, string $endpoint = '/chat/completions' ): array {
		$base_url = $this->build_base_url( $provider_slug );
		$url      = $base_url . $endpoint;
		$base_host = (string) wp_parse_url( $base_url, PHP_URL_HOST );

		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => home_url(),
			'X-Title'       => 'Peptide GEO Monitor',
		);

		// Belt-and-suspenders cURL injection — some hosts strip Authorization.
		$curl_filter = $this->register_curl_auth_filter( $headers, $base_host );

		$last_error = '';

		try {
			for ( $attempt = 1; $attempt <= PGM_MAX_RETRIES; $attempt++ ) {
				$response = wp_remote_post(
					$url,
					array(
						'timeout' => PGM_API_TIMEOUT_SECONDS,
						'headers' => $headers,
						'body'    => wp_json_encode( $body ),
					)
				);

				if ( is_wp_error( $response ) ) {
					$last_error = $response->get_error_message();
					if ( $attempt < PGM_MAX_RETRIES ) {
						sleep( PGM_RETRY_BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 ) );
					}
					continue;
				}

				$status   = wp_remote_retrieve_response_code( $response );
				$raw_body = wp_remote_retrieve_body( $response );

				if ( $status >= 500 || 429 === $status ) {
					$last_error = sprintf( 'HTTP %d', $status );
					if ( $attempt < PGM_MAX_RETRIES ) {
						$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
						$delay = $retry_after ? min( (int) $retry_after, 60 ) : PGM_RETRY_BASE_DELAY_SECONDS * (int) pow( 2, $attempt - 1 );
						sleep( $delay );
					}
					continue;
				}

				if ( $status >= 400 ) {
					throw new \RuntimeException(
						sprintf( 'GEO Monitor gateway HTTP %d: %s', $status, substr( $raw_body, 0, 300 ) )
					);
				}

				$data = json_decode( $raw_body, true );
				if ( ! is_array( $data ) ) {
					throw new \RuntimeException( 'GEO Monitor: invalid JSON response from gateway.' );
				}

				return $data;
			}
		} finally {
			remove_action( 'http_api_curl', $curl_filter, 99 );
		}

		throw new \RuntimeException(
			sprintf( 'GEO Monitor: gateway failed after %d attempts. Last: %s', PGM_MAX_RETRIES, $last_error )
		);
	}

	/**
	 * Build the API base URL (gateway or direct fallback).
	 *
	 * @param string $provider_slug Path segment after gateway_id (e.g. "openrouter").
	 *
	 * @return string Base URL without trailing slash.
	 */
	public function build_base_url( string $provider_slug ): string {
		if ( defined( 'PGM_CF_ACCOUNT_ID' ) && defined( 'PGM_CF_GATEWAY_ID' )
			&& '' !== PGM_CF_ACCOUNT_ID && '' !== PGM_CF_GATEWAY_ID ) {
			return sprintf(
				'%s/%s/%s/%s',
				self::GATEWAY_BASE,
				PGM_CF_ACCOUNT_ID,
				PGM_CF_GATEWAY_ID,
				$provider_slug
			);
		}
		return self::OPENROUTER_BASE;
	}

	/**
	 * Register cURL auth injection filter (mirrors PRAutoBlogger pattern).
	 *
	 * @param array<string, string> $headers   Request headers including Authorization.
	 * @param string                $base_host Upstream host — limits leakage scope.
	 *
	 * @return callable The registered filter (caller MUST remove_action after request).
	 */
	private function register_curl_auth_filter( array $headers, string $base_host ): callable {
		$filter = function ( $handle, $parsed_args, $url ) use ( $headers, $base_host ): void {
			if ( '' === $base_host || false === strpos( (string) $url, $base_host ) ) {
				return;
			}
			$curl_headers = array();
			foreach ( $headers as $name => $value ) {
				$curl_headers[] = $name . ': ' . $value;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_HTTPHEADER, $curl_headers );
		};
		add_action( 'http_api_curl', $filter, 99, 3 );
		return $filter;
	}
}
