<?php
/**
 * PHPUnit bootstrap for PR Vision tests.
 *
 * Loads WP stubs in plain PHP (no WP install required) and registers
 * the plugin autoloader so all classes are available to PHPUnit test cases.
 *
 * Pattern: WP stubs (tests: stubs) — no DB, no service, fast.
 *
 * @package PrVision
 */

declare(strict_types=1);

// Composer autoloader must come first so PHPUnit classes are available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/* ── Constants ────────────────────────────────────────────────────────── */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WordPress DB result-format constants.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

define( 'PRV_VERSION', '0.3.2' );
define( 'PRV_PLUGIN_FILE', __DIR__ . '/../pr-vision.php' );
define( 'PRV_PLUGIN_DIR', realpath( __DIR__ . '/..' ) . '/' );
define( 'PRV_PLUGIN_URL', 'http://example.test/wp-content/plugins/pr-vision/' );
define( 'PRV_SCHEMA_VERSION', 3 );
define( 'PRV_MAX_RETRIES', 3 );
define( 'PRV_API_TIMEOUT_SECONDS', 60 );
define( 'PRV_RETRY_BASE_DELAY_SECONDS', 2 );
define( 'PRV_DEFAULT_MONTHLY_BUDGET_USD', 5.0 );
define( 'PRV_CRON_HOOK', 'prv_weekly_probe' );
define( 'PRV_TARGET_DOMAIN', 'peptiderepo.com' );
define( 'PRV_IO_RETENTION_DEFAULT_DAYS', 90 );
define( 'PRV_DAILY_PRUNE_HOOK', 'prv_daily_prune' );

// Salt constants required by PRV_Key_Store::derive_encryption_key().
// These provide deterministic 64-char salts matching the pattern in test-key-store.php.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-at-least-64-chars-long-for-realistic-entropy-aaaa' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-64-chars-long-realistic-entropy-bbbb-cccc' );
}

// Provider key constant; defined here once so no test class defines it mid-run.
// Tests that need a configured key get it from this constant (via PRV_Key_Store::get_key()).
// The sentinel leak test (CaptureWriterTest) asserts this value does not appear in stored rows.
if ( ! defined( 'PRV_OPENROUTER_API_KEY' ) ) {
	define( 'PRV_OPENROUTER_API_KEY', 'sk-or-test-key' );
}

// Cloudflare gateway constants (empty in test environment).
if ( ! defined( 'PRV_CF_ACCOUNT_ID' ) ) {
	define( 'PRV_CF_ACCOUNT_ID', '' );
}
if ( ! defined( 'PRV_CF_GATEWAY_ID' ) ) {
	define( 'PRV_CF_GATEWAY_ID', '' );
}

/* ── Global test state ─────────────────────────────────────────────────── */

$GLOBALS['prv_test_state'] = [
	'options'              => [],
	'transients'           => [],
	'actions'              => [],
	'wpdb_insert_id'       => 1,
	'wpdb_results'         => [],
	'wpdb_results_queue'   => [],
	'wpdb_var'             => null,
	'wpdb_row'             => null,
	'cron_events'          => [],
	'remote_posts'         => [],
	'wpdb_call_meta_rows'  => [],
	'wpdb_call_io_rows'    => [],
	'wpdb_dropped_tables'  => [],
];

function prv_test_reset(): void {
	$GLOBALS['prv_test_state'] = [
		'options'              => [],
		'transients'           => [],
		'actions'              => [],
		'wpdb_insert_id'       => 1,
		'wpdb_results'         => [],
		'wpdb_results_queue'   => [],
		'wpdb_var'             => null,
		'wpdb_row'             => null,
		'cron_events'          => [],
		'remote_posts'         => [],
		'wpdb_call_meta_rows'  => [],
		'wpdb_call_io_rows'    => [],
		'wpdb_dropped_tables'  => [],
	];
	PRV_Collector_Registry::reset_for_testing();
}

/* ── WordPress function stubs ──────────────────────────────────────────── */

function get_option( string $name, $default = false ) {
	return $GLOBALS['prv_test_state']['options'][ $name ] ?? $default;
}

function update_option( string $name, $value ): bool {
	$GLOBALS['prv_test_state']['options'][ $name ] = $value;
	return true;
}

function add_option( string $name, $value = '' ): bool {
	if ( ! isset( $GLOBALS['prv_test_state']['options'][ $name ] ) ) {
		$GLOBALS['prv_test_state']['options'][ $name ] = $value;
	}
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['prv_test_state']['options'][ $name ] );
	return true;
}

function get_transient( string $name ) {
	return $GLOBALS['prv_test_state']['transients'][ $name ] ?? false;
}

function set_transient( string $name, $value, int $ttl = 0 ): bool {
	$GLOBALS['prv_test_state']['transients'][ $name ] = $value;
	return true;
}

function delete_transient( string $name ): bool {
	unset( $GLOBALS['prv_test_state']['transients'][ $name ] );
	return true;
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['prv_test_state']['actions'][] = compact( 'hook', 'callback', 'priority' );
	return true;
}

function remove_action( string $hook, $callback, int $priority = 10 ): bool { return true; }

function add_menu_page( ...$args ): void {}
function add_submenu_page( ...$args ): void {}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): void {
	$GLOBALS['prv_test_state']['cron_events'][ $hook ] = [ 'timestamp' => $timestamp, 'schedule' => $recurrence ];
}

function wp_next_scheduled( string $hook ) {
	return $GLOBALS['prv_test_state']['cron_events'][ $hook ]['timestamp'] ?? false;
}

function wp_unschedule_event( int $timestamp, string $hook ): void {
	unset( $GLOBALS['prv_test_state']['cron_events'][ $hook ] );
}

function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['prv_test_state']['cron_events'][ $hook ] );
}

function _get_cron_array(): array {
	$out = [];
	foreach ( $GLOBALS['prv_test_state']['cron_events'] as $hook => $data ) {
		$out[ $data['timestamp'] ][ $hook ] = [ '' => [ 'schedule' => $data['schedule'], 'interval' => 604800 ] ];
	}
	return $out;
}

function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min ?: 0, $max ?: PHP_INT_MAX );
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function home_url( string $path = '' ): string { return 'http://example.test' . $path; }

function __( string $text, string $domain = 'default' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return 1 === $number ? $single : $plural; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html__( string $text, string $domain = 'default' ): string { return esc_html( $text ); }
function esc_attr__( string $text, string $domain = 'default' ): string { return esc_attr( $text ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_textarea( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_js( string $text ): string { return addslashes( $text ); }
function esc_url( string $url ): string { return $url; }

function wp_json_encode( $data, int $flags = 0 ): string {
	return json_encode( $data, $flags ) ?: 'null';
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( string $str ): string { return trim( $str ); }
function sanitize_textarea_field( string $str ): string { return trim( $str ); }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }

function current_user_can( string $cap ): bool { return true; }
function wp_die( $message = '', $code = 0 ): void {
	throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die' );
}

function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
function wp_nonce_field( string $action, string $name, bool $referer = true, bool $echo = true ): string { return ''; }
function wp_create_nonce( string $action ): string { return 'test_nonce'; }

function submit_button( string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {}
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function admin_url( string $path ): string { return 'http://example.test/wp-admin/' . ltrim( $path, '/' ); }
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function wp_safe_redirect( string $url ): void {}
function wp_send_json_success( $data = null ): void { throw new \RuntimeException( 'json_success:' . json_encode( $data ) ); }
function wp_send_json_error( $data = null ): void { throw new \RuntimeException( 'json_error:' . json_encode( $data ) ); }

function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }

function selected( $selected, $current = true, bool $echo = true ): string {
	$str = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $str; }
	return $str;
}

function wp_remote_post( string $url, array $args = array() ) {
	$queue = &$GLOBALS['prv_test_state']['remote_posts'];
	if ( ! empty( $queue ) ) { return array_shift( $queue ); }
	return array( 'response' => array( 'code' => 200 ), 'body' => '{"choices":[{"message":{"content":"test"}}],"citations":[],"usage":{"total_tokens":100}}' );
}

function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 200 );
}
function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_retrieve_header( $response, string $header ): string { return (string) ( $response['headers'][ $header ] ?? '' ); }

function is_admin(): bool { return false; }
function wp_enqueue_script( ...$args ): void {}
function wp_add_inline_style( ...$args ): void {}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

/* ── Minimal $wpdb stub ────────────────────────────────────────────────── */

class stdClass_wpdb {
	public string $prefix    = 'wp_';
	public int    $insert_id = 0;
	public array  $_results  = [];
	public mixed  $_var      = null;
	public string $options   = 'wp_options';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), array_map( 'addslashes', $args ) );
	}
	public function query( string $sql ): bool {
		if ( preg_match( '/DROP TABLE.*?(prv_\w+)/i', $sql, $m ) ) {
			$GLOBALS['prv_test_state']['wpdb_dropped_tables'][] = $m[1];
		}
		return true;
	}
	public function insert( string $table, array $data, array $format = [] ): int {
		$this->insert_id = $GLOBALS['prv_test_state']['wpdb_insert_id']++;
		// Track call_meta and call_io rows for test assertions.
		if ( str_contains( $table, 'prv_call_meta' ) ) {
			$GLOBALS['prv_test_state']['wpdb_call_meta_rows'][] = $data;
		}
		if ( str_contains( $table, 'prv_call_io' ) ) {
			$GLOBALS['prv_test_state']['wpdb_call_io_rows'][] = $data;
		}
		return 1;
	}
	public function update( string $table, array $data, array $where, array $df = [], array $wf = [] ): int { return 1; }
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		// Queue mode: if wpdb_results_queue is non-empty, shift one result set.
		if ( ! empty( $GLOBALS['prv_test_state']['wpdb_results_queue'] ) ) {
			return (array) array_shift( $GLOBALS['prv_test_state']['wpdb_results_queue'] );
		}
		return is_array( $GLOBALS['prv_test_state']['wpdb_results'] ) ? $GLOBALS['prv_test_state']['wpdb_results'] : [];
	}
	public function get_row( string $query, string $output = 'OBJECT' ): mixed { return $GLOBALS['prv_test_state']['wpdb_row'] ?? null; }
	public function get_var( string $query ): mixed { return $GLOBALS['prv_test_state']['wpdb_var']; }
}

$wpdb = new stdClass_wpdb();

function dbDelta( string $sql ): array { return []; }

/* ── Load autoloader + all plugin classes ──────────────────────────────── */

require_once __DIR__ . '/../includes/core/class-prv-autoloader.php';
PRV_Autoloader::register();
require_once __DIR__ . '/../includes/providers/interface-prv-probe-provider.php';
require_once __DIR__ . '/../includes/collector/interface-prv-data-collector.php';
require_once __DIR__ . '/../includes/panel/interface-prv-dashboard-panel.php';
