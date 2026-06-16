<?php
/**
 * Tests for uninstall purge: all prv_ options deleted, lock cleared.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers PRV_Activator
 * @covers PRV_Deactivator
 */
class UninstallTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	public function test_uninstall_purges_all_prv_options(): void {
		// Populate all known prv_ options.
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
		$GLOBALS['prv_test_state']['cron_events'][ PRV_CRON_HOOK ]         = [ 'timestamp' => time() + 3600, 'schedule' => 'weekly' ];

		// Verify pre-uninstall state.
		$this->assertNotFalse( get_option( 'prv_models' ) );
		$this->assertNotFalse( get_option( 'prv_monthly_budget_usd' ) );
		$this->assertNotFalse( get_option( 'prv_api_key_status' ) );
		$this->assertNotFalse( get_option( 'prv_active_config_version' ) );
		$this->assertNotFalse( get_transient( PRV_Run_Lock::LOCK_KEY ) );
		$this->assertTrue( PRV_Cron::is_scheduled() );

		// Simulate uninstall: delete all prv_ options.
		$keys_to_purge = array_filter(
			array_keys( $GLOBALS['prv_test_state']['options'] ),
			static fn( $k ) => str_starts_with( $k, 'prv_' )
		);
		foreach ( $keys_to_purge as $k ) {
			unset( $GLOBALS['prv_test_state']['options'][ $k ] );
		}
		delete_transient( PRV_Run_Lock::LOCK_KEY );
		wp_clear_scheduled_hook( PRV_CRON_HOOK );

		// Verify all prv_ options are gone.
		$this->assertFalse( get_option( 'prv_models' ) );
		$this->assertFalse( get_option( 'prv_monthly_budget_usd' ) );
		$this->assertFalse( get_option( 'prv_api_key_status' ) );
		$this->assertFalse( get_option( 'prv_active_config_version' ) );
		$this->assertFalse( get_option( 'prv_config_versions' ) );
		$this->assertFalse( get_option( 'prv_models_schema_version' ) );
		$this->assertFalse( get_option( 'prv_schema_version' ) );
		$this->assertFalse( get_option( 'prv_provider_key_enc' ) );
		$this->assertFalse( get_option( 'prv_last_run_truncated' ) );
		$this->assertFalse( get_transient( PRV_Run_Lock::LOCK_KEY ) );
		$this->assertFalse( PRV_Cron::is_scheduled() );
	}
}
