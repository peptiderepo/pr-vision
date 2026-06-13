<?php
/**
 * Minimal test bootstrap — stubs all WordPress functions used by the plugin
 * so classes can be exercised in plain PHP without a WP install.
 *
 * Pattern mirrors peptide-repo-core's bootstrap (flat PHP, no PHPUnit).
 *
 * @package PeptideGeoMonitor
 */

declare(strict_types=1);

/* ── Constants ────────────────────────────────────────────────────────── */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

define( 'PGM_VERSION', '0.1.0' );
define( 'PGM_PLUGIN_FILE', __DIR__ . '/../peptide-geo-monitor.php' );
define( 'PGM_PLUGIN_DIR', realpath( __DIR__ . '/..' ) . '/' );
define( 'PGM_PLUGIN_URL', 'http://example.test/wp-content/plugins/peptide-geo-monitor/' );
define( 'PGM_SCHEMA_VERSION', 1 );
define( 'PGM_MAX_RETRIES', 3 );
define( 'PGM_API_TIMEOUT_SECONDS', 60 );
define( 'PGM_RETRY_BASE_DELAY_SECONDS', 2 );
define( 'PGM_DEFAULT_MONTHLY_BUDGET_USD', 5.0 );
define( 'PGM_CRON_HOOK', 'pgm_weekly_probe' );
define( 'PGM_TARGET_DOMAIN', 'peptiderepo.com' );

/* ── Global test state ─────────────────────────────────────────────────── */

$GLOBALS['pgm_test_state'] = [
	'options'        => [],
	'actions'        => [],
	'wpdb_insert_id' => 1,
	'wpdb_results'   => [],
	'wpdb_var'       => null,
	'cron_events'    => [],
	'remote_posts'   => [],
];

function pgm_test_reset(): void {
	$GLOBALS['pgm_test_state'] = [
		'options'        => [],
		'actions'        => [],
		'wpdb_insert_id' => 1,
		'wpdb_results'   => [],
		'wpdb_var'       => null,
		'cron_events'    => [],
		'remote_posts'   => [],
	];
	PGM_Collector_Registry::reset_for_testing();
}

/* ── WordPress function stubs ──────────────────────────────────────────── */

function get_option( string $name, $default = false ) {
	return $GLOBALS['pgm_test_state']['options'][ $name ] ?? $default;
}

function update_option( string $name, $value ): bool {
	$GLOBALS['pgm_test_state']['options'][ $name ] = $value;
	return true;
}

function add_option( string $name, $value = '' ): bool {
	if ( ! isset( $GLOBALS['pgm_test_state']['options'][ $name ] ) ) {
		$GLOBALS['pgm_test_state']['options'][ $name ] = $value;
	}
	return true;
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['pgm_test_state']['actions'][] = compact( 'hook', 'callback', 'priority' );
	return true;
}

function remove_action( string $hook, $callback, int $priority = 10 ): bool {
	return true;
}

function add_menu_page( ...$args ): void {}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): void {
	$GLOBALS['pgm_test_state']['cron_events'][ $hook ] = $timestamp;
}

function wp_next_scheduled( string $hook ) {
	return $GLOBALS['pgm_test_state']['cron_events'][ $hook ] ?? false;
}

function wp_unschedule_event( int $timestamp, string $hook ): void {
	unset( $GLOBALS['pgm_test_state']['cron_events'][ $hook ] );
}

function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['pgm_test_state']['cron_events'][ $hook ] );
}

function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min ?: 0, $max ?: PHP_INT_MAX );
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function home_url( string $path = '' ): string {
	return 'http://example.test' . $path;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

function esc_js( string $text ): string {
	return addslashes( $text );
}

function esc_url( string $url ): string {
	return $url;
}

function wp_json_encode( $data, int $flags = 0 ): string {
	return json_encode( $data, $flags ) ?: 'null';
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( string $str ): string {
	return trim( $str );
}

function current_user_can( string $cap ): bool {
	return true;
}

function wp_die( $message = '', $code = 0 ): void {
	throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die' );
}

function wp_verify_nonce( string $nonce, string $action ): bool {
	return true;
}

function wp_nonce_field( string $action, string $name ): string {
	return '';
}

function submit_button( string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {}

function add_query_arg( array $args, string $url ): string {
	return $url . '?' . http_build_query( $args );
}

function admin_url( string $path ): string {
	return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
}

function wp_safe_redirect( string $url ): void {}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_remote_post( string $url, array $args = array() ) {
	// Return from test queue if set, else a generic success.
	$queue = &$GLOBALS['pgm_test_state']['remote_posts'];
	if ( ! empty( $queue ) ) {
		return array_shift( $queue );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"choices":[{"message":{"content":"test"}}],"citations":[],"usage":{"total_tokens":100}}' );
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 200 );
}

function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_header( $response, string $header ): string {
	return (string) ( $response['headers'][ $header ] ?? '' );
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

/* ── Minimal $wpdb stub ────────────────────────────────────────────────── */

class stdClass_wpdb {
	public string $prefix  = 'wp_';
	public int    $insert_id = 0;
	public array  $_results  = [];
	public mixed  $_var      = null;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), array_map( 'addslashes', $args ) );
	}
	public function query( string $sql ): bool { return true; }
	public function insert( string $table, array $data, array $format = [] ): int {
		$this->insert_id = $GLOBALS['pgm_test_state']['wpdb_insert_id']++;
		return 1;
	}
	public function update( string $table, array $data, array $where, array $data_format = [], array $where_format = [] ): int {
		return 1;
	}
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		return is_array( $GLOBALS['pgm_test_state']['wpdb_results'] ) ? $GLOBALS['pgm_test_state']['wpdb_results'] : [];
	}
	public function get_var( string $query ): mixed {
		return $GLOBALS['pgm_test_state']['wpdb_var'];
	}
}

$wpdb = new stdClass_wpdb();

function dbDelta( string $sql ): array { return []; }

/* ── Test assertion helpers ────────────────────────────────────────────── */

$GLOBALS['pgm_test_report'] = [ 'pass' => 0, 'fail' => 0, 'failures' => [] ];

function pgm_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		$GLOBALS['pgm_test_report']['pass']++;
		echo "  PASS: {$label}\n";
	} else {
		$GLOBALS['pgm_test_report']['fail']++;
		$GLOBALS['pgm_test_report']['failures'][] = $label;
		echo "  FAIL: {$label}\n";
	}
}

function pgm_assert_equals( $expected, $actual, string $label ): void {
	$pass = ( $expected === $actual );
	pgm_assert( $pass, $label . ( $pass ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

function pgm_assert_throws( callable $fn, string $exception_class, string $label ): void {
	try {
		$fn();
		pgm_assert( false, $label . ' — expected ' . $exception_class . ' but no exception thrown' );
	} catch ( \Throwable $e ) {
		pgm_assert( $e instanceof $exception_class, $label . ' — got ' . get_class( $e ) );
	}
}

function pgm_test_summary(): int {
	$r = $GLOBALS['pgm_test_report'];
	echo "\n---\n";
	echo "Totals: {$r['pass']} passed, {$r['fail']} failed\n";
	if ( $r['fail'] > 0 ) {
		echo "Failures:\n";
		foreach ( $r['failures'] as $f ) {
			echo "  - {$f}\n";
		}
		return 1;
	}
	return 0;
}

/* ── Load autoloader + all plugin classes ──────────────────────────────── */

require_once __DIR__ . '/../includes/core/class-pgm-autoloader.php';
PGM_Autoloader::register();
require_once __DIR__ . '/../includes/providers/interface-pgm-probe-provider.php';
require_once __DIR__ . '/../includes/collector/interface-pgm-data-collector.php';
require_once __DIR__ . '/../includes/panel/interface-pgm-dashboard-panel.php';
