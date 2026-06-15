<?php
/**
 * Tests: PRV_Capture_Writer — metadata + I/O writes; security test.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== test-capture-writer ===\n";
prv_test_reset();

// ── Test 1: write_meta writes a row to prv_call_meta ─────────────────────
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

prv_assert( $call_id > 0, 'write_meta returns a positive row ID' );
$meta_rows = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'];
prv_assert( count( $meta_rows ) === 1, 'write_meta inserts exactly one call_meta row' );
prv_assert_equals( 'bpc-157', $meta_rows[0]['peptide_slug'], 'write_meta stores peptide_slug' );
prv_assert_equals( 100, $meta_rows[0]['tokens_in'], 'write_meta stores tokens_in' );
prv_assert_equals( 250, $meta_rows[0]['tokens_out'], 'write_meta stores tokens_out' );
prv_assert_equals( 0, $meta_rows[0]['io_captured'], 'write_meta sets io_captured=0 initially' );

// ── Test 2: write_io writes a row to prv_call_io ─────────────────────────
$ok = $writer->write_io( $call_id, 'what is BPC-157?', 'BPC-157 is a peptide...' );
prv_assert( $ok, 'write_io returns true on success' );
$io_rows = $GLOBALS['prv_test_state']['wpdb_call_io_rows'];
prv_assert( count( $io_rows ) === 1, 'write_io inserts exactly one call_io row' );
prv_assert_equals( 'what is BPC-157?', $io_rows[0]['prompt_text'], 'write_io stores prompt_text' );
prv_assert_equals( 'BPC-157 is a peptide...', $io_rows[0]['response_text'], 'write_io stores response_text' );

// ── Test 3 (P0 SECURITY — direct writer): 401 error record does NOT store key/Bearer ──
// Direct-writer path: simulate the probe-runner error path parameters.
prv_test_reset();
$writer2 = new PRV_Capture_Writer();
$writer2->write_meta(
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
$writer2->write_io( 1, 'what is TB-500?', '' );

$err_meta = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'][0];
$err_io   = $GLOBALS['prv_test_state']['wpdb_call_io_rows'][0];

prv_assert_equals( 401, $err_meta['http_status'], 'Error record stores http_status 401' );

$all_stored = wp_json_encode( $err_meta ) . wp_json_encode( $err_io );
prv_assert( false === strpos( $all_stored, 'Bearer' ), 'Stored data does not contain "Bearer"' );
prv_assert( false === strpos( $all_stored, 'sk-or-' ), 'Stored data does not contain "sk-or-"' );
prv_assert( false === strpos( $all_stored, 'Authorization' ), 'Stored data does not contain "Authorization"' );
prv_assert( null === $err_meta['cited'], 'Error record has cited=null' );
prv_assert_equals( 'what is TB-500?', $err_io['prompt_text'], 'Error record stores rendered query as prompt_text' );

// ── Test 4 (P0 SECURITY — full executor path): sentinel key must not leak ─
// Inject a known sentinel as the resolved API key; drive a mocked 401 error
// path end-to-end through PRV_Probe_Run_Executor (via PRV_Probe_Runner).
// Assert the sentinel, "Bearer", and "Authorization" appear in NO stored row.
prv_test_reset();

if ( ! defined( 'PRV_OPENROUTER_API_KEY' ) ) {
	define( 'PRV_OPENROUTER_API_KEY', 'sk-or-TEST-LEAK-CANARY' );
}

$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd']    = 5.0;
$GLOBALS['prv_test_state']['options']['prv_peptides']               = array( array( 'slug' => 'bpc-157', 'label' => 'BPC-157' ) );
$GLOBALS['prv_test_state']['options']['prv_prompt_intents']         = array( 'what is {peptide}' );
$GLOBALS['prv_test_state']['options']['prv_models_v2']              = array(
	array( 'slug' => 'openai/gpt-4o', 'enabled' => true ),
);
$GLOBALS['prv_test_state']['options']['prv_active_config_version']  = 3;
$GLOBALS['prv_test_state']['wpdb_var']                              = '0.0'; // No prior spend.

// Queue a 401 response for wp_remote_post.
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

// The executor must have recorded the 401 error call (skipped_error >= 1).
prv_assert( isset( $sentinel_counts['skipped_error'] ) && $sentinel_counts['skipped_error'] >= 1, 'Executor recorded a skipped_error for the 401 path' );

// Now verify NO stored row (meta or io) contains the sentinel key or auth material.
$all_meta = $GLOBALS['prv_test_state']['wpdb_call_meta_rows'];
$all_io   = $GLOBALS['prv_test_state']['wpdb_call_io_rows'];
$all_json = wp_json_encode( $all_meta ) . wp_json_encode( $all_io );

prv_assert( false === strpos( $all_json, 'sk-or-TEST-LEAK-CANARY' ), 'Sentinel key does not appear in any stored row (executor path)' );
prv_assert( false === strpos( $all_json, 'Bearer' ), 'Bearer does not appear in any stored row (executor path)' );
prv_assert( false === strpos( $all_json, 'Authorization' ), 'Authorization does not appear in any stored row (executor path)' );
prv_assert( count( $all_meta ) >= 1, 'Executor path wrote at least one call_meta row on 401' );

if ( ! empty( $all_meta ) ) {
	prv_assert_equals( 401, $all_meta[0]['http_status'], 'Executor 401 path stores http_status=401 in call_meta' );
}

// ── Test 5: update_meta_cost writes back the cost ────────────────────────
prv_test_reset();
$writer3 = new PRV_Capture_Writer();
$result  = $writer3->update_meta_cost( 5, 0.004567 );
prv_assert( $result, 'update_meta_cost returns true' );

exit( prv_test_summary() );
