<?php
/**
 * Orchestrates a full probe run: lock + delegate to PRV_Probe_Run_Executor.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Orchestrates a full probe run.
 *
 * Acquires the run-lock, generates a UUID, stamps the config version, then
 * delegates all peptide × intent × model iteration to PRV_Probe_Run_Executor.
 * Released from >300-line obligation by the executor split.
 *
 * Capture ordering (P0) is enforced in PRV_Probe_Run_Executor:
 *   can_afford → probe → persist_result → update_row_cost → write_meta → capture_io
 *
 * Who triggers: PRV_Cron::handle_cron_tick() and PRV_Settings_Page (Run now).
 * Dependencies: PRV_Cost_Ledger, PRV_Capture_Writer, PRV_Run_Lock,
 *               PRV_Config_Version, PRV_Probe_Run_Executor.
 *
 * @see class-prv-probe-run-executor.php — Inner loop implementation.
 * @see class-prv-cost-ledger.php        — Budget cap enforcement.
 * @see class-prv-run-lock.php           — Concurrency guard.
 * @see class-prv-capture-writer.php     — Per-call metadata + I/O writer.
 * @see ARCHITECTURE.md                  — Section Probe run flow v0.3.0.
 * @package PrVision
 */
class PRV_Probe_Runner {

	/**
	 * Budget ledger for cost-cap enforcement.
	 *
	 * @var PRV_Cost_Ledger
	 */
	private PRV_Cost_Ledger $ledger;

	/**
	 * Per-call capture writer (best-effort; never in critical path).
	 *
	 * @var PRV_Capture_Writer
	 */
	private PRV_Capture_Writer $capture;

	/**
	 * Constructor.
	 *
	 * @param PRV_Cost_Ledger|null    $ledger  Injected for testing; auto-created otherwise.
	 * @param PRV_Capture_Writer|null $capture Injected for testing; auto-created otherwise.
	 */
	public function __construct( ?PRV_Cost_Ledger $ledger = null, ?PRV_Capture_Writer $capture = null ) {
		$this->ledger  = $ledger ?? new PRV_Cost_Ledger();
		$this->capture = $capture ?? new PRV_Capture_Writer();
	}

	/**
	 * Execute the full probe run.
	 *
	 * Acquires a run-lock so the cron and "Run now" cannot collide.
	 * Returns early with sentinel -1 in skipped_error when the lock is busy.
	 *
	 * Side effects: HTTP calls, database writes, option writes.
	 *
	 * @return array{probed: int, skipped_budget: int, skipped_error: int, truncated: bool, run_id: string}
	 */
	public function run(): array {
		$counts = array(
			'probed'         => 0,
			'skipped_budget' => 0,
			'skipped_error'  => 0,
			'truncated'      => false,
			'run_id'         => '',
		);

		if ( ! PRV_Run_Lock::acquire() ) {
			$counts['skipped_error'] = -1; // Sentinel: lock busy.
			return $counts;
		}

		try {
			$counts = $this->execute_run();
		} finally {
			PRV_Run_Lock::release();
		}

		return $counts;
	}

	/**
	 * Core run logic executed inside the lock.
	 *
	 * Generates a run UUID, stamps the config version, then hands off to
	 * PRV_Probe_Run_Executor which owns the inner loop.
	 *
	 * Side effects: Same as PRV_Probe_Run_Executor::execute().
	 *
	 * @return array{probed: int, skipped_budget: int, skipped_error: int, truncated: bool, run_id: string}
	 */
	private function execute_run(): array {
		$run_id     = $this->generate_run_id();
		$config_ver = PRV_Config_Version::get_active_version();

		$executor = new PRV_Probe_Run_Executor( $this->ledger, $this->capture );
		return $executor->execute( $run_id, $config_ver );
	}

	/**
	 * Generate a UUID v4 run identifier.
	 *
	 * @return string
	 */
	private function generate_run_id(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}
}
