<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Channels\Bot\Updates;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class UpdatesTest extends TestCase {

	private $processed = [];

	public function setUp(): void {
		Db::reset();
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set_secret( 'channels.bale.token', Fixtures::TOKEN );
		$this->processed = [];
		$this->filter( 'fnfe_bot_update', function ( $platform, $update ) { $this->processed[] = $update['update_id']; }, 10, 2 );
	}

	public function tearDown(): void {
		wp_clear_scheduled_hook( Cron::POLL );
	}

	private function public_site( string $base = 'https://example.com/wp-json/' ): void {
		$this->filter( 'rest_url', static function ( $url, $path ) use ( $base ) { return $base . ltrim( $path, '/' ); }, 10, 2 );
	}

	private function me( array $more = [] ): void {
		HttpMock::expect( 'POST', '~/getMe$~', [ 'ok' => true, 'result' => [ 'id' => 123456, 'is_bot' => true, 'first_name' => 'Demo', 'username' => 'demo_bot' ] ] );
		HttpMock::expect( 'POST', '~/(setMyCommands|setChatMenuButton|deleteMyCommands)$~', [ 'ok' => true, 'result' => true ] );
		foreach ( $more as $method ) {
			HttpMock::expect( 'POST', '~/' . $method . '$~', [ 'ok' => true, 'result' => true ] );
		}
	}

	private function update( int $id ): array {
		return Fixtures::update_start( $id );
	}

	private function quiet(): void {
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
	}

	private function webhook( string $platform = 'telegram' ): array {
		$this->public_site();
		$this->me( [ 'setWebhook', 'deleteWebhook' ] );
		$this->assertSame( '', Updates::connect( $platform, 'webhook' ) );
		return [ (string) State::get( "bot.$platform.webhook_secret" ), (string) State::get( "bot.$platform.header_secret" ) ];
	}

	private function post( string $platform, string $secret, string $body, array $headers = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', "/fnfe/v1/bot/$platform/hook/$secret" );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	public function test_webhook_verification(): void {
		list( $secret, $header ) = $this->webhook();
		$this->assertSame( 32, strlen( $secret ) );
		$this->assertMatches( '/^[A-Za-z0-9_-]{48}$/', $header );
		$body = wp_json_encode( $this->update( 1 ) );
		$ok   = [ 'X-Telegram-Bot-Api-Secret-Token' => $header ];

		$this->assertSame( 403, $this->post( 'telegram', str_repeat( 'a', 32 ), $body, $ok )->get_status() );
		$this->assertSame( 403, $this->post( 'telegram', 'short', $body, $ok )->get_status() );
		$this->assertSame( 403, $this->post( 'telegram', $secret, $body )->get_status() );
		$this->assertSame( 403, $this->post( 'telegram', $secret, $body, [ 'X-Telegram-Bot-Api-Secret-Token' => 'wrong' ] )->get_status() );
		$this->assertSame( 403, $this->post( 'bale', $secret, $body, $ok )->get_status() );
		$this->assertSame( 404, $this->post( 'other', $secret, $body, $ok )->get_status() );
		// The secret of the path cannot be replaced by a parameter.
		$request = new \WP_REST_Request( 'POST', '/fnfe/v1/bot/telegram/hook/' . str_repeat( 'b', 32 ) );
		$request->set_query_params( [ 'secret' => $secret, 'platform' => 'telegram' ] );
		$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $header );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		Updates::run_pending();
		$this->assertSame( [], $this->processed );
	}

	public function test_webhook_processes_after_the_response(): void {
		list( $secret, $header ) = $this->webhook();
		$ok = [ 'X-Telegram-Bot-Api-Secret-Token' => $header ];
		$r  = $this->post( 'telegram', $secret, wp_json_encode( $this->update( 10 ) ), $ok );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( [ 'ok' => true ], $r->get_data() );
		$this->assertSame( [], $this->processed, 'nothing runs before the response' );
		$this->assertTrue( has_action( 'shutdown', [ Updates::class, 'run_pending' ] ) === 1 );
		$this->quiet();
		Updates::run_pending();
		$this->assertSame( [ 10 ], $this->processed );
		$this->assertNotNull( ChatRepository::find_chat( 'telegram', '111111' ) );

		$this->assertSame( 200, $this->post( 'telegram', $secret, wp_json_encode( $this->update( 10 ) ), $ok )->get_status() );
		$this->assertSame( 200, $this->post( 'telegram', $secret, wp_json_encode( $this->update( 9 ) ), $ok )->get_status() );
		Updates::run_pending();
		$this->assertSame( [ 10 ], $this->processed, 'duplicates and older ids are ignored' );
	}

	public function test_webhook_ignores_bad_bodies(): void {
		list( $secret, $header ) = $this->webhook();
		$ok   = [ 'X-Telegram-Bot-Api-Secret-Token' => $header ];
		$huge = wp_json_encode( [ 'update_id' => 5, 'x' => str_repeat( 'a', Updates::MAX_BODY ) ] );
		foreach ( [ $huge, '{"update_id":"5"}', '{"update_id":5.5}', '[1]', '{}' ] as $body ) {
			$this->assertSame( 200, $this->post( 'telegram', $secret, $body, $ok )->get_status() );
		}
		Updates::run_pending();
		$this->assertSame( [], $this->processed );
	}

	public function test_bale_needs_only_the_path_secret(): void {
		list( $secret ) = $this->webhook( 'bale' );
		$this->quiet();
		$this->assertSame( 200, $this->post( 'bale', $secret, wp_json_encode( $this->update( 3 ) ) )->get_status() );
		Updates::run_pending();
		$this->assertSame( [ 3 ], $this->processed );
		$this->assertSame( 403, $this->post( 'bale', str_repeat( 'a', 32 ), '{}' )->get_status() );
	}

	public function test_off_ignores_updates(): void {
		list( $secret, $header ) = $this->webhook();
		State::set( 'bot.telegram.mode_effective', 'off' );
		$this->assertSame( 200, $this->post( 'telegram', $secret, wp_json_encode( $this->update( 3 ) ), [ 'X-Telegram-Bot-Api-Secret-Token' => $header ] )->get_status() );
		Updates::run_pending();
		$this->assertSame( [], $this->processed );
	}

	public function test_handler_errors_are_logged(): void {
		$this->filter( 'fnfe_bot_update', static function () { throw new \RuntimeException( 'boom' ); } );
		Updates::process( 'telegram', $this->update( 1 ) );
		$this->assertContains( 'boom', wp_json_encode( \AmirhpCom\FormsNotifier\Repository\LogRepository::query( [] ) ) );
	}

	public function test_accept(): void {
		$this->freeze( 1800000000 );
		$this->assertTrue( Updates::accept( 'telegram', 100 ) );
		$this->assertFalse( Updates::accept( 'telegram', 100 ) );
		$this->assertFalse( Updates::accept( 'telegram', 99 ) );
		$this->assertTrue( Updates::accept( 'telegram', 103 ) );
		$this->assertTrue( Updates::accept( 'telegram', 102 ), 'arrived late' );
		$this->assertFalse( Updates::accept( 'telegram', 102 ) );
		$this->assertTrue( Updates::accept( 'bale', 100 ), 'platforms are separate' );
		$this->assertSame( 103, State::get( 'bot.telegram.last_update' ) );
	}

	public function test_accept_after_a_quiet_week(): void {
		$now = 1800000000;
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; } );
		Updates::accept( 'telegram', 900000 );
		$this->assertFalse( Updates::accept( 'telegram', 5 ) );
		$now += 8 * DAY_IN_SECONDS;
		$this->assertTrue( Updates::accept( 'telegram', 5 ), 'ids may start again' );
		$this->assertTrue( Updates::accept( 'telegram', 6 ) );
		$this->assertFalse( Updates::accept( 'telegram', 5 ) );
	}

	public function test_accept_with_a_busy_lock(): void {
		Lock::acquire( 'bot_telegram', 30 );
		$this->assertTrue( Updates::accept( 'telegram', 1 ), 'an update is not lost' );
		$this->assertFalse( Lock::acquire( 'bot_telegram', 30 ), 'the lock of the other request stays' );
		Lock::release( 'bot_telegram' );
	}

	public function test_polling(): void {
		$this->quiet();
		HttpMock::expect( 'POST', '~/deleteWebhook$~', [ 'ok' => true, 'result' => true ] );
		HttpMock::queue( 'POST', '~/getUpdates$~', [ [ 'ok' => true, 'result' => array_map( [ $this, 'update' ], range( 1, 100 ) ) ], [ 'ok' => true, 'result' => [ $this->update( 101 ), 'junk', [ 'x' => 1 ] ] ] ] );
		$r = Updates::poll( 'telegram' );
		$this->assertSame( [ 'ok' => true, 'updates' => 101, 'chats' => 1, 'message' => '' ], $r );
		$this->assertSame( range( 1, 101 ), $this->processed );
		$this->assertSame( 102, State::get( 'bot.telegram.offset' ) );
		$this->assertMatches( '~/deleteWebhook$~', HttpMock::requests()[0]['url'] );
		$calls = HttpMock::requests( '~/getUpdates$~' );
		$this->assertEquals( [ 'limit' => 100, 'allowed_updates' => [ 'message', 'channel_post', 'my_chat_member', 'callback_query' ] ], $calls[0]['json'] );
		$this->assertSame( 101, $calls[1]['json']['offset'] );

		HttpMock::reset();
		HttpMock::queue( 'POST', '~/getUpdates$~', [ [ 'ok' => true, 'result' => [ $this->update( 50 ) ] ] ] );
		$r = Updates::poll( 'telegram' );
		$this->assertSame( 0, $r['updates'] );
		$this->assertCount( 1, HttpMock::requests(), 'the webhook is deleted only once' );
		$this->assertTrue( Lock::acquire( 'bot_poll_telegram' ), 'the lock was released' );
		Lock::release( 'bot_poll_telegram' );
	}

	public function test_polling_bale_sends_no_allowed_updates(): void {
		HttpMock::expect( 'POST', '~/deleteWebhook$~', [ 'ok' => true, 'result' => true ] );
		HttpMock::expect( 'POST', '~/getUpdates$~', [ 'ok' => true, 'result' => [] ] );
		Updates::poll( 'bale' );
		$this->assertEquals( [ 'limit' => 100 ], HttpMock::last()['json'] );
	}

	public function test_polling_lock(): void {
		Lock::acquire( 'bot_poll_telegram', 120 );
		$this->assertFalse( Updates::poll( 'telegram' )['ok'] );
		HttpMock::assertNoRequests();
		Lock::release( 'bot_poll_telegram' );
		HttpMock::expect( 'POST', '~~', new \WP_Error( 'http', 'down' ) );
		$this->assertFalse( Updates::poll( 'telegram' )['ok'] );
		$this->assertTrue( Lock::acquire( 'bot_poll_telegram', 120 ), 'released after an error' );
		Lock::release( 'bot_poll_telegram' );
	}

	public function test_polling_conflict_deletes_the_webhook_next_time(): void {
		State::set( 'bot.telegram.bot_id', '123456' );
		State::set( 'bot.telegram.webhook_cleared', true );
		HttpMock::expect( 'POST', '~/getUpdates$~', [ 'ok' => false, 'error_code' => 409, 'description' => 'Conflict' ], 409 );
		$this->assertFalse( Updates::poll( 'telegram' )['ok'] );
		$this->assertFalse( State::get( 'bot.telegram.webhook_cleared' ) );
	}

	public function test_poll_does_not_break_a_webhook(): void {
		$this->webhook();
		HttpMock::reset();
		$r = Updates::poll( 'telegram' );
		$this->assertTrue( $r['ok'] );
		$this->assertNotSame( '', $r['message'] );
		HttpMock::assertNoRequests();
	}

	public function test_poll_all_respects_the_mode(): void {
		Updates::poll_all();
		HttpMock::assertNoRequests();
		State::set( 'bot.bale.mode_effective', 'polling' );
		HttpMock::expect( 'POST', '~tapi\.bale\.ai~', [ 'ok' => true, 'result' => [] ] );
		Updates::poll_all();
		$this->assertCount( 2, HttpMock::requests() );
	}

	public function test_can_webhook(): void {
		$cases = [
			'http://example.com/wp-json/'        => [ false, false ],
			'https://localhost/wp-json/'         => [ false, false ],
			'https://site.local/wp-json/'        => [ false, false ],
			'https://site.test/wp-json/'         => [ false, false ],
			'https://192.168.1.4/wp-json/'       => [ false, false ],
			'https://127.0.0.1/wp-json/'         => [ false, false ],
			'https://example.com/wp-json/'       => [ true, true ],
			'https://example.com:8443/wp-json/'  => [ true, false ],
			'https://example.com:88/wp-json/'    => [ true, true ],
			'https://example.com:9000/wp-json/'  => [ false, false ],
			'https://example.com/?rest_route=/'  => [ true, true ],
		];
		$base = '';
		$this->filter( 'rest_url', static function ( $url, $path ) use ( &$base ) { return $base . ltrim( $path, '/' ); }, 10, 2 );
		foreach ( $cases as $base => list( $telegram, $bale ) ) {
			list( $ok, $reason ) = Updates::can_webhook( 'telegram' );
			$this->assertSame( $telegram, $ok, $base );
			$this->assertNotSame( '', $reason );
			$this->assertSame( $bale, Updates::can_webhook( 'bale' )[0], $base );
		}
	}

	public function test_auto_chooses_the_webhook_on_a_public_site(): void {
		$this->public_site();
		$this->me( [ 'setWebhook' ] );
		$this->assertSame( '', Updates::connect( 'telegram', 'auto' ) );
		$this->assertSame( 'webhook', Updates::effective_mode( 'telegram' ) );
		$q = HttpMock::requests( '~/setWebhook$~' )[0]['json'];
		$this->assertSame( Urls::bot_webhook( 'telegram' ), $q['url'] );
		$this->assertSame( State::get( 'bot.telegram.header_secret' ), $q['secret_token'] );
		$this->assertSame( [ 'message', 'channel_post', 'my_chat_member', 'callback_query' ], $q['allowed_updates'] );
		$this->assertFalse( wp_next_scheduled( Cron::POLL ) );
		$this->assertSame( 'demo_bot', State::get( 'bot.telegram.me.username' ) );
		$commands = HttpMock::requests( '~/setMyCommands$~' );
		$this->assertSame( [ 'start', 'id', 'help', 'forms', 'last', 'stats', 'app' ], array_column( $commands[0]['json']['commands'], 'command' ) );
	}

	public function test_auto_chooses_polling_on_a_local_site(): void {
		$this->public_site( 'http://localhost/wp-json/' );
		$this->me( [ 'deleteWebhook' ] );
		$this->assertSame( '', Updates::connect( 'telegram', 'auto' ) );
		$this->assertSame( 'polling', Updates::effective_mode( 'telegram' ) );
		$this->assertTrue( (bool) wp_next_scheduled( Cron::POLL ) );
		$this->assertSame( '', Updates::connect( 'telegram', 'off' ) );
		$this->assertSame( 'off', Updates::effective_mode( 'telegram' ) );
		$this->assertFalse( wp_next_scheduled( Cron::POLL ) );
	}

	public function test_auto_falls_back_when_the_webhook_is_refused(): void {
		$this->public_site();
		$this->me( [ 'deleteWebhook' ] );
		HttpMock::expect( 'POST', '~/setWebhook$~', [ 'ok' => false, 'error_code' => 400, 'description' => 'bad webhook: IP address is reserved' ], 400 );
		State::set( 'bot.telegram.webhook_secret', str_repeat( 'a', 32 ) );
		$this->assertSame( '', Updates::connect( 'telegram', 'auto' ) );
		$this->assertSame( 'polling', Updates::effective_mode( 'telegram' ) );
		$this->assertContains( 'reserved', Updates::status( 'telegram', false )['reason'] );
		$this->assertSame( str_repeat( 'a', 32 ), State::get( 'bot.telegram.webhook_secret' ), 'old secrets stay' );
	}

	public function test_explicit_webhook_errors(): void {
		$this->public_site( 'http://localhost/wp-json/' );
		$this->me();
		$this->assertNotSame( '', Updates::connect( 'telegram', 'webhook' ) );
		$this->assertSame( 'off', Updates::effective_mode( 'telegram' ) );
	}

	public function test_bad_token(): void {
		HttpMock::expect( 'POST', '~/getMe$~', [ 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ], 401 );
		$this->assertNotSame( '', Updates::connect( 'telegram', 'auto' ) );
		$this->assertTrue( (int) State::get( 'bot.telegram.token_error' ) > 0 );
	}

	public function test_reconnect_rotates_the_secrets(): void {
		list( $secret, $header ) = $this->webhook();
		$this->assertSame( '', Updates::connect( 'telegram', 'webhook' ) );
		$this->assertNotSame( $secret, State::get( 'bot.telegram.webhook_secret' ) );
		$this->assertNotSame( $header, State::get( 'bot.telegram.header_secret' ) );
	}

	public function test_another_bot_starts_from_scratch(): void {
		$this->webhook();
		Updates::accept( 'telegram', 500 );
		State::set( 'bot.telegram.offset', 501 );
		Settings::set_secret( 'channels.telegram.token', '99:OTHER' );
		Updates::connect( 'telegram', 'webhook' );
		$this->assertSame( '99', State::get( 'bot.telegram.bot_id' ) );
		$this->assertNull( State::get( 'bot.telegram.offset' ) );
		$this->assertTrue( Updates::accept( 'telegram', 1 ) );
	}

	public function test_status(): void {
		list( $secret ) = $this->webhook();
		HttpMock::expect( 'POST', '~/getWebhookInfo$~', [ 'ok' => true, 'result' => [ 'url' => Urls::bot_webhook( 'telegram' ), 'pending_update_count' => 3, 'last_error_message' => '<b>Wrong</b> response', 'last_error_date' => 1767261600 ] ] );
		$s = Updates::status( 'telegram' );
		$this->assertTrue( $s['configured'] );
		$this->assertSame( [ 'id' => '123456', 'username' => 'demo_bot', 'name' => 'Demo' ], $s['me'] );
		$this->assertSame( 'webhook', $s['mode'] );
		$this->assertTrue( $s['webhook']['set'] );
		$this->assertSame( 3, $s['webhook']['pending'] );
		$this->assertSame( 'Wrong response', $s['webhook']['last_error'] );
		$this->assertSame( '2026-01-01T10:00:00Z', $s['webhook']['last_error_date'] );
		$this->assertNotContains( $secret, wp_json_encode( $s ) );
		$this->assertTrue( $s['can_webhook'] );
	}

	public function test_removed_webhook_is_set_again(): void {
		$this->webhook();
		Settings::set( 'channels.telegram.enabled', true );
		HttpMock::expect( 'POST', '~/getWebhookInfo$~', [ 'ok' => true, 'result' => [ 'url' => '' ] ] );
		$before = count( HttpMock::requests( '~/setWebhook$~' ) );
		Updates::check_webhooks();
		$this->assertSame( $before + 1, count( HttpMock::requests( '~/setWebhook$~' ) ) );
	}

	public function test_working_webhook_is_left_alone(): void {
		$this->webhook();
		Settings::set( 'channels.telegram.enabled', true );
		HttpMock::expect( 'POST', '~/getWebhookInfo$~', [ 'ok' => true, 'result' => [ 'url' => Urls::bot_webhook( 'telegram' ) ] ] );
		$before = count( HttpMock::requests() );
		Updates::check_webhooks();
		$this->assertSame( $before + 1, count( HttpMock::requests() ) );
		$this->assertTrue( BotClient::for_platform( 'telegram' )->has_token() );
	}

	public function test_commands_can_be_switched_off(): void {
		$this->webhook();
		Settings::set( 'channels.telegram.commands', false );
		$before = count( HttpMock::requests( '~/setMyCommands$~' ) );
		Updates::connect( 'telegram', 'webhook' );
		$this->assertSame( $before, count( HttpMock::requests( '~/setMyCommands$~' ) ) );
		$this->assertCount( 1, HttpMock::requests( '~/deleteMyCommands$~' ) );
	}

	public function test_polling_is_scheduled_again_on_init_after_a_reactivation(): void {
		$this->assertSame( 20, has_action( 'init', [ Updates::class, 'sync_schedule' ] ) );
		State::set( 'bot.telegram.mode_effective', 'polling' );
		Cron::clear_all(); // What deactivation does.
		$this->assertFalse( (bool) wp_next_scheduled( Cron::POLL ) );
		Updates::sync_schedule();
		$this->assertTrue( (bool) wp_next_scheduled( Cron::POLL ) );
		State::delete( 'bot.telegram.mode_effective' );
		Updates::sync_schedule();
		$this->assertFalse( (bool) wp_next_scheduled( Cron::POLL ) );
		Cron::ensure_events();
	}
}
