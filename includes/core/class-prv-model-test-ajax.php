<?php
/**
 * AJAX handler: Test model slug validity.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * AJAX handler for the "Test" model button.
 *
 * Performs a single cheap probe to verify a model slug resolves at the
 * provider. Rate-limited to one test per slug per 30 seconds via transient.
 * This is a point-in-time check (not a monitor) -- the UI labels it as such.
 * Never displays the API key.
 *
 * Key is resolved via PRV_Key_Store::get_key() (constant → admin option → none).
 *
 * Who triggers: wp_ajax_prv_test_model (registered by PRV_Settings_Page).
 * Dependencies: PRV_Key_Store, PRV_Model_Registry, PRV_Perplexity_Provider,
 *               PRV_OpenRouter_Provider.
 *
 * @see class-prv-settings-page.php  -- Registers the wp_ajax_ hook.
 * @see class-prv-key-store.php      -- Key resolution.
 * @see class-prv-model-registry.php -- Provides model data by id.
 * @package PrVision
 */
class PRV_Model_Test_Ajax {

	/**
	 * Rate-limit window in seconds.
	 */
	const RATE_LIMIT_SECONDS = 30;

	/**
	 * Handle the prv_test_model AJAX request.
	 *
	 * Verifies capability + nonce, rate-limits, performs one probe, returns JSON.
	 *
	 * Side effects: HTTP call to LLM provider; sets a short-lived transient; exits.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$nonce = isset( $_POST['prv_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, PRV_Settings_Page::NONCE_TEST ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model_id'] ) ) : '';
		$model    = PRV_Model_Registry::find_by_id( $model_id );

		if ( null === $model ) {
			wp_send_json_error( array( 'message' => 'Model not found.' ) );
		}

		$slug     = (string) $model['slug'];
		$lock_key = 'prv_test_lock_' . md5( $slug );
		if ( false !== get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'message' => 'Rate limited -- wait 30 s between tests.' ) );
		}
		set_transient( $lock_key, 1, self::RATE_LIMIT_SECONDS );

		// Resolve key through the store — never bypass to a direct constant read.
		$api_key = PRV_Key_Store::get_key();
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => 'API key not configured — set it in wp-config.php or via Settings → Provider API Key.' ) );
		}

		$start    = microtime( true );
		$provider = ( 'perplexity/sonar' === $slug )
			? new PRV_Perplexity_Provider()
			: new PRV_OpenRouter_Provider( $slug );

		try {
			if ( $provider instanceof PRV_OpenRouter_Provider ) {
				$provider->probe_with_key( 'what is BPC-157', $api_key );
			} else {
				$provider->probe( 'what is BPC-157' );
			}
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			wp_send_json_success(
				array(
					'resolved'   => true,
					'latency_ms' => $ms,
					'slug'       => $slug,
					'message'    => sprintf( 'Resolved · %d ms', $ms ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'resolved' => false,
					'slug'     => $slug,
					'message'  => 'Not resolved -- provider returned error (slug retired?)',
				)
			);
		}
	}
}
