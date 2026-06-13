<?php
/**
 * Tests for PRV_Probe_Runner: budget-cap abort and run mechanics.
 *
 * Uses queued mock HTTP responses (via $GLOBALS['prv_test_state']['remote_posts'])
 * and a mock ledger to verify runner behaviour without real API calls.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Probe Runner Tests ===\n";

// ── Define API key constants (required by providers) ──────────────────────

if ( ! defined( 'PRV_OPENROUTER_API_KEY' ) ) {
	define( 'PRV_OPENROUTER_API_KEY', 'sk-or-test-key' );
}
if ( ! defined( 'PRV_CF_ACCOUNT_ID' ) ) {
	define( 'PRV_CF_ACCOUNT_ID', '' );
}
if ( ! defined( 'PRV_CF_GATEWAY_ID' ) ) {
	define( 'PRV_CF_GATEWAY_ID', '' );
}

// ── Mock ledger: tracks afford calls, simulates exhaustion ────────────────

class PRV_Mock_Ledger extends PRV_Cost_Ledger {
	public int  $afford_count    = 0;
	public int  $no_afford_count = 0;
	public int  $exhaust_after   = PHP_INT_MAX;

	public function can_afford( float $estimated ): bool {
		$total = $this->afford_count + $this->no_afford_count;
		if ( $total >= $this->exhaust_after ) {
			$this->no_afford_count++;
			return false;
		}
		$this->afford_count++;
		return true;
	}

	public function update_row_cost( int $row_id, float $cost ): bool { return true; }
	public function get_month_to_date_usd(): float { return 0.0; }
}

// ── Shared mock response body (Perplexity / cited) ────────────────────────

$cited_body = json_encode([
	'choices'   => [['message' => ['content' => 'BPC-157 info.']]],
	'citations' => ['https://peptiderepo.com/bpc-157/', 'https://examine.com'],
	'usage'     => ['total_tokens' => 100],
]);

// ── Test: basic run completes — 1×1×1 (peptide × intent × model) ─────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [['slug'=>'bpc-157','label'=>'BPC-157']];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
$GLOBALS['prv_test_state']['remote_posts'] = [
	['response' => ['code' => 200], 'body' => $cited_body],
];

$ledger1 = new PRV_Mock_Ledger();
$runner1 = new PRV_Probe_Runner( $ledger1 );
$counts1 = $runner1->run();

prv_assert_equals( 1, $counts1['probed'],         'basic run: 1 probed' );
prv_assert_equals( 0, $counts1['skipped_budget'], 'basic run: 0 budget skips' );
prv_assert_equals( 0, $counts1['skipped_error'],  'basic run: 0 error skips' );
prv_assert_equals( 1, $ledger1->afford_count,     'basic run: 1 can_afford check' );

// ── Test: 2×1×1 run completes all probes when budget sufficient ───────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [
	['slug'=>'bpc-157','label'=>'BPC-157'],
	['slug'=>'tb-500','label'=>'TB-500'],
];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
$GLOBALS['prv_test_state']['remote_posts'] = [
	['response' => ['code' => 200], 'body' => $cited_body],
	['response' => ['code' => 200], 'body' => $cited_body],
];

$ledger2 = new PRV_Mock_Ledger();
$runner2 = new PRV_Probe_Runner( $ledger2 );
$counts2 = $runner2->run();

prv_assert_equals( 2, $counts2['probed'], '2×1×1 run: 2 probes completed' );
prv_assert_equals( 2, $ledger2->afford_count, '2×1×1 run: 2 can_afford checks' );

// ── Test: budget cap abort after N probes ────────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [
	['slug'=>'bpc-157','label'=>'BPC-157'],
	['slug'=>'tb-500','label'=>'TB-500'],
	['slug'=>'mk-677','label'=>'MK-677'],
];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
// Queue 3 responses but cap budget at 1.
$GLOBALS['prv_test_state']['remote_posts'] = [
	['response' => ['code' => 200], 'body' => $cited_body],
	['response' => ['code' => 200], 'body' => $cited_body],
	['response' => ['code' => 200], 'body' => $cited_body],
];

$exhausting_ledger = new PRV_Mock_Ledger();
$exhausting_ledger->exhaust_after = 1; // Budget exhausted after 1st can_afford check.

$runner3 = new PRV_Probe_Runner( $exhausting_ledger );
$counts3 = $runner3->run();

prv_assert_equals( 1, $counts3['probed'],        'budget abort: 1 probe completed before cap' );
prv_assert( $counts3['skipped_budget'] >= 2,     'budget abort: ≥2 probes skipped (budget)' );
prv_assert_equals( 0, $counts3['skipped_error'], 'budget abort: 0 error skips' );

// ── Test: HTTP error counts as skipped_error ─────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [['slug'=>'bpc-157','label'=>'BPC-157']];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
// All retries fail with 500.
$GLOBALS['prv_test_state']['remote_posts'] = [
	['response' => ['code' => 500], 'body' => 'Error'],
	['response' => ['code' => 500], 'body' => 'Error'],
	['response' => ['code' => 500], 'body' => 'Error'],
];

$ledger4 = new PRV_Mock_Ledger();
$runner4 = new PRV_Probe_Runner( $ledger4 );
$counts4 = $runner4->run();

prv_assert_equals( 0, $counts4['probed'],        'HTTP 500: 0 probes' );
prv_assert( $counts4['skipped_error'] >= 1,      'HTTP 500: ≥1 error skip recorded' );

// ── Test: WP_Error from wp_remote_post counts as skipped_error ──────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [['slug'=>'bpc-157','label'=>'BPC-157']];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
// Return WP_Error for all retries.
$wp_error = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$GLOBALS['prv_test_state']['remote_posts'] = [ $wp_error, $wp_error, $wp_error ];

$ledger5 = new PRV_Mock_Ledger();
$runner5 = new PRV_Probe_Runner( $ledger5 );
$counts5 = $runner5->run();

prv_assert_equals( 0, $counts5['probed'],   'WP_Error: 0 probes completed' );
prv_assert( $counts5['skipped_error'] >= 1, 'WP_Error: ≥1 error skip recorded' );

// ── Test: run returns correct keys in counts array ───────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_peptides']       = [['slug'=>'bpc-157','label'=>'BPC-157']];
$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = ['what is {peptide}'];
$GLOBALS['prv_test_state']['options']['prv_models']         = ['perplexity/sonar'];
$GLOBALS['prv_test_state']['remote_posts'] = [
	['response' => ['code' => 200], 'body' => $cited_body],
];

$ledger6 = new PRV_Mock_Ledger();
$runner6 = new PRV_Probe_Runner( $ledger6 );
$counts6 = $runner6->run();

prv_assert( array_key_exists( 'probed',         $counts6 ), 'run: returns probed key' );
prv_assert( array_key_exists( 'skipped_budget', $counts6 ), 'run: returns skipped_budget key' );
prv_assert( array_key_exists( 'skipped_error',  $counts6 ), 'run: returns skipped_error key' );

exit( prv_test_summary() );
