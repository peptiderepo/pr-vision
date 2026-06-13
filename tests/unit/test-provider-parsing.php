<?php
/**
 * Tests for provider response parsing.
 *
 * Exercises PGM_Perplexity_Provider::parse_response() and
 * PGM_OpenRouter_Provider::parse_response() with mock HTTP responses,
 * verifying domain extraction, citation detection, and cost calculation.
 *
 * @package PeptideGeoMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PGM Provider Response Parsing Tests ===\n";

// ── PGM_Perplexity_Provider parsing ─────────────────────────────────────

$provider = new PGM_Perplexity_Provider( null, new PGM_Citation_Detector() );

// Test: cited via citations array
pgm_test_reset();
$data = [
	'choices' => [[ 'message' => [ 'content' => 'BPC-157 is a peptide. See peptiderepo.com' ] ]],
	'citations' => [
		'https://peptiderepo.com/bpc-157/',
		'https://examine.com/bpc-157',
	],
	'usage' => [ 'total_tokens' => 500 ],
];
$result = $provider->parse_response( $data );
pgm_assert( $result->is_cited(), 'Perplexity: cited=true when peptiderepo.com in citations' );
pgm_assert_equals( 1, $result->get_our_position(), 'Perplexity: position=1 when first in citations' );
pgm_assert( count( $result->get_source_domains() ) === 2, 'Perplexity: 2 source domains extracted' );
pgm_assert_equals( 500 * 0.000001, $result->get_cost_usd(), 'Perplexity: cost from token usage' );

// Test: not cited, no citations
pgm_test_reset();
$data_empty = [
	'choices' => [[ 'message' => [ 'content' => 'BPC-157 info here.' ] ]],
	'citations' => [],
	'usage' => [ 'total_tokens' => 200 ],
];
$result_empty = $provider->parse_response( $data_empty );
pgm_assert( ! $result_empty->is_cited(), 'Perplexity: cited=false with empty citations' );
pgm_assert_equals( null, $result_empty->get_our_position(), 'Perplexity: position=null when not cited' );

// Test: excerpt truncated to 500 chars
pgm_test_reset();
$long_content = str_repeat( 'a', 600 );
$data_long = [
	'choices' => [[ 'message' => [ 'content' => $long_content ] ]],
	'citations' => [],
];
$result_long = $provider->parse_response( $data_long );
pgm_assert_equals( 500, mb_strlen( $result_long->get_raw_excerpt() ), 'Perplexity: excerpt truncated to 500 chars' );

// Test: object-style citations
pgm_test_reset();
$data_obj = [
	'choices' => [[ 'message' => [ 'content' => 'answer' ] ]],
	'citations' => [
		[ 'url' => 'https://peptiderepo.com/mk-677/' ],
		[ 'url' => 'https://examine.com/mk677' ],
	],
];
$result_obj = $provider->parse_response( $data_obj );
pgm_assert( $result_obj->is_cited(), 'Perplexity: object-style citations — cited=true' );
pgm_assert_equals( 1, $result_obj->get_our_position(), 'Perplexity: object-style — position=1' );

// Test: fallback cost when no usage data
pgm_test_reset();
$data_no_usage = [
	'choices' => [[ 'message' => [ 'content' => 'answer' ] ]],
	'citations' => [],
];
$result_no_usage = $provider->parse_response( $data_no_usage );
pgm_assert_equals( PGM_Perplexity_Provider::ESTIMATED_COST_PER_PROBE, $result_no_usage->get_cost_usd(), 'Perplexity: fallback to ESTIMATED_COST_PER_PROBE when no usage' );

// ── PGM_OpenRouter_Provider parsing ─────────────────────────────────────

echo "\n--- OpenRouter provider parsing ---\n";

$or_provider = new PGM_OpenRouter_Provider( 'openai/gpt-4o-search-preview', null, new PGM_Citation_Detector() );

// Test: annotations path (structured citations)
pgm_test_reset();
$data_ann = [
	'choices' => [[
		'message' => [
			'content' => 'BPC-157 reconstitution steps.',
			'annotations' => [
				[ 'url_citation' => [ 'url' => 'https://peptiderepo.com/bpc-157-reconstitution/' ] ],
				[ 'url_citation' => [ 'url' => 'https://examine.com/bpc-157' ] ],
			],
		],
	]],
	'usage' => [ 'total_tokens' => 300 ],
];
$result_ann = $or_provider->parse_response( $data_ann );
pgm_assert( $result_ann->is_cited(), 'OpenRouter: annotations path — cited=true' );
pgm_assert_equals( 1, $result_ann->get_our_position(), 'OpenRouter: annotations path — position=1' );

// Test: regex URL extraction fallback
pgm_test_reset();
$data_regex = [
	'choices' => [[
		'message' => [
			'content' => 'See https://peptiderepo.com/tb-500/ and https://examine.com/tb-500 for details.',
		],
	]],
	'usage' => [ 'total_tokens' => 150 ],
];
$result_regex = $or_provider->parse_response( $data_regex );
pgm_assert( $result_regex->is_cited(), 'OpenRouter: regex fallback — cited=true from inline URL' );

// Test: not cited
pgm_test_reset();
$data_no_cite = [
	'choices' => [[ 'message' => [ 'content' => 'Some response without our domain.' ] ]],
];
$result_no_cite = $or_provider->parse_response( $data_no_cite );
pgm_assert( ! $result_no_cite->is_cited(), 'OpenRouter: not cited when peptiderepo.com absent from text' );

// Test: cost calculation from token usage
pgm_test_reset();
$data_cost = [
	'choices' => [[ 'message' => [ 'content' => 'test' ] ]],
	'usage' => [ 'total_tokens' => 1000 ],
];
$result_cost = $or_provider->parse_response( $data_cost );
pgm_assert_equals( 1000 * 0.000002, $result_cost->get_cost_usd(), 'OpenRouter: cost from token usage (2$/1M)' );

exit( pgm_test_summary() );
