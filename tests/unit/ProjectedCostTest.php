<?php
/**
 * Tests for projected cost calculation and budget cap logic.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PRV_Exhausting_Ledger extends PRV_Cost_Ledger {
	private int $count = 0;
	public function can_afford( float $e ): bool { return $this->count++ < 1; }
	public function update_row_cost( int $id, float $c ): bool { return true; }
	public function get_month_to_date_usd(): float { return 4.99; }
}

/**
 * @covers PRV_Config
 */
class ProjectedCostTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	public function test_projected_cost_reflects_enabled_models_x_peptides_x_intents(): void {
		PRV_Model_Registry::run_migration_v2();
		$GLOBALS['prv_test_state']['options']['prv_models'] = [
			[ 'id' => 'mdl_a1', 'slug' => 'perplexity/sonar', 'provider' => 'perplexity', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
			[ 'id' => 'mdl_a2', 'slug' => 'openai/gpt-4o-search-preview', 'provider' => 'openrouter', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
		];
		$GLOBALS['prv_test_state']['options'][ PRV_Model_Registry::VERSION_KEY ] = PRV_Model_Registry::SCHEMA_VERSION;
		$GLOBALS['prv_test_state']['options']['prv_peptides']       = [ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ], [ 'slug' => 'tb-500', 'label' => 'TB-500' ] ];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = [ 'what is {peptide}', '{peptide} dosage' ];
		$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd'] = 5.0;
		$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ] = 'weekly';

		$cost = PRV_Config::get_projected_cost();

		$this->assertSame( 8, $cost['probe_count'], '2 models x 2 peptides x 2 intents = 8' );
		$this->assertGreaterThan( 0.0, $cost['per_run_usd'] );
		$this->assertGreaterThan( 0.0, $cost['per_month_usd'] );
		$this->assertGreaterThanOrEqual( $cost['per_run_usd'], $cost['per_month_usd'] );
		$this->assertFalse( $cost['over_cap'] );
	}

	public function test_over_cap_flag_fires_when_projected_exceeds_cap(): void {
		PRV_Model_Registry::run_migration_v2();
		$big = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$big[] = [ 'slug' => "pep-{$i}", 'label' => "Peptide {$i}" ];
		}
		$GLOBALS['prv_test_state']['options']['prv_models'] = [
			[ 'id' => 'mdl_b1', 'slug' => 'perplexity/sonar', 'provider' => 'perplexity', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
			[ 'id' => 'mdl_b2', 'slug' => 'openai/gpt-4o-search-preview', 'provider' => 'openrouter', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
			[ 'id' => 'mdl_b3', 'slug' => 'google/gemini-2.0-flash-001', 'provider' => 'openrouter', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
		];
		$GLOBALS['prv_test_state']['options'][ PRV_Model_Registry::VERSION_KEY ] = PRV_Model_Registry::SCHEMA_VERSION;
		$GLOBALS['prv_test_state']['options']['prv_peptides']                    = $big;
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents']              = [ 'what is {peptide}', '{peptide} dosage', '{peptide} guide' ];
		$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd']          = 5.0;
		$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ]          = 'weekly';

		$cost = PRV_Config::get_projected_cost();
		$this->assertTrue( $cost['over_cap'] );
	}

	public function test_disabled_models_excluded_from_probe_count(): void {
		PRV_Model_Registry::run_migration_v2();
		$GLOBALS['prv_test_state']['options']['prv_models'] = [
			[ 'id' => 'mdl_c1', 'slug' => 'perplexity/sonar', 'provider' => 'perplexity', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
			[ 'id' => 'mdl_c2', 'slug' => 'openai/gpt-4o-search-preview', 'provider' => 'openrouter', 'enabled' => false, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ],
		];
		$GLOBALS['prv_test_state']['options'][ PRV_Model_Registry::VERSION_KEY ] = PRV_Model_Registry::SCHEMA_VERSION;
		$GLOBALS['prv_test_state']['options']['prv_peptides']                    = [ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ] ];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents']              = [ 'what is {peptide}' ];
		$GLOBALS['prv_test_state']['options']['prv_monthly_budget_usd']          = 5.0;
		$GLOBALS['prv_test_state']['options'][ PRV_Config::CADENCE_KEY ]          = 'weekly';

		$cost = PRV_Config::get_projected_cost();
		$this->assertSame( 1, $cost['probe_count'] );
	}

	public function test_truncation_flag_set_when_budget_hit_mid_run(): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();

		$GLOBALS['prv_test_state']['options']['prv_peptides']       = [
			[ 'slug' => 'bpc-157', 'label' => 'BPC-157' ],
			[ 'slug' => 'tb-500', 'label' => 'TB-500' ],
			[ 'slug' => 'mk-677', 'label' => 'MK-677' ],
		];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = [ 'what is {peptide}' ];

		$cited_body = json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'test' ] ] ], 'citations' => [ 'https://peptiderepo.com/bpc-157/' ], 'usage' => [ 'total_tokens' => 50 ] ] );
		$GLOBALS['prv_test_state']['remote_posts'] = array_fill( 0, 3, [ 'response' => [ 'code' => 200 ], 'body' => $cited_body ] );

		$counts = ( new PRV_Probe_Runner( new PRV_Exhausting_Ledger() ) )->run();
		$this->assertTrue( $counts['truncated'] );
		$this->assertSame( 1, (int) get_option( 'prv_last_run_truncated', 0 ) );
	}
}
