<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SimpleMessengersTest extends TestCase {

	const TOKEN = 'TESTSECRETTOKEN';

	public function setUp(): void {
		Db::reset();
		foreach ( [ 'eitaa', 'rubika', 'gap' ] as $id ) {
			Settings::set_secret( "channels.$id.token", self::TOKEN );
			Settings::set( "channels.$id.enabled", true );
		}
		$this->filter( 'fnfe_allowed_file_roots', static function ( $roots ) { return array_merge( (array) $roots, [ Db::uploads() ] ); } );
	}

	private function send( string $channel, string $address, array $files = [] ): SendResult {
		$m              = Fixtures::message( $channel, $address, 'متن پیام' );
		$m->subject     = 'Subject';
		$m->buttons     = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/' ], [ 'text' => 'No', 'type' => 'callback', 'value' => 'done' ] ] ];
		$m->links       = [ [ 'name' => 'cv.pdf', 'url' => 'https://example.com/cv.pdf', 'size' => 5 ] ];
		$m->lang        = 'en';
		$m->attachments = $files;
		return Registry::channel( $channel )->send( $m );
	}

	private function assertText( string $text ): void {
		$this->assertMatches( '/^متن پیام\n\nOpen: https:\/\/example\.com\/\n\n.+\ncv\.pdf: https:\/\/example\.com\/cv\.pdf$/u', $text );
	}

	public function test_eitaa(): void {
		HttpMock::expect( 'POST', '~^https://eitaayar\.ir/api/TESTSECRETTOKEN/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 85917 ] ] );
		HttpMock::expect( 'POST', '~/sendFile$~', [ 'ok' => true, 'result' => [ 'message_id' => 85918 ] ] );
		$r = $this->send( 'eitaa', '@my_channel', [ Fixtures::file( 'cv.pdf' ) ] );
		$this->assertTrue( $r->ok, $r->error );
		$this->assertSame( '85917', $r->remote_id );
		$form = HttpMock::requests()[0]['form'];
		$this->assertKeys( [ 'chat_id', 'text', 'title' ], $form );
		$this->assertSame( [ 'my_channel', 'Subject' ], [ $form['chat_id'], $form['title'] ] );
		$this->assertText( $form['text'] );
		$this->assertSame( 'cv.pdf', HttpMock::last()['parts']['file']['filename'] );
		$this->assertSame( 'my_channel', HttpMock::last()['parts']['chat_id']['value'] );
	}

	public function test_eitaa_errors(): void {
		$cases = [
			[ [ 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized ' . self::TOKEN ], 401, true ],
			[ [ 'ok' => false, 'error_code' => 400, 'description' => 'chat not found' ], 200, true ],
			[ [ 'ok' => false, 'error_code' => 429, 'description' => 'slow down' ], 429, false ],
			[ '<html>', 200, false ],
			[ '<html>', 503, false ],
			[ new \WP_Error( 'http', 'timeout' ), 200, false ],
		];
		foreach ( $cases as list( $body, $code, $permanent ) ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~~', $body, $code );
			$r = $this->send( 'eitaa', '12345' );
			$this->assertFalse( $r->ok );
			$this->assertSame( $permanent, $r->permanent, wp_json_encode( $body ) );
			$this->assertNotContains( self::TOKEN, wp_json_encode( $r->to_array() ) );
		}
	}

	public function test_failed_file_does_not_fail_the_text(): void {
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
		HttpMock::expect( 'POST', '~/sendFile$~', [ 'ok' => false, 'error_code' => 400, 'description' => 'x' ], 400 );
		$r = $this->send( 'eitaa', '12345', [ Fixtures::file( 'cv.pdf' ) ] );
		$this->assertTrue( $r->ok );
		$this->assertSame( [ 'cv.pdf' ], $r->meta['files_failed'] );
	}

	public function test_rubika(): void {
		$base = 'https://botapi.rubika.ir/v3/' . self::TOKEN . '/';
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'status' => 'OK', 'data' => [ 'message_id' => '77' ] ] );
		HttpMock::expect( 'POST', '~/requestSendFile$~', [ 'status' => 'OK', 'data' => [ 'upload_url' => 'https://upload.example.com/u/1' ] ] );
		HttpMock::expect( 'POST', '~^https://upload\.example\.com/~', [ 'status' => 'OK', 'data' => [ 'file_id' => 'F1' ] ] );
		HttpMock::expect( 'POST', '~/sendFile$~', [ 'status' => 'OK', 'data' => [ 'message_id' => '78' ] ] );
		$r = $this->send( 'rubika', 'b0TestChatId', [ Fixtures::file( 'pic.png' ) ] );
		$this->assertTrue( $r->ok, $r->error );
		$this->assertSame( '77', $r->remote_id );
		$q = HttpMock::requests();
		$this->assertSame( [ $base . 'sendMessage', $base . 'requestSendFile', 'https://upload.example.com/u/1', $base . 'sendFile' ], array_column( $q, 'url' ) );
		$this->assertKeys( [ 'chat_id', 'text' ], $q[0]['json'] );
		$this->assertText( $q[0]['json']['text'] );
		$this->assertSame( [ 'type' => 'Image' ], $q[1]['json'] );
		$this->assertSame( 'pic.png', $q[2]['parts']['file']['filename'] );
		$this->assertSame( [ 'chat_id' => 'b0TestChatId', 'file_id' => 'F1', 'text' => 'pic.png' ], $q[3]['json'] );
	}

	public function test_rubika_status_decides(): void {
		$cases = [
			[ [ 'status' => 'INVALID_INPUT' ], 200, true, 0 ],
			[ [ 'status' => 'INVALID_ACCESS' ], 200, true, 0 ],
			[ [ 'status' => 'TOO_REQUESTS' ], 200, false, 60 ],
			[ [ 'status' => 'SERVER_ERROR' ], 200, false, 0 ],
			[ [ 'x' => 1 ], 200, false, 0 ],
			[ '<html>', 404, true, 0 ],
			[ [ 'status' => 'OK' ], 500, false, 0 ],
		];
		foreach ( $cases as list( $body, $code, $permanent, $retry ) ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~~', $body, $code );
			$r = $this->send( 'rubika', 'b0TestChatId' );
			$this->assertFalse( $r->ok, wp_json_encode( $body ) );
			$this->assertSame( $permanent, $r->permanent, wp_json_encode( $body ) );
			$this->assertSame( $retry, $r->retry_after );
		}
	}

	public function test_rubika_upload_address_is_checked(): void {
		foreach ( [ 'http://upload.example.com/', 'https://localhost/', 'https://10.0.0.1/', 'file:///etc/passwd', [ 'x' ], '' ] as $url ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~/sendMessage$~', [ 'status' => 'OK', 'data' => [ 'message_id' => '77' ] ] );
			HttpMock::expect( 'POST', '~/requestSendFile$~', [ 'status' => 'OK', 'data' => [ 'upload_url' => $url ] ] );
			$r = $this->send( 'rubika', 'b0TestChatId', [ Fixtures::file( 'a.pdf' ) ] );
			$this->assertTrue( $r->ok );
			$this->assertSame( [ 'a.pdf' ], $r->meta['files_failed'] );
			$this->assertCount( 2, HttpMock::requests() );
		}
	}

	public function test_gap(): void {
		HttpMock::expect( 'POST', '~^https://api\.gap\.im/sendMessage$~', [ 'id' => 1333 ] );
		$r = $this->send( 'gap', '+98 912 000 0000' );
		$this->assertTrue( $r->ok, $r->error );
		$this->assertSame( '1333', $r->remote_id );
		$q = HttpMock::last();
		$this->assertSame( self::TOKEN, $q['headers']['token'] );
		$this->assertKeys( [ 'chat_id', 'type', 'data' ], $q['form'] );
		$this->assertSame( [ '+989120000000', 'text' ], [ $q['form']['chat_id'], $q['form']['type'] ] );
		$this->assertText( $q['form']['data'] );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', [ 'error' => 'invalid chat' ], 400 );
		$r = $this->send( 'gap', '@mybot' );
		$this->assertTrue( $r->permanent );
		$this->assertSame( 'invalid chat', $r->error );
	}

	public function test_addresses(): void {
		$cases = [
			'eitaa'  => [ '@my_ch' => 'my_ch', 'https://eitaa.com/my_ch' => 'my_ch', '۱۲۳۴' => '1234', 'a b' => '', '../x' => '' ],
			'rubika' => [ 'b0AbC-9_' => 'b0AbC-9_', 'a b' => '', '<x>' => '' ],
			'gap'    => [ '+98 912 000 0000' => '+989120000000', '@bot_name' => '@bot_name', '123456' => '123456', 'x' => '' ],
		];
		foreach ( $cases as $id => $list ) {
			foreach ( $list as $raw => $expected ) {
				$this->assertSame( $expected, Registry::channel( $id )->normalize_address( (string) $raw ), "$id $raw" );
			}
		}
	}

	public function test_problems_and_bad_tokens(): void {
		foreach ( [ 'eitaa', 'rubika', 'gap' ] as $id ) {
			$channel = Registry::channel( $id );
			$this->assertTrue( $channel->is_ready(), $id );
			$this->assertSame( 'text', $channel->format() );
			$this->assertSame( [], $channel->capabilities()['buttons'] );
			Settings::set_secret( "channels.$id.token", 'bad/../token' );
			$this->assertTrue( $this->send( $id, '123456' )->permanent );
			Settings::set_secret( "channels.$id.token", '' );
			$this->assertFalse( $channel->is_ready() );
			$this->assertFalse( $this->send( $id, '123456' )->ok );
		}
		HttpMock::assertNoRequests();
	}
}
