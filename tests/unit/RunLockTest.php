<?php
/**
 * Tests for PRV_Run_Lock: acquire/release/is_locked concurrency guard.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers PRV_Run_Lock
 */
class RunLockTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	public function test_acquire_returns_true_when_free(): void {
		$this->assertTrue( PRV_Run_Lock::acquire() );
		PRV_Run_Lock::release();
	}

	public function test_is_locked_true_after_acquire(): void {
		PRV_Run_Lock::acquire();
		$this->assertTrue( PRV_Run_Lock::is_locked() );
		PRV_Run_Lock::release();
	}

	public function test_second_acquire_returns_false(): void {
		PRV_Run_Lock::acquire();
		$this->assertFalse( PRV_Run_Lock::acquire() );
		PRV_Run_Lock::release();
	}

	public function test_release_clears_lock(): void {
		PRV_Run_Lock::acquire();
		PRV_Run_Lock::release();
		$this->assertFalse( PRV_Run_Lock::is_locked() );
	}

	public function test_acquire_succeeds_again_after_release(): void {
		PRV_Run_Lock::acquire();
		PRV_Run_Lock::release();
		$this->assertTrue( PRV_Run_Lock::acquire() );
		PRV_Run_Lock::release();
	}

	public function test_locked_since_returns_null_when_not_locked(): void {
		$this->assertNull( PRV_Run_Lock::locked_since() );
	}

	public function test_locked_since_non_null_when_locked(): void {
		PRV_Run_Lock::acquire();
		$since = PRV_Run_Lock::locked_since();
		$this->assertNotNull( $since );
		$this->assertGreaterThanOrEqual( 0, $since );
		PRV_Run_Lock::release();
	}

	public function test_runner_refuses_when_locked(): void {
		PRV_Model_Registry::run_migration_v2();
		$GLOBALS['prv_test_state']['options']['prv_peptides']       = [ [ 'slug' => 'bpc-157', 'label' => 'BPC-157' ] ];
		$GLOBALS['prv_test_state']['options']['prv_prompt_intents'] = [ 'what is {peptide}' ];

		PRV_Run_Lock::acquire();
		$counts = ( new PRV_Probe_Runner( new PRV_Cost_Ledger() ) )->run();

		$this->assertSame( 0, $counts['probed'] );
		$this->assertSame( -1, $counts['skipped_error'] );
		PRV_Run_Lock::release();
	}

	public function test_lock_released_after_runner_completes(): void {
		PRV_Model_Registry::run_migration_v2();
		$this->assertFalse( PRV_Run_Lock::is_locked() );
	}
}
