<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class BotClientTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	private function client( string $platform = 'telegram', ?string $base = null ): BotClient {
		return new BotClient( $platform, Fixtures::TOKEN, $base );
	}

	public function test_json_request(): void {
		HttpMock::expect( 'POST', '~~', [ 'ok' => true, 'result' => [ 'message_id' => 7 ] ] );
		$r = $this->client()->send_message( Fixtures::CHAT . '/45', '<b>سلام</b> 🙂', [ 'parse_mode' => 'HTML', 'disable_preview' => true, 'silent' => true, 'protect' => true, 'reply_to' => 3, 'reply_markup' => [ 'inline_keyboard' => [ [ [ 'text' => 'x', 'url' => 'https://example.com' ] ] ] ] ] );
		$this->assertTrue( $r['ok'] );
		$q = HttpMock::last();
		$this->assertSame( 'https://api.telegram.org/bot' . Fixtures::TOKEN . '/sendMessage', $q['url'] );
		$this->assertSame( 0, $q['args']['redirection'] );
		$this->assertMatches( '~^application/json~', $q['headers']['content-type'] );
		$this->assertEquals(
			[
				'chat_id'              => Fixtures::CHAT,
				'message_thread_id'    => 45,
				'text'                 => '<b>سلام</b> 🙂',
				'parse_mode'           => 'HTML',
				'link_preview_options' => [ 'is_disabled' => true ],
				'disable_notification' => true,
				'protect_content'      => true,
				'reply_parameters'     => [ 'message_id' => 3, 'allow_sending_without_reply' => true ],
				'reply_markup'         => [ 'inline_keyboard' => [ [ [ 'text' => 'x', 'url' => 'https://example.com' ] ] ] ],
			],
			$q['json']
		);
		$this->assertSame( [ 7 ], BotClient::message_ids( $r ) );
	}

	public function test_general_topic_has_no_thread(): void {
		HttpMock::expect( 'POST', '~~', [ 'ok' => true, 'result' => [] ] );
		$this->client()->send_message( Fixtures::CHAT . '/1', 'x' );
		$this->assertFalse( isset( HttpMock::last()['json']['message_thread_id'] ) );
	}

	public function test_bale_request(): void {
		HttpMock::expect( 'POST', '~^https://tapi\.bale\.ai/bot~', [ 'ok' => true, 'result' => [ 'message_id' => 7 ] ] );
		$opts = [ 'parse_mode' => 'HTML', 'disable_preview' => true, 'silent' => true, 'protect' => true, 'reply_to' => 3 ];
		$this->client( 'bale' )->send_message( '4567/45', 'متن', $opts + [ 'chat_type' => 'group' ] );
		$this->assertEquals( [ 'chat_id' => '4567', 'text' => 'متن', 'reply_to_message_id' => 3 ], HttpMock::last()['json'] );
		$this->client( 'bale' )->send_message( '4567/45', 'متن', $opts + [ 'chat_type' => 'private' ] );
		$this->assertSame( 45, HttpMock::last()['json']['message_thread_id'] );
	}

	public function test_multipart_request(): void {
		HttpMock::expect( 'POST', '~/sendDocument$~', [ 'ok' => true, 'result' => [ 'message_id' => 9 ] ] );
		$file = Fixtures::file( 'cv.pdf', "bin\0ary" );
		$r    = $this->client()->send_file( Fixtures::CHAT, 'document', [ 'path' => $file->path, 'name' => 'رزومه.pdf', 'mime' => $file->mime ], 'caption', [ 'reply_to' => 5 ] );
		$this->assertTrue( $r['ok'] );
		$parts = HttpMock::last()['parts'];
		$this->assertSame( "bin\0ary", $parts['document']['value'] );
		$this->assertSame( 'رزومه.pdf', $parts['document']['filename'] );
		$this->assertSame( 'application/pdf', $parts['document']['type'] );
		$this->assertSame( Fixtures::CHAT, $parts['chat_id']['value'] );
		$this->assertSame( 'caption', $parts['caption']['value'] );
		$this->assertSame( 5, json_decode( $parts['reply_parameters']['value'], true )['message_id'] );
		$this->assertTrue( HttpMock::last()['args']['timeout'] >= 90 );
	}

	public function test_media_group_request(): void {
		HttpMock::expect( 'POST', '~/sendMediaGroup$~', [ 'ok' => true, 'result' => [ [ 'message_id' => 1 ], [ 'message_id' => 2 ] ] ] );
		$a = Fixtures::file( 'a.pdf', 'A' );
		$b = Fixtures::file( 'b.pdf', 'B' );
		$r = $this->client()->send_media_group( Fixtures::CHAT, 'document', [ [ 'path' => $a->path, 'name' => 'a.pdf', 'mime' => $a->mime ], [ 'path' => $b->path, 'name' => 'b.pdf', 'mime' => $b->mime, 'caption' => 'two' ] ], 'one' );
		$this->assertSame( [ 1, 2 ], BotClient::message_ids( $r ) );
		$parts = HttpMock::last()['parts'];
		$this->assertEquals( [ [ 'type' => 'document', 'media' => 'attach://file0', 'caption' => 'one' ], [ 'type' => 'document', 'media' => 'attach://file1', 'caption' => 'two' ] ], json_decode( $parts['media']['value'], true ) );
		$this->assertSame( 'B', $parts['file1']['value'] );
		$this->assertFalse( $this->client()->send_media_group( Fixtures::CHAT, 'photo', [ [] ] )['ok'] );
	}

	public function test_too_large_file_is_not_read(): void {
		$this->filter( 'fnfe_bot_max_upload_bytes', static function () { return 3; } );
		$file = Fixtures::file( 'a.pdf', 'four' );
		$r    = $this->client()->send_file( Fixtures::CHAT, 'document', [ 'path' => $file->path, 'name' => 'a.pdf' ] );
		$this->assertSame( 413, $r['code'] );
		HttpMock::assertNoRequests();
	}

	public function test_custom_base(): void {
		HttpMock::expect( 'POST', '~^https://bot\.example\.workers\.dev/bot123456:TEST-TOKEN/getMe$~', [ 'ok' => true, 'result' => [] ] );
		Settings::overlay( [ 'channels.telegram.api_base' => 'https://bot.example.workers.dev/' ], [ 'channels.telegram.token' => Fixtures::TOKEN ] );
		$client = BotClient::for_platform( 'telegram' );
		$this->assertSame( 'https://bot.example.workers.dev', $client->base() );
		$this->assertTrue( $client->get_me()['ok'] );
		$this->assertTrue( $client->has_custom_base() );
		$this->filter( 'fnfe_bot_api_base', static function () { return 'https://other.example.com/x/'; } );
		$this->assertSame( 'https://other.example.com/x', $client->base() );
		Settings::clear_overlay();
	}

	public function test_base_validation(): void {
		foreach ( [ 'http://api.example.com', 'ftp://x.example.com', 'https://', 'https://user@example.com', 'javascript:alert(1)', 'https://example.com?x=1' ] as $bad ) {
			$this->assertNotSame( '', $this->client( 'telegram', $bad )->base_error(), $bad );
			$this->assertFalse( $this->client( 'telegram', $bad )->get_me()['ok'], $bad );
		}
		HttpMock::assertNoRequests();
		Settings::set( 'advanced.allow_private_hosts', true );
		$this->assertSame( '', $this->client( 'telegram', 'http://192.168.1.5:8081' )->base_error() );
	}

	public function test_upload_limit(): void {
		Settings::set( 'channels.telegram.max_upload_mb', 900 );
		$this->assertSame( 50 * MB_IN_BYTES, $this->client()->max_upload_bytes() );
		$this->assertSame( 900 * MB_IN_BYTES, $this->client( 'telegram', 'https://bots.example.com' )->max_upload_bytes() );
		Settings::set( 'channels.telegram.max_upload_mb', 10 );
		$this->assertSame( 10 * MB_IN_BYTES, $this->client()->max_upload_bytes() );
	}

	public function test_token_is_never_exposed(): void {
		HttpMock::queue(
			'POST',
			'~~',
			[
				new \WP_Error( 'http', 'cURL error for https://api.telegram.org/bot' . Fixtures::TOKEN . '/getMe and ' . rawurlencode( Fixtures::TOKEN ) ),
				[ 'code' => 404, 'body' => [ 'ok' => false, 'error_code' => 404, 'description' => 'no bot ' . Fixtures::TOKEN ] ],
				[ 'code' => 502, 'body' => '<html>bot' . Fixtures::TOKEN . '</html>' ],
			]
		);
		$errors = '';
		foreach ( [ 'getMe', 'getChat', 'getUpdates' ] as $method ) {
			$r = $this->client()->call( $method );
			$this->assertFalse( $r['ok'] );
			$this->assertKeys( [ 'ok', 'result', 'error', 'code', 'retry_after', 'migrate_to' ], $r );
			$errors .= wp_json_encode( [ $r, $this->client()->to_result( $r )->to_array() ] );
		}
		$log = wp_json_encode( LogRepository::query( [ 'per_page' => 100 ] ) );
		$this->assertContains( 'getMe', $log );
		foreach ( [ 'TEST-TOKEN', rawurlencode( Fixtures::TOKEN ) ] as $needle ) {
			$this->assertNotContains( $needle, $errors );
			$this->assertNotContains( $needle, $log );
		}
	}

	public function test_bad_tokens_and_methods_are_refused(): void {
		foreach ( [ '', '123:a/../b', "1:a\nb", '1:a?x' ] as $token ) {
			$this->assertSame( 401, ( new BotClient( 'telegram', $token ) )->get_me()['code'], $token );
		}
		$this->assertSame( 400, $this->client()->call( '../getMe' )['code'] );
		HttpMock::assertNoRequests();
	}

	public function test_result_mapping(): void {
		$cases = [
			// code, description, parameters, permanent, retry_after
			[ 401, 'Unauthorized', [], true, 0 ],
			[ 400, 'Bad Request: chat not found', [], true, 0 ],
			[ 403, 'Forbidden: bot was blocked by the user', [], true, 0 ],
			[ 403, 'Forbidden: bot was kicked from the group chat', [], true, 0 ],
			[ 404, 'Not Found', [], true, 0 ],
			[ 429, 'Too Many Requests: retry after 14', [ 'retry_after' => 14 ], false, 14 ],
			[ 500, 'Internal Server Error', [], false, 0 ],
			[ 502, 'Bad Gateway', [], false, 0 ],
		];
		foreach ( $cases as list( $code, $description, $parameters, $permanent, $retry ) ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => $code, 'description' => $description, 'parameters' => $parameters ], $code );
			$result = $this->client()->to_result( $this->client()->get_me() );
			$this->assertFalse( $result->ok );
			$this->assertSame( $permanent, $result->permanent, (string) $code );
			$this->assertSame( $retry, $result->retry_after );
			$this->assertSame( $code, $result->http_code );
			$this->assertNotSame( '', $result->error );
		}

		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => 400, 'description' => 'group chat was upgraded', 'parameters' => [ 'migrate_to_chat_id' => -1009876543210 ] ], 400 );
		$result = $this->client()->to_result( $this->client()->get_me() );
		$this->assertFalse( $result->permanent );
		$this->assertSame( '-1009876543210', $result->meta['migrate_to'] );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', new \WP_Error( 'http', 'timeout' ) );
		$result = $this->client()->to_result( $this->client()->get_me() );
		$this->assertFalse( $result->permanent );
		$this->assertSame( 0, $result->http_code );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', 'not json', 200 );
		$r = $this->client()->get_me();
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $this->client()->to_result( $r )->permanent );
		$this->assertTrue( $this->client()->to_result( [ 'ok' => true, 'result' => [ 'message_id' => 5 ] ] )->ok );
	}

	public function test_webhook_request(): void {
		HttpMock::expect( 'POST', '~/setWebhook$~', [ 'ok' => true, 'result' => true ] );
		$this->client()->set_webhook( 'https://example.com/hook', 'secret', [ 'message' ] );
		$this->assertEquals( [ 'url' => 'https://example.com/hook', 'secret_token' => 'secret', 'allowed_updates' => [ 'message' ], 'drop_pending_updates' => false, 'max_connections' => 1 ], HttpMock::last()['json'] );
		$this->client()->set_webhook( 'https://example.com/hook', 'secret', [] );
		$this->assertSame( [], HttpMock::last()['json']['allowed_updates'] );
		$this->client( 'bale' )->set_webhook( 'https://example.com/hook', 'secret', [ 'message' ] );
		$this->assertEquals( [ 'url' => 'https://example.com/hook' ], HttpMock::last()['json'] );
	}

	public function test_instances(): void {
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		$a = BotClient::for_platform( 'telegram' );
		$this->assertTrue( $a === BotClient::for_platform( 'telegram' ) );
		Settings::overlay( [], [ 'channels.telegram.token' => '99:OTHER' ] );
		$this->assertSame( '99', BotClient::for_platform( 'telegram' )->bot_id() );
		Settings::clear_overlay();
		$this->assertSame( '123456', BotClient::for_platform( 'telegram' )->bot_id() );
	}
}
