<?php
/**
 * Tests for PRV_Cron: scheduling, clearing, and registration.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Cron Tests ===\n";

// ── Test: schedule_weekly adds the event ────────────────────────────────

prv_test_reset();
prv_assert( ! PRV_Cron::is_scheduled(), 'schedule_weekly: not scheduled before call' );

PRV_Cron::schedule_weekly();
prv_assert( PRV_Cron::is_scheduled(), 'schedule_weekly: event scheduled after call' );

// ── Test: schedule_weekly is idempotent ──────────────────────────────────

PRV_Cron::schedule_weekly(); // Second call should no-op.
$events = $GLOBALS['prv_test_state']['cron_events'];
prv_assert_equals( 1, count( $events ), 'schedule_weekly: idempotent — second call does not add duplicate' );

// ── Test: clear_schedule removes the event ──────────────────────────────

PRV_Cron::clear_schedule();
prv_assert( ! PRV_Cron::is_scheduled(), 'clear_schedule: event removed after clear' );

// ── Test: clear_schedule is safe when nothing scheduled ─────────────────

prv_test_reset();
PRV_Cron::clear_schedule(); // Should not throw.
prv_assert( ! PRV_Cron::is_scheduled(), 'clear_schedule: safe when nothing scheduled' );

// ── Test: register_hooks registers the cron hook action ─────────────────

prv_test_reset();
$cron = new PRV_Cron();
$cron->register_hooks();

$hooks = array_column( $GLOBALS['prv_test_state']['actions'], 'hook' );
prv_assert( in_array( PRV_CRON_HOOK, $hooks, true ), 'register_hooks: prv_weekly_probe action registered' );

// ── Test: hook constant is correct string ────────────────────────────────

prv_assert_equals( 'prv_weekly_probe', PRV_CRON_HOOK, 'PRV_CRON_HOOK: constant value correct' );

// ── Test: PRV_Activator calls schedule_weekly ────────────────────────────

prv_test_reset();
// Seed options so seed_defaults() doesn't fail.
PRV_Activator::activate();
prv_assert( PRV_Cron::is_scheduled(), 'PRV_Activator::activate: weekly cron scheduled after activation' );

// ── Test: PRV_Deactivator calls clear_schedule ───────────────────────────

PRV_Deactivator::deactivate();
prv_assert( ! PRV_Cron::is_scheduled(), 'PRV_Deactivator::deactivate: cron cleared after deactivation' );

exit( prv_test_summary() );
