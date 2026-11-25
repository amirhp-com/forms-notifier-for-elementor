<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\Keyboard;
use AmirhpCom\FormsNotifier\Channels\Bot\UpdateHandler;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class CallbacksTest extends TestCase {

	private $id = 0;
	private $changes = [];

	public function setUp(): void {
		Db::reset();
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set( 'channels.telegram.admins', [ [ 'uid' => '111111', 'name' => 'Sara', 'perms' => [ 'view', 'act' ] ], [ 'uid' => '222', 'name' => 'Viewer', 'perms' => [ 'view' ] ] ] );
		HttpMock::expect( 'POST', '~/(answerCallbackQuery|editMessageReplyMarkup)$~', [ 'ok' => true, 'result' => true ] );
		$this->id      = SubmissionRepository::insert( Fixtures::submission( [ 'uuid' => md5( 'c' ) ] ) );
		$this->changes = [];
		$this->filter( 'fnfe_submission_status_changed', function ( ...$args ) { $this->changes[] = $args; }, 10, 4 );
	}

	private function press( string $data, int $from = 111111, ?array $rows = null, array $chat = [] ): void {
		UpdateHandler::handle( 'telegram', [ 'update_id' => 1, 'callback_query' => [
			'id'      => '4382bfdwdsb323b2d9',
			'from'    => [ 'id' => $from, 'first_name' => 'Sara', 'language_code' => 'en' ],
			'data'    => $data,
			'message' => [ 'message_id' => 70, 'chat' => $chat + [ 'id' => (int) Fixtures::CHAT, 'type' => 'supergroup' ], 'reply_markup' => [ 'inline_keyboard' => $rows ?? $this->rows() ] ],
		] ] );
	}

	private function rows(): array {
		return [ [ [ 'text' => 'Read it', 'callback_data' => $this->data( 'read' ) ], [ 'text' => 'Done', 'callback_data' => $this->data( 'done' ) ] ], [ [ 'text' => 'Open', 'url' => 'https://example.com/' ] ] ];
	}

	private function data( string $action ): string {
		return Keyboard::callback_data( $action, $this->id );
	}

	private function answers(): array {
		return array_column( HttpMock::requests( '~/answerCallbackQuery$~' ), 'json' );
	}

	private function status(): string {
		return SubmissionRepository::find( $this->id )->status;
	}

	public function test_status_change(): void {
		$this->press( $this->data( 'done' ) );
		$this->assertSame( 'done', $this->status() );
		$this->assertSame( [ [ $this->id, 'done', 'new', 'bot' ] ], $this->changes );
		$this->assertCount( 1, $this->answers() );
		$this->assertSame( '4382bfdwdsb323b2d9', $this->answers()[0]['callback_query_id'] );
		$this->assertNotSame( '', $this->answers()[0]['text'] );
		$edit = HttpMock::requests( '~/editMessageReplyMarkup$~' )[0]['json'];
		$rows = $edit['reply_markup']['inline_keyboard'];
		$this->assertSame( '✓ Done · Sara', $rows[0][1]['text'] );
		$this->assertSame( 'Read it', $rows[0][0]['text'] );
		$this->assertSame( 'https://example.com/', $rows[1][0]['url'] );
		$this->assertSame( $this->data( 'done' ), $rows[0][1]['callback_data'] );

		// Another press moves the mark.
		$this->press( $this->data( 'read' ), 111111, $rows );
		$this->assertSame( 'read', $this->status() );
		$rows = HttpMock::requests( '~/editMessageReplyMarkup$~' )[1]['json']['reply_markup']['inline_keyboard'];
		$this->assertSame( [ '✓ Read it · Sara', 'Done' ], array_column( $rows[0], 'text' ) );

		// The same press again changes nothing.
		$this->press( $this->data( 'read' ), 111111, $rows );
		$this->assertCount( 2, $this->changes );
		$this->assertCount( 2, HttpMock::requests( '~/editMessageReplyMarkup$~' ) );
		$this->assertCount( 3, $this->answers() );
	}

	public function test_unauthorized(): void {
		foreach ( [ 999, 222 ] as $from ) {
			$this->press( $this->data( 'done' ), $from );
		}
		$this->assertSame( 'new', $this->status() );
		$this->assertCount( 2, $this->answers() );
		$this->assertTrue( $this->answers()[0]['show_alert'] );
		$this->assertSame( [], HttpMock::requests( '~/editMessageReplyMarkup$~' ) );
	}

	public function test_chat_access(): void {
		Settings::set( 'channels.telegram.callback_access', 'chat' );
		$this->press( $this->data( 'done' ), 999 );
		$this->assertSame( 'new', $this->status(), 'the chat is no receiver' );
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( Fixtures::CHAT ) ] );
		$this->press( $this->data( 'done' ), 999, null, [ 'id' => -42 ] );
		$this->assertSame( 'new', $this->status() );
		$this->press( $this->data( 'done' ), 999 );
		$this->assertSame( 'done', $this->status() );
		$this->assertCount( 3, $this->answers() );
	}

	public function test_tampered_and_unknown(): void {
		foreach ( [ '', 'x', str_replace( ':done:', ':spam:', $this->data( 'done' ) ), Keyboard::callback_data( 'done', $this->id + 1 ) ] as $data ) {
			$this->press( $data );
		}
		$this->assertSame( 'new', $this->status() );
		$this->assertCount( 4, $this->answers() );
	}

	public function test_custom_action(): void {
		$seen = [];
		$this->filter( 'fnfe_bot_callback', static function ( $action, $id, $query, $platform ) use ( &$seen ) { $seen[] = [ $action, $id, $platform ]; }, 10, 4 );
		$this->press( $this->data( 'assign' ), 999 );
		$this->assertSame( [], $seen, 'custom actions need permission too' );
		$this->press( $this->data( 'assign' ) );
		$this->assertSame( [ [ 'assign', $this->id, 'telegram' ] ], $seen );
		$this->filter( 'fnfe_callback_actions', static function ( $actions ) { $actions['bin'] = [ 'label' => 'Bin', 'status' => 'trash' ]; return $actions; } );
		$this->press( $this->data( 'bin' ) );
		$this->assertSame( 'trash', $this->status() );
		$this->assertCount( 3, $this->answers() );
	}

	public function test_errors_are_answered(): void {
		$this->filter( 'fnfe_callback_actions', static function () { throw new \RuntimeException( 'boom' ); } );
		$this->press( $this->data( 'done' ) );
		$this->assertCount( 1, $this->answers() );
		$this->assertNotContains( 'boom', $this->answers()[0]['text'] );
	}

	public function test_retry(): void {
		$this->press( $this->data( 'retry' ) );
		$this->assertCount( 1, $this->answers() );
		MessageRepository::insert( [ 'submission_id' => $this->id, 'channel' => 'fake', 'status' => 'sent' ] );
		$this->press( $this->data( 'retry' ) );
		$this->assertCount( 2, $this->answers() );
	}

	public function test_malformed_query(): void {
		UpdateHandler::handle( 'telegram', [ 'update_id' => 1, 'callback_query' => [ 'id' => [ 1 ] ] ] );
		UpdateHandler::handle( 'telegram', [ 'update_id' => 1, 'callback_query' => [ 'id' => '5' ] ] );
		$this->assertCount( 1, $this->answers() );
	}
}
