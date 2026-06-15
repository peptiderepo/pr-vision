<?php
/**
 * AJAX handler: Test the resolved API key validity.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * AJAX handler for the "Test key" button in the Provider API Key section.
 *
 * Resolves the API key via PRV_Key_Store::get_key() and performs a single
 * cheap probe. Reports valid/invalid without revealing the key value.
 * Rate-limited to one test per 30 seconds.
 *
 * Who triggers: wp_ajax_prv_test_key (registered by PRV_Settings_Page).
 * Dependencies: PRV_Key_Store, PRV_OpenRouter_Provider, PRV_Gateway_Client.
 *
 * @see class-prv-key-store.php      -- Key resolution.
 * @see class-prv-settings-page.php  -- Registers the wp_ajax_ hook.
 * @see class-prv-settings-renderer.php -- Renders the "Test key" button.
 * @package PrVision
 */
class PRV_Key_Test_Ajax {

	/**
	 * Rate-limit window in seconds.
	 */
	const RATE_LIMIT_SECONDS = 30;

	/**
	 * Transient key for the rate-limit lock.
	 */
	const RATE_LIMIT_TRANSIENT = 'prv_key_test_lock';

	/**
	 * Cheap probe query — one call, minimal tokens.
	 */
	private const PROBE_QUERY = 'what is BPC-157';

	/**
	 * Handle the prv_test_key AJAX request.
	 *
	 * Verifies manage_options + nonce, rate-limits, resolves the key, probes,
	 * and returns JSON success/error without ever including the key value.
	 *
	 * Side effects: HTTP call to LLM provider; sets a rate-limit transient; exits.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$nonce = isset( $_POST['prv_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, PRV_Settings_Page::NONCE_KEY_TEST ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		if ( false !== get_transient( self::RATE_LIMIT_TRANSIENT ) ) {
			wp_send_json_error( array( 'message' => 'Rate limited — wait 30 s between tests.' ) );
		}
		set_transient( self::RATE_LIMIT_TRANSIENT, 1, self::RATE_LIMIT_SECONDS );

		$api_key = PRV_Key_Store::get_key();
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => 'No API key configured.' ) );
		}

		$start    = microtime( true );
		$provider = new PRV_OpenRouter_Provider( 'openai/gpt-4o-mini', null, null );

		try {
			$provider->probe_with_key( self::PROBE_QUERY, $api_key );
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			wp_send_json_success(
				array(
					'valid'      => true,
					'latency_ms' => $ms,
					'message'    => sprintf( 'Key valid · %d ms', $ms ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'valid'   => false,
					'message' => 'Key invalid or provider error — check key and try again.',
				)
			);
		}
	}
}
