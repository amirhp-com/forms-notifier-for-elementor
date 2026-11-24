<?php
/**
 * Encryption and signatures.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Secret encryption (sodium secretbox), HMAC signatures, random tokens. */
final class Crypto {

	private const PREFIX = 'fnfe1:';

	/**
	 * Encrypt a secret. '' stays ''.
	 *
	 * @param string $plain Clear text.
	 */
	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key( 'secret' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored in an option.
		return self::PREFIX . base64_encode( $nonce . $box );
	}

	/**
	 * Decrypt a stored secret, '' on failure.
	 *
	 * @param string $stored Output of encrypt().
	 */
	public static function decrypt( string $stored ): string {
		if ( strncmp( $stored, self::PREFIX, strlen( self::PREFIX ) ) !== 0 ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext, never executed.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key( 'secret' )
			);
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Hex HMAC-SHA256.
	 *
	 * @param string $data    Data to sign.
	 * @param string $purpose Separates key spaces.
	 */
	public static function sign( string $data, string $purpose = '' ): string {
		return hash_hmac( 'sha256', $data, self::key( 'sign:' . $purpose ) );
	}

	/**
	 * Constant-time check of sign().
	 *
	 * @param string $data      Signed data.
	 * @param string $signature Hex signature.
	 * @param string $purpose   Purpose used when signing.
	 */
	public static function verify( string $data, string $signature, string $purpose = '' ): bool {
		return $signature !== '' && hash_equals( self::sign( $data, $purpose ), $signature );
	}

	/**
	 * Random hex token.
	 *
	 * @param int $bytes Random bytes (the string is twice as long).
	 */
	public static function token( int $bytes = 16 ): string {
		return bin2hex( random_bytes( max( 1, $bytes ) ) );
	}

	/** The site uses default or empty salts in wp-config. */
	public static function uses_weak_salts(): bool {
		foreach ( [ 'AUTH_KEY', 'AUTH_SALT' ] as $name ) {
			if ( self::is_weak_salt( defined( $name ) ? (string) constant( $name ) : '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A salt value that is empty, short or the sample text.
	 *
	 * @param string $salt Salt.
	 */
	public static function is_weak_salt( string $salt ): bool {
		return strlen( $salt ) < 32 || stripos( $salt, 'put your unique phrase here' ) !== false;
	}

	/**
	 * 32 byte key derived from the auth salt and a purpose.
	 *
	 * @param string $purpose Purpose.
	 */
	private static function key( string $purpose ): string {
		return hash( 'sha256', 'fnfe|' . $purpose . '|' . wp_salt( 'auth' ), true );
	}
}
