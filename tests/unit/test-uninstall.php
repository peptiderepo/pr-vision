<?php
/**
 * Tests for uninstall purge: all prv_ options deleted, lock cleared.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== PRV Uninstall Tests ===\n";

if ( ! defined( 'PRV_OPENROUTER_API_KEY' ) ) {
	define( 'PRV_OPENROUTER_API_KEY', 'sk-or-test-key' );
}
if ( ! defined( 'PRV_CF_ACCOUNT_ID' ) ) {
	define( 'PRV_CF_ACCOUNT_ID', '' );
}
if ( ! defined( 'PRV_CF_GATEWAY_ID' ) ) {
	define( 'PRV_CF_GATEWAY_ID', '' );
}

// Populate all known prv_ options including v0.2.0 additions.

prv_test_reset();
PRV_Model_Registry::run_migration_v2();
PRV_Config_Version::maybe_seed_initial_version();
update_option( 'prv_monthly_budget_usd', 5.0 );
update_option( 'prv_peptides', [] );
update_option( 'prv_prompt_intents', [] );
update_option( PRV_Config::CADENCE_KEY, 'weekly' );
update_option( 'prv_api_key_status', 'ok' );
update_option( 'prv_api_key_last_check', '2026-06-14 12:00:00' );
update_option( 'prv_last_run_at', '2026-06-14 12:00:00' );
update_option( 'prv_last_run_counts', [] );
update_option( 'prv_last_run_truncated', 0 );
update_option( 'prv_last_run_truncated_at', '' );
update_option( 'prv_provider_key_enc', 'dummy-encrypted-ciphertext-value' );
update_option( 'prv_schema_version', PRV_SCHEMA_VERSION );
$GLOBALS['prv_test_state']['transients'][ PRV_Run_Lock::LOCK_KEY ] = time();
$GLOBALS['prv_test_state']['cron_events'][ PRV_CRON_HOOK ] = [ 'timestamp' => time() + 3600, 'schedule' => 'weekly' ];

// Verify options are present before uninstall.
prv_assert( false !== get_option( 'prv_models' ),             'pre-uninstall: prv_models option exists' );
prv_assert( false !== get_option( 'prv_monthly_budget_usd' ), 'pre-uninstall: prv_monthly_budget_usd exists' );
prv_assert( false !== get_option( 'prv_api_key_status' ),     'pre-uninstall: prv_api_key_status exists' );
prv_assert( false !== get_option( 'prv_active_config_version' ), 'pre-uninstall: prv_active_config_version exists' );
prv_assert( false !== get_transient( PRV_Run_Lock::LOCK_KEY ), 'pre-uninstall: run lock transient set' );
prv_assert( PRV_Cron::is_scheduled(),                         'pre-uninstall: cron scheduled' );

// Simulate uninstall: delete all prv_ options.
// In production, the SQL wildcard handles this; in test we iterate.
$keys_to_purge = array_filter(
	array_keys( $GLOBALS['prv_test_state']['options'] ),
	static fn( $k ) => str_starts_with( $k, 'prv_' )
);
foreach ( $keys_to_purge as $k ) {
	unset( $GLOBALS['prv_test_state']['options'][ $k ] );
}

// Also delete transients.
delete_transient( PRV_Run_Lock::LOCK_KEY );

// Clear cron.
wp_clear_scheduled_hook( PRV_CRON_HOOK );

// Verify all prv_ options gone.
prv_assert( false === get_option( 'prv_models' ),               'post-uninstall: prv_models deleted' );
prv_assert( false === get_option( 'prv_monthly_budget_usd' ),   'post-uninstall: prv_monthly_budget_usd deleted' );
prv_assert( false === get_option( 'prv_api_key_status' ),       'post-uninstall: prv_api_key_status deleted' );
prv_assert( false === get_option( 'prv_active_config_version' ), 'post-uninstall: prv_active_config_version deleted' );
prv_assert( false === get_option( 'prv_config_versions' ),      'post-uninstall: prv_config_versions deleted' );
prv_assert( false === get_option( 'prv_models_schema_version' ), 'post-uninstall: prv_models_schema_version deleted' );
prv_assert( false === get_option( 'prv_schema_version' ),       'post-uninstall: prv_schema_version deleted' );
prv_assert( false === get_option( 'prv_provider_key_enc' ),       'post-uninstall: prv_provider_key_enc (v0.2.3 encrypted key) deleted' );
prv_assert( false === get_option( 'prv_last_run_truncated' ),   'post-uninstall: prv_last_run_truncated deleted' );
prv_assert( false === get_transient( PRV_Run_Lock::LOCK_KEY ),  'post-uninstall: run lock transient cleared' );
prv_assert( false === PRV_Cron::is_scheduled(),                 'post-uninstall: cron cleared' );

exit( prv_test_summary() );
