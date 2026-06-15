<?php
/**
 * Tests for PRV_Key_Store and PRV_Crypto_Helper:
 * encrypt/decrypt round-trip, resolver precedence, write-only render, Remove.
 *
 * @package PrVision
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Provide deterministic WP-salt constants so derive_encryption_key() works.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-at-least-64-chars-long-for-realistic-entropy-aaaa' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-64-chars-long-realistic-entropy-bbbb-cccc' );
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme ): string {
		return 'static-test-salt-' . $scheme;
	}
}

/* ── Load classes under test ────────────────────────────────────────────── */

require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-crypto-helper.php';
require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-key-store.php';

/* ── Helpers ────────────────────────────────────────────────────────────── */

/**
 * Capture rendered HTML from a callable.
 *
 * @param callable $fn Function that produces output.
 *
 * @return string Captured HTML.
 */
function prv_capture( callable $fn ): string {
	ob_start();
	$fn();
	return (string) ob_get_clean();
}

echo "=== PRV_Key_Store + PRV_Crypto_Helper tests ===\n\n";

/* ─────────────────────────────────────────────────────────────────────────
 * Group 1: PRV_Crypto_Helper — encrypt/decrypt round-trip
 * ──────────────────────────────────────────────────────────────────────── */

echo "Group 1: PRV_Crypto_Helper encrypt/decrypt round-trip\n";

// Derive a test enc key the same way PRV_Key_Store does.
$enc_key = PRV_Key_Store::derive_encryption_key();
prv_assert( 32 === strlen( $enc_key ), 'derive_encryption_key() returns 32-byte key' );

$plaintext = 'sk-or-test-key-value-abc123';
$encrypted = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );

prv_assert( '' !== $encrypted, 'encrypt() returns non-empty string' );
prv_assert( $plaintext !== $encrypted, 'encrypt() does not return plaintext' );
prv_assert( false === strpos( $encrypted, $plaintext ), 'Plaintext not visible in ciphertext' );

$decrypted = PRV_Crypto_Helper::decrypt( $encrypted, $enc_key );
prv_assert_equals( $plaintext, $decrypted, 'decrypt(encrypt(key)) === original key' );

// Different calls produce different ciphertexts (random nonce).
$encrypted2 = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );
prv_assert( $encrypted !== $encrypted2, 'Two encryptions differ (random nonce)' );
prv_assert_equals( $plaintext, PRV_Crypto_Helper::decrypt( $encrypted2, $enc_key ), 'Second ciphertext also decrypts correctly' );

// Malformed inputs are rejected gracefully.
prv_assert_equals( '', PRV_Crypto_Helper::decrypt( '', $enc_key ), 'decrypt("") returns empty' );
prv_assert_equals( '', PRV_Crypto_Helper::decrypt( 'garbage', $enc_key ), 'decrypt(garbage) returns empty' );
prv_assert_equals( '', PRV_Crypto_Helper::decrypt( 'aabb:', $enc_key ), 'decrypt(empty cipher) returns empty' );

// Wrong key decrypts to empty (not wrong plaintext).
$wrong_key = hash( 'sha256', 'wrong-salt', true );
prv_assert_equals( '', PRV_Crypto_Helper::decrypt( $encrypted, $wrong_key ), 'decrypt with wrong key returns empty' );

/* ─────────────────────────────────────────────────────────────────────────
 * Group 2: PRV_Key_Store — store_key / clear_key / get_source
 * ──────────────────────────────────────────────────────────────────────── */

echo "\nGroup 2: store_key / clear_key / get_source\n";

prv_test_reset();

prv_assert_equals( PRV_Key_Store::SOURCE_NONE, PRV_Key_Store::get_source(), 'get_source() == NONE when no key stored' );

$stored = PRV_Key_Store::store_key( $plaintext );
prv_assert( $stored, 'store_key() returns true on success' );

$option_val = get_option( PRV_Key_Store::OPTION_ENC, '' );
prv_assert( '' !== $option_val, 'Option is set after store_key()' );
prv_assert( $plaintext !== $option_val, 'Option value is NOT the plaintext key (encrypted at rest)' );
prv_assert( false === strpos( (string) $option_val, $plaintext ), 'Plaintext not in stored option value' );
prv_assert( false === strpos( (string) $option_val, 'sk-or-' ), 'Key prefix not in stored option' );

prv_assert_equals( PRV_Key_Store::SOURCE_OPTION, PRV_Key_Store::get_source(), 'get_source() == OPTION after store_key()' );

PRV_Key_Store::clear_key();
prv_assert_equals( '', get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Option cleared after clear_key()' );
prv_assert_equals( PRV_Key_Store::SOURCE_NONE, PRV_Key_Store::get_source(), 'get_source() == NONE after clear_key()' );

// store_key('') is a no-op.
prv_assert( ! PRV_Key_Store::store_key( '' ), 'store_key("") returns false' );
prv_assert_equals( '', get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Option unchanged after store_key("")' );

/* ─────────────────────────────────────────────────────────────────────────
 * Group 3: Resolver precedence — constant > option > none
 * ──────────────────────────────────────────────────────────────────────── */

echo "\nGroup 3: Resolver precedence\n";

prv_test_reset();

// None scenario.
prv_assert_equals( '', PRV_Key_Store::get_key(), 'get_key() returns "" when no source' );

// Option scenario (no constant defined yet).
PRV_Key_Store::store_key( $plaintext );
prv_assert_equals( $plaintext, PRV_Key_Store::get_key(), 'get_key() returns plaintext from encrypted option' );

// Constant wins over option.
define( 'PRV_OPENROUTER_API_KEY', 'sk-or-constant-key-xyz' );
prv_assert_equals( 'sk-or-constant-key-xyz', PRV_Key_Store::get_key(), 'Constant takes precedence over option' );
prv_assert( PRV_Key_Store::constant_is_set(), 'constant_is_set() true when constant is defined' );
prv_assert_equals( PRV_Key_Store::SOURCE_CONSTANT, PRV_Key_Store::get_source(), 'get_source() == CONSTANT when constant defined' );

/* ─────────────────────────────────────────────────────────────────────────
 * Group 4: Write-only — key value never in rendered HTML output
 * ──────────────────────────────────────────────────────────────────────── */

echo "\nGroup 4: Write-only render — key not in output\n";

prv_test_reset();
PRV_Key_Store::store_key( $plaintext );

// Load rendering dependencies.
require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-settings-page.php';
require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-key-manager-renderer.php';

$html = prv_capture( function () {
	( new PRV_Key_Manager_Renderer() )->render();
} );

prv_assert( '' !== $html, 'Key manager card renders non-empty HTML' );

// Plaintext key must be absent everywhere.
prv_assert( false === strpos( $html, $plaintext ), 'Plaintext key absent from rendered HTML' );
prv_assert( false === strpos( $html, 'sk-or-test' ), 'Key value prefix absent from rendered HTML' );
prv_assert( false === strpos( $html, 'sk-or-constant-key-xyz' ), 'Constant key value absent from rendered HTML' );

// Password input must render with empty value.
prv_assert( false !== strpos( $html, 'type="password"' ), 'Password input is present in output' );
prv_assert( false !== strpos( $html, 'value=""' ), 'Password input has empty value attribute' );
prv_assert( false === strpos( $html, 'value="sk-' ), 'No key value in any value= attribute' );

/* ─────────────────────────────────────────────────────────────────────────
 * Group 5: Remove clears the encrypted option
 * ──────────────────────────────────────────────────────────────────────── */

echo "\nGroup 5: Remove clears encrypted option\n";

prv_test_reset();
PRV_Key_Store::store_key( $plaintext );
prv_assert( '' !== get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Key present before remove' );

PRV_Key_Store::clear_key();
prv_assert_equals( '', get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Option empty after clear_key()' );

// get_key() falls back to constant when present.
prv_assert_equals( 'sk-or-constant-key-xyz', PRV_Key_Store::get_key(), 'get_key() returns constant after option cleared' );

/* ─────────────────────────────────────────────────────────────────────────
 * Summary
 * ──────────────────────────────────────────────────────────────────────── */

exit( prv_test_summary() );
