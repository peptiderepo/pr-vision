<?php
/**
 * Symmetric encryption/decryption helper for PR Vision.
 *
 * @package PrVision
 */

declare(strict_types=1);

/**
 * Symmetric encryption/decryption using libsodium (preferred) or OpenSSL AES-256-GCM.
 *
 * Provides a narrow, single-purpose interface: encrypt() / decrypt(). The
 * encryption key is always supplied by the caller (PRV_Key_Store derives it
 * from WP salts). No key material is persisted here.
 *
 * Algorithm selection:
 *  - libsodium XSalsa20-Poly1305 (sodium_crypto_secretbox): used when the
 *    sodium extension is available (PHP 7.2+ default). Nonce = 24 bytes (random).
 *  - OpenSSL AES-256-GCM fallback: used when sodium is unavailable. IV = 12 bytes
 *    (random); 16-byte GCM auth tag appended to ciphertext.
 *
 * Storage format (both algorithms): hex(nonce_or_iv) : hex(ciphertext[+tag])
 * The algorithm is inferred from nonce length at decrypt time.
 *
 * Security invariants:
 *  - Plaintext is NEVER stored, logged, or returned beyond the caller.
 *  - Each encrypt() call uses a fresh random nonce/IV.
 *  - Decryption silently returns '' on any failure (no info leak via exception).
 *
 * Who triggers: PRV_Key_Store — all encryption/decryption delegated here.
 * Dependencies: sodium_crypto_secretbox (optional), openssl_encrypt (fallback).
 *
 * @see class-prv-key-store.php -- Calls encrypt() / decrypt().
 * @package PrVision
 */
class PRV_Crypto_Helper {

	/**
	 * Separator between hex-encoded nonce/IV and ciphertext in stored format.
	 */
	const SEPARATOR = ':';

	/**
	 * Encrypt plaintext using libsodium or OpenSSL fallback.
	 *
	 * @param string $plaintext  Value to encrypt.
	 * @param string $enc_key    32-byte raw binary encryption key.
	 *
	 * @return string hex(nonce):hex(ciphertext), or empty string on error.
	 */
	public static function encrypt( string $plaintext, string $enc_key ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::encrypt_sodium( $plaintext, $enc_key );
		}
		return self::encrypt_openssl( $plaintext, $enc_key );
	}

	/**
	 * Decrypt a stored ciphertext produced by encrypt().
	 *
	 * Algorithm is inferred from nonce length: sodium nonce = 24 bytes (48 hex).
	 *
	 * @param string $stored  Encoded ciphertext (hex(nonce):hex(ciphertext)).
	 * @param string $enc_key 32-byte raw binary encryption key.
	 *
	 * @return string Plaintext, or empty string on any error.
	 */
	public static function decrypt( string $stored, string $enc_key ): string {
		$sep_pos = strpos( $stored, self::SEPARATOR );
		if ( false === $sep_pos ) {
			return '';
		}

		$nonce_hex  = substr( $stored, 0, $sep_pos );
		$cipher_hex = substr( $stored, $sep_pos + 1 );

		if ( '' === $nonce_hex || '' === $cipher_hex ) {
			return '';
		}

		// Infer algorithm from nonce length: sodium = 24 bytes (48 hex chars).
		$nonce_byte_count = strlen( $nonce_hex ) / 2;
		if ( function_exists( 'sodium_crypto_secretbox_open' )
			&& SODIUM_CRYPTO_SECRETBOX_NONCEBYTES === (int) $nonce_byte_count ) {
			return self::decrypt_sodium( $nonce_hex, $cipher_hex, $enc_key );
		}

		// OpenSSL path: IV = 12 bytes (24 hex), 16-byte tag appended.
		return self::decrypt_openssl( $nonce_hex, $cipher_hex, $enc_key );
	}

	// ── Private algorithm implementations ─────────────────────────────────

	/**
	 * Encrypt using libsodium secretbox (XSalsa20-Poly1305).
	 *
	 * @param string $plaintext Value to encrypt.
	 * @param string $enc_key   32-byte raw key.
	 *
	 * @return string hex(nonce):hex(ciphertext), or empty on error.
	 */
	private static function encrypt_sodium( string $plaintext, string $enc_key ): string {
		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $enc_key );
			return bin2hex( $nonce ) . self::SEPARATOR . bin2hex( $ciphertext );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypt using libsodium secretbox.
	 *
	 * @param string $nonce_hex  Hex-encoded 24-byte nonce.
	 * @param string $cipher_hex Hex-encoded ciphertext with Poly1305 MAC.
	 * @param string $enc_key    32-byte raw key.
	 *
	 * @return string Plaintext, or empty on failure.
	 */
	private static function decrypt_sodium( string $nonce_hex, string $cipher_hex, string $enc_key ): string {
		try {
			$nonce      = hex2bin( $nonce_hex );
			$ciphertext = hex2bin( $cipher_hex );
			if ( false === $nonce || false === $ciphertext ) {
				return '';
			}
			$result = sodium_crypto_secretbox_open( $ciphertext, $nonce, $enc_key );
			return ( false === $result ) ? '' : $result;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Encrypt using OpenSSL AES-256-GCM (sodium fallback).
	 *
	 * Appends the 16-byte GCM auth tag to the ciphertext before hex-encoding,
	 * so decrypt() can split it without a separate field.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @param string $enc_key   32-byte raw key.
	 *
	 * @return string hex(iv):hex(ciphertext+tag), or empty on error.
	 */
	private static function encrypt_openssl( string $plaintext, string $enc_key ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$iv  = random_bytes( 12 ); // GCM standard IV length.
		$tag = '';
		$enc = openssl_encrypt( $plaintext, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $enc || '' === $tag ) {
			return '';
		}
		return bin2hex( $iv ) . self::SEPARATOR . bin2hex( $enc . $tag );
	}

	/**
	 * Decrypt using OpenSSL AES-256-GCM.
	 *
	 * Splits off the 16-byte auth tag appended by encrypt_openssl().
	 *
	 * @param string $iv_hex     Hex-encoded 12-byte IV.
	 * @param string $cipher_hex Hex-encoded ciphertext with 16-byte GCM tag appended.
	 * @param string $enc_key    32-byte raw key.
	 *
	 * @return string Plaintext, or empty on failure.
	 */
	private static function decrypt_openssl( string $iv_hex, string $cipher_hex, string $enc_key ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$iv         = hex2bin( $iv_hex );
		$cipher_raw = hex2bin( $cipher_hex );
		if ( false === $iv || false === $cipher_raw || strlen( $cipher_raw ) < 16 ) {
			return '';
		}
		$tag        = substr( $cipher_raw, -16 );
		$ciphertext = substr( $cipher_raw, 0, -16 );
		$result     = openssl_decrypt( $ciphertext, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag );
		return ( false === $result ) ? '' : $result;
	}
}
