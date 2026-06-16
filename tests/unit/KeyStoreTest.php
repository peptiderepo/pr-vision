<?php
/**
 * Tests for PRV_Key_Store and PRV_Crypto_Helper.
 *
 * Covers: encrypt/decrypt round-trip, resolver precedence,
 * write-only render, and Remove (clear_key).
 *
 * Security assertions (P0): plaintext never in stored option,
 * password input renders with empty value, wrong-key decrypts to empty.
 *
 * @package PrVision
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers PRV_Key_Store
 * @covers PRV_Crypto_Helper
 */
class KeyStoreTest extends TestCase {

	protected function setUp(): void {
		prv_test_reset();
	}

	// ── Group 1: PRV_Crypto_Helper encrypt/decrypt round-trip ────────────

	public function test_derive_encryption_key_returns_32_bytes(): void {
		$key = PRV_Key_Store::derive_encryption_key();
		$this->assertSame( 32, strlen( $key ), 'derive_encryption_key() returns 32-byte key' );
	}

	public function test_encrypt_returns_non_empty_and_opaque(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		$enc_key   = PRV_Key_Store::derive_encryption_key();
		$encrypted = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );

		$this->assertNotEmpty( $encrypted, 'encrypt() returns non-empty string' );
		$this->assertNotSame( $plaintext, $encrypted, 'encrypt() does not return plaintext' );
		$this->assertStringNotContainsString( $plaintext, $encrypted, 'Plaintext not visible in ciphertext' );
	}

	public function test_decrypt_recovers_plaintext(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		$enc_key   = PRV_Key_Store::derive_encryption_key();
		$encrypted = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );
		$decrypted = PRV_Crypto_Helper::decrypt( $encrypted, $enc_key );

		$this->assertSame( $plaintext, $decrypted, 'decrypt(encrypt(key)) === original key' );
	}

	public function test_each_encryption_produces_unique_ciphertext(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		$enc_key   = PRV_Key_Store::derive_encryption_key();
		$ct1       = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );
		$ct2       = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );

		$this->assertNotSame( $ct1, $ct2, 'Two encryptions differ (random nonce)' );
		$this->assertSame( $plaintext, PRV_Crypto_Helper::decrypt( $ct2, $enc_key ), 'Second ciphertext also decrypts correctly' );
	}

	public function test_decrypt_rejects_malformed_inputs(): void {
		$enc_key = PRV_Key_Store::derive_encryption_key();

		$this->assertSame( '', PRV_Crypto_Helper::decrypt( '', $enc_key ), 'decrypt("") returns empty' );
		$this->assertSame( '', PRV_Crypto_Helper::decrypt( 'garbage', $enc_key ), 'decrypt(garbage) returns empty' );
		$this->assertSame( '', PRV_Crypto_Helper::decrypt( 'aabb:', $enc_key ), 'decrypt(empty cipher) returns empty' );
	}

	public function test_decrypt_wrong_key_returns_empty(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		$enc_key   = PRV_Key_Store::derive_encryption_key();
		$encrypted = PRV_Crypto_Helper::encrypt( $plaintext, $enc_key );
		$wrong_key = hash( 'sha256', 'wrong-salt', true );

		$this->assertSame( '', PRV_Crypto_Helper::decrypt( $encrypted, $wrong_key ), 'decrypt with wrong key returns empty' );
	}

	// ── Group 2: store_key / clear_key / get_source ──────────────────────

	public function test_get_source_none_when_no_key(): void {
		$this->assertSame( PRV_Key_Store::SOURCE_NONE, PRV_Key_Store::get_source() );
	}

	public function test_store_key_writes_encrypted_option(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		$stored    = PRV_Key_Store::store_key( $plaintext );

		$this->assertTrue( $stored, 'store_key() returns true on success' );

		$option_val = get_option( PRV_Key_Store::OPTION_ENC, '' );
		$this->assertNotEmpty( $option_val, 'Option is set after store_key()' );
		$this->assertNotSame( $plaintext, $option_val, 'Option value is NOT the plaintext key (encrypted at rest)' );
		$this->assertStringNotContainsString( $plaintext, (string) $option_val, 'Plaintext not in stored option value' );
		$this->assertStringNotContainsString( 'sk-or-', (string) $option_val, 'Key prefix not in stored option' );
		$this->assertSame( PRV_Key_Store::SOURCE_OPTION, PRV_Key_Store::get_source() );
	}

	public function test_clear_key_removes_option(): void {
		PRV_Key_Store::store_key( 'sk-or-test-key-value-abc123' );
		PRV_Key_Store::clear_key();

		$this->assertSame( '', get_option( PRV_Key_Store::OPTION_ENC, '' ) );
		$this->assertSame( PRV_Key_Store::SOURCE_NONE, PRV_Key_Store::get_source() );
	}

	public function test_store_empty_key_is_noop(): void {
		$result = PRV_Key_Store::store_key( '' );

		$this->assertFalse( $result, 'store_key("") returns false' );
		$this->assertSame( '', get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Option unchanged after store_key("")' );
	}

	// ── Group 3: Resolver precedence — constant > option > none ──────────

	public function test_get_key_returns_constant_value(): void {
		// PRV_OPENROUTER_API_KEY='sk-or-test-key' is defined in bootstrap.
		// get_key() returns the constant value (highest precedence).
		$this->assertSame( 'sk-or-test-key', PRV_Key_Store::get_key(), 'get_key() returns the wp-config constant value' );
	}

	public function test_get_key_constant_wins_over_option(): void {
		// store_key() writes a different plaintext to the encrypted option.
		// But get_key() still returns the constant because constant > option.
		$plaintext = 'sk-or-test-key-value-abc123';
		PRV_Key_Store::store_key( $plaintext );

		$this->assertSame( 'sk-or-test-key', PRV_Key_Store::get_key(), 'Constant wins over stored option (constant > option precedence)' );
	}

	// ── Group 4: Write-only — key value never in rendered HTML output ─────

	public function test_rendered_html_never_contains_key(): void {
		// P0 invariant: key value NEVER appears in any rendered HTML output.
		// With PRV_OPENROUTER_API_KEY constant in bootstrap, source = SOURCE_CONSTANT.
		// The renderer must still render the password input with value="" (write-only).
		require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-settings-page.php';
		require_once PRV_PLUGIN_DIR . 'includes/core/class-prv-key-manager-renderer.php';

		ob_start();
		( new PRV_Key_Manager_Renderer() )->render();
		$html = (string) ob_get_clean();

		$this->assertNotEmpty( $html, 'Key manager card renders non-empty HTML' );
		// The constant key 'sk-or-test-key' must NOT appear in the HTML (P0 write-only).
		$this->assertStringNotContainsString( 'sk-or-test-key', $html, 'Constant API key value absent from rendered HTML (P0)' );
		$this->assertStringContainsString( 'type="password"', $html, 'Password input is present in output' );
		$this->assertStringContainsString( 'value=""', $html, 'Password input has empty value attribute (write-only)' );
		$this->assertStringNotContainsString( 'value="sk-', $html, 'No key value in any value= attribute' );
	}

	// ── Group 5: Remove clears the encrypted option ───────────────────────

	public function test_clear_key_empties_option(): void {
		$plaintext = 'sk-or-test-key-value-abc123';
		PRV_Key_Store::store_key( $plaintext );

		$this->assertNotEmpty( get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Key present before remove' );

		PRV_Key_Store::clear_key();

		$this->assertSame( '', get_option( PRV_Key_Store::OPTION_ENC, '' ), 'Option empty after clear_key()' );
	}
}
