<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

/** Helpers for golden tests of gateways. */
abstract class ProviderTestCase extends TestCase {

	const CHANNEL = 'sms';

	const IR = '+989120000000';

	const INTL = '+15550100000';

	public function setUp(): void {
		Db::reset();
		HttpMock::reset();
	}

	public function tearDown(): void {
		Settings::clear_overlay();
	}

	/** Provider with its settings; keys of secret fields go to the secret store. */
	protected function provider( string $id, array $settings ): ProviderInterface {
		$provider = Registry::provider( static::CHANNEL, $id );
		$this->assertNotNull( $provider, $id . ' is registered' );
		$types = array_column( $provider->fields(), 'type', 'key' );
		$plain = $secret = [];
		foreach ( $settings as $key => $value ) {
			$path = 'channels.' . static::CHANNEL . ".providers.$id.$key";
			if ( ( $types[ $key ] ?? '' ) === 'secret' ) {
				$secret[ $path ] = $value;
			} else {
				$plain[ $path ] = $value;
			}
		}
		Settings::clear_overlay();
		Settings::overlay( $plain, $secret );
		return $provider;
	}

	protected function message( string $text = 'Hello', array $pattern = [] ): Message {
		$message          = new Message( static::CHANNEL, new Recipient( static::CHANNEL, self::IR ) );
		$message->plain   = $text;
		$message->body    = $text;
		$message->pattern = $pattern;
		return $message;
	}

	protected function pattern( string $code, array $vars, string $lang = '' ): array {
		return [ 'code' => $code, 'vars' => $vars, 'lang' => $lang, 'codes' => [] ];
	}

	/**
	 * One provider.
	 *
	 * @param array $case id, settings, to?, optional?: string[] settings that may be missing, secrets: string[],
	 *                    text / pattern: [ input, method, url, headers, body, response, remote id, code? ],
	 *                    failures: [ [ response, code, permanent, text in error ] ], status: [ method, url regex, response, text ]
	 */
	protected function golden( array $case ): void {
		$id       = $case['id'];
		$to       = $case['to'] ?? self::IR;
		$provider = $this->provider( $id, $case['settings'] );
		$this->assertSame( $id, $provider->id() );
		$this->assertTrue( $provider->is_configured(), $id . ' configured' );
		$this->assertSame( [], $provider->problems() );
		$this->assertMatches( '~^(https://\S+)?$~', $provider->panel_url() );

		foreach ( [ 'text', 'pattern' ] as $kind ) {
			if ( isset( $case[ $kind ] ) ) {
				foreach ( is_string( $case[ $kind ][1] ?? null ) ? [ $case[ $kind ] ] : $case[ $kind ] as $send ) {
					$this->golden_send( $id, $to, $provider, $kind, $send, $case['secrets'] );
				}
			}
		}

		// Failures through the text request, or the pattern request of a gateway without texts.
		$sample = $case['text'] ?? $case['pattern'];
		$sample = is_string( $sample[1] ?? null ) ? $sample : reset( $sample );
		$kind   = isset( $case['text'] ) ? 'text' : 'pattern';
		$fails  = array_merge(
			$case['failures'],
			[
				[ new \WP_Error( 'http', 'cURL error 28: timed out ' . reset( $case['secrets'] ) ), 0, false, 'timed out' ],
				[ '<html><body>Bad gateway</body></html>', 502, false, '' ],
				[ '<html>Login</html>', 200, true, '' ],
				[ [ 'unrelated' => 1 ], 200, true, '' ],
				[ '', 429, false, '' ],
			]
		);
		foreach ( $fails as $i => $fail ) {
			HttpMock::reset();
			HttpMock::expect( '*', '~.~', $fail[0], $fail[1], [ 'Retry-After' => '7' ] );
			$result = $provider->send( $to, $kind === 'text' ? $this->message( $sample[0] ) : $this->message( '', $sample[0] ) );
			$label  = "$id failure $i: " . $result->error;
			$this->assertFalse( $result->ok, $label );
			$this->assertSame( $fail[2], $result->permanent, $label );
			$this->assertSame( $id, $result->provider );
			$this->assertTrue( $result->error !== '', $label );
			if ( $fail[3] !== '' ) {
				$this->assertContains( $fail[3], $result->error, $label );
			}
			if ( $fail[1] === 429 ) {
				$this->assertSame( 7, $result->retry_after, $label );
			}
			$this->assert_scrubbed( $result, $case['secrets'] );
			$this->assertNotContains( '<', $result->error );
		}

		// A gateway that echoes the credentials.
		foreach ( [ 200, 401, 500 ] as $code ) {
			HttpMock::reset();
			HttpMock::expect( '*', '~.~', implode( ' ', $case['secrets'] ), $code );
			$result = $provider->send( $to, $kind === 'text' ? $this->message( $sample[0] ) : $this->message( '', $sample[0] ) );
			$this->assertFalse( $result->ok, $id );
			$this->assert_scrubbed( $result, $case['secrets'] );
		}

		if ( isset( $case['status'] ) ) {
			list( $method, $url, $response, $text ) = $case['status'];
			HttpMock::reset();
			HttpMock::expect( $method, $url, $response );
			$this->assertContains( $text, (string) $provider->status(), $id . ' status' );
			HttpMock::reset();
			HttpMock::expect( '*', '~.~', 'nope ' . implode( ' ', $case['secrets'] ), 401 );
			$status = (string) $provider->status();
			$this->assertTrue( $status !== '' );
			$this->assertNotContains( reset( $case['secrets'] ), \AmirhpCom\FormsNotifier\Core\Logger::clean( $status ) );
		} else {
			HttpMock::reset();
			$this->assertNull( $provider->status(), $id . ' has no status' );
			HttpMock::assertNoRequests();
		}

		// Each required setting.
		HttpMock::reset();
		foreach ( array_diff( array_keys( $case['settings'] ), $case['optional'] ?? [] ) as $key ) {
			$this->provider( $id, [ $key => '' ] + $case['settings'] );
			$this->assertFalse( $provider->is_configured(), "$id without $key" );
			$this->assertCount( 1, $provider->problems() );
			$this->assertFalse( $provider->send( $to, $this->message() )->ok );
			$this->assertTrue( $provider->send( $to, $this->message() )->permanent );
		}
		foreach ( $case['optional'] ?? [] as $key ) {
			$this->provider( $id, [ $key => '' ] + $case['settings'] );
			$this->assertTrue( $provider->is_configured(), "$id without $key" );
		}
		HttpMock::assertNoRequests();
	}

	private function golden_send( string $id, string $to, ProviderInterface $provider, string $kind, array $send, array $secrets ): void {
		list( $input, $method, $url, $headers, $body, $response, $remote ) = $send;
		HttpMock::reset();
		HttpMock::queue( $method, '~.~', [ [ 'code' => $send[7] ?? 200, 'body' => $response ] ] );
		$result = $provider->send( $to, $kind === 'text' ? $this->message( $input ) : $this->message( '', $input ) );
		$label  = "$id $kind: " . $result->error;
		$this->assertTrue( $result->ok, $label );
		$this->assertSame( $remote, $result->remote_id, $label );
		$this->assertSame( $send[7] ?? 200, $result->http_code, $label );
		$this->assert_scrubbed( $result, $secrets );
		$request = HttpMock::last();
		$this->assertSame( $method, $request['method'], $label );
		$this->assertSame( $url, $request['url'], $label );
		$this->assertSame( 0, $request['args']['redirection'], $label );
		foreach ( $headers as $name => $value ) {
			$this->assertSame( $value, $request['headers'][ strtolower( $name ) ] ?? null, "$label $name" );
		}
		if ( $body === null ) {
			$this->assertSame( '', $request['body'], $label );
		} elseif ( is_string( $body ) ) {
			$this->assertSame( $body, $request['body'], $label );
		} elseif ( isset( $body['form'] ) ) {
			$this->assertSame( $body['form'], $request['form'], $label );
			$this->assertMatches( '~^application/x-www-form-urlencoded~', $request['headers']['content-type'] );
		} else {
			$this->assertSame( wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $request['body'], $label );
			$this->assertMatches( '~^application/json~', $request['headers']['content-type'] );
		}
	}

	protected function assert_scrubbed( SendResult $result, array $secrets ): void {
		$text = wp_json_encode( $result->to_array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		foreach ( $secrets as $secret ) {
			$this->assertNotContains( $secret, $text, $result->provider . ' leaks a credential' );
		}
	}
}
