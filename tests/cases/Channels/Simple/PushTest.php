<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Channels\Simple;

use AmirhpCom\FormsNotifier\Channels\Push\GotifyChannel;
use AmirhpCom\FormsNotifier\Channels\Push\NtfyChannel;
use AmirhpCom\FormsNotifier\Channels\Push\PushoverChannel;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PushTest extends TestCase {

	public function tearDown(): void {
		Settings::clear_overlay();
	}

	private function message( string $id, string $address, string $body = '<b>Hi</b> <code>x</code>' ): Message {
		$m          = new Message( $id, new Recipient( $id, $address ) );
		$m->format  = Registry::channel( $id )->format();
		$m->subject = 'عنوان';
		$m->body    = Registry::formatter( $m->format )->convert( $body );
		$m->plain   = wp_strip_all_tags( $body );
		$m->buttons = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/s' ] ] ];
		return $m;
	}

	public function test_ntfy_payload(): void {
		Settings::overlay( [ 'channels.ntfy.priority' => '4' ], [ 'channels.ntfy.token' => 'tk_test' ] );
		HttpMock::expect( 'POST', '~^https://ntfy\.sh/$~', [ 'id' => 'abc' ] );
		$result = ( new NtfyChannel() )->send( $this->message( 'ntfy', 'my-topic_1' ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'abc', $result->remote_id );
		$this->assertSame(
			[ 'topic' => 'my-topic_1', 'message' => '**Hi** `x`', 'markdown' => true, 'title' => 'عنوان', 'priority' => 4, 'click' => 'https://example.com/s', 'actions' => [ [ 'action' => 'view', 'label' => 'Open', 'url' => 'https://example.com/s' ] ] ],
			HttpMock::last()['json']
		);
		$this->assertSame( 'Bearer tk_test', HttpMock::last()['headers']['authorization'] );
		$m                      = $this->message( 'ntfy', 'my-topic_1' );
		$m->options['priority'] = '1';
		( new NtfyChannel() )->send( $m );
		$this->assertSame( 1, HttpMock::last()['json']['priority'] );
	}

	public function test_ntfy_address_limits_and_errors(): void {
		$ntfy = new NtfyChannel();
		$this->assertSame( 'a_b-1', $ntfy->normalize_address( ' a_b-1 ' ) );
		$this->assertSame( 'topic', $ntfy->normalize_address( 'https://ntfy.sh/topic' ) );
		foreach ( [ '', 'a b', 'a/b', 'https://evil.example/topic', str_repeat( 'a', 65 ), 'تاپیک' ] as $bad ) {
			$this->assertSame( '', $ntfy->normalize_address( $bad ), $bad );
		}
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'x' ] );
		$ntfy->send( $this->message( 'ntfy', 't', str_repeat( 'متن بلند فارسی ', 600 ) ) );
		$this->assertTrue( count( HttpMock::requests() ) > 2 );
		foreach ( HttpMock::requests() as $request ) {
			$this->assertTrue( strlen( $request['json']['message'] ) <= 4096 );
		}
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'code' => 40101, 'http' => 401, 'error' => 'unauthorized' ], 401 );
		$result = $ntfy->send( $this->message( 'ntfy', 't' ) );
		$this->assertTrue( $result->permanent );
		$this->assertContains( 'unauthorized', $result->error );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', '', 503 );
		$this->assertFalse( $ntfy->send( $this->message( 'ntfy', 't' ) )->permanent );
	}

	public function test_pushover_payload(): void {
		$key = str_repeat( 'u', 30 );
		Settings::overlay( [], [ 'channels.pushover.app_token' => 'app-token-1' ] );
		HttpMock::expect( 'POST', '~^https://api\.pushover\.net/1/messages\.json$~', [ 'status' => 1, 'request' => 'req-1' ] );
		$result = ( new PushoverChannel() )->send( $this->message( 'pushover', $key ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'req-1', $result->remote_id );
		$this->assertSame( [ 'token' => 'app-token-1', 'user' => $key, 'message' => '<b>Hi</b> x', 'html' => '1', 'priority' => '0', 'title' => 'عنوان', 'url' => 'https://example.com/s', 'url_title' => 'Open' ], HttpMock::last()['form'] );

		$m                        = $this->message( 'pushover', $key, '<b>' . str_repeat( 'word ', 500 ) . '</b>' );
		$m->buttons               = [];
		$m->meta['submission_id'] = 7;
		$m->subject               = str_repeat( 'س', 400 );
		( new PushoverChannel() )->send( $m );
		$form = HttpMock::last()['form'];
		$this->assertTrue( mb_strlen( $form['message'] ) <= 1024 );
		$this->assertMatches( '~</b> …$~', $form['message'] );
		$this->assertTrue( mb_strlen( $form['title'] ) <= 250 );
		$this->assertSame( Urls::submission_admin( 7 ), $form['url'] );
	}

	public function test_pushover_errors(): void {
		$key = str_repeat( 'u', 30 );
		$this->assertCount( 1, ( new PushoverChannel() )->problems() );
		$this->assertTrue( ( new PushoverChannel() )->send( $this->message( 'pushover', $key ) )->permanent );
		$this->assertSame( '', ( new PushoverChannel() )->normalize_address( 'short' ) );
		Settings::overlay( [], [ 'channels.pushover.app_token' => 'app-token-1' ] );
		HttpMock::expect( 'POST', '~.~', [ 'status' => 0, 'errors' => [ 'user identifier is invalid' ] ], 400 );
		$result = ( new PushoverChannel() )->send( $this->message( 'pushover', $key ) );
		$this->assertTrue( $result->permanent );
		$this->assertSame( 'user identifier is invalid', $result->error );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'status' => 0, 'errors' => [ 'quota' ] ], 429 );
		$this->assertTrue( ( new PushoverChannel() )->send( $this->message( 'pushover', $key ) )->permanent );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', '', 500 );
		$this->assertFalse( ( new PushoverChannel() )->send( $this->message( 'pushover', $key ) )->permanent );
	}

	public function test_gotify(): void {
		$gotify = new GotifyChannel();
		$this->assertCount( 2, $gotify->problems() );
		$this->assertTrue( $gotify->send( $this->message( 'gotify', 'default' ) )->permanent );
		Settings::overlay( [ 'channels.gotify.server' => 'https://gotify.example.com/', 'channels.gotify.priority' => 8 ], [ 'channels.gotify.app_token' => 'A-token-1' ] );
		$this->assertSame( [], $gotify->problems() );
		HttpMock::expect( 'POST', '~^https://gotify\.example\.com/message$~', [ 'id' => 5 ] );
		$result = $gotify->send( $this->message( 'gotify', 'default' ) );
		$this->assertSame( [ true, '5' ], [ $result->ok, $result->remote_id ] );
		$this->assertSame(
			[ 'title' => 'عنوان', 'message' => "**Hi** `x`\n\n[Open](https://example.com/s)", 'priority' => 8, 'extras' => [ 'client::display' => [ 'contentType' => 'text/markdown' ], 'client::notification' => [ 'click' => [ 'url' => 'https://example.com/s' ] ] ] ],
			HttpMock::last()['json']
		);
		$this->assertSame( 'A-token-1', HttpMock::last()['headers']['x-gotify-key'] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'error' => 'Unauthorized', 'errorCode' => 401, 'errorDescription' => 'you need to provide a valid access token' ], 401 );
		$result = $gotify->send( $this->message( 'gotify', 'default' ) );
		$this->assertTrue( $result->permanent );
		$this->assertContains( 'valid access token', $result->error );
	}

	public function test_test_action_of_every_push_channel(): void {
		Settings::overlay( [ 'channels.gotify.server' => 'https://gotify.example.com' ], [ 'channels.gotify.app_token' => 'A-token-1', 'channels.pushover.app_token' => 'app-token-1' ] );
		HttpMock::expect( 'POST', '~.~', [ 'status' => 1, 'id' => 1 ] );
		foreach ( [ 'ntfy' => 'topic', 'pushover' => str_repeat( 'u', 30 ), 'gotify' => 'default' ] as $id => $to ) {
			$this->assertTrue( Registry::channel( $id )->test( new Recipient( $id, $to ) )->ok, $id );
		}
		$this->assertCount( 3, HttpMock::requests() );
	}
}
