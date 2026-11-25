<?php
/**
 * Webhook signatures.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Webhook;

defined( 'ABSPATH' ) || exit;

/** X-FNFE-Signature: v1=hex HMAC-SHA256 of "{timestamp}.{raw body}". */
final class Signer {

	/**
	 * Signature header value.
	 *
	 * @param string $secret    Shared secret.
	 * @param int    $timestamp Unix time sent in X-FNFE-Timestamp.
	 * @param string $body      Raw request body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Check a signature.
	 *
	 * @param string $secret    Shared secret.
	 * @param int    $timestamp Unix time of the request.
	 * @param string $body      Raw request body.
	 * @param string $signature Header value.
	 */
	public static function verify( string $secret, int $timestamp, string $body, string $signature ): bool {
		return $secret !== '' && hash_equals( self::sign( $secret, $timestamp, $body ), $signature );
	}
}
