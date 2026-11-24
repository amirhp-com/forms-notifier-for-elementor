<?php
/**
 * HTTP client.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** wp_remote_* wrapper with one response shape. */
final class Http {

	/**
	 * Send a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   wp_remote_request args plus: 'json' => array (encodes body + content type),
	 *                       'timeout' (default advanced.http_timeout), 'safe' => bool (default true: wp_safe_remote_request
	 *                       unless advanced.allow_private_hosts), 'scrub' => string[] secrets to remove from errors.
	 * @return array{ok:bool,code:int,body:string,json:mixed,headers:array<string,string>,error:string}
	 */
	public static function request( string $method, string $url, array $args = [] ): array {
		$scrub = array_filter( array_map( 'strval', (array) ( $args['scrub'] ?? [] ) ), 'strlen' );
		$safe  = ! array_key_exists( 'safe', $args ) || (bool) $args['safe'];

		$args['method']  = strtoupper( $method );
		$args['headers'] = (array) ( $args['headers'] ?? [] );
		if ( (float) ( $args['timeout'] ?? 0 ) <= 0 ) {
			$args['timeout'] = max( 1, Settings::int( 'advanced.http_timeout', 20 ) );
		}
		if ( ! isset( $args['user-agent'] ) ) {
			$args['user-agent'] = 'FormsNotifier/' . FNFE_VERSION;
		}
		if ( array_key_exists( 'json', $args ) ) {
			$args['body']    = $args['json'] === [] ? '{}' : (string) wp_json_encode( $args['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$args['headers'] = self::with_header( $args['headers'], 'Content-Type', 'application/json; charset=utf-8' );
		}
		unset( $args['json'], $args['safe'], $args['scrub'] );

		$args = (array) apply_filters( 'fnfe_http_args', $args, $url, $args['method'] );

		if ( ! preg_match( '~^https?://~i', $url ) ) {
			return self::response( 0, '', [], __( 'The address is not a valid URL.', 'forms-notifier-for-elementor' ) );
		}

		$raw = $safe && ! Settings::bool( 'advanced.allow_private_hosts' )
			? wp_safe_remote_request( $url, $args )
			: wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			return self::response( 0, '', [], self::scrub( $raw->get_error_message(), $scrub ) );
		}

		$code  = (int) wp_remote_retrieve_response_code( $raw );
		$body  = (string) wp_remote_retrieve_body( $raw );
		$error = '';
		if ( $code < 200 || $code > 299 ) {
			$text  = (string) wp_remote_retrieve_response_message( $raw );
			$error = self::scrub( trim( 'HTTP ' . $code . ' ' . $text ), $scrub );
		}
		return self::response( $code, $body, self::headers( $raw ), $error );
	}

	/**
	 * GET request.
	 *
	 * @param string $url  URL.
	 * @param array  $args See request().
	 */
	public static function get( string $url, array $args = [] ): array {
		return self::request( 'GET', $url, $args );
	}

	/**
	 * POST request.
	 *
	 * @param string $url  URL.
	 * @param array  $args See request().
	 */
	public static function post( string $url, array $args = [] ): array {
		return self::request( 'POST', $url, $args );
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string $url     URL.
	 * @param array  $json    Body.
	 * @param array  $headers Headers.
	 * @param int    $timeout Seconds, 0 = default.
	 */
	public static function post_json( string $url, array $json, array $headers = [], int $timeout = 0 ): array {
		return self::request(
			'POST',
			$url,
			[
				'json'    => $json,
				'headers' => $headers,
				'timeout' => $timeout,
			]
		);
	}

	/**
	 * POST a form-encoded body.
	 *
	 * @param string $url     URL.
	 * @param array  $fields  Body fields.
	 * @param array  $headers Headers.
	 * @param int    $timeout Seconds, 0 = default.
	 */
	public static function post_form( string $url, array $fields, array $headers = [], int $timeout = 0 ): array {
		return self::request(
			'POST',
			$url,
			[
				'body'    => http_build_query( $fields, '', '&' ),
				'headers' => self::with_header( $headers, 'Content-Type', 'application/x-www-form-urlencoded; charset=utf-8' ),
				'timeout' => $timeout,
			]
		);
	}

	/**
	 * Build a multipart/form-data body. Files are read into memory; callers check sizes first.
	 *
	 * @param array $fields Name => scalar or array (arrays are JSON encoded).
	 * @param array $files  Field => [ 'path' => abs path, 'name' => file name, 'mime' => string ].
	 * @return array{0:string,1:string} [ body, content type header value ]
	 */
	public static function multipart( array $fields, array $files ): array {
		$boundary = '----fnfe' . Crypto::token( 16 );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$body .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="' . self::token_value( (string) $name ) . '"' . $eol . $eol
				. (string) $value . $eol;
		}

		foreach ( $files as $name => $file ) {
			$path = (string) ( $file['path'] ?? '' );
			if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$mime = (string) ( $file['mime'] ?? '' );
			if ( ! preg_match( '~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime ) ) {
				$mime = 'application/octet-stream';
			}
			$file_name = (string) ( $file['name'] ?? '' );
			$body     .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="' . self::token_value( (string) $name ) . '"; filename="'
				. self::token_value( $file_name === '' ? basename( $path ) : $file_name ) . '"' . $eol
				. 'Content-Type: ' . $mime . $eol . $eol
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, checked above.
				. file_get_contents( $path ) . $eol;
		}

		$body .= '--' . $boundary . '--' . $eol;
		return [ $body, 'multipart/form-data; boundary=' . $boundary ];
	}

	/**
	 * Value of an Authorization header.
	 *
	 * @param string $user User name.
	 * @param string $pass Password.
	 */
	public static function basic_auth( string $user, string $pass ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic authentication.
		return 'Basic ' . base64_encode( $user . ':' . $pass );
	}

	/**
	 * Uniform response array.
	 *
	 * @param int    $code    HTTP status, 0 = transport error.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @param string $error   Error.
	 */
	private static function response( int $code, string $body, array $headers, string $error ): array {
		return [
			'ok'      => $code >= 200 && $code <= 299,
			'code'    => $code,
			'body'    => $body,
			'json'    => $body === '' ? null : json_decode( $body, true ),
			'headers' => $headers,
			'error'   => $error,
		];
	}

	/**
	 * Lowercase header map.
	 *
	 * @param array $raw WordPress response.
	 */
	private static function headers( array $raw ): array {
		$headers = wp_remote_retrieve_headers( $raw );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		$out = [];
		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}

	/**
	 * Add a header unless it is already there in any case.
	 *
	 * @param array  $headers Headers.
	 * @param string $name    Header name.
	 * @param string $value   Header value.
	 */
	private static function with_header( array $headers, string $name, string $value ): array {
		foreach ( array_keys( $headers ) as $key ) {
			if ( strtolower( (string) $key ) === strtolower( $name ) ) {
				return $headers;
			}
		}
		$headers[ $name ] = $value;
		return $headers;
	}

	/**
	 * Remove secrets from an error text.
	 *
	 * @param string   $text    Text.
	 * @param string[] $secrets Secrets.
	 */
	private static function scrub( string $text, array $secrets ): string {
		return Logger::clean( $secrets ? str_replace( $secrets, '***', $text ) : $text );
	}

	/**
	 * Quote-safe value of a Content-Disposition parameter.
	 *
	 * @param string $value Value.
	 */
	private static function token_value( string $value ): string {
		return str_replace( [ '"', "\r", "\n", "\0" ], [ '%22', '', '', '' ], $value );
	}
}
