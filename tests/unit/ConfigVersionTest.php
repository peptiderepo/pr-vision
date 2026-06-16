<?php
/**
 * Tests for PRV_Config_Version: config hash, bump, version records.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers PRV_Config_Version
 */
class ConfigVersionTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	public function test_maybe_seed_initial_version_creates_v1(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		$versions = PRV_Config_Version::get_all_versions();
		$this->assertGreaterThanOrEqual( 1, count( $versions ), 'seed: at least 1 version record after initial seed' );
		$this->assertSame( 1, (int) $versions[0]['version'], 'seed: first version number is 1' );
		$this->assertNotEmpty( $versions[0]['hash'], 'seed: version has a hash' );
		$this->assertSame( 1, PRV_Config_Version::get_active_version(), 'seed: active version is 1' );
	}

	public function test_maybe_seed_is_idempotent(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();
		PRV_Config_Version::maybe_seed_initial_version();

		$this->assertCount( 1, PRV_Config_Version::get_all_versions(), 'seed idempotent: still 1 version after second seed call' );
	}

	public function test_bump_returns_null_when_config_unchanged(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		$bump = PRV_Config_Version::bump_version_if_changed();

		$this->assertNull( $bump, 'bump: returns null when config unchanged' );
		$this->assertSame( 1, PRV_Config_Version::get_active_version(), 'bump: active version stays 1 when config unchanged' );
	}

	public function test_bump_creates_new_version_when_config_changes(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		PRV_Model_Registry::add( 'anthropic/claude-3-haiku', 'openrouter', true, 'New model' );
		$new_ver = PRV_Config_Version::bump_version_if_changed();

		$this->assertNotNull( $new_ver, 'bump: returns new version number when config changed' );
		$this->assertSame( 2, (int) $new_ver, 'bump: new version is 2' );
		$this->assertSame( 2, PRV_Config_Version::get_active_version(), 'bump: active version updated to 2' );
		$this->assertCount( 2, PRV_Config_Version::get_all_versions(), 'bump: 2 version records exist' );
	}

	public function test_would_change_detects_config_diff(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		$original_hash = PRV_Config_Version::compute_hash();
		$this->assertFalse( PRV_Config_Version::would_change( $original_hash ), 'would_change: false when hash is current' );

		PRV_Model_Registry::add( 'x/new-model', 'openrouter', true );
		$new_hash = PRV_Config_Version::compute_hash();
		$this->assertTrue( PRV_Config_Version::would_change( $new_hash ), 'would_change: true when config differs' );
	}

	public function test_compute_hash_is_stable_for_same_config(): void {
		PRV_Model_Registry::run_migration_v2();
		$h1 = PRV_Config_Version::compute_hash();
		$h2 = PRV_Config_Version::compute_hash();

		$this->assertSame( $h1, $h2, 'compute_hash: stable for same config' );
	}

	public function test_runner_stamps_config_version(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		$this->assertSame( 1, PRV_Config_Version::get_active_version(), 'version stamp: active version is 1 before run' );

		$GLOBALS['prv_test_state']['options']['prv_peptides']       = [ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ] ];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = [ 'what is {peptide}' ];
		$GLOBALS['prv_test_state']['options']['prv_models']         = [
			[ 'id' => 'mdl_test01', 'slug' => 'perplexity/sonar', 'provider' => 'perplexity', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
		];
		$GLOBALS['prv_test_state']['options'][ PRV_Model_Registry::VERSION_KEY ] = PRV_Model_Registry::SCHEMA_VERSION;

		$cited_body = json_encode( [
			'choices'   => [ [ 'message' => [ 'content' => 'test' ] ] ],
			'citations' => [ 'https://peptiderepo.com/bpc-157/' ],
			'usage'     => [ 'total_tokens' => 50 ],
		] );
		$GLOBALS['prv_test_state']['remote_posts'] = [
			[ 'response' => [ 'code' => 200 ], 'body' => $cited_body ],
		];

		$runner = new PRV_Probe_Runner( new PRV_Cost_Ledger() );
		$result = $runner->run();

		$this->assertSame( 1, $result['probed'], 'version stamp: runner completes 1 probe' );
		$this->assertSame( 1, PRV_Config_Version::get_active_version(), 'version stamp: config version unchanged after run' );
	}
}
