<?php
/**
 * Mini app sessions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;

defined( 'ABSPATH' ) || exit;

/** Signed session tokens. Nothing is stored; a token dies with its expiry, the version, the admin entry or the bot token. */
final class Session {

	private const MIN_TTL = 300;

	/**
	 * Issue a token.
	 *
	 * @param string   $platform Platform.
	 * @param string   $user_id  Messenger user id.
	 * @param string[] $perms    Permissions.
	 * @return array{token:string,expires:int}
	 */
	public static function issue( string $platform, string $user_id, array $perms ): array {
		$expires = self::now() + max( self::MIN_TTL, Settings::int( 'miniapp.session_ttl', 7200 ) );
		$payload = (string) wp_json_encode(
			[
				'p' => $platform,
				'u' => $user_id,
				'r' => array_values( array_filter( $perms, 'is_string' ) ),
				'e' => $expires,
				'v' => self::version(),
				'b' => get_current_blog_id(),
				'k' => self::bot_key( $platform ),
			]
		);
		return [
			'token'   => self::encode( $payload ) . '.' . self::encode( (string) hex2bin( Crypto::sign( $payload, 'app' ) ) ),
			'expires' => $expires,
		];
	}

	/**
	 * Verify a token. The permissions are the ones the user still has.
	 *
	 * @param string $token Token.
	 * @return array{platform:string,uid:string,perms:string[],exp:int}|null
	 */
	public static function verify( string $token ): ?array {
		if ( strlen( $token ) > 2048 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/', $token, $match ) ) {
			return null;
		}
		$payload   = self::decode( $match[1] );
		$signature = self::decode( $match[2] );
		if ( $payload === '' || ! Crypto::verify( $payload, bin2hex( $signature ), 'app' ) ) {
			return null;
		}
		// The second half of a base64 character can be changed without changing the bytes.
		if ( self::encode( $payload ) !== $match[1] || self::encode( $signature ) !== $match[2] ) {
			return null;
		}

		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || ! isset( $data['p'], $data['u'], $data['r'], $data['e'], $data['v'], $data['b'], $data['k'] ) ) {
			return null;
		}
		$platform = (string) $data['p'];
		$uid      = (string) $data['u'];
		$key      = self::bot_key( $platform );
		if ( (int) $data['e'] <= self::now() || (int) $data['v'] !== self::version() || (int) $data['b'] !== get_current_blog_id() ) {
			return null;
		}
		if ( $key === '' || ! hash_equals( $key, (string) $data['k'] ) ) {
			return null;
		}
		$perms = array_values( array_intersect( (array) $data['r'], Access::perms( $platform, $uid ) ) );
		if ( ! in_array( 'view', $perms, true ) ) {
			return null;
		}
		return [
			'platform' => $platform,
			'uid'      => $uid,
			'perms'    => $perms,
			'exp'      => (int) $data['e'],
		];
	}

	/** Invalidate every token. */
	public static function revoke_all(): void {
		State::set( 'token_version', self::version() + 1 );
	}

	/** Current token version. */
	private static function version(): int {
		$version = State::get( 'token_version', 0 );
		return is_numeric( $version ) ? (int) $version : 0;
	}

	/**
	 * Fingerprint of the bot token, '' without one. Replacing a leaked bot token ends the sessions opened with it.
	 *
	 * @param string $platform Platform.
	 */
	private static function bot_key( string $platform ): string {
		$token = in_array( $platform, InitData::PLATFORMS, true ) ? Settings::secret( "channels.$platform.token" ) : '';
		return $token === '' ? '' : substr( Crypto::sign( $token, 'app-bot' ), 0, 16 );
	}

	/** Clock. */
	private static function now(): int {
		return (int) apply_filters( 'fnfe_now', time() );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $data Bytes.
	 */
	private static function encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe form of a signed token.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Reverse of encode(), '' on failure.
	 *
	 * @param string $text Text.
	 */
	private static function decode( string $text ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Signed JSON, never executed.
		return (string) base64_decode( strtr( $text, '-_', '+/' ), true );
	}
}
