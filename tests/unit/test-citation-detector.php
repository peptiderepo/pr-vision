<?php
/**
 * Tests for PRV_Citation_Detector: domain extraction and cite detection.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Citation Detector Tests ===\n";

// ── Helpers ──────────────────────────────────────────────────────────────

$detector = new PRV_Citation_Detector();

// ── Test: parse plain URL strings ────────────────────────────────────────

prv_test_reset();
$domains = $detector->parse_domains( [
	'https://peptiderepo.com/bpc-157/',
	'https://www.examine.com/supplements/bpc-157/',
	'https://pubmed.ncbi.nlm.nih.gov/12345',
] );

prv_assert( in_array( 'peptiderepo.com', $domains, true ), 'parse_domains: peptiderepo.com extracted from URL' );
prv_assert( in_array( 'examine.com', $domains, true ), 'parse_domains: www. stripped from examine.com' );
prv_assert( in_array( 'pubmed.ncbi.nlm.nih.gov', $domains, true ), 'parse_domains: full subdomain preserved when not www.' );
prv_assert_equals( 3, count( $domains ), 'parse_domains: exactly 3 unique domains' );

// ── Test: parse object-style citations (Perplexity format) ───────────────

prv_test_reset();
$domains = $detector->parse_domains( [
	[ 'url' => 'https://peptiderepo.com/tb-500/' ],
	[ 'url' => 'https://examine.com/tb500' ],
	[ 'url' => 'https://peptiderepo.com/bpc-157/' ], // duplicate domain
] );

prv_assert_equals( 2, count( $domains ), 'parse_domains: object-style citations, duplicates removed' );
prv_assert( in_array( 'peptiderepo.com', $domains, true ), 'parse_domains: object-style, peptiderepo.com present' );

// ── Test: parse empty input ──────────────────────────────────────────────

prv_test_reset();
$domains = $detector->parse_domains( [] );
prv_assert_equals( [], $domains, 'parse_domains: empty input returns empty array' );

// ── Test: is_cited detection ─────────────────────────────────────────────

prv_test_reset();
prv_assert( $detector->is_cited( ['peptiderepo.com', 'examine.com'] ), 'is_cited: returns true when target present' );
prv_assert( ! $detector->is_cited( ['examine.com', 'pubmed.ncbi.nlm.nih.gov'] ), 'is_cited: returns false when target absent' );
prv_assert( ! $detector->is_cited( [] ), 'is_cited: returns false for empty list' );

// ── Test: get_our_position ───────────────────────────────────────────────

prv_test_reset();
$domains = ['examine.com', 'peptiderepo.com', 'pubmed.ncbi.nlm.nih.gov'];
prv_assert_equals( 2, $detector->get_our_position( $domains ), 'get_our_position: 1-based, position 2' );

$domains_first = ['peptiderepo.com', 'examine.com'];
prv_assert_equals( 1, $detector->get_our_position( $domains_first ), 'get_our_position: position 1 when first' );

$domains_absent = ['examine.com', 'pubmed.ncbi.nlm.nih.gov'];
prv_assert_equals( null, $detector->get_our_position( $domains_absent ), 'get_our_position: null when not present' );

// ── Test: malformed / unexpected input ───────────────────────────────────

prv_test_reset();
$domains = $detector->parse_domains( [
	'not-a-url',
	42,
	null,
	['no_url_key' => 'foo'],
	['url' => ''],
] );
prv_assert_equals( [], $domains, 'parse_domains: gracefully skips malformed items' );


// ── Test: P2-A — www-strip must not corrupt non-www domains starting with 'w' ──

prv_test_reset();
$domains = $detector->parse_domains( [
	'https://wikipedia.org/wiki/bpc-157',
	'https://www.examine.com/bpc-157/',
	'https://webmd.com/drug/bpc-157',
] );

prv_assert( in_array( 'wikipedia.org', $domains, true ), 'P2-A: wikipedia.org survives intact (no char-mask corruption)' );
prv_assert( in_array( 'examine.com', $domains, true ),   'P2-A: www.examine.com correctly stripped to examine.com' );
prv_assert( in_array( 'webmd.com', $domains, true ),     'P2-A: webmd.com survives intact (no char-mask corruption)' );
prv_assert_equals( 3, count( $domains ),                  'P2-A: exactly 3 unique domains from 3 distinct URLs' );

exit( prv_test_summary() );
