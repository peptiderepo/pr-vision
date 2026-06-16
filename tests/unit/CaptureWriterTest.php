<?php
/**
 * Tests: PRV_Capture_Writer — metadata + I/O writes and security assertions.
 *
 * P0 SECURITY tests (preserved from v0.2.3 + v0.3.0):
 *  - Direct-writer path: 401 error record must not store "Bearer",
 *    "sk-or-", or "Authorization" in any stored row.
 *  - Full executor path: sentinel key must not leak through
 *    PRV_Probe_Runner → PRV_Probe_Run_Executor end-to-end.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers PRV_Capture_Writer
 */
class CaptureWriterTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	// ── Test 1: write_meta writes a row to prv_call_meta ─────────────────

	public function test_write_meta_inserts_one_row(): void {
		$writer  = new PRV_Capture_Writer();
		$call_id = $writer->write_meta(
			array(
				'visibility_row' => 10,
				'run_id'         => 'abc-123',
				'peptide_slug'   => 'bpc-157',
				'model'          => 'perplexity/sonar',
				'intent_label'   => 'what is {peptide}',
				'tokens_in'      => 100,
				'tokens_out'     => 250,
				'cost_usd'       => 0.00123,
				'latency_ms'     => 800,
				'cited'          => 1,
				'http_status'    => 200,
				'config_version' => 3,
			)
		);

		$this->assertGreaterThan( 0, $call_id, 'write_meta returns a positive row ID' );

		$meta_rows = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'];
		$this->assertCount( 1, $meta_rows, 'write_meta inserts exactly one call_meta row' );
		$this->assertSame( 'bpc-157', $meta_rows[0]['peptide_slug'], 'write_meta stores peptide_slug' );
		$this->assertSame( 100, $meta_rows[0]['tokens_in'], 'write_meta stores tokens_in' );
		$this->assertSame( 250, $meta_rows[0]['tokens_out'], 'write_meta stores tokens_out' );
		$this->assertSame( 0, $meta_rows[0]['io_captured'], 'write_meta sets io_captured=0 initially' );
	}

	// ── Test 2: write_io writes a row to prv_call_io ─────────────────────

	public function test_write_io_inserts_one_row(): void {
		$writer  = new PRV_Capture_Writer();
		$call_id = $writer->write_meta(
			array(
				'visibility_row' => 10,
				'run_id'         => 'abc-123',
				'peptide_slug'   => 'bpc-157',
				'model'          => 'perplexity/sonar',
				'intent_label'   => 'what is {peptide}',
				'tokens_in'      => 100,
				'tokens_out'     => 250,
				'cost_usd'       => 0.00123,
				'latency_ms'     => 800,
				'cited'          => 1,
				'http_status'    => 200,
				'config_version' => 3,
			)
		);
		$ok = $writer->write_io( $call_id, 'what is BPC-157?', 'BPC-157 is a peptide...' );

		$this->assertTrue( $ok, 'write_io returns true on success' );

		$io_rows = $GLOBALS['prv_test_state']['wpdb_call_io_rows'];
		$this->assertCount( 1, $io_rows, 'write_io inserts exactly one call_io row' );
		$this->assertSame( 'what is BPC-157?', $io_rows[0]['prompt_text'], 'write_io stores prompt_text' );
		$this->assertSame( 'BPC-157 is a peptide...', $io_rows[0]['response_text'], 'write_io stores response_text' );
	}

	// ── Test 3 (P0 SECURITY — direct writer): 401 does not store auth ────

	public function test_p0_direct_writer_401_no_auth_material_stored(): void {
		$writer = new PRV_Capture_Writer();
		$writer->write_meta(
			array(
				'visibility_row' => null,
				'run_id'         => 'error-run-1',
				'peptide_slug'   => 'tb-500',
				'model'          => 'openai/gpt-4o',
				'intent_label'   => 'what is {peptide}',
				'tokens_in'      => null,
				'tokens_out'     => null,
				'cost_usd'       => 0.0,
				'latency_ms'     => 150,
				'cited'          => null,
				'http_status'    => 401,
				'config_version' => 3,
			)
		);
		$writer->write_io( 1, 'what is TB-500?', '' );

		$err_meta = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'][0];
		$err_io   = $GLOBALS['prv_test_state']['wpdb_call_io_rows'][0];

		$this->assertSame( 401, $err_meta['http_status'], 'Error record stores http_status 401' );

		$all_stored = wp_json_encode( $err_meta ) . wp_json_encode( $err_io );
		$this->assertStringNotContainsString( 'Bearer', $all_stored, 'Stored data does not contain "Bearer"' );
		$this->assertStringNotContainsString( 'sk-or-', $all_stored, 'Stored data does not contain "sk-or-"' );
		$this->assertStringNotContainsString( 'Authorization', $all_stored, 'Stored data does not contain "Authorization"' );
		$this->assertNull( $err_meta['cited'], 'Error record has cited=null' );
		$this->assertSame( 'what is TB-500?', $err_io['prompt_text'], 'Error record stores rendered query as prompt_text' );
	}

	// ── Test 4 (P0 SECURITY — executor path): configured key must not store in rows ──

	/**
	 * Verifies the executor path does not store the configured API key value
	 * (PRV_OPENROUTER_API_KEY, defined in bootstrap) in any DB row on a 401.
	 *
	 * The test key 'sk-or-test-key' is defined in tests/bootstrap.php so it is
	 * available for ALL tests without per-class define() calls. This test asserts
	 * the value never appears in prv_call_meta or prv_call_io rows, verifying P0
	 * security invariant: key does not leak through stored data regardless of path.
	 */
	public function test_p0_executor_path_sentinel_key_does_not_leak(): void {
		$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd']   = 5.0;
		$GLOBALS['prv_test_state']['options']['prv_peptides']              = array( array( 'slug' => 'bpc-157', 'label' => 'BPC-157' ) );
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents']        = array( 'what is {peptide}' );
		$GLOBALS['prv_test_state']['options']['prv_models_v2']             = array(
			array( 'slug' => 'openai/gpt-4o', 'enabled' => true ),
		);
		$GLOBALS['prv_test_state']['options']['prv_active_config_version'] = 3;
		$GLOBALS['prv_test_state']['wpdb_var']                             = '0.0';

		// Queue a 401 response.
		$GLOBALS['prv_test_state']['remote_posts'][] = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{"error":{"message":"Invalid API key","code":401}}',
		);

		$sentinel_capture = new PRV_Capture_Writer();
		$sentinel_ledger  = new PRV_Cost_Ledger();
		$sentinel_runner  = new PRV_Probe_Runner( $sentinel_ledger, $sentinel_capture );

		try {
			$sentinel_counts = $sentinel_runner->run();
		} catch ( \Throwable $ex ) {
			$sentinel_counts = array( 'skipped_error' => -99 );
		}

		$this->assertGreaterThanOrEqual( 1, $sentinel_counts['skipped_error'], 'Executor recorded a skipped_error for the 401 path' );

		$all_meta = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'];
		$all_io   = $GLOBALS['prv_test_state']['wpdb_call_io_rows'];
		$all_json = wp_json_encode( $all_meta ) . wp_json_encode( $all_io );

		$this->assertStringNotContainsString( 'sk-or-test-key', $all_json, 'Configured key does not appear in any stored row (executor path)' );
		$this->assertStringNotContainsString( 'Bearer', $all_json, 'Bearer does not appear in any stored row (executor path)' );
		$this->assertStringNotContainsString( 'Authorization', $all_json, 'Authorization does not appear in any stored row (executor path)' );
		$this->assertGreaterThanOrEqual( 1, count( $all_meta ), 'Executor path wrote at least one call_meta row on 401' );

		if ( ! empty( $all_meta ) ) {
			$this->assertSame( 401, $all_meta[0]['http_status'], 'Executor 401 path stores http_status=401 in call_meta' );
		}
	}

	// ── Test 5: update_meta_cost writes back the cost ─────────────────────

	public function test_update_meta_cost_returns_true(): void {
		$writer = new PRV_Capture_Writer();
		$result = $writer->update_meta_cost( 5, 0.004567 );

		$this->assertTrue( $result, 'update_meta_cost returns true' );
	}
}
