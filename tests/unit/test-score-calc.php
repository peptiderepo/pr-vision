<?php
/**
 * Tests for the visibility score formula in PRV_Ai_Visibility_Collector.
 *
 * Score formula (CONTEXT.md):
 *   base_score     = cited_probes / total_probes
 *   position_bonus = Σ(1/our_position for cited) / total_probes
 *   visibility_score = round((base_score + position_bonus) / 2, 4)
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Score Calculation Tests ===\n";

// Instantiate with a null ledger (no DB calls in these tests).
$collector = new PRV_Ai_Visibility_Collector( null );

// ── Test: zero total → score 0 ──────────────────────────────────────────

prv_test_reset();
prv_assert_equals( 0.0, $collector->compute_score( 0, 0, 0.0 ), 'score: zero total returns 0.0' );

// ── Test: all cited, position 1 ─────────────────────────────────────────

// 10 probes, all cited at position 1
// base_score = 10/10 = 1.0
// position_bonus = (1+1+1+…)/10 = 10/10 = 1.0
// score = (1.0 + 1.0) / 2 = 1.0
prv_test_reset();
prv_assert_equals( 1.0, $collector->compute_score( 10, 10, 10.0 ), 'score: all cited at position 1 → 1.0' );

// ── Test: none cited ────────────────────────────────────────────────────

// 10 probes, 0 cited, position_sum=0
// base_score = 0, position_bonus = 0 → score = 0
prv_test_reset();
prv_assert_equals( 0.0, $collector->compute_score( 0, 10, 0.0 ), 'score: none cited → 0.0' );

// ── Test: half cited at position 2 ──────────────────────────────────────

// 10 probes, 5 cited each at position 2 → position_sum = 5*(1/2) = 2.5
// base_score = 5/10 = 0.5
// position_bonus = 2.5/10 = 0.25
// score = (0.5 + 0.25) / 2 = 0.375
prv_test_reset();
$score = $collector->compute_score( 5, 10, 2.5 );
prv_assert_equals( 0.375, $score, 'score: half cited at position 2 → 0.375' );

// ── Test: single probe, cited at position 3 ─────────────────────────────

// 1 probe, 1 cited, position_sum = 1/3 ≈ 0.3333
// base_score = 1.0
// position_bonus = 0.3333
// score = (1.0 + 0.3333) / 2 ≈ 0.6667 (rounded to 4 decimals)
prv_test_reset();
$score = $collector->compute_score( 1, 1, 1.0 / 3.0 );
prv_assert_equals( 0.6667, $score, 'score: single probe cited at position 3 → 0.6667' );

// ── Test: score is bounded [0, 1] ───────────────────────────────────────

// Extreme: position_sum could theoretically exceed total but that's logically
// impossible with 1-based positions (position_sum ≤ cited_count ≤ total).
// Verify edge case: 1 probe, 1 cited, position 1
prv_test_reset();
prv_assert( $collector->compute_score( 1, 1, 1.0 ) <= 1.0, 'score: never exceeds 1.0' );
prv_assert( $collector->compute_score( 0, 10, 0.0 ) >= 0.0, 'score: never below 0.0' );

// ── Test: precision — 4 decimal places ──────────────────────────────────

prv_test_reset();
$score = $collector->compute_score( 3, 7, 1.5 );
// base = 3/7 ≈ 0.4286, bonus = 1.5/7 ≈ 0.2143, avg ≈ 0.3214
$expected = round( ( 3.0 / 7.0 + 1.5 / 7.0 ) / 2.0, 4 );
prv_assert_equals( $expected, $score, 'score: 4-decimal precision maintained' );

exit( prv_test_summary() );
