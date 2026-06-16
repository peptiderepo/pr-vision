<?php
/**
 * Tests for PRV_Probe_Runner: budget-cap abort, run-lock, model health.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PRV_Mock_Ledger extends PRV_Cost_Ledger {
	public int $afford_count    = 0;
	public int $no_afford_count = 0;
	public int $exhaust_after   = PHP_INT_MAX;

	public function can_afford( float $estimated ): bool {
		$total = $this->afford_count + $this->no_afford_count;
		if ( $total >= $this->exhaust_after ) {
			$this->no_afford_count++;
			return false;
		}
		$this->afford_count++;
		return true;
	}
	public function update_row_cost( int $row_id, float $cost ): bool { return true; }
	public function get_month_to_date_usd(): float { return 0.0; }
}

/**
 * @covers PRV_Probe_Runner
 */
class ProbeRunnerTest extends TestCase {

	private array $sonar_row;
	private string $cited_body;

	protected function setUp(): void {
		prv_test_reset();
		$this->sonar_row  = [ 'id' => 'mdl_t1', 'slug' => 'perplexity/sonar', 'provider' => 'perplexity', 'enabled' => true, 'note' => '', 'health_status' => 'unknown', 'health_probed' => 0, 'health_errors' => 0, 'health_run_id' => null ];
		$this->cited_body = json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'BPC-157 info.' ] ] ], 'citations' => [ 'https://peptiderepo.com/bpc-157/', 'https://examine.com' ], 'usage' => [ 'total_tokens' => 100 ] ] );
	}

	private function setupRun( array $model_rows, array $peptides = null, array $intents = null ): void {
		PRV_Model_Registry::run_migration_v2();
		PRV_Config_Version::maybe_seed_initial_version();
		$GLOBALS['prv_test_state']['options']['prv_models']         = $model_rows;
		$GLOBALS['prv_test_state']['options'][ PRV_Model_Registry::VERSION_KEY ] = PRV_Model_Registry::SCHEMA_VERSION;
		$GLOBALS['prv_test_state']['options']['prv_peptides']       = $peptides ?? [ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ] ];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = $intents  ?? [ 'what is {peptide}' ];
	}

	public function test_basic_run_1x1x1(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$GLOBALS['prv_test_state']['remote_posts'] = [ [ 'response' => [ 'code' => 200 ], 'body' => $this->cited_body ] ];

		$ledger = new PRV_Mock_Ledger();
		$counts = ( new PRV_Probe_Runner( $ledger ) )->run();

		$this->assertSame( 1, $counts['probed'] );
		$this->assertSame( 0, $counts['skipped_budget'] );
		$this->assertSame( 0, $counts['skipped_error'] );
		$this->assertSame( 1, $ledger->afford_count );
		$this->assertNotEmpty( $counts['run_id'] );
		$this->assertFalse( $counts['truncated'] );
	}

	public function test_budget_cap_abort(): void {
		$this->setupRun(
			[ $this->sonar_row ],
			[ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ], [ 'slug' => 'tb-500', 'label' => 'TB-500' ], [ 'slug' => 'mk-677', 'label' => 'MK-677' ] ]
		);
		$GLOBALS['prv_test_state']['remote_posts'] = array_fill( 0, 3, [ 'response' => [ 'code' => 200 ], 'body' => $this->cited_body ] );

		$exh = new PRV_Mock_Ledger();
		$exh->exhaust_after = 1;
		$counts = ( new PRV_Probe_Runner( $exh ) )->run();

		$this->assertSame( 1, $counts['probed'] );
		$this->assertGreaterThanOrEqual( 2, $counts['skipped_budget'] );
		$this->assertSame( 0, $counts['skipped_error'] );
		$this->assertTrue( $counts['truncated'] );
	}

	public function test_http_error_counts_as_skipped_error(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$GLOBALS['prv_test_state']['remote_posts'] = array_fill( 0, 3, [ 'response' => [ 'code' => 500 ], 'body' => 'Error' ] );

		$counts = ( new PRV_Probe_Runner( new PRV_Mock_Ledger() ) )->run();
		$this->assertSame( 0, $counts['probed'] );
		$this->assertGreaterThanOrEqual( 1, $counts['skipped_error'] );
	}

	public function test_wp_error_counts_as_skipped_error(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$wp_error = new WP_Error( 'http_request_failed', 'cURL error 28' );
		$GLOBALS['prv_test_state']['remote_posts'] = [ $wp_error, $wp_error, $wp_error ];

		$counts = ( new PRV_Probe_Runner( new PRV_Mock_Ledger() ) )->run();
		$this->assertSame( 0, $counts['probed'] );
		$this->assertGreaterThanOrEqual( 1, $counts['skipped_error'] );
	}

	public function test_run_returns_all_required_keys(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$GLOBALS['prv_test_state']['remote_posts'] = [ [ 'response' => [ 'code' => 200 ], 'body' => $this->cited_body ] ];

		$counts = ( new PRV_Probe_Runner( new PRV_Mock_Ledger() ) )->run();
		foreach ( [ 'probed', 'skipped_budget', 'skipped_error', 'truncated', 'run_id' ] as $key ) {
			$this->assertArrayHasKey( $key, $counts );
		}
	}

	public function test_run_lock_refused_when_locked(): void {
		$this->setupRun( [ $this->sonar_row ] );
		PRV_Run_Lock::acquire();

		$ledger = new PRV_Mock_Ledger();
		$counts = ( new PRV_Probe_Runner( $ledger ) )->run();

		$this->assertSame( 0, $counts['probed'] );
		$this->assertSame( -1, $counts['skipped_error'] );
		$this->assertSame( 0, $ledger->afford_count );
		PRV_Run_Lock::release();
	}

	public function test_health_updated_after_successful_run(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$GLOBALS['prv_test_state']['remote_posts'] = [ [ 'response' => [ 'code' => 200 ], 'body' => $this->cited_body ] ];

		( new PRV_Probe_Runner( new PRV_Mock_Ledger() ) )->run();

		foreach ( PRV_Model_Registry::get_all() as $m ) {
			if ( 'perplexity/sonar' === $m['slug'] ) {
				$this->assertSame( 'healthy', $m['health_status'] );
				$this->assertSame( 1, $m['health_probed'] );
				$this->assertSame( 0, $m['health_errors'] );
			}
		}
	}

	public function test_lock_released_after_successful_run(): void {
		$this->setupRun( [ $this->sonar_row ] );
		$GLOBALS['prv_test_state']['remote_posts'] = [ [ 'response' => [ 'code' => 200 ], 'body' => $this->cited_body ] ];

		( new PRV_Probe_Runner( new PRV_Mock_Ledger() ) )->run();
		$this->assertFalse( PRV_Run_Lock::is_locked() );
	}
}
