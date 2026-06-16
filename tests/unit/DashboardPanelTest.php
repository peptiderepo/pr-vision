<?php
/**
 * Tests for PRV_Ai_Visibility_Panel and PRV_Dashboard_Renderer data-shaping.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class DashboardPanelTest extends TestCase {

	private PRV_Ai_Visibility_Panel $panel;

	protected function setUp(): void {
		prv_test_reset();
		$this->panel = new PRV_Ai_Visibility_Panel();
	}

	private function callPrivate( object $obj, string $method, array $args = [] ): mixed {
		$ref = new ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $obj, $args );
	}

	public function test_health_pill_empty_map_is_neutral(): void {
		$result = $this->callPrivate( $this->panel, 'derive_health_pill_state', [ [] ] );
		$this->assertSame( 'neutral', $result['state'] );
	}

	public function test_health_pill_all_healthy_is_ok(): void {
		$result = $this->callPrivate( $this->panel, 'derive_health_pill_state', [
			[ 'a' => [ 'health_status' => 'healthy' ], 'b' => [ 'health_status' => 'healthy' ] ],
		] );
		$this->assertSame( 'ok', $result['state'] );
		$this->assertStringContainsString( 'healthy', $result['label'] );
	}

	public function test_health_pill_one_retired_is_warn(): void {
		$result = $this->callPrivate( $this->panel, 'derive_health_pill_state', [
			[ 'a' => [ 'health_status' => 'healthy' ], 'b' => [ 'health_status' => 'retired' ] ],
		] );
		$this->assertSame( 'warn', $result['state'] );
		$this->assertStringContainsString( '1', $result['label'] );
	}

	public function test_health_pill_disabled_only_is_neutral(): void {
		$result = $this->callPrivate( $this->panel, 'derive_health_pill_state', [
			[ 'a' => [ 'health_status' => 'disabled' ] ],
		] );
		$this->assertSame( 'neutral', $result['state'] );
	}

	public function test_build_delta_html_positive_gets_up_class(): void {
		$renderer = new PRV_Dashboard_Renderer();
		$out      = $renderer->build_delta_html( 0.10, 0.08 );
		$this->assertStringContainsString( 'prv-delta--up', $out );
		$this->assertStringContainsString( '+', $out );
		$this->assertStringContainsString( '2.00', $out );
	}

	public function test_build_delta_html_negative_gets_down_class(): void {
		$renderer = new PRV_Dashboard_Renderer();
		$this->assertStringContainsString( 'prv-delta--down', $renderer->build_delta_html( 0.05, 0.08 ) );
	}

	public function test_build_delta_html_zero_gets_flat_class(): void {
		$renderer = new PRV_Dashboard_Renderer();
		$this->assertStringContainsString( 'prv-delta--flat', $renderer->build_delta_html( 0.08, 0.08 ) );
	}

	public function test_build_delta_html_null_previous_returns_empty(): void {
		$renderer = new PRV_Dashboard_Renderer();
		$this->assertSame( '', $renderer->build_delta_html( 0.08, null ) );
	}

	public function test_collector_trendline_includes_config_version(): void {
		// Collector calls get_results() twice: (1) trendline, (2) standings.
		// Use wpdb_results_queue to provide separate row sets for each call.
		$GLOBALS['prv_test_state']['wpdb_results_queue'] = [
			// 1st call: trendline rows.
			[
				[ 'run_id' => 'run-001', 'captured_at' => '2026-06-01 10:00:00', 'cited_count' => 2, 'total_count' => 10, 'position_sum' => 0.5, 'config_version' => 1 ],
				[ 'run_id' => 'run-002', 'captured_at' => '2026-06-08 10:00:00', 'cited_count' => 3, 'total_count' => 10, 'position_sum' => 0.6, 'config_version' => 2 ],
			],
			// 2nd call: standings rows (empty — not needed for this assertion).
			[],
		];
		$GLOBALS['prv_test_state']['wpdb_var'] = '2026-06-08 10:00:00';

		$data = ( new PRV_Ai_Visibility_Collector() )->collect();

		$this->assertArrayHasKey( 'trendline', $data );
		$this->assertCount( 2, $data['trendline'] );
		$this->assertSame( 1, $data['trendline'][0]['config_version'] );
		$this->assertSame( 2, $data['trendline'][1]['config_version'] );
	}

	public function test_collector_payload_has_new_keys(): void {
		// Both get_results() calls return [] (no data needed for key-presence check).
		$GLOBALS['prv_test_state']['wpdb_results'] = [];
		$GLOBALS['prv_test_state']['wpdb_var']     = null;

		$data = ( new PRV_Ai_Visibility_Collector() )->collect();
		$this->assertArrayHasKey( 'last_run_counts', $data );
		$this->assertIsArray( $data['last_run_counts'] );
		$this->assertArrayHasKey( 'config_versions', $data );
		$this->assertIsArray( $data['config_versions'] );
	}

	public function test_null_config_version_preserved(): void {
		$GLOBALS['prv_test_state']['wpdb_results_queue'] = [
			// 1st call: trendline rows.
			[ [ 'run_id' => 'run-001', 'captured_at' => '2026-06-01 10:00:00', 'cited_count' => 1, 'total_count' => 5, 'position_sum' => 0.25, 'config_version' => null ] ],
			// 2nd call: standings rows (empty).
			[],
		];
		$GLOBALS['prv_test_state']['wpdb_var'] = '2026-06-01 10:00:00';

		$data = ( new PRV_Ai_Visibility_Collector() )->collect();
		$this->assertCount( 1, $data['trendline'] );
		$this->assertNull( $data['trendline'][0]['config_version'] );
	}

	public function test_single_version_trendline_produces_no_cfg_note(): void {
		$renderer = new PRV_Dashboard_Renderer();
		ob_start();
		$renderer->render_trendline( [
			[ 'run_id' => 'r1', 'captured_at' => '2026-06-01 10:00:00', 'score' => 0.0, 'config_version' => 1 ],
			[ 'run_id' => 'r2', 'captured_at' => '2026-06-08 10:00:00', 'score' => 0.0, 'config_version' => 1 ],
		] );
		$out = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'prv-cfg-note', $out );
		$this->assertStringContainsString( 'prv-trendline-chart', $out );
	}

	public function test_two_version_trendline_produces_cfg_note(): void {
		$renderer = new PRV_Dashboard_Renderer();
		ob_start();
		$renderer->render_trendline( [
			[ 'run_id' => 'r1', 'captured_at' => '2026-06-01 10:00:00', 'score' => 0.0, 'config_version' => 1 ],
			[ 'run_id' => 'r2', 'captured_at' => '2026-06-08 10:00:00', 'score' => 0.0, 'config_version' => 2 ],
		] );
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'prv-cfg-note', $out );
		$this->assertStringContainsString( 'configMarker', $out );
		$this->assertStringContainsString( 'prv-leg-cfg', $out );
		$this->assertStringContainsString( 'prv-noscript', $out );
		$this->assertStringContainsString( 'prv-chart-fallback', $out );
	}
}
