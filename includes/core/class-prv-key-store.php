<?php
/**
 * PR Vision API key resolver and encrypted-at-rest key store.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Resolves and stores the OpenRouter API key with precedence and encryption.
 *
 * Precedence order (first match wins):
 *   1. `PRV_OPENROUTER_API_KEY` wp-config constant (highest — most secure).
 *   2. Encrypted admin option `prv_provider_key_enc`.
 *   3. Empty string (no key configured).
 *
 * Encryption is delegated to PRV_Crypto_Helper (libsodium XSalsa20-Poly1305
 * preferred; OpenSSL AES-256-GCM fallback). The encryption key is derived
 * from WP salts (`AUTH_KEY` + `SECURE_AUTH_KEY`) via SHA-256 — environment-
 * specific, never stored.
 *
 * Security invariants:
 * - Plaintext key NEVER stored in any option, transient, log, or echoed.
 * - Decryption occurs only at resolve time, server-side only.
 * - Ciphertext purged on uninstall via the `prv_` wildcard DELETE.
 *
 * Who triggers: PRV_Perplexity_Provider, PRV_OpenRouter_Provider,
 *               PRV_Key_Test_Ajax.
 * Dependencies: PRV_Crypto_Helper, wp_salt(), update_option(),
 *               delete_option(), get_option().
 *
 * @see class-prv-crypto-helper.php      -- Low-level encrypt/decrypt.
 * @see class-prv-perplexity-provider.php -- Calls get_key().
 * @see class-prv-openrouter-provider.php -- Calls get_key().
 * @see class-prv-key-test-ajax.php      -- Calls get_key() for validation.
 * @see class-prv-settings-renderer.php  -- Calls get_source() for UI status.
 * @see class-prv-settings-controller.php -- Calls store_key() / clear_key().
 * @see ARCHITECTURE.md                  -- §Key management.
 * @package PrVision
 */
class PRV_Key_Store {

	/**
	 * WP option name for the encrypted key ciphertext.
	 */
	const OPTION_ENC = 'prv_provider_key_enc';

	/**
	 * Source constant: key comes from wp-config constant.
	 */
	const SOURCE_CONSTANT = 'constant';

	/**
	 * Source constant: key comes from the encrypted admin option.
	 */
	const SOURCE_OPTION = 'option';

	/**
	 * Source constant: no key is configured.
	 */
	const SOURCE_NONE = 'none';

	/**
	 * Resolve the API key following the precedence chain.
	 *
	 * Returns the plaintext key for server-side use ONLY. Callers MUST NOT
	 * pass this value to any client-facing surface (page, REST, log, exception).
	 *
	 * @return string Plaintext API key, or empty string when none is configured.
	 */
	public static function get_key(): string {
		// 1. wp-config constant wins when defined and non-empty.
		if ( self::constant_is_set() ) {
			return (string) constant( 'PRV_OPENROUTER_API_KEY' );
		}

		// 2. Encrypted admin option.
		$enc = get_option( self::OPTION_ENC, '' );
		if ( is_string( $enc ) && '' !== $enc ) {
			$enc_key = self::derive_encryption_key();
			if ( '' !== $enc_key ) {
				$decrypted = PRV_Crypto_Helper::decrypt( $enc, $enc_key );
				if ( '' !== $decrypted ) {
					return $decrypted;
				}
			}
		}

		return '';
	}

	/**
	 * Determine the source of the resolved key.
	 *
	 * Returns one of: SOURCE_CONSTANT, SOURCE_OPTION, SOURCE_NONE.
	 * Safe for UI output — never returns the key value itself.
	 *
	 * @return string One of the SOURCE_* class constants.
	 */
	public static function get_source(): string {
		if ( self::constant_is_set() ) {
			return self::SOURCE_CONSTANT;
		}
		$enc = get_option( self::OPTION_ENC, '' );
		if ( is_string( $enc ) && '' !== $enc ) {
			return self::SOURCE_OPTION;
		}
		return self::SOURCE_NONE;
	}

	/**
	 * Encrypt and store a new API key.
	 *
	 * Encrypts via PRV_Crypto_Helper and stores only the ciphertext. The
	 * plaintext is never persisted. Caller is responsible for capability and
	 * nonce checks before calling this method.
	 *
	 * Side effects: Writes to wp_options (`prv_provider_key_enc`).
	 *
	 * @param string $plaintext_key The raw API key to store.
	 *
	 * @return bool True on success, false if encryption fails or key is empty.
	 */
	public static function store_key( string $plaintext_key ): bool {
		if ( '' === $plaintext_key ) {
			return false;
		}
		$enc_key = self::derive_encryption_key();
		if ( '' === $enc_key ) {
			return false;
		}
		$enc = PRV_Crypto_Helper::encrypt( $plaintext_key, $enc_key );
		if ( '' === $enc ) {
			return false;
		}
		update_option( self::OPTION_ENC, $enc, false );
		return true;
	}

	/**
	 * Delete the stored encrypted key (reverts to constant or none).
	 *
	 * Side effects: Deletes `prv_provider_key_enc` from wp_options.
	 *
	 * @return void
	 */
	public static function clear_key(): void {
		delete_option( self::OPTION_ENC );
	}

	/**
	 * True when the PRV_OPENROUTER_API_KEY constant is defined and non-empty.
	 *
	 * @return bool
	 */
	public static function constant_is_set(): bool {
		return defined( 'PRV_OPENROUTER_API_KEY' )
			&& '' !== (string) constant( 'PRV_OPENROUTER_API_KEY' );
	}

	/**
	 * Derive a 32-byte raw binary encryption key from WP salts via SHA-256.
	 *
	 * Uses AUTH_KEY + SECURE_AUTH_KEY when defined; falls back to wp_salt().
	 * The derived key is site-specific and never persisted.
	 *
	 * @return string 32-byte raw binary key, or empty string when salts unavailable.
	 */
	public static function derive_encryption_key(): string {
		$salt = '';

		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$salt = AUTH_KEY . SECURE_AUTH_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			// wp_salt() is available after WP loads; safe in plugin context.
			$salt = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}

		if ( '' === $salt ) {
			return '';
		}

		return hash( 'sha256', $salt, true ); // Raw binary — exactly 32 bytes.
	}
}
