<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\BotModule;
use AmirhpCom\FormsNotifier\Channels\Bot\ChatDiscovery;
use AmirhpCom\FormsNotifier\Channels\Bot\Linking;
use AmirhpCom\FormsNotifier\Channels\Bot\WorkerGuide;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class RoutesTest extends TestCase {

	private static $admin = 0;
	private static $subscriber = 0;

	public static function setUpClass(): void {
		self::$admin      = (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
		self::$subscriber = (int) wp_insert_user( [ 'user_login' => 'fnfe_bot_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
	}

	public static function tearDownClass(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( self::$subscriber );
		wp_set_current_user( 0 );
	}

	public function setUp(): void {
		Db::reset();
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		wp_set_current_user( self::$admin );
	}

	public function tearDown(): void {
		wp_clear_scheduled_hook( Cron::POLL );
	}

	private function call( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/fnfe/v1/bots/telegram' . $path );
		if ( in_array( $method, [ 'GET', 'DELETE' ], true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( (object) $params ) );
		}
		return rest_do_request( $request );
	}

	public function test_permissions(): void {
		ChatDiscovery::handle( 'telegram', Fixtures::update_start() );
		$id     = ChatRepository::find_chat( 'telegram', '111111' )['id'];
		$routes = [ [ 'GET', '/status' ], [ 'POST', '/connect' ], [ 'POST', '/poll' ], [ 'GET', '/chats' ], [ 'PATCH', "/chats/$id" ], [ 'DELETE', "/chats/$id" ], [ 'POST', '/link' ], [ 'GET', '/admins' ], [ 'DELETE', '/admins/1' ] ];
		foreach ( [ 0 => 401, self::$subscriber => 403 ] as $user => $status ) {
			wp_set_current_user( $user );
			foreach ( $routes as list( $method, $path ) ) {
				$this->assertSame( $status, $this->call( $method, $path )->get_status(), "$method $path" );
			}
		}
		HttpMock::assertNoRequests();
		$this->filter( 'fnfe_capability', static function ( $cap, $fnfe ) { return $fnfe === 'fnfe_manage_forms' ? 'read' : $cap; }, 10, 2 );
		$this->assertSame( 200, $this->call( 'GET', '/chats' )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/admins' )->get_status() );
	}

	public function test_status_shape(): void {
		HttpMock::expect( 'POST', '~/getMe$~', [ 'ok' => true, 'result' => [ 'id' => 123456, 'first_name' => 'Demo', 'username' => 'demo_bot' ] ] );
		$d = $this->call( 'GET', '/status' )->get_data();
		$this->assertKeys( [ 'configured', 'me', 'mode', 'webhook', 'polling', 'can_webhook', 'reason', 'miniapp_url', 'error' ], $d );
		$this->assertKeys( [ 'id', 'username', 'name' ], $d['me'] );
		$this->assertKeys( [ 'url', 'set', 'pending', 'last_error', 'last_error_date' ], $d['webhook'] );
		$this->assertKeys( [ 'last_run', 'offset' ], $d['polling'] );
		$this->assertSame( 'off', $d['mode'] );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', '/fnfe/v1/bots/other/status' ) )->get_status() );
	}

	public function test_connect_with_unsaved_values(): void {
		HttpMock::expect( 'POST', '~^https://proxy\.example\.com/bot99:NEW/~', [ 'ok' => true, 'result' => [ 'id' => 99, 'first_name' => 'New', 'username' => 'new_bot' ] ] );
		$r = $this->call( 'POST', '/connect', [ 'mode' => 'polling', 'values' => [ 'channels.telegram.api_base' => 'https://proxy.example.com' ], 'secrets' => [ 'channels.telegram.token' => '99:NEW' ] ] );
		$this->assertSame( 200, $r->get_status() );
		$d = $r->get_data();
		$this->assertSame( '', $d['error'] );
		$this->assertSame( 'polling', $d['mode'] );
		$this->assertSame( 'new_bot', $d['me']['username'] );
		Settings::clear_overlay();
		$this->assertSame( '99:NEW', Settings::secret( 'channels.telegram.token' ) );
		$this->assertSame( 'https://proxy.example.com', Settings::str( 'channels.telegram.api_base' ) );
		$this->assertSame( 'polling', Settings::str( 'channels.telegram.update_mode' ) );
		$this->assertNotContains( '99:NEW', wp_json_encode( $d ) );
	}

	public function test_failed_connect_saves_nothing(): void {
		HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ], 401 );
		$d = $this->call( 'POST', '/connect', [ 'mode' => 'polling', 'secrets' => [ 'channels.telegram.token' => '99:BAD' ] ] )->get_data();
		$this->assertNotSame( '', $d['error'] );
		$this->assertSame( 'off', $d['mode'] );
		$this->assertSame( Fixtures::TOKEN, Settings::secret( 'channels.telegram.token' ) );
		$this->assertSame( 400, $this->call( 'POST', '/connect', [ 'mode' => 'magic' ] )->get_status() );
	}

	public function test_poll(): void {
		HttpMock::expect( 'POST', '~/deleteWebhook$~', [ 'ok' => true, 'result' => true ] );
		HttpMock::expect( 'POST', '~/getUpdates$~', [ 'ok' => true, 'result' => [ Fixtures::update_topic( 5 ) ] ] );
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
		$d = $this->call( 'POST', '/poll' )->get_data();
		$this->assertTrue( $d['ok'] );
		$this->assertSame( 1, $d['updates'] );
		$this->assertSame( 2, $d['chats'] );
	}

	public function test_chats(): void {
		ChatDiscovery::handle( 'telegram', Fixtures::update_start() );
		ChatDiscovery::handle( 'telegram', Fixtures::update_topic() );
		ChatDiscovery::handle( 'bale', Fixtures::update_start() );
		$d = $this->call( 'GET', '/chats' )->get_data();
		$this->assertSame( 2, $d['total'] );
		$this->assertKeys( [ 'id', 'chat_id', 'thread_id', 'type', 'title', 'username', 'topic_name', 'is_forum', 'status', 'bot_role', 'address', 'last_seen' ], $d['items'][0] );
		$this->assertSame( 3, $this->call( 'GET', '/chats', [ 'with_topics' => true ] )->get_data()['total'] );
		$this->assertSame( 1, $this->call( 'GET', '/chats', [ 'type' => 'private' ] )->get_data()['total'] );
		$this->assertSame( 400, $this->call( 'GET', '/chats', [ 'type' => 'x' ] )->get_status() );

		$topic = ChatRepository::find_chat( 'telegram', Fixtures::CHAT, 45 );
		$r     = $this->call( 'PATCH', '/chats/' . $topic['id'], [ 'topic_name' => 'My <b>leads</b>' ] );
		$this->assertSame( 'My leads', $r->get_data()['chat']['topic_name'] );
		$this->assertSame( Fixtures::CHAT . '/45', $r->get_data()['chat']['address'] );
		ChatDiscovery::handle( 'telegram', Fixtures::update_topic( 3 ) );
		$this->assertSame( 'My leads', ChatDiscovery::client_row( ChatRepository::find( $topic['id'] ) )['topic_name'], 'a rename survives updates' );
		$this->assertSame( 'Leads', $this->call( 'PATCH', '/chats/' . $topic['id'], [ 'topic_name' => '' ] )->get_data()['chat']['topic_name'] );

		$other = ChatRepository::find_chat( 'bale', '111111' );
		$this->assertSame( 404, $this->call( 'PATCH', '/chats/' . $other['id'], [ 'title' => 'x' ] )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/chats/' . $other['id'] )->get_status() );
		$forum = ChatRepository::find_chat( 'telegram', Fixtures::CHAT );
		$this->assertTrue( $this->call( 'DELETE', '/chats/' . $forum['id'] )->get_data()['ok'] );
		$this->assertNull( ChatRepository::find( $topic['id'] ), 'topics go with their forum' );
	}

	public function test_link_and_admins(): void {
		State::set( 'bot.telegram.me', [ 'id' => '123456', 'username' => 'demo_bot' ] );
		$d = $this->call( 'POST', '/link' )->get_data();
		$this->assertKeys( [ 'url', 'code', 'expires' ], $d );
		$this->assertSame( 404, $this->call( 'POST', '/link', [ 'user_id' => 99999999 ] )->get_status() );
		$this->assertNotNull( Linking::consume( 'telegram', $d['code'], [ 'id' => 111111, 'first_name' => 'Sara' ] ) );
		$items = $this->call( 'GET', '/admins' )->get_data()['items'];
		$this->assertKeys( [ 'uid', 'name', 'username', 'wp_user', 'wp_name', 'perms' ], $items[0] );
		$this->assertSame( self::$admin, $items[0]['wp_user'] );
		$this->assertTrue( $this->call( 'DELETE', '/admins/111111' )->get_data()['ok'] );
		$this->assertSame( 404, $this->call( 'DELETE', '/admins/111111' )->get_status() );
	}

	public function test_admins_through_settings(): void {
		$version = (int) State::get( 'token_version', 0 );
		$request = new \WP_REST_Request( 'POST', '/fnfe/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'values' => [ 'channels.telegram.admins' => [ [ 'uid' => '55', 'name' => 'Typed', 'perms' => [ 'view' ] ] ] ] ] ) );
		$this->assertTrue( rest_do_request( $request )->get_data()['ok'] );
		$this->assertTrue( Linking::can( 'telegram', '55' ) );
		$this->assertFalse( Linking::can( 'telegram', '55', 'act' ) );
		$this->assertTrue( (int) State::get( 'token_version', 0 ) > $version );
	}

	public function test_channel_actions(): void {
		HttpMock::expect( 'POST', '~/getMe$~', [ 'ok' => true, 'result' => [ 'id' => 1, 'first_name' => 'Demo', 'username' => 'demo_bot' ] ] );
		$actions = Registry::channel( 'telegram' )->actions();
		$r       = $actions['test_connection']( [] );
		$this->assertTrue( $r['ok'] );
		$this->assertContains( '@demo_bot', $r['message'] );
		$this->assertContains( 'api.telegram.org', $r['html'] );
		$this->assertNotContains( 'TEST-TOKEN', wp_json_encode( $r ) );
		$this->assertTrue( $actions['status']( [] )['ok'] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', new \WP_Error( 'http', 'timeout' ) );
		$this->assertFalse( $actions['test_connection']( [] )['ok'] );
		$this->assertFalse( $actions['connect']( [ 'mode' => 'polling' ] )['ok'] );
		$this->assertFalse( $actions['find_chats']( [] )['ok'] );
	}

	public function test_saving_outside_the_panel_contacts_nobody(): void {
		Settings::set_secret( 'channels.telegram.token', '5:OTHER' );
		Settings::set_many( [ 'channels.telegram.update_mode' => 'polling' ] );
		$this->assertFalse( has_action( 'shutdown', [ BotModule::class, 'reconnect' ] ) );
	}

	public function test_guide_and_topics(): void {
		$topics = apply_filters( 'fnfe_help_topics', [] );
		foreach ( [ 'bots-connect', 'bots-chats', 'bots-commands', 'bots-worker' ] as $id ) {
			$this->assertKeys( [ 'title', 'group', 'body', 'steps' ], $topics[ $id ] );
		}
		foreach ( [ 'telegram', 'bale' ] as $platform ) {
			$steps = WorkerGuide::steps( $platform );
			$all   = wp_json_encode( $steps, JSON_UNESCAPED_SLASHES );
			foreach ( $steps as $step ) {
				$this->assertKeys( [ 'title', 'body', 'code', 'lang', 'copy' ], $step );
				$this->assertSame( wp_kses( $step['body'], [ 'a' => [ 'href' => true ], 'code' => [], 'strong' => [], 'em' => [], 'br' => [] ] ), $step['body'] );
			}
			$this->assertContains( 'export default', $all );
			$this->assertContains( 'workers.dev', $all );
			$this->assertNotContains( 'cloudflare.com', $all );
			$this->assertSame( $platform === 'bale', strpos( $all, 'UPSTREAM = https://tapi.bale.ai' ) !== false );
		}
	}
}
