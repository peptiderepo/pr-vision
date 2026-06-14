<?php
/**
 * Tests for PRV_Ai_Visibility_Panel and PRV_Dashboard_Renderer data-shaping added
 * in v0.2.1: config-version trendline annotation, KPI health pill, and collector
 * last_run_counts / config_versions payload fields.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== test-dashboard-panel.php ===\n";

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Invoke a private or protected method on an object instance.
 *
 * @param object $obj    Instance to invoke on.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 *
 * @return mixed
 */
function prv_call_private( object $obj, string $method, array $args = array() ) {
	$ref = new ReflectionMethod( $obj, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $obj, $args );
}

// ── 1. Panel: derive_health_pill_state ───────────────────────────────────────

echo "\n-- derive_health_pill_state --\n";

$panel = new PRV_Ai_Visibility_Panel();

$result = prv_call_private( $panel, 'derive_health_pill_state', array( array() ) );
prv_assert_equals( 'neutral', $result['state'], 'health pill: empty map → neutral' );

$result = prv_call_private(
	$panel,
	'derive_health_pill_state',
	array(
		array(
			'slug-a' => array( 'health_status' => 'healthy' ),
			'slug-b' => array( 'health_status' => 'healthy' ),
		),
	)
);
prv_assert_equals( 'ok', $result['state'], 'health pill: all healthy → ok' );
prv_assert( false !== strpos( $result['label'], 'healthy' ), 'health pill: ok label contains "healthy"' );

$result = prv_call_private(
	$panel,
	'derive_health_pill_state',
	array(
		array(
			'slug-a' => array( 'health_status' => 'healthy' ),
			'slug-b' => array( 'health_status' => 'retired' ),
		),
	)
);
prv_assert_equals( 'warn', $result['state'], 'health pill: one retired → warn' );
prv_assert( false !== strpos( $result['label'], '1' ), 'health pill: warn label contains count "1"' );

$result = prv_call_private(
	$panel,
	'derive_health_pill_state',
	array(
		array(
			'slug-a' => array( 'health_status' => 'retired' ),
			'slug-b' => array( 'health_status' => 'retired' ),
		),
	)
);
prv_assert_equals( 'warn', $result['state'], 'health pill: two retired → warn' );
prv_assert( false !== strpos( $result['label'], '2' ), 'health pill: warn label contains count "2"' );

$result = prv_call_private(
	$panel,
	'derive_health_pill_state',
	array( array( 'slug-a' => array( 'health_status' => 'disabled' ) ) )
);
prv_assert_equals( 'neutral', $result['state'], 'health pill: disabled-only → neutral' );

$result = prv_call_private(
	$panel,
	'derive_health_pill_state',
	array( array( 'slug-a' => array( 'health_status' => 'unknown' ) ) )
);
prv_assert_equals( 'neutral', $result['state'], 'health pill: unknown status → neutral' );

// ── 2. Dashboard Renderer: build_delta_html ──────────────────────────────────

echo "\n-- PRV_Dashboard_Renderer::build_delta_html --\n";

$renderer = new PRV_Dashboard_Renderer();

$delta_up   = $renderer->build_delta_html( 0.10, 0.08 );
$delta_down = $renderer->build_delta_html( 0.05, 0.08 );
$delta_flat = $renderer->build_delta_html( 0.08, 0.08 );
$delta_null = $renderer->build_delta_html( 0.08, null );

prv_assert( false !== strpos( $delta_up, 'prv-delta--up' ), 'delta: positive → --up class' );
prv_assert( false !== strpos( $delta_up, '+' ), 'delta: positive → "+" prefix' );
prv_assert( false !== strpos( $delta_down, 'prv-delta--down' ), 'delta: negative → --down class' );
prv_assert( false !== strpos( $delta_flat, 'prv-delta--flat' ), 'delta: zero → --flat class' );
prv_assert_equals( '', $delta_null, 'delta: null previous → empty string' );

// Precision: 0.10 - 0.08 = +2.00 pp
prv_assert( false !== strpos( $delta_up, '2.00' ), 'delta: value formatted to 2dp' );

// ── 3. Collector: trendline includes config_version ──────────────────────────

echo "\n-- collector trendline config_version field --\n";

prv_test_reset();

$GLOBALS['prv_test_state']['wpdb_results'] = array(
	array(
		'run_id'         => 'run-001',
		'captured_at'    => '2026-06-01 10:00:00',
		'cited_count'    => 2,
		'total_count'    => 10,
		'position_sum'   => 0.5,
		'config_version' => 1,
	),
	array(
		'run_id'         => 'run-002',
		'captured_at'    => '2026-06-08 10:00:00',
		'cited_count'    => 3,
		'total_count'    => 10,
		'position_sum'   => 0.6,
		'config_version' => 2,
	),
);
$GLOBALS['prv_test_state']['wpdb_var'] = '2026-06-08 10:00:00';

$collector = new PRV_Ai_Visibility_Collector();
$data      = $collector->collect();

prv_assert( isset( $data['trendline'] ), 'collector: trendline key present' );
prv_assert_equals( 2, count( $data['trendline'] ), 'collector: two trendline rows' );
prv_assert( array_key_exists( 'config_version', $data['trendline'][0] ), 'trendline[0] has config_version key' );
prv_assert_equals( 1, $data['trendline'][0]['config_version'], 'trendline[0].config_version = 1' );
prv_assert_equals( 2, $data['trendline'][1]['config_version'], 'trendline[1].config_version = 2' );

// ── 4. Collector: last_run_counts and config_versions keys present ────────────

echo "\n-- collector: new payload keys --\n";

prv_assert( isset( $data['last_run_counts'] ), 'collector: last_run_counts key present' );
prv_assert( is_array( $data['last_run_counts'] ), 'collector: last_run_counts is array' );
prv_assert( isset( $data['config_versions'] ), 'collector: config_versions key present' );
prv_assert( is_array( $data['config_versions'] ), 'collector: config_versions is array' );

// ── 5. Collector: null config_version handled gracefully ─────────────────────

echo "\n-- collector: null config_version row --\n";

prv_test_reset();
$GLOBALS['prv_test_state']['wpdb_results'] = array(
	array(
		'run_id'         => 'run-001',
		'captured_at'    => '2026-06-01 10:00:00',
		'cited_count'    => 1,
		'total_count'    => 5,
		'position_sum'   => 0.25,
		'config_version' => null,
	),
);
$GLOBALS['prv_test_state']['wpdb_var'] = '2026-06-01 10:00:00';

$collector2 = new PRV_Ai_Visibility_Collector();
$data2      = $collector2->collect();

prv_assert_equals( 1, count( $data2['trendline'] ), 'null config_version row included' );
prv_assert( null === $data2['trendline'][0]['config_version'], 'null config_version preserved as null' );

// ── 6. Collector: collect_model_health from registry ─────────────────────────

echo "\n-- collector: collect_model_health --\n";

prv_test_reset();

$GLOBALS['prv_test_state']['options']['prv_models_schema_version'] = 2;
$GLOBALS['prv_test_state']['options']['prv_models'] = array(
	array(
		'id'            => 'mdl_aaa',
		'slug'          => 'perplexity/sonar',
		'provider'      => 'perplexity',
		'enabled'       => true,
		'note'          => '',
		'health_status' => 'healthy',
		'health_probed' => 10,
		'health_errors' => 0,
		'health_run_id' => 'run-001',
	),
	array(
		'id'            => 'mdl_bbb',
		'slug'          => 'openai/gpt-4o-search-preview',
		'provider'      => 'openrouter',
		'enabled'       => true,
		'note'          => '',
		'health_status' => 'retired',
		'health_probed' => 0,
		'health_errors' => 5,
		'health_run_id' => 'run-001',
	),
);
$GLOBALS['prv_test_state']['wpdb_results'] = array();
$GLOBALS['prv_test_state']['wpdb_var']     = null;

$collector3      = new PRV_Ai_Visibility_Collector();
$data3           = $collector3->collect();
$last_run_counts = $data3['last_run_counts'];

prv_assert( isset( $last_run_counts['perplexity/sonar'] ), 'sonar slug present in last_run_counts' );
prv_assert_equals( 'healthy', $last_run_counts['perplexity/sonar']['health_status'], 'sonar = healthy' );
prv_assert( isset( $last_run_counts['openai/gpt-4o-search-preview'] ), 'gpt slug present in last_run_counts' );
prv_assert_equals( 'retired', $last_run_counts['openai/gpt-4o-search-preview']['health_status'], 'gpt = retired' );

// Health pill should be warn from this data.
$pill = prv_call_private( $panel, 'derive_health_pill_state', array( $last_run_counts ) );
prv_assert_equals( 'warn', $pill['state'], 'health pill from registry data → warn' );

// ── 7. Renderer: trendline marker detection ───────────────────────────────────

echo "\n-- renderer: config-change marker detection (output capture) --\n";

// Verify that a single-version trendline produces no cfg-note.
ob_start();
$renderer->render_trendline(
	array(
		array( 'run_id' => 'r1', 'captured_at' => '2026-06-01 10:00:00', 'score' => 0.0, 'config_version' => 1 ),
		array( 'run_id' => 'r2', 'captured_at' => '2026-06-08 10:00:00', 'score' => 0.0, 'config_version' => 1 ),
	)
);
$out_no_marker = ob_get_clean();
prv_assert( false === strpos( $out_no_marker, 'prv-cfg-note' ), 'no marker: no cfg-note rendered when config version unchanged' );
prv_assert( false !== strpos( $out_no_marker, 'prv-trendline-chart' ), 'no marker: canvas present' );

// Verify that a two-version trendline produces cfg-note and markerIdx.
ob_start();
$renderer->render_trendline(
	array(
		array( 'run_id' => 'r1', 'captured_at' => '2026-06-01 10:00:00', 'score' => 0.0, 'config_version' => 1 ),
		array( 'run_id' => 'r2', 'captured_at' => '2026-06-08 10:00:00', 'score' => 0.0, 'config_version' => 2 ),
	)
);
$out_with_marker = ob_get_clean();
prv_assert( false !== strpos( $out_with_marker, 'prv-cfg-note' ), 'marker: cfg-note rendered when config version changes' );
prv_assert( false !== strpos( $out_with_marker, 'configMarker' ), 'marker: configMarker plugin JS emitted' );
prv_assert( false !== strpos( $out_with_marker, 'prv-leg-cfg' ), 'marker: legend swatch present' );

// Verify graceful-degradation path is present.
prv_assert( false !== strpos( $out_with_marker, 'prv-noscript' ), 'trendline: noscript fallback class in JS guard' );
prv_assert( false !== strpos( $out_with_marker, 'prv-chart-fallback' ), 'trendline: fallback note element present' );

// ── Summary ──────────────────────────────────────────────────────────────────

exit( prv_test_summary() );
