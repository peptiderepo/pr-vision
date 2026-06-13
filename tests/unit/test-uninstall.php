<?php
/**
 * Tests for uninstall purge: table drop + options deletion.
 *
 * Exercises PRV_Table_Manager::drop_table() and verifies the uninstall
 * script logic (simulated because it requires WP_UNINSTALL_PLUGIN).
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Uninstall Purge Tests ===\n";

// ── Mock $wpdb to track DROP calls ──────────────────────────────────────

$GLOBALS['prv_test_state']['drop_calls'] = [];

// Extend the stub to record DROP statements.
global $wpdb;
$wpdb = new class extends stdClass_wpdb {
	public function query( string $sql ): bool {
		if ( str_contains( strtoupper( $sql ), 'DROP TABLE' ) ) {
			$GLOBALS['prv_test_state']['drop_calls'][] = $sql;
		}
		if ( str_contains( strtoupper( $sql ), 'DELETE FROM' ) && str_contains( $sql, "prv\\_" ) ) {
			$GLOBALS['prv_test_state']['options_deleted'] = true;
		}
		return true;
	}
};

// ── Test: drop_table issues DROP TABLE IF EXISTS ─────────────────────────

prv_test_reset();
$GLOBALS['prv_test_state']['drop_calls'] = [];
PRV_Table_Manager::drop_table();

prv_assert( ! empty( $GLOBALS['prv_test_state']['drop_calls'] ), 'drop_table: DROP TABLE query executed' );
$drop_sql = $GLOBALS['prv_test_state']['drop_calls'][0] ?? '';
prv_assert( str_contains( strtoupper( $drop_sql ), 'DROP TABLE IF EXISTS' ), 'drop_table: uses DROP TABLE IF EXISTS' );
prv_assert( str_contains( $drop_sql, 'prv_ai_visibility' ), 'drop_table: targets prv_ai_visibility table' );

// ── Test: create_table sets the schema_version option ────────────────────

prv_test_reset();
PRV_Table_Manager::create_table();
$schema_ver = get_option( 'prv_schema_version' );
prv_assert_equals( PRV_SCHEMA_VERSION, $schema_ver, 'create_table: prv_schema_version option set after create' );

// ── Test: get_table_name returns prefixed name ───────────────────────────

prv_test_reset();
$table = PRV_Table_Manager::get_table_name();
prv_assert_equals( 'wp_prv_ai_visibility', $table, 'get_table_name: returns wp_ prefixed table name' );

// ── Test: seed_defaults writes expected option keys ──────────────────────

prv_test_reset();
PRV_Config::seed_defaults();

prv_assert( isset( $GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] ), 'seed_defaults: prv_monthly_budget_usd set' );
prv_assert( isset( $GLOBALS['prv_test_state']['options']['prv_peptides'] ), 'seed_defaults: prv_peptides set' );
prv_assert( isset( $GLOBALS['prv_test_state']['options']['prv_prompt_intents'] ), 'seed_defaults: prv_prompt_intents set' );
prv_assert( isset( $GLOBALS['prv_test_state']['options']['prv_models'] ), 'seed_defaults: prv_models set' );

// ── Test: seed_defaults does not overwrite existing values ───────────────

prv_test_reset();
$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 99.0;
PRV_Config::seed_defaults();
prv_assert_equals( 99.0, $GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'], 'seed_defaults: does not overwrite existing prv_monthly_budget_usd' );

// ── Test: default budget is PRV_DEFAULT_MONTHLY_BUDGET_USD ──────────────

prv_test_reset();
PRV_Config::seed_defaults();
prv_assert_equals( PRV_DEFAULT_MONTHLY_BUDGET_USD, $GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'], 'seed_defaults: default budget = PRV_DEFAULT_MONTHLY_BUDGET_USD (5.0)' );

// ── Test: default peptide count is 12 ───────────────────────────────────

prv_test_reset();
$peptides = PRV_Config::get_peptides();
prv_assert_equals( 12, count( $peptides ), 'get_peptides: default seed has 12 peptides' );

// ── Test: default prompt intent count is 3 ──────────────────────────────

prv_test_reset();
$intents = PRV_Config::get_prompt_intents();
prv_assert_equals( 3, count( $intents ), 'get_prompt_intents: default seed has 3 intents' );

// ── Test: default model count is 3 ──────────────────────────────────────

prv_test_reset();
$models = PRV_Config::get_models();
prv_assert_equals( 3, count( $models ), 'get_models: default seed has 3 models' );
prv_assert( in_array( 'perplexity/sonar', $models, true ), 'get_models: perplexity/sonar in default models' );

exit( prv_test_summary() );
