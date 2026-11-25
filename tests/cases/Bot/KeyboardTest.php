<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\Keyboard;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class KeyboardTest extends TestCase {

	const PURE = true;

	const CONTEXT = [ 'submission_id' => 12, 'purpose' => 'admin', 'bot_username' => 'demo_bot', 'short_name' => '', 'miniapp' => true, 'webapp_as_link' => false ];

	private function one( array $button, string $platform = 'telegram', string $type = 'private', array $context = [] ) {
		$rows = Keyboard::build( [ [ $button + [ 'text' => 'Go' ] ] ], $platform, $type, $context + self::CONTEXT );
		return $rows[0][0] ?? null;
	}

	public function test_url(): void {
		foreach ( [ 'telegram', 'bale', 'soroush' ] as $platform ) {
			$this->assertSame( [ 'text' => 'Go', 'url' => 'https://example.com/a?b=1' ], $this->one( [ 'type' => 'url', 'value' => 'https://example.com/a?b=1' ], $platform ) );
		}
		$this->assertSame( 'tg://resolve?domain=x', $this->one( [ 'type' => 'url', 'value' => 'tg://resolve?domain=x' ] )['url'] );
		$this->assertNull( $this->one( [ 'type' => 'url', 'value' => 'tg://resolve?domain=x' ], 'bale' ) );
		$bad = [ '', 'javascript:alert(1)', 'mailto:a@example.com', 'http://localhost/x', 'https://localhost:8080', 'https://site.local/x', 'https://site.test', 'http://127.0.0.1/', 'http://10.0.0.5', 'https://192.168.1.2/x', 'http://[::1]/', 'https://intranet/', 'https://', 'https://example.com/' . str_repeat( 'a', 2100 ) ];
		foreach ( $bad as $url ) {
			$this->assertNull( $this->one( [ 'type' => 'url', 'value' => $url ] ), $url );
		}
		$this->assertSame( 'http://8.8.8.8/', $this->one( [ 'type' => 'url', 'value' => 'http://8.8.8.8/' ] )['url'] );
	}

	public function test_callback(): void {
		foreach ( [ 'telegram', 'bale' ] as $platform ) {
			$button = $this->one( [ 'type' => 'callback', 'value' => 'done' ], $platform, 'supergroup' );
			$this->assertTrue( strlen( $button['callback_data'] ) <= 64 );
			$this->assertSame( [ 'action' => 'done', 'submission_id' => 12 ], Keyboard::parse_callback( $button['callback_data'] ) );
		}
		$this->assertNull( $this->one( [ 'type' => 'callback', 'value' => 'done' ], 'soroush' ) );
		$this->assertNull( $this->one( [ 'type' => 'callback', 'value' => 'done' ], 'telegram', 'private', [ 'submission_id' => 0 ] ) );
		foreach ( [ 'submitter', 'alert', 'digest', 'test' ] as $purpose ) {
			$this->assertNull( $this->one( [ 'type' => 'callback', 'value' => 'done' ], 'telegram', 'private', [ 'purpose' => $purpose ] ), $purpose );
		}
		$this->assertNull( $this->one( [ 'type' => 'callback', 'value' => str_repeat( 'a', 30 ) ] ) );
	}

	public function test_callback_data_is_signed(): void {
		$data = Keyboard::callback_data( 'spam', 99999999999999999 );
		$this->assertTrue( strlen( $data ) <= 64 );
		$this->assertTrue( strlen( Keyboard::callback_data( str_repeat( 'z', 24 ), 99999999999999999 ) ) <= 64 );
		$this->assertSame( 99999999999999999, Keyboard::parse_callback( $data )['submission_id'] );
		$good = Keyboard::callback_data( 'read', 5 );
		$bad  = [ str_replace( ':read:', ':spam:', $good ), str_replace( ':5:', ':6:', $good ), substr( $good, 0, -1 ), $good . '0', strtoupper( $good ), $good . "\n", 'fnfe:read:5:', 'fnfe:read:0:0000000000000000', '', str_repeat( 'a', 200 ) ];
		foreach ( $bad as $data ) {
			$this->assertNull( Keyboard::parse_callback( $data ), $data );
		}
		$flip = substr( $good, 0, -1 ) . ( substr( $good, -1 ) === '0' ? '1' : '0' );
		$this->assertNull( Keyboard::parse_callback( $flip ) );
		$this->assertSame( '', Keyboard::callback_data( 'Bad Action', 1 ) );
		$this->assertSame( '', Keyboard::callback_data( 'read', 0 ) );
	}

	public function test_web_app(): void {
		$button = [ 'type' => 'web_app', 'value' => 'https://example.com/fnfe-app/telegram/?startapp=sub_12' ];
		$this->assertSame( [ 'text' => 'Go', 'web_app' => [ 'url' => $button['value'] ] ], $this->one( $button ) );
		foreach ( [ 'group', 'supergroup', 'channel', '' ] as $type ) {
			$this->assertSame( [ 'text' => 'Go', 'url' => 'https://t.me/demo_bot?startapp=sub_12' ], $this->one( $button, 'telegram', $type ) );
			$this->assertSame( 'https://t.me/demo_bot/panel?startapp=sub_12', $this->one( $button, 'telegram', $type, [ 'short_name' => 'panel' ] )['url'] );
			$this->assertNull( $this->one( $button, 'telegram', $type, [ 'bot_username' => '' ] ) );
			$this->assertSame( $button['value'], $this->one( $button, 'bale', $type )['web_app']['url'] );
			$this->assertSame( 'https://ble.ir/demo_bot?startapp=sub_12', $this->one( $button, 'bale', $type, [ 'webapp_as_link' => true ] )['url'] );
		}
		$this->assertNull( $this->one( $button, 'telegram', 'private', [ 'miniapp' => false ] ) );
		$this->assertNull( $this->one( [ 'type' => 'web_app', 'value' => 'http://example.com/' ] ) );
		$this->assertNull( $this->one( [ 'type' => 'web_app', 'value' => 'https://localhost/' ] ) );
		$this->assertNull( $this->one( $button, 'soroush' ) );
		$this->assertSame( 'https://t.me/demo_bot?startapp=', $this->one( [ 'type' => 'web_app', 'value' => 'https://example.com/' ], 'telegram', 'group' )['url'] );
	}

	public function test_start_param(): void {
		$this->assertSame( 'form_3', Keyboard::start_param( 'https://example.com/?x=1&startapp=form_3#a' ) );
		$this->assertSame( 'sub_9', Keyboard::start_param( 'https://example.com/app/#/submissions/9' ) );
		$this->assertSame( 'form_9', Keyboard::start_param( 'https://example.com/app/#/forms/9' ) );
		$this->assertSame( '', Keyboard::start_param( 'https://example.com/?startapp=a b' ) );
		$this->assertSame( '', Keyboard::start_param( 'https://example.com/' ) );
	}

	public function test_copy_submission_and_style(): void {
		$this->assertSame( [ 'text' => 'Go', 'copy_text' => [ 'text' => 'abc' ] ], $this->one( [ 'type' => 'copy', 'value' => 'abc' ] ) );
		$this->assertSame( 256, mb_strlen( $this->one( [ 'type' => 'copy', 'value' => str_repeat( 'س', 300 ) ], 'bale' )['copy_text']['text'] ) );
		$this->assertNull( $this->one( [ 'type' => 'copy', 'value' => '' ] ) );
		$this->assertSame( 'https://example.com/wp-admin/', $this->one( [ 'type' => 'submission', 'value' => 'https://example.com/wp-admin/' ] )['url'] );
		$this->assertTrue( isset( $this->one( [ 'type' => 'submission', 'value' => 'https://example.com/?startapp=sub_1' ] )['web_app'] ) );
		foreach ( [ 'primary', 'success', 'danger' ] as $style ) {
			$this->assertSame( $style, $this->one( [ 'type' => 'copy', 'value' => 'a', 'style' => $style ] )['style'] );
			$this->assertSame( $style, $this->one( [ 'type' => 'copy', 'value' => 'a', 'style' => $style ], 'bale' )['style'] );
		}
		$this->assertFalse( isset( $this->one( [ 'type' => 'copy', 'value' => 'a', 'style' => 'pink' ] )['style'] ) );
		$this->assertNull( $this->one( [ 'type' => 'unknown', 'value' => 'a' ] ) );
	}

	public function test_text_and_limits(): void {
		$this->assertSame( 'a b', $this->one( [ 'type' => 'copy', 'value' => 'x', 'text' => " <b>a</b>\n b " ] )['text'] );
		$this->assertSame( 64, mb_strlen( $this->one( [ 'type' => 'copy', 'value' => 'x', 'text' => str_repeat( 'م', 90 ) ] )['text'] ) );
		$this->assertNull( $this->one( [ 'type' => 'copy', 'value' => 'x', 'text' => ' ' ] ) );

		$button = [ 'text' => 'x', 'type' => 'copy', 'value' => 'x' ];
		$rows   = Keyboard::build( [ array_fill( 0, 20, $button ), [], [ [ 'text' => '', 'type' => 'copy', 'value' => 'x' ] ] ], 'telegram', 'private', self::CONTEXT );
		$this->assertSame( [ 8, 8, 4 ], array_map( 'count', $rows ) );
		$this->assertSame( 100, array_sum( array_map( 'count', Keyboard::build( array_fill( 0, 40, array_fill( 0, 4, $button ) ), 'telegram', 'private', self::CONTEXT ) ) ) );
		$this->assertCount( 2, Keyboard::build( [ $button, $button ], 'telegram', 'private', self::CONTEXT ) );
		$this->assertSame( [], Keyboard::build( [ 'x', [ 'y' ] ], 'telegram', 'private', self::CONTEXT ) );
	}
}
