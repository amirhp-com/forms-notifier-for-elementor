<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class HttpTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	public function test_json(): void {
		HttpMock::expect( 'POST', '~^https://api\.example\.com/x$~', [ 'ok' => true, 'text' => 'سلام' ], 201, [ 'X-Id' => '7' ] );
		$r = Http::post_json( 'https://api.example.com/x', [ 'a' => 'سلام', 'u' => 'https://x/' ], [ 'X-Key' => 'k' ] );
		$this->assertSame( [ 'ok', 'code', 'body', 'json', 'headers', 'error' ], array_keys( $r ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 201, $r['code'] );
		$this->assertSame( 'سلام', $r['json']['text'] );
		$this->assertSame( '7', $r['headers']['x-id'] );
		$this->assertSame( '', $r['error'] );
		$q = HttpMock::last();
		$this->assertSame( '{"a":"سلام","u":"https://x/"}', $q['body'] );
		$this->assertMatches( '~^application/json~', $q['headers']['content-type'] );
		$this->assertSame( 'k', $q['headers']['x-key'] );
		$this->assertSame( 'FormsNotifier/' . FNFE_VERSION, $q['args']['user-agent'] );
	}

	public function test_empty_json_is_an_object(): void {
		HttpMock::expect( 'POST', '~.~' );
		Http::post_json( 'https://api.example.com', [], [ 'content-type' => 'application/vnd.x+json' ] );
		$this->assertSame( '{}', HttpMock::last()['body'] );
		$this->assertSame( 'application/vnd.x+json', HttpMock::last()['headers']['content-type'] );
	}

	public function test_form(): void {
		HttpMock::expect( 'POST', '~.~', 'plain text' );
		$r = Http::post_form( 'https://api.example.com', [ 'to' => Fixtures::PHONE, 'text' => 'a&b' ] );
		$this->assertSame( [ 'to' => Fixtures::PHONE, 'text' => 'a&b' ], HttpMock::last()['form'] );
		$this->assertNull( $r['json'] );
		$this->assertSame( 'plain text', $r['body'] );
		$this->assertTrue( $r['ok'] );
	}

	public function test_errors(): void {
		HttpMock::queue( 'GET', '~.~', [ new \WP_Error( 'http_request_failed', 'cURL error 28: timed out for my-hidden-token' ), [ 'code' => 429, 'body' => [ 'e' => 1 ], 'headers' => [ 'Retry-After' => '9' ] ] ] );
		$r = Http::get( 'https://api.example.com', [ 'scrub' => [ 'my-hidden-token' ] ] );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 0, $r['code'] );
		$this->assertContains( 'timed out', $r['error'] );
		$this->assertNotContains( 'my-hidden-token', $r['error'] );
		$this->assertSame( [], $r['headers'] );
		$r = Http::get( 'https://api.example.com' );
		$this->assertSame( 429, $r['code'] );
		$this->assertContains( '429', $r['error'] );
		$this->assertSame( '9', $r['headers']['retry-after'] );
		$this->assertSame( 1, $r['json']['e'] );
	}

	public function test_bad_scheme(): void {
		foreach ( [ 'file:///etc/passwd', 'gopher://x', '', 'example.com' ] as $url ) {
			$r = Http::get( $url );
			$this->assertSame( 0, $r['code'] );
			$this->assertNotSame( '', $r['error'] );
		}
		HttpMock::assertNoRequests();
	}

	public function test_timeout_default(): void {
		HttpMock::expect( 'GET', '~.~' );
		Http::get( 'https://api.example.com' );
		$this->assertSame( 20, HttpMock::last()['args']['timeout'] );
		Settings::set( 'advanced.http_timeout', 7 );
		Http::get( 'https://api.example.com' );
		$this->assertSame( 7, HttpMock::last()['args']['timeout'] );
		Http::get( 'https://api.example.com', [ 'timeout' => 3 ] );
		$this->assertSame( 3, HttpMock::last()['args']['timeout'] );
	}

	public function test_safe_host_switch(): void {
		HttpMock::expect( '*', '~.~' );
		Http::get( 'https://api.example.com' );
		$this->assertTrue( HttpMock::last()['args']['reject_unsafe_urls'] );
		Http::get( 'https://api.example.com', [ 'safe' => false ] );
		$this->assertFalse( HttpMock::last()['args']['reject_unsafe_urls'] );
		Settings::set( 'advanced.allow_private_hosts', true );
		Http::get( 'http://192.168.1.5:5678/webhook' );
		$this->assertFalse( HttpMock::last()['args']['reject_unsafe_urls'] );
	}

	public function test_args_filter(): void {
		HttpMock::expect( 'PUT', '~.~' );
		$this->filter( 'fnfe_http_args', static function ( $args, $url, $method ) {
			$args['headers']['X-Seen'] = $method . ' ' . $url;
			return $args;
		}, 10, 3 );
		Http::request( 'put', 'https://api.example.com' );
		$this->assertSame( 'PUT https://api.example.com', HttpMock::last()['headers']['x-seen'] );
	}

	public function test_multipart(): void {
		$binary = "\x00\x01\r\n--x\r\n\xFF";
		$file   = Fixtures::file( 'a.png', $binary );
		list( $body, $type ) = Http::multipart(
			[ 'chat_id' => Fixtures::CHAT, 'markup' => [ 'k' => 'ی' ], 'flag' => true ],
			[ 'photo' => [ 'path' => $file->path, 'name' => "ev\"il\r\n.png", 'mime' => 'image/png' ], 'gone' => [ 'path' => '/no/such/file', 'name' => 'x', 'mime' => '' ], 'other' => [ 'path' => $file->path, 'name' => '', 'mime' => 'bad mime' ] ]
		);
		HttpMock::expect( 'POST', '~.~' );
		Http::post( 'https://api.example.com', [ 'body' => $body, 'headers' => [ 'Content-Type' => $type ] ] );
		$parts = HttpMock::last()['parts'];
		$this->assertSame( [ 'chat_id', 'markup', 'flag', 'photo', 'other' ], array_keys( $parts ) );
		$this->assertSame( Fixtures::CHAT, $parts['chat_id']['value'] );
		$this->assertSame( '{"k":"ی"}', $parts['markup']['value'] );
		$this->assertSame( 'true', $parts['flag']['value'] );
		$this->assertTrue( strpos( $body, $binary ) !== false );
		$this->assertSame( 'ev%22il.png', $parts['photo']['filename'] );
		$this->assertSame( 'image/png', $parts['photo']['type'] );
		$this->assertSame( 'application/octet-stream', $parts['other']['type'] );
		$this->assertSame( 1, preg_match( '/boundary=(\S+)$/', $type, $m ) );
		$this->assertSame( '--' . $m[1] . "--\r\n", substr( $body, -strlen( '--' . $m[1] . "--\r\n" ) ) );
	}

	public function test_basic_auth(): void {
		$this->assertSame( 'Basic dXNlcjpwYXNz', Http::basic_auth( 'user', 'pass' ) );
	}

	public function test_result_mapping(): void {
		$map = static function ( int $code, string $body = 'b', array $headers = [] ) {
			return AbstractChannel::map_http( 'p', [ 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body, 'error' => '', 'headers' => $headers ] );
		};
		$this->assertTrue( $map( 204 )->ok );
		foreach ( [ 0, 408, 429, 500, 503 ] as $code ) {
			$this->assertFalse( $map( $code )->ok );
			$this->assertFalse( $map( $code )->permanent, (string) $code );
			$this->assertNotSame( '', $map( $code )->error );
		}
		foreach ( [ 400, 401, 403, 404, 410 ] as $code ) {
			$this->assertTrue( $map( $code )->permanent, (string) $code );
		}
		$this->assertSame( 9, $map( 429, '', [ 'retry-after' => '9' ] )->retry_after );
		$this->assertSame( 'note', AbstractChannel::map_http( 'p', [ 'ok' => true, 'code' => 200, 'body' => 'x' ], 'note' )->response );
	}
}
