<?php
/**
 * Launch data of the messenger clients.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Validates the signed launch data that a messenger hands to the mini app. */
final class InitData {

	public const PLATFORMS = [ 'telegram', 'bale' ];

	/** Longest accepted raw string. Real launch data is below 2 kB. */
	private const MAX_LENGTH = 8192;

	/** Accepted clock difference into the future, seconds. */
	private const MAX_FUTURE = 300;

	/**
	 * Validate launch data from the messenger client.
	 *
	 * @param string   $platform  telegram | bale.
	 * @param string   $init_data Raw query string exactly as received (tgWebAppData).
	 * @param int|null $max_age   Accepted age in seconds, null = setting.
	 * @return array{ok:bool,error:string,user:array{id:string,first_name:string,last_name:string,username:string,language_code:string,photo_url:string},auth_date:int,start_param:string,chat_type:string}
	 *         error codes: missing | malformed | bad_hash | expired | no_user | no_token
	 */
	public static function validate( string $platform, string $init_data, ?int $max_age = null ): array {
		if ( $init_data === '' ) {
			return self::result( 'missing' );
		}
		$token = in_array( $platform, self::PLATFORMS, true ) ? Settings::secret( "channels.$platform.token" ) : '';
		if ( $token === '' ) {
			return self::result( 'no_token' );
		}
		return self::validate_with( $init_data, $token, $max_age ?? Settings::int( 'miniapp.max_age', DAY_IN_SECONDS ) );
	}

	/**
	 * Validate launch data against a known bot token.
	 *
	 * @param string $init_data Raw query string.
	 * @param string $bot_token Bot token.
	 * @param int    $max_age   Accepted age in seconds.
	 * @return array Same shape as validate().
	 */
	public static function validate_with( string $init_data, string $bot_token, int $max_age ): array {
		if ( $init_data === '' ) {
			return self::result( 'missing' );
		}
		if ( $bot_token === '' ) {
			return self::result( 'no_token' );
		}
		$pairs = self::parse( $init_data );
		if ( $pairs === null || ! isset( $pairs['hash'] ) ) {
			return self::result( 'malformed' );
		}
		if ( ! self::hash_matches( $pairs, $bot_token ) ) {
			return self::result( 'bad_hash' );
		}

		// Everything below is signed by the messenger.
		$date = $pairs['auth_date'] ?? '';
		if ( ! preg_match( '/^\d{1,12}$/', $date ) ) {
			return self::result( 'malformed' );
		}
		$date = (int) $date;
		$now  = (int) apply_filters( 'fnfe_now', time() );
		if ( $now - $date > max( 0, $max_age ) || $date - $now > self::MAX_FUTURE ) {
			return self::result( 'expired', $date );
		}

		$user = self::user( $pairs['user'] ?? '' );
		if ( $user === null ) {
			return self::result( 'no_user', $date );
		}

		$result         = self::result( '', $date );
		$result['ok']   = true;
		$result['user'] = $user;
		if ( preg_match( '/^[A-Za-z0-9_-]{1,512}$/', $pairs['start_param'] ?? '' ) ) {
			$result['start_param'] = $pairs['start_param'];
		}
		if ( preg_match( '/^[a-z_]{1,20}$/', $pairs['chat_type'] ?? '' ) ) {
			$result['chat_type'] = $pairs['chat_type'];
		}
		return $result;
	}

	/**
	 * HMAC check only.
	 *
	 * @param string $init_data Raw query string.
	 * @param string $bot_token Bot token.
	 */
	public static function check_hash( string $init_data, string $bot_token ): bool {
		$pairs = self::parse( $init_data );
		return $pairs !== null && $bot_token !== '' && self::hash_matches( $pairs, $bot_token );
	}

	/**
	 * Raw query string to key => decoded value. A plus sign is data here, not a space; a repeated key is refused because
	 * two readers could pick different values.
	 *
	 * @param string $init_data Raw query string.
	 * @return array<string,string>|null
	 */
	private static function parse( string $init_data ): ?array {
		if ( $init_data === '' || strlen( $init_data ) > self::MAX_LENGTH ) {
			return null;
		}
		$pairs = [];
		foreach ( explode( '&', $init_data ) as $part ) {
			$split = explode( '=', $part, 2 );
			if ( count( $split ) !== 2 ) {
				return null;
			}
			$key   = rawurldecode( $split[0] );
			$value = rawurldecode( $split[1] );
			if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $key ) || isset( $pairs[ $key ] ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
				return null;
			}
			$pairs[ $key ] = $value;
		}
		return $pairs;
	}

	/**
	 * Compare the hash with the one computed from every other field.
	 *
	 * @param array  $pairs     Decoded pairs.
	 * @param string $bot_token Bot token.
	 */
	private static function hash_matches( array $pairs, string $bot_token ): bool {
		$hash = strtolower( $pairs['hash'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return false;
		}
		unset( $pairs['hash'] );
		ksort( $pairs, SORT_STRING );
		$lines = [];
		foreach ( $pairs as $key => $value ) {
			$lines[] = $key . '=' . $value;
		}
		$secret = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		return hash_equals( hash_hmac( 'sha256', implode( "\n", $lines ), $secret ), $hash );
	}

	/**
	 * User object of the launch data.
	 *
	 * @param string $json JSON text.
	 */
	private static function user( string $json ): ?array {
		$raw = json_decode( $json, true, 8, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $raw ) || ! isset( $raw['id'] ) || ( ! is_int( $raw['id'] ) && ! is_string( $raw['id'] ) ) ) {
			return null;
		}
		$id = (string) $raw['id'];
		if ( ! preg_match( '/^[1-9]\d{0,19}$/', $id ) ) {
			return null;
		}
		$photo = self::text( $raw['photo_url'] ?? '', 500 );
		return [
			'id'            => $id,
			'first_name'    => self::text( $raw['first_name'] ?? '', 100 ),
			'last_name'     => self::text( $raw['last_name'] ?? '', 100 ),
			'username'      => self::text( $raw['username'] ?? '', 64 ),
			'language_code' => preg_match( '/^[A-Za-z-]{2,12}$/', self::text( $raw['language_code'] ?? '', 12 ) ) ? (string) $raw['language_code'] : '',
			'photo_url'     => strncmp( $photo, 'https://', 8 ) === 0 ? esc_url_raw( $photo, [ 'https' ] ) : '',
		];
	}

	/**
	 * Clean one line.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Most characters.
	 */
	private static function text( $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}

	/**
	 * Empty result.
	 *
	 * @param string $error     Error code.
	 * @param int    $auth_date Signed date when known.
	 */
	private static function result( string $error, int $auth_date = 0 ): array {
		return [
			'ok'          => false,
			'error'       => $error,
			'user'        => [
				'id'            => '',
				'first_name'    => '',
				'last_name'     => '',
				'username'      => '',
				'language_code' => '',
				'photo_url'     => '',
			],
			'auth_date'   => $auth_date,
			'start_param' => '',
			'chat_type'   => '',
		];
	}
}
