<?php
/**
 * Tests for PRV_Cron_Guard: self-healing cron scheduling.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Cron Guard Tests ===\n";

// ── Test: ensure_scheduled schedules prv_weekly_probe when missing ──────

prv_test_reset();
prv_assert( false === wp_next_scheduled( PRV_CRON_HOOK ), 'guard/weekly: not scheduled before call' );
prv_assert( false === wp_next_scheduled( PRV_Prune_Cron::PRUNE_HOOK ), 'guard/prune: not scheduled before call' );

$guard = new PRV_Cron_Guard();
$guard->ensure_scheduled();

prv_assert( false !== wp_next_scheduled( PRV_CRON_HOOK ), 'guard/weekly: prv_weekly_probe scheduled after ensure_scheduled()' );

// ── Test: ensure_scheduled schedules prv_daily_prune when missing ────────

prv_assert( false !== wp_next_scheduled( PRV_Prune_Cron::PRUNE_HOOK ), 'guard/prune: prv_daily_prune scheduled after ensure_scheduled()' );

// ── Test: idempotent -- called twice, still only one event each ──────────

$ts_weekly_1 = wp_next_scheduled( PRV_CRON_HOOK );
$ts_prune_1  = wp_next_scheduled( PRV_Prune_Cron::PRUNE_HOOK );

$guard->ensure_scheduled(); // Second call.

$ts_weekly_2 = wp_next_scheduled( PRV_CRON_HOOK );
$ts_prune_2  = wp_next_scheduled( PRV_Prune_Cron::PRUNE_HOOK );

prv_assert( $ts_weekly_1 === $ts_weekly_2, 'guard/idempotent: weekly event timestamp unchanged after second call' );
prv_assert( $ts_prune_1 === $ts_prune_2, 'guard/idempotent: prune event timestamp unchanged after second call' );

// Verify the test state has exactly one entry per hook (no duplicates).
$cron_events = $GLOBALS['prv_test_state']['cron_events'];
prv_assert( array_key_exists( PRV_CRON_HOOK, $cron_events ), 'guard/idempotent: exactly one weekly event entry' );
prv_assert( array_key_exists( PRV_Prune_Cron::PRUNE_HOOK, $cron_events ), 'guard/idempotent: exactly one prune event entry' );
prv_assert( 2 === count( $cron_events ), 'guard/idempotent: only two cron event entries total' );

// ── Test: guard uses same recurrence as canonical schedulers ─────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ] = 'weekly';
PRV_Cron::schedule();
$canonical_weekly = $GLOBALS['prv_test_state']['cron_events'][ PRV_CRON_HOOK ]['schedule'] ?? null;

prv_test_reset();
PRV_Prune_Cron::schedule();
$canonical_prune = $GLOBALS['prv_test_state']['cron_events'][ PRV_Prune_Cron::PRUNE_HOOK ]['schedule'] ?? null;

// Now verify guard-scheduled events share the same recurrences.
prv_test_reset();
$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ] = 'weekly';
$guard2 = new PRV_Cron_Guard();
$guard2->ensure_scheduled();

$guard_weekly = $GLOBALS['prv_test_state']['cron_events'][ PRV_CRON_HOOK ]['schedule'] ?? null;
$guard_prune  = $GLOBALS['prv_test_state']['cron_events'][ PRV_Prune_Cron::PRUNE_HOOK ]['schedule'] ?? null;

prv_assert_equals( $canonical_weekly, $guard_weekly, 'guard/recurrence: weekly recurrence matches canonical PRV_Cron::schedule()' );
prv_assert_equals( $canonical_prune, $guard_prune, 'guard/recurrence: prune recurrence matches canonical PRV_Prune_Cron::schedule()' );

// ── Test: no-op when both events already scheduled ───────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ] = 'weekly';
PRV_Cron::schedule();
PRV_Prune_Cron::schedule();

$before = $GLOBALS['prv_test_state']['cron_events'];

$guard3 = new PRV_Cron_Guard();
$guard3->ensure_scheduled();

$after = $GLOBALS['prv_test_state']['cron_events'];

prv_assert( $before === $after, 'guard/noop: cron_events unchanged when all events already scheduled' );

// ── Test: register_hooks adds the init action ────────────────────────────

prv_test_reset();
$guard4 = new PRV_Cron_Guard();
$guard4->register_hooks();

$hooks = array_column( $GLOBALS['prv_test_state']['actions'], 'hook' );
prv_assert( in_array( 'init', $hooks, true ), 'guard/register_hooks: init action registered' );

exit( prv_test_summary() );
