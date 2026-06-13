<?php
/**
 * Tests for PGM_Cron: scheduling, clearing, and registration.
 *
 * @package PeptideGeoMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PGM Cron Tests ===\n";

// ── Test: schedule_weekly adds the event ────────────────────────────────

pgm_test_reset();
pgm_assert( ! PGM_Cron::is_scheduled(), 'schedule_weekly: not scheduled before call' );

PGM_Cron::schedule_weekly();
pgm_assert( PGM_Cron::is_scheduled(), 'schedule_weekly: event scheduled after call' );

// ── Test: schedule_weekly is idempotent ──────────────────────────────────

PGM_Cron::schedule_weekly(); // Second call should no-op.
$events = $GLOBALS['pgm_test_state']['cron_events'];
pgm_assert_equals( 1, count( $events ), 'schedule_weekly: idempotent — second call does not add duplicate' );

// ── Test: clear_schedule removes the event ──────────────────────────────

PGM_Cron::clear_schedule();
pgm_assert( ! PGM_Cron::is_scheduled(), 'clear_schedule: event removed after clear' );

// ── Test: clear_schedule is safe when nothing scheduled ─────────────────

pgm_test_reset();
PGM_Cron::clear_schedule(); // Should not throw.
pgm_assert( ! PGM_Cron::is_scheduled(), 'clear_schedule: safe when nothing scheduled' );

// ── Test: register_hooks registers the cron hook action ─────────────────

pgm_test_reset();
$cron = new PGM_Cron();
$cron->register_hooks();

$hooks = array_column( $GLOBALS['pgm_test_state']['actions'], 'hook' );
pgm_assert( in_array( PGM_CRON_HOOK, $hooks, true ), 'register_hooks: pgm_weekly_probe action registered' );

// ── Test: hook constant is correct string ────────────────────────────────

pgm_assert_equals( 'pgm_weekly_probe', PGM_CRON_HOOK, 'PGM_CRON_HOOK: constant value correct' );

// ── Test: PGM_Activator calls schedule_weekly ────────────────────────────

pgm_test_reset();
// Seed options so seed_defaults() doesn't fail.
PGM_Activator::activate();
pgm_assert( PGM_Cron::is_scheduled(), 'PGM_Activator::activate: weekly cron scheduled after activation' );

// ── Test: PGM_Deactivator calls clear_schedule ───────────────────────────

PGM_Deactivator::deactivate();
pgm_assert( ! PGM_Cron::is_scheduled(), 'PGM_Deactivator::deactivate: cron cleared after deactivation' );

exit( pgm_test_summary() );
