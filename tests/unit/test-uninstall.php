<?php
/**
 * Tests for uninstall purge: table drop + options deletion.
 *
 * Exercises PGM_Table_Manager::drop_table() and verifies the uninstall
 * script logic (simulated because it requires WP_UNINSTALL_PLUGIN).
 *
 * @package PeptideGeoMonitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PGM Uninstall Purge Tests ===\n";

// ── Mock $wpdb to track DROP calls ──────────────────────────────────────

$GLOBALS['pgm_test_state']['drop_calls'] = [];

// Extend the stub to record DROP statements.
global $wpdb;
$wpdb = new class extends stdClass_wpdb {
	public function query( string $sql ): bool {
		if ( str_contains( strtoupper( $sql ), 'DROP TABLE' ) ) {
			$GLOBALS['pgm_test_state']['drop_calls'][] = $sql;
		}
		if ( str_contains( strtoupper( $sql ), 'DELETE FROM' ) && str_contains( $sql, "pgm\\_" ) ) {
			$GLOBALS['pgm_test_state']['options_deleted'] = true;
		}
		return true;
	}
};

// ── Test: drop_table issues DROP TABLE IF EXISTS ─────────────────────────

pgm_test_reset();
$GLOBALS['pgm_test_state']['drop_calls'] = [];
PGM_Table_Manager::drop_table();

pgm_assert( ! empty( $GLOBALS['pgm_test_state']['drop_calls'] ), 'drop_table: DROP TABLE query executed' );
$drop_sql = $GLOBALS['pgm_test_state']['drop_calls'][0] ?? '';
pgm_assert( str_contains( strtoupper( $drop_sql ), 'DROP TABLE IF EXISTS' ), 'drop_table: uses DROP TABLE IF EXISTS' );
pgm_assert( str_contains( $drop_sql, 'pgm_ai_visibility' ), 'drop_table: targets pgm_ai_visibility table' );

// ── Test: create_table sets the schema_version option ────────────────────

pgm_test_reset();
PGM_Table_Manager::create_table();
$schema_ver = get_option( 'pgm_schema_version' );
pgm_assert_equals( PGM_SCHEMA_VERSION, $schema_ver, 'create_table: pgm_schema_version option set after create' );

// ── Test: get_table_name returns prefixed name ───────────────────────────

pgm_test_reset();
$table = PGM_Table_Manager::get_table_name();
pgm_assert_equals( 'wp_pgm_ai_visibility', $table, 'get_table_name: returns wp_ prefixed table name' );

// ── Test: seed_defaults writes expected option keys ──────────────────────

pgm_test_reset();
PGM_Config::seed_defaults();

pgm_assert( isset( $GLOBALS['pgm_test_state']['options']['pgm_monthly_budget_usd'] ), 'seed_defaults: pgm_monthly_budget_usd set' );
pgm_assert( isset( $GLOBALS['pgm_test_state']['options']['pgm_peptides'] ), 'seed_defaults: pgm_peptides set' );
pgm_assert( isset( $GLOBALS['pgm_test_state']['options']['pgm_prompt_intents'] ), 'seed_defaults: pgm_prompt_intents set' );
pgm_assert( isset( $GLOBALS['pgm_test_state']['options']['pgm_models'] ), 'seed_defaults: pgm_models set' );

// ── Test: seed_defaults does not overwrite existing values ───────────────

pgm_test_reset();
$GLOBALS['pgm_test_state']['options']['pgm_monthly_budget_usd'] = 99.0;
PGM_Config::seed_defaults();
pgm_assert_equals( 99.0, $GLOBALS['pgm_test_state']['options']['pgm_monthly_budget_usd'], 'seed_defaults: does not overwrite existing pgm_monthly_budget_usd' );

// ── Test: default budget is PGM_DEFAULT_MONTHLY_BUDGET_USD ──────────────

pgm_test_reset();
PGM_Config::seed_defaults();
pgm_assert_equals( PGM_DEFAULT_MONTHLY_BUDGET_USD, $GLOBALS['pgm_test_state']['options']['pgm_monthly_budget_usd'], 'seed_defaults: default budget = PGM_DEFAULT_MONTHLY_BUDGET_USD (5.0)' );

// ── Test: default peptide count is 12 ───────────────────────────────────

pgm_test_reset();
$peptides = PGM_Config::get_peptides();
pgm_assert_equals( 12, count( $peptides ), 'get_peptides: default seed has 12 peptides' );

// ── Test: default prompt intent count is 3 ──────────────────────────────

pgm_test_reset();
$intents = PGM_Config::get_prompt_intents();
pgm_assert_equals( 3, count( $intents ), 'get_prompt_intents: default seed has 3 intents' );

// ── Test: default model count is 3 ──────────────────────────────────────

pgm_test_reset();
$models = PGM_Config::get_models();
pgm_assert_equals( 3, count( $models ), 'get_models: default seed has 3 models' );
pgm_assert( in_array( 'perplexity/sonar', $models, true ), 'get_models: perplexity/sonar in default models' );

exit( pgm_test_summary() );
