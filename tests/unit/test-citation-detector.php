<?php
/**
 * Tests for PGM_Citation_Detector: domain extraction and cite detection.
 *
 * @package PeptideGeoMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PGM Citation Detector Tests ===\n";

// ── Helpers ──────────────────────────────────────────────────────────────

$detector = new PGM_Citation_Detector();

// ── Test: parse plain URL strings ────────────────────────────────────────

pgm_test_reset();
$domains = $detector->parse_domains( [
	'https://peptiderepo.com/bpc-157/',
	'https://www.examine.com/supplements/bpc-157/',
	'https://pubmed.ncbi.nlm.nih.gov/12345',
] );

pgm_assert( in_array( 'peptiderepo.com', $domains, true ), 'parse_domains: peptiderepo.com extracted from URL' );
pgm_assert( in_array( 'examine.com', $domains, true ), 'parse_domains: www. stripped from examine.com' );
pgm_assert( in_array( 'pubmed.ncbi.nlm.nih.gov', $domains, true ), 'parse_domains: full subdomain preserved when not www.' );
pgm_assert_equals( 3, count( $domains ), 'parse_domains: exactly 3 unique domains' );

// ── Test: parse object-style citations (Perplexity format) ───────────────

pgm_test_reset();
$domains = $detector->parse_domains( [
	[ 'url' => 'https://peptiderepo.com/tb-500/' ],
	[ 'url' => 'https://examine.com/tb500' ],
	[ 'url' => 'https://peptiderepo.com/bpc-157/' ], // duplicate domain
] );

pgm_assert_equals( 2, count( $domains ), 'parse_domains: object-style citations, duplicates removed' );
pgm_assert( in_array( 'peptiderepo.com', $domains, true ), 'parse_domains: object-style, peptiderepo.com present' );

// ── Test: parse empty input ──────────────────────────────────────────────

pgm_test_reset();
$domains = $detector->parse_domains( [] );
pgm_assert_equals( [], $domains, 'parse_domains: empty input returns empty array' );

// ── Test: is_cited detection ─────────────────────────────────────────────

pgm_test_reset();
pgm_assert( $detector->is_cited( ['peptiderepo.com', 'examine.com'] ), 'is_cited: returns true when target present' );
pgm_assert( ! $detector->is_cited( ['examine.com', 'pubmed.ncbi.nlm.nih.gov'] ), 'is_cited: returns false when target absent' );
pgm_assert( ! $detector->is_cited( [] ), 'is_cited: returns false for empty list' );

// ── Test: get_our_position ───────────────────────────────────────────────

pgm_test_reset();
$domains = ['examine.com', 'peptiderepo.com', 'pubmed.ncbi.nlm.nih.gov'];
pgm_assert_equals( 2, $detector->get_our_position( $domains ), 'get_our_position: 1-based, position 2' );

$domains_first = ['peptiderepo.com', 'examine.com'];
pgm_assert_equals( 1, $detector->get_our_position( $domains_first ), 'get_our_position: position 1 when first' );

$domains_absent = ['examine.com', 'pubmed.ncbi.nlm.nih.gov'];
pgm_assert_equals( null, $detector->get_our_position( $domains_absent ), 'get_our_position: null when not present' );

// ── Test: malformed / unexpected input ───────────────────────────────────

pgm_test_reset();
$domains = $detector->parse_domains( [
	'not-a-url',
	42,
	null,
	['no_url_key' => 'foo'],
	['url' => ''],
] );
pgm_assert_equals( [], $domains, 'parse_domains: gracefully skips malformed items' );

exit( pgm_test_summary() );
