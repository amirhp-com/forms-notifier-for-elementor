<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace {
	// Stand-ins for the public functions of the two plugins, only when they are not active.
	if ( ! function_exists( 'wp_sms_send' ) ) {
		function wp_sms_send( $to, $msg, $is_flash = false, $from = null, $media = [], $vars = [] ) {
			$GLOBALS['fnfe_test_wpsms'][] = func_get_args();
			$answer                       = $GLOBALS['fnfe_test_wpsms_answer'] ?? true;
			if ( $answer instanceof \Throwable ) {
				throw $answer;
			}
			return $answer;
		}
	}
	if ( ! function_exists( 'PWSMS' ) ) {
		function PWSMS() {
			static $plugin = null;
			if ( ! $plugin ) {
				$plugin = new class() {
					public function send_sms( $data ) {
						$GLOBALS['fnfe_test_pwsms'][] = $data;
						return $GLOBALS['fnfe_test_pwsms_answer'] ?? true;
					}
				};
			}
			return $plugin;
		}
	}
}

namespace AmirhpCom\FormsNotifier\Tests\Sms {

	use AmirhpCom\FormsNotifier\Core\Registry;
	use AmirhpCom\FormsNotifier\Core\Settings;
	use AmirhpCom\FormsNotifier\Tests\HttpMock;

	require_once __DIR__ . '/ProviderTestCase.php';

	final class PassthroughTest extends ProviderTestCase {

		public function setUp(): void {
			parent::setUp();
			$GLOBALS['fnfe_test_wpsms'] = $GLOBALS['fnfe_test_pwsms'] = [];
			$GLOBALS['fnfe_test_wpsms_answer'] = $GLOBALS['fnfe_test_pwsms_answer'] = true;
		}

		public function test_wpsms(): void {
			$provider = $this->provider( 'wpsms', [] );
			$this->assertTrue( $provider->is_configured() );
			$this->assertTrue( $provider->supports_pattern() );
			$this->assertTrue( is_string( $provider->status() ) );
			$this->assertSame( 'passthrough', $provider->region() );

			$result = $provider->send( self::IR, $this->message( 'Hello a|99' ) );
			$this->assertTrue( $result->ok );
			$this->assertSame( [ [ '09120000000' ], 'Hello a/99', false, null, [], [] ], $GLOBALS['fnfe_test_wpsms'][0] );
			$provider->send( self::INTL, $this->message( 'Hello' ) );
			$this->assertSame( [ self::INTL ], $GLOBALS['fnfe_test_wpsms'][1][0] );

			$provider->send( self::IR, $this->message( 'Your code | 12', $this->pattern( '77', [ 'code' => '12' ] ) ) );
			$this->assertSame( 'Your code / 12|77', $GLOBALS['fnfe_test_wpsms'][2][1] );
			$this->assertSame( [ 'code' => '12' ], $GLOBALS['fnfe_test_wpsms'][2][5] );
			$provider->send( self::IR, $this->message( '', $this->pattern( '77', [ 'a' => 'x|1', 'b' => 'y' ] ) ) );
			$this->assertSame( 'x/1 y|77', $GLOBALS['fnfe_test_wpsms'][3][1] );

			$this->provider( 'wpsms', [ 'local_numbers' => false ] );
			$provider->send( self::IR, $this->message() );
			$this->assertSame( [ self::IR ], $GLOBALS['fnfe_test_wpsms'][4][0] );
			HttpMock::assertNoRequests();
		}

		public function test_wpsms_failures(): void {
			$provider = $this->provider( 'wpsms', [] );
			foreach ( [ false, '', new \WP_Error( 'sms', 'No credit <b>x</b>' ) ] as $answer ) {
				$GLOBALS['fnfe_test_wpsms_answer'] = $answer;
				$result                            = $provider->send( self::IR, $this->message() );
				$this->assertFalse( $result->ok );
				$this->assertTrue( $result->error !== '' );
			}
			$this->assertSame( 'No credit x', $result->error );
			$GLOBALS['fnfe_test_wpsms_answer'] = (object) [ 'id' => 5 ];
			$this->assertSame( '{"id":5}', $provider->send( self::IR, $this->message() )->response );

			// An exception of the other plugin stays inside the channel.
			$GLOBALS['fnfe_test_wpsms_answer'] = new \RuntimeException( 'broken gateway' );
			Settings::overlay( [ 'channels.sms.default_provider' => 'wpsms' ] );
			$result = Registry::channel( 'sms' )->send( $this->message() );
			$this->assertFalse( $result->ok );
			$this->assertFalse( $result->permanent );
			$this->assertContains( 'broken gateway', $result->error );
		}

		public function test_pwsms(): void {
			$provider = $this->provider( 'pwsms', [] );
			$this->assertTrue( $provider->is_configured() );
			$this->assertFalse( $provider->supports_pattern() );

			$this->assertTrue( $provider->send( self::IR, $this->message( 'سلام' ) )->ok );
			$this->assertSame( [ 'mobile' => '09120000000', 'message' => 'سلام', 'type' => 0 ], $GLOBALS['fnfe_test_pwsms'][0] );

			// A visitor cannot smuggle a pattern in.
			$provider->send( self::IR, $this->message( "pattern:12\ncode:1" ) );
			$this->assertSame( "pattern\u{200C}:12\ncode:1", $GLOBALS['fnfe_test_pwsms'][1]['message'] );
			$provider->send( self::IR, $this->message( 'x template=abc' ) );
			$this->assertSame( "x template\u{200C}=abc", $GLOBALS['fnfe_test_pwsms'][2]['message'] );

			$this->provider( 'pwsms', [ 'pattern' => true ] );
			$this->assertTrue( $provider->supports_pattern() );
			$provider->send( self::IR, $this->message( 'text', $this->pattern( '12', [ 'code' => "1~\n2" ] ) ) );
			$this->assertSame( "pattern:12\ncode:1  2", $GLOBALS['fnfe_test_pwsms'][3]['message'] );

			$this->assertTrue( $provider->send( self::INTL, $this->message() )->permanent );
			$this->assertCount( 4, $GLOBALS['fnfe_test_pwsms'] );

			foreach ( [ 'No credit', false ] as $answer ) {
				$GLOBALS['fnfe_test_pwsms_answer'] = $answer;
				$result                            = $provider->send( self::IR, $this->message() );
				$this->assertFalse( $result->ok );
				$this->assertTrue( $result->error !== '' );
			}
		}

		private function custom( array $settings ) {
			return $this->provider( 'custom', $settings + [ 'url' => 'https://sms.example.com/send', 'token' => 'fake-token-123' ] );
		}

		public function test_custom_json(): void {
			$provider = $this->custom( [ 'headers' => [ 'X-Key' => 'k {token}', "Bad\r\nName" => "a\r\nb" ] ] );
			$this->assertTrue( $provider->is_configured() );
			$this->assertFalse( $provider->supports_pattern() );
			HttpMock::expect( '*', '~.~', [ 'id' => 5 ] );
			$result = $provider->send( self::IR, $this->message( "He said \"hi\"\n{token} \\ سلام" ) );
			$this->assertTrue( $result->ok );
			$this->assertSame( '5', $result->remote_id );
			$request = HttpMock::last();
			$this->assertSame( 'POST', $request['method'] );
			$this->assertSame( 'https://sms.example.com/send', $request['url'] );
			$this->assertSame( [ 'to' => self::IR, 'text' => "He said \"hi\"\n{token} \\ سلام" ], $request['json'] );
			$this->assertSame( 'k fake-token-123', $request['headers']['x-key'] );
			$this->assertSame( 'a b', $request['headers']['badname'] );
			$this->assertMatches( '~^application/json~', $request['headers']['content-type'] );

			$this->custom( [ 'body' => '{"a":["{to_local}","{to_digits}"],"s":"{sender}","x":"{unknown}","v":"{var:x}"}', 'sender' => '3000', 'method' => 'PUT' ] );
			$provider->send( self::IR, $this->message() );
			$this->assertSame( 'PUT', HttpMock::last()['method'] );
			$this->assertSame( [ 'a' => [ '09120000000', '989120000000' ], 's' => '3000', 'x' => '{unknown}', 'v' => '' ], HttpMock::last()['json'] );
			$provider->send( self::INTL, $this->message() );
			$this->assertSame( [ '15550100000', '15550100000' ], HttpMock::last()['json']['a'] );

			HttpMock::reset();
			$this->custom( [ 'body' => '{"to":{to},"text":{text}}' ] );
			$result = $provider->send( self::IR, $this->message( 'plain' ) );
			$this->assertTrue( ! $result->ok && $result->permanent );
			HttpMock::assertNoRequests();
		}

		public function test_custom_form_and_query(): void {
			$provider = $this->custom( [ 'format' => 'form', 'body' => "to={to}\ntext={text}\nkey={token}" ] );
			HttpMock::expect( '*', '~.~', 'OK' );
			$this->assertTrue( $provider->send( self::IR, $this->message( 'a&b=c {to}' ) )->ok );
			$this->assertSame( [ 'to' => self::IR, 'text' => 'a&b=c {to}', 'key' => 'fake-token-123' ], HttpMock::last()['form'] );
			$this->assertMatches( '~^application/x-www-form-urlencoded~', HttpMock::last()['headers']['content-type'] );

			$this->custom( [ 'format' => 'query', 'method' => 'GET', 'url' => 'https://sms.example.com/{token}/send?v=1' ] );
			$provider->send( self::IR, $this->message( 'a b&c' ) );
			$this->assertSame( 'https://sms.example.com/fake-token-123/send?v=1&to=%2B989120000000&text=a%20b%26c', HttpMock::last()['url'] );
			$this->assertSame( '', HttpMock::last()['body'] );
			$this->assertSame( 'GET', HttpMock::last()['method'] );

			$this->custom( [ 'format' => 'json', 'method' => 'GET', 'url' => 'https://sms.example.com/?t={text}' ] );
			$provider->send( self::IR, $this->message( '/../x?y#z' ) );
			$this->assertSame( 'https://sms.example.com/?t=%2F..%2Fx%3Fy%23z', HttpMock::last()['url'] );
		}

		public function test_custom_pattern(): void {
			$provider = $this->custom( [ 'pattern_body' => '{"tpl":"{pattern_code}","a":"{var:name}","b":"{var:0}","c":"{var:1}","t":"{text}"}' ] );
			$this->assertTrue( $provider->supports_pattern() );
			HttpMock::expect( '*', '~.~', [] );
			$provider->send( self::IR, $this->message( '', $this->pattern( 'T"1', [ 'name' => 'Ali {var:1}', 'x' => "two\nlines" ] ) ) );
			$this->assertSame( [ 'tpl' => 'T"1', 'a' => 'Ali {var:1}', 'b' => 'Ali {var:1}', 'c' => 'two lines', 't' => '' ], HttpMock::last()['json'] );
		}

		public function test_custom_success_rules(): void {
			$send = function ( array $settings, $response, int $code = 200 ) {
				HttpMock::reset();
				HttpMock::expect( '*', '~.~', $response, $code );
				return $this->custom( $settings )->send( self::IR, $this->message() );
			};
			$this->assertTrue( $send( [], 'anything' )->ok );
			$this->assertTrue( $send( [ 'success_contains' => 'OK' ], 'status OK' )->ok );
			$result = $send( [ 'success_contains' => 'OK' ], 'status failed fake-token-123' );
			$this->assertTrue( ! $result->ok && $result->permanent );
			$this->assert_scrubbed( $result, [ 'fake-token-123' ] );
			$path = [ 'success_path' => 'data.0.status', 'success_value' => 'true' ];
			$this->assertTrue( $send( $path, [ 'data' => [ [ 'status' => true ] ] ] )->ok );
			$this->assertTrue( $send( $path, [ 'data' => [ [ 'status' => 'true' ] ] ] )->ok );
			$this->assertFalse( $send( $path, [ 'data' => [ [ 'status' => false ] ] ] )->ok );
			$this->assertFalse( $send( $path, 'not json' )->ok );
			$this->assertTrue( $send( [ 'success_path' => 'code', 'success_value' => '1' ], [ 'code' => 1 ] )->ok );
			$result = $send( [], [ 'error' => [ 'message' => 'bad number' ] ], 400 );
			$this->assertTrue( ! $result->ok && $result->permanent );
			$this->assertSame( 'bad number', $result->error );
			$this->assertFalse( $send( [], 'x', 503 )->permanent );
			$this->assertFalse( $send( [], new \WP_Error( 'http', 'timeout' ) )->permanent );
		}

		public function test_custom_configuration(): void {
			foreach ( [ '', 'ftp://x', 'sms.example.com', 'https://', 'https://{text}.example.com', 'javascript:alert(1)' ] as $url ) {
				$provider = $this->provider( 'custom', [ 'url' => $url ] );
				$this->assertFalse( $provider->is_configured(), $url );
				$this->assertCount( 1, $provider->problems() );
			}
			$this->assertTrue( $this->provider( 'custom', [ 'url' => 'http://10.0.0.1/x' ] )->is_configured() );
			$this->assertNull( $provider->status() );
			$this->assertTrue( $this->provider( 'custom', [ 'url' => '' ] )->send( self::IR, $this->message() )->permanent );
		}
	}
}
