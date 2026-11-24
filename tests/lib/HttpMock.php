<?php
/**
 * HTTP mock.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

/**
 * No test performs a real request: every call must match an expectation.
 *
 *   HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true ] );            array body => JSON, 200
 *   HttpMock::expect( 'POST', '~/x~', 'plain', 500, [ 'retry-after' => '3' ] );
 *   HttpMock::expect( 'GET', '~/y~', new \WP_Error( 'http', 'timeout' ) );
 *   HttpMock::queue( 'POST', '~/x~', [ [ 'ok' => false ], [ 'ok' => true ] ] );  one response per call, in order
 */
final class HttpMock {

	/** @var array[] */
	private static $expectations = [];

	/** @var array[] */
	private static $requests = [];

	/** @var string */
	private static $unexpected = '';

	/** Hook into WordPress. */
	public static function install(): void {
		add_filter( 'pre_http_request', [ self::class, 'intercept' ], 1, 3 );
	}

	/**
	 * Answer every matching request.
	 *
	 * @param string                  $method   HTTP method or '*'.
	 * @param string                  $pattern  Regex for the URL.
	 * @param array|string|\WP_Error  $response Array = JSON body (or a full response when it has the key 'response').
	 * @param int                     $code     Status.
	 * @param array                   $headers  Response headers.
	 */
	public static function expect( string $method, string $pattern, $response = [ 'ok' => true ], int $code = 200, array $headers = [] ): void {
		self::$expectations[] = [
			'method'    => strtoupper( $method ),
			'pattern'   => $pattern,
			'responses' => [ self::response( $response, $code, $headers ) ],
			'repeat'    => true,
		];
	}

	/**
	 * Answer matching requests one by one; a further request is unexpected.
	 *
	 * @param string $method    HTTP method or '*'.
	 * @param string $pattern   Regex for the URL.
	 * @param array  $responses Responses; [ 'code' =>, 'body' => ] sets a status.
	 */
	public static function queue( string $method, string $pattern, array $responses ): void {
		$list = [];
		foreach ( $responses as $response ) {
			$list[] = is_array( $response ) && isset( $response['code'] ) && array_key_exists( 'body', $response )
				? self::response( $response['body'], (int) $response['code'], (array) ( $response['headers'] ?? [] ) )
				: self::response( $response, 200, [] );
		}
		self::$expectations[] = [
			'method'    => strtoupper( $method ),
			'pattern'   => $pattern,
			'responses' => $list,
			'repeat'    => false,
		];
	}

	/**
	 * Recorded requests: url, method, headers (lowercase), body, json, form, parts.
	 *
	 * @param string $pattern Only URLs that match, '' = all.
	 * @return array[]
	 */
	public static function requests( string $pattern = '' ): array {
		return array_values(
			array_filter(
				self::$requests,
				static function ( array $request ) use ( $pattern ): bool {
					return $pattern === '' || preg_match( $pattern, $request['url'] ) === 1;
				}
			)
		);
	}

	/** Last recorded request. */
	public static function last(): ?array {
		return self::$requests ? end( self::$requests ) : null;
	}

	/** Fail when something was requested. */
	public static function assertNoRequests(): void {
		++TestCase::$assertions;
		if ( self::$requests ) {
			throw new AssertionFailed( 'expected no HTTP request, got ' . self::$requests[0]['method'] . ' ' . self::$requests[0]['url'] );
		}
	}

	/** Forget expectations and requests. */
	public static function reset(): void {
		self::$expectations = [];
		self::$requests     = [];
		self::$unexpected   = '';
	}

	/** Called by the runner: an unexpected request fails the test even when the code swallowed the error. */
	public static function verify(): void {
		if ( self::$unexpected !== '' ) {
			throw new AssertionFailed( 'unexpected HTTP request: ' . self::$unexpected );
		}
	}

	/** Filter pre_http_request. */
	public static function intercept( $pre, $args, $url ) {
		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$request = self::record( $method, (string) $url, (array) $args );

		self::$requests[] = $request;

		foreach ( self::$expectations as $i => $expectation ) {
			if ( ! $expectation['responses'] || ( $expectation['method'] !== '*' && $expectation['method'] !== $method ) || ! preg_match( $expectation['pattern'], (string) $url ) ) {
				continue;
			}
			return $expectation['repeat'] ? $expectation['responses'][0] : array_shift( self::$expectations[ $i ]['responses'] );
		}
		self::$unexpected = $method . ' ' . $url;
		return new \WP_Error( 'fnfe_unexpected_request', 'Unexpected request: ' . $method . ' ' . $url );
	}

	private static function record( string $method, string $url, array $args ): array {
		$headers = [];
		foreach ( (array) ( $args['headers'] ?? [] ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = (string) $value;
		}
		$body = $args['body'] ?? '';
		$type = $headers['content-type'] ?? '';
		$form = is_array( $body ) ? $body : [];
		$raw  = is_array( $body ) ? http_build_query( $body ) : (string) $body;
		if ( ! $form && strpos( $type, 'application/x-www-form-urlencoded' ) === 0 ) {
			parse_str( $raw, $form );
		}
		return [
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $raw,
			'json'    => json_decode( $raw, true ),
			'form'    => $form,
			'parts'   => strpos( $type, 'multipart/form-data' ) === 0 ? self::parts( $raw, $type ) : [],
			'args'    => $args,
		];
	}

	/** Multipart body: name => [ value, filename, type ]. */
	private static function parts( string $body, string $type ): array {
		if ( ! preg_match( '/boundary="?([^";]+)"?/', $type, $m ) ) {
			return [];
		}
		$out = [];
		foreach ( explode( '--' . $m[1], $body ) as $chunk ) {
			$split = explode( "\r\n\r\n", $chunk, 2 );
			if ( count( $split ) !== 2 || ! preg_match( '/name="([^"]*)"/', $split[0], $name ) ) {
				continue;
			}
			$out[ $name[1] ] = [
				'value'    => substr( $split[1], 0, -2 ),
				'filename' => preg_match( '/filename="([^"]*)"/', $split[0], $file ) ? $file[1] : '',
				'type'     => preg_match( '/Content-Type:\s*(\S+)/i', $split[0], $mime ) ? $mime[1] : '',
			];
		}
		return $out;
	}

	private static function response( $response, int $code, array $headers ) {
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		if ( is_array( $response ) && isset( $response['response'] ) ) {
			return $response + [
				'headers' => [],
				'body'    => '',
				'cookies' => [],
			];
		}
		return [
			'headers'  => array_change_key_case( $headers ),
			'body'     => is_array( $response ) ? (string) wp_json_encode( $response ) : (string) $response,
			'response' => [
				'code'    => $code,
				'message' => (string) get_status_header_desc( $code ),
			],
			'cookies'  => [],
		];
	}
}
