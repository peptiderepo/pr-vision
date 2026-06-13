<?php
/**
 * Tests for PRV_Cost_Ledger: MTD cost retrieval, budget pre-check, and
 * the budget-cap abort behaviour.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Cost Ledger Tests ===\n";

// ── Test: can_afford when below cap ─────────────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = '2.50'; // MTD spent

$ledger = new PRV_Cost_Ledger();
prv_assert( $ledger->can_afford( 1.0 ), 'can_afford: 2.50 spent + 1.00 = 3.50 < 5.00 → true' );

// ── Test: can_afford when at cap ─────────────────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = '5.00';

$ledger = new PRV_Cost_Ledger();
prv_assert( ! $ledger->can_afford( 0.01 ), 'can_afford: 5.00 spent + 0.01 > 5.00 → false' );

// ── Test: can_afford when exactly at cap ─────────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = '4.99';

$ledger = new PRV_Cost_Ledger();
prv_assert( $ledger->can_afford( 0.01 ), 'can_afford: 4.99 + 0.01 = 5.00 exactly → true (at cap is allowed)' );

// ── Test: get_remaining_budget_usd ──────────────────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = '3.25';

$ledger = new PRV_Cost_Ledger();
prv_assert_equals( 1.75, round( $ledger->get_remaining_budget_usd(), 2 ), 'get_remaining_budget_usd: 5.00 - 3.25 = 1.75' );

// ── Test: get_remaining_budget_usd never negative ────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = '10.00'; // over cap

$ledger = new PRV_Cost_Ledger();
prv_assert_equals( 0.0, $ledger->get_remaining_budget_usd(), 'get_remaining_budget_usd: clamps to 0.0 when over cap' );

// ── Test: get_month_to_date_usd with null DB result (no rows yet) ────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
$GLOBALS['prv_test_state']['wpdb_var'] = null;

$ledger = new PRV_Cost_Ledger();
prv_assert_equals( 0.0, $ledger->get_month_to_date_usd(), 'get_month_to_date_usd: null DB result → 0.0' );

// ── Test: update_row_cost returns true ──────────────────────────────────

prv_test_reset();
$ledger = new PRV_Cost_Ledger();
prv_assert( $ledger->update_row_cost( 42, 0.00123456 ), 'update_row_cost: returns true on success' );

// ── Test: budget abort mid-run (runner stops when cap hit) ──────────────

prv_test_reset();
// Simulate a nearly-exhausted budget.
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 0.001; // $0.001 cap
$GLOBALS['prv_test_state']['wpdb_var'] = '0.001'; // fully spent

$ledger2 = new PRV_Cost_Ledger();
prv_assert( ! $ledger2->can_afford( 0.000001 ), 'budget abort: can_afford returns false at cap → runner would stop' );

exit( prv_test_summary() );
