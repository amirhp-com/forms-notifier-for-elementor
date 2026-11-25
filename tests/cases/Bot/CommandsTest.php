<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\Commands;
use AmirhpCom\FormsNotifier\Channels\Bot\Linking;
use AmirhpCom\FormsNotifier\Channels\Bot\UpdateHandler;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class CommandsTest extends TestCase {

	private static $user = 0;

	public static function setUpClass(): void {
		self::$user = (int) wp_insert_user( [ 'user_login' => 'fnfe_bot_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'display_name' => 'Nima & Co', 'role' => 'editor', 'locale' => 'en_US' ] );
	}

	public static function tearDownClass(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( self::$user );
	}

	public function setUp(): void {
		Db::reset();
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set_secret( 'channels.bale.token', Fixtures::TOKEN );
		State::set( 'bot.telegram.me', [ 'id' => '123456', 'username' => 'demo_bot', 'name' => 'Demo' ] );
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
	}

	private function say( string $text, array $chat = [], array $from = [], array $more = [], string $platform = 'telegram' ): void {
		UpdateHandler::handle( $platform, [ 'update_id' => 1, 'message' => $more + [ 'message_id' => 1, 'text' => $text, 'chat' => $chat + [ 'id' => 111111, 'type' => 'private' ], 'from' => $from + [ 'id' => 111111, 'first_name' => 'Sara', 'language_code' => 'en' ] ] ] );
	}

	private function replies(): array {
		return array_column( HttpMock::requests( '~/sendMessage$~' ), 'json' );
	}

	private function admin( array $perms = [ 'view', 'act' ], int $wp_user = 0 ): void {
		Settings::set( 'channels.telegram.admins', [ [ 'id' => 'e_1', 'uid' => '111111', 'name' => 'Sara', 'username' => '', 'wp_user' => $wp_user, 'perms' => $perms ] ] );
	}

	private function group(): array {
		return [ 'id' => (int) Fixtures::CHAT, 'type' => 'supergroup', 'title' => 'Sales <b>' ];
	}

	private function has_private_site(): void {
		$this->filter( 'home_url', static function ( $url, $path ) { return 'https://example.com/' . ltrim( (string) $path, '/' ); }, 10, 2 );
	}

	public function test_start(): void {
		$this->say( '/start' );
		$reply = $this->replies()[0];
		$this->assertSame( '111111', $reply['chat_id'] );
		$this->assertContains( '111111', $reply['text'] );
		$this->assertSame( 'HTML', $reply['parse_mode'] );
		$this->say( '/start@demo_bot' );
		$this->say( '/START hello' );
		$this->assertCount( 3, $this->replies() );
	}

	public function test_other_bots_and_plain_text_are_ignored(): void {
		$this->say( '/start@another_bot' );
		$this->say( 'hello /start' );
		$this->say( '/start', [], [ 'is_bot' => true ] );
		$this->say( '/' );
		HttpMock::assertNoRequests();
	}

	public function test_id(): void {
		$this->say( '/id', $this->group(), [], [ 'message_thread_id' => 45, 'is_topic_message' => true ] );
		$reply = $this->replies()[0];
		$this->assertSame( 45, $reply['message_thread_id'] );
		$this->assertContains( Fixtures::CHAT . '/45', $reply['text'] );
		$this->assertContains( 'Sales', $reply['text'] );
		$this->say( '/id', [ 'id' => (int) Fixtures::CHAT, 'type' => 'supergroup' ], [ 'id' => 1087968824, 'is_bot' => true ] );
		$this->assertCount( 2, $this->replies(), 'anonymous admins may ask' );
	}

	public function test_id_in_a_channel(): void {
		UpdateHandler::handle( 'telegram', [ 'update_id' => 1, 'channel_post' => [ 'message_id' => 1, 'chat' => [ 'id' => -1007, 'type' => 'channel', 'title' => 'N' ], 'text' => '/id' ] ] );
		UpdateHandler::handle( 'telegram', [ 'update_id' => 2, 'channel_post' => [ 'message_id' => 1, 'chat' => [ 'id' => -1007, 'type' => 'channel', 'title' => 'N' ], 'text' => '/help' ] ] );
		$this->assertCount( 1, $this->replies() );
	}

	public function test_help_depends_on_the_role(): void {
		$this->say( '/help' );
		$this->assertNotContains( '/last', $this->replies()[0]['text'] );
		$this->admin();
		$this->say( '/help' );
		$this->assertContains( '/last', $this->replies()[1]['text'] );
	}

	public function test_strangers_are_refused_once_a_minute(): void {
		foreach ( [ '/forms', '/last', '/stats', '/app' ] as $command ) {
			$this->say( $command );
		}
		$this->assertCount( 1, $this->replies() );
	}

	public function test_flood_limit(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			$this->say( '/id' );
		}
		$this->assertCount( 20, $this->replies() );
	}

	public function test_switched_off(): void {
		Settings::set( 'channels.telegram.commands', false );
		$this->say( '/id' );
		$this->say( '/help' );
		HttpMock::assertNoRequests();
		$this->say( '/start' );
		$this->assertCount( 1, $this->replies() );
	}

	public function test_admin_commands_in_groups(): void {
		$this->admin();
		$this->say( '/stats', $this->group() );
		$this->assertSame( '111111', $this->replies()[0]['chat_id'], 'hint in private' );
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( Fixtures::CHAT . '/45' ) ] );
		$this->say( '/stats', $this->group() );
		$this->assertSame( Fixtures::CHAT, $this->replies()[1]['chat_id'] );
	}

	public function test_last(): void {
		$this->admin();
		$this->say( '/last' );
		$this->assertCount( 1, $this->replies() );
		for ( $i = 0; $i < 12; $i++ ) {
			SubmissionRepository::insert( Fixtures::submission( [ 'uuid' => md5( 'u' . $i ), 'form_name' => 'Contact <x>' ] ) );
		}
		SubmissionRepository::insert( Fixtures::submission( [ 'uuid' => md5( 'spam' ), 'form_name' => 'Spam form', 'status' => 'spam' ] ) );
		$counts = [ '' => 5, ' 3' => 3, ' 99' => 10, ' ۲' => 2, ' abc' => 5 ];
		foreach ( $counts as $arg => $expected ) {
			$this->say( '/last' . $arg );
			$replies = $this->replies();
			$text    = end( $replies )['text'];
			$this->assertSame( $expected, substr_count( $text, 'Contact &lt;x&gt;' ), $arg );
			$this->assertNotContains( 'Spam form', $text );
			$this->assertFalse( isset( end( $replies )['reply_markup'] ), 'a local admin address is not sent as a button' );
		}
	}

	public function test_last_buttons_open_the_app(): void {
		$this->admin();
		$this->has_private_site();
		Settings::set( 'miniapp.enabled', true );
		$id = SubmissionRepository::insert( Fixtures::submission( [ 'uuid' => md5( 'x' ) ] ) );
		$this->say( '/last' );
		$button = $this->replies()[0]['reply_markup']['inline_keyboard'][0][0];
		$this->assertContains( 'startapp=sub_' . $id, $button['web_app']['url'] );
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( Fixtures::CHAT ) ] );
		$this->say( '/last', $this->group() );
		$this->assertSame( 'https://t.me/demo_bot?startapp=sub_' . $id, $this->replies()[1]['reply_markup']['inline_keyboard'][0][0]['url'] );
	}

	public function test_forms_stats_app(): void {
		$this->admin();
		$this->has_private_site();
		$this->say( '/forms' );
		FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact', 'status' => 'active' ] );
		$this->say( '/forms' );
		$this->assertContains( 'Contact', $this->replies()[1]['text'] );
		$this->say( '/stats' );
		$this->assertContains( '<b>', $this->replies()[2]['text'] );
		$this->say( '/app' );
		$this->assertFalse( isset( $this->replies()[3]['reply_markup'] ) );
		Settings::set( 'miniapp.enabled', true );
		$this->say( '/app' );
		$this->assertTrue( isset( $this->replies()[4]['reply_markup']['inline_keyboard'][0][0]['web_app'] ) );
	}

	public function test_bale_reply(): void {
		$this->say( '/id', [], [], [], 'bale' );
		$reply = HttpMock::last();
		$this->assertMatches( '~tapi\.bale\.ai~', $reply['url'] );
		$this->assertKeys( [ 'chat_id', 'text' ], $reply['json'] );
		$this->assertNotContains( '<code>', $reply['json']['text'] );
	}

	public function test_custom_and_unknown_commands(): void {
		$seen = [];
		$this->filter( 'fnfe_bot_command', static function ( $command ) use ( &$seen ) { $seen[] = $command; } );
		$this->say( '/unknown x' );
		$this->assertSame( [ 'unknown' ], $seen );
		HttpMock::assertNoRequests();
		$this->filter( 'fnfe_bot_commands', static function ( $commands ) { $commands['echo'] = [ 'desc' => 'Echo', 'admin' => false, 'handler' => static function ( $args ) { return '<b>' . $args . '</b>'; } ]; return $commands; } );
		$this->say( '/echo hi there' );
		$this->assertContains( 'hi there', $this->replies()[0]['text'] );
	}

	public function test_language(): void {
		$site = \AmirhpCom\FormsNotifier\Core\I18n::default_lang();
		$this->assertSame( 'en', Commands::language( 'telegram', [ 'id' => 5, 'language_code' => 'en' ] ) );
		$this->assertSame( 'en', Commands::language( 'telegram', [ 'id' => 5, 'language_code' => 'en-US' ] ) );
		$this->assertSame( $site, Commands::language( 'telegram', [ 'id' => 5, 'language_code' => 'xx' ] ) );
		$this->assertSame( $site, Commands::language( 'telegram', [ 'id' => 5, 'language_code' => '../etc' ] ) );
		$this->assertSame( $site, Commands::language( 'telegram', [ 'id' => 5 ] ) );
		$this->admin( [ 'view' ], self::$user );
		$this->assertSame( 'en', Commands::language( 'telegram', [ 'id' => 111111, 'language_code' => 'fa' ] ), 'the linked user decides' );
		$locale = Commands::in_language( 'telegram', [ 'id' => 111111 ], 'determine_locale' );
		$this->assertSame( 'en_US', $locale );
	}

	public function test_linking(): void {
		$link = Linking::create( 'telegram', self::$user );
		$this->assertMatches( '/^link_[a-z0-9]{16}$/', $link['code'] );
		$this->assertSame( 'https://t.me/demo_bot?start=' . $link['code'], $link['url'] );

		// Wrong chat type, wrong platform, unknown code.
		$this->say( '/start ' . $link['code'], $this->group() );
		$this->say( '/start ' . $link['code'], [], [], [], 'bale' );
		$this->say( '/start link_aaaaaaaaaaaaaaaa' );
		$this->assertSame( [], Linking::admins( 'telegram' ) );
		$this->assertSame( [], Linking::admins( 'bale' ) );

		$version = (int) State::get( 'token_version', 0 );
		$this->say( '/start ' . $link['code'], [], [ 'username' => 'sara_example', 'last_name' => '<b>X</b>' ] );
		$admin = Linking::admin( 'telegram', '111111' );
		$this->assertSame( [ 'Sara X', 'sara_example', self::$user, [ 'view', 'act' ] ], [ $admin['name'], $admin['username'], $admin['wp_user'], $admin['perms'] ] );
		$this->assertSame( [ 'telegram' => '111111' ], get_user_meta( self::$user, 'fnfe_bot_ids', true ) );
		$replies = $this->replies();
		$this->assertContains( 'Nima &amp; Co', end( $replies )['text'] );

		// The code works once.
		$this->say( '/start ' . $link['code'], [ 'id' => 222 ], [ 'id' => 222 ] );
		$this->assertCount( 1, Linking::admins( 'telegram' ) );

		// Linking again updates the entry.
		$again = Linking::create( 'telegram', self::$user );
		$this->say( '/start ' . $again['code'] );
		$this->assertCount( 1, Linking::admins( 'telegram' ) );
		$this->assertSame( $admin['id'], Linking::admin( 'telegram', '111111' )['id'] );

		$this->assertTrue( Linking::remove( 'telegram', '111111' ) );
		$this->assertFalse( Linking::remove( 'telegram', '111111' ) );
		$this->assertSame( '', get_user_meta( self::$user, 'fnfe_bot_ids', true ) );
		$this->assertTrue( (int) State::get( 'token_version', 0 ) > $version );
	}

	public function test_linking_expires(): void {
		$now = 1800000000;
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; } );
		$link = Linking::create( 'telegram', self::$user );
		$now += Linking::TTL + 1;
		$this->say( '/start ' . $link['code'] );
		$this->assertSame( [], Linking::admins( 'telegram' ) );
	}

	public function test_link_guessing_is_limited(): void {
		$link = Linking::create( 'telegram', self::$user );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->say( '/start link_' . str_pad( (string) $i, 16, 'a' ) );
		}
		$this->say( '/start ' . $link['code'] );
		$this->assertSame( [], Linking::admins( 'telegram' ) );
	}

	public function test_deleted_user_loses_access(): void {
		$this->admin( [ 'view', 'act' ], 987654 );
		do_action( 'deleted_user', 987654, null, null );
		$this->assertSame( [], Linking::admins( 'telegram' ) );
	}

	public function test_admin_entries_are_cleaned(): void {
		Settings::set( 'channels.telegram.admins', [ [ 'uid' => '۱۲۳' ], [ 'uid' => 'abc' ], [ 'uid' => '-5' ], 'x', [ 'uid' => 55, 'perms' => [ 'act', 'root' ] ] ] );
		$this->assertSame( [ '123', '55' ], array_map( 'strval', array_keys( Linking::admins( 'telegram' ) ) ) );
		$this->assertTrue( Linking::can( 'telegram', '55', 'act' ) );
		$this->assertFalse( Linking::can( 'telegram', '123', 'act' ) );
		$this->assertFalse( Linking::can( 'telegram', '', 'view' ) );
	}
}
