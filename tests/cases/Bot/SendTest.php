<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SendTest extends TestCase {

	private static $next = 100;

	public function setUp(): void {
		Db::reset();
		foreach ( [ 'telegram', 'bale', 'soroush' ] as $platform ) {
			Settings::set_secret( "channels.$platform.token", Fixtures::TOKEN );
			Settings::set( "channels.$platform.enabled", true );
		}
		// Tests run on the attachment guard of the site, whose roots are the temporary uploads of the run.
		$this->filter( 'fnfe_allowed_file_roots', static function ( $roots ) { return array_merge( (array) $roots, [ Db::uploads() ] ); } );
	}

	private function message( string $body, string $channel = 'telegram', string $address = Fixtures::CHAT, string $format = '' ): Message {
		$m         = new Message( $channel, new Recipient( $channel, $address, 'Desk' ) );
		$m->format = $format === '' ? Registry::channel( $channel )->format() : $format;
		$m->body   = $body;
		$m->plain  = $body;
		$m->meta   = [ 'submission_id' => 12 ];
		return $m;
	}

	private function send( Message $m ): SendResult {
		return Registry::channel( $m->channel )->send( $m );
	}

	private function answer(): void {
		$this->filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				$method = basename( $url );
				$result = [ 'message_id' => ++self::$next ];
				if ( $method === 'sendMediaGroup' ) {
					$result = [];
					preg_match_all( '/attach:\/\//', (string) $args['body'], $m );
					foreach ( $m[0] as $i => $x ) {
						$result[] = [ 'message_id' => ++self::$next ];
					}
				}
				return [ 'headers' => [], 'body' => wp_json_encode( [ 'ok' => true, 'result' => $result ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
			},
			2,
			3
		);
		HttpMock::expect( 'POST', '~~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
	}

	private function methods(): array {
		return array_map( static function ( $r ) { return basename( $r['url'] ); }, HttpMock::requests() );
	}

	public function test_short_text_with_options(): void {
		$this->answer();
		$m                     = $this->message( '<b>Hi</b>' );
		$m->buttons            = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/', 'style' => '' ], [ 'text' => 'Done', 'type' => 'callback', 'value' => 'done', 'style' => 'success' ] ] ];
		$m->options['silent']  = 'yes';
		$m->options['protect'] = '';
		$r                     = $this->send( $m );
		$this->assertTrue( $r->ok, $r->error );
		$this->assertNotSame( '', $r->remote_id );
		$this->assertSame( 1, $r->meta['parts'] );
		$q = HttpMock::last()['json'];
		$this->assertSame( 'HTML', $q['parse_mode'] );
		$this->assertSame( [ 'is_disabled' => true ], $q['link_preview_options'] );
		$this->assertTrue( $q['disable_notification'] );
		$this->assertFalse( isset( $q['protect_content'] ) );
		$this->assertSame( 2, count( $q['reply_markup']['inline_keyboard'][0] ) );
		$this->assertSame( 'success', $q['reply_markup']['inline_keyboard'][0][1]['style'] );
	}

	public function test_option_no_overrides_the_setting(): void {
		$this->answer();
		Settings::set( 'channels.telegram.silent', true );
		$m          = $this->message( 'x' );
		$m->options = [ 'silent' => 'no', 'disable_preview' => 'no' ];
		$this->send( $m );
		$this->assertFalse( isset( HttpMock::last()['json']['disable_notification'] ) );
		$this->assertFalse( isset( HttpMock::last()['json']['link_preview_options'] ) );
		$this->send( $this->message( 'x' ) );
		$this->assertTrue( HttpMock::last()['json']['disable_notification'] );
	}

	public function test_long_text_buttons_on_the_last_part(): void {
		$this->answer();
		$m          = $this->message( str_repeat( "Line of text that repeats itself\n", 300 ) );
		$m->buttons = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/' ] ] ];
		$r          = $this->send( $m );
		$this->assertTrue( $r->ok );
		$requests = HttpMock::requests();
		$this->assertTrue( count( $requests ) > 2 );
		$this->assertSame( count( $requests ), $r->meta['parts'] );
		foreach ( $requests as $i => $request ) {
			$this->assertTrue( mb_strlen( $request['json']['text'] ) <= 4096 );
			$this->assertSame( $i === count( $requests ) - 1, isset( $request['json']['reply_markup'] ), 'part ' . $i );
			$this->assertSame( $i > 0, isset( $request['json']['reply_parameters'] ) );
		}
		$this->assertCount( count( $requests ), explode( ',', $r->remote_id ) );
	}

	public function test_partial_failure_resumes(): void {
		$body = str_repeat( "Line of text that repeats itself\n", 200 );
		HttpMock::queue( 'POST', '~~', [ [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ], [ 'code' => 429, 'body' => [ 'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => [ 'retry_after' => 9 ] ] ] ] );
		$m                     = $this->message( $body );
		$m->meta['message_id'] = 55;
		$r                     = $this->send( $m );
		$this->assertFalse( $r->ok );
		$this->assertFalse( $r->permanent );
		$this->assertSame( 9, $r->retry_after );
		$this->assertSame( 1, $r->meta['parts_sent'] );
		HttpMock::reset();
		$this->answer();
		$this->assertTrue( $this->send( $m )->ok );
		$first = HttpMock::requests()[0]['json'];
		$this->assertSame( 1, $first['reply_parameters']['message_id'], 'the first part is not repeated' );
	}

	public function test_button_error_retries_without_keyboard(): void {
		HttpMock::queue( 'POST', '~~', [ [ 'code' => 400, 'body' => [ 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUTTON_URL_INVALID' ] ], [ 'ok' => true, 'result' => [ 'message_id' => 4 ] ] ] );
		$m          = $this->message( 'x' );
		$m->buttons = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/' ] ] ];
		$r          = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertTrue( $r->meta['buttons_dropped'] );
		$this->assertFalse( isset( HttpMock::last()['json']['reply_markup'] ) );
	}

	public function test_parse_error_retries_as_text(): void {
		$error = [ 'code' => 400, 'body' => [ 'ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities: unclosed tag" ] ];
		HttpMock::queue( 'POST', '~~', [ $error, [ 'ok' => true, 'result' => [ 'message_id' => 4 ] ], $error, [ 'ok' => true, 'result' => [ 'message_id' => 5 ] ] ] );
		$r = $this->send( $this->message( '<b>a &amp; b' ) );
		$this->assertTrue( $r->ok );
		$this->assertTrue( $r->meta['sent_as_text'] );
		$this->assertSame( 'a & b', HttpMock::last()['json']['text'] );
		$this->assertFalse( isset( HttpMock::last()['json']['parse_mode'] ) );
		$this->send( $this->message( 'a\.b \_c', 'telegram', Fixtures::CHAT, 'telegram_md' ) );
		$this->assertSame( 'MarkdownV2', HttpMock::requests()[2]['json']['parse_mode'] );
		$this->assertSame( 'a.b _c', HttpMock::last()['json']['text'] );
	}

	public function test_permanent_errors(): void {
		ChatRepository::upsert( 'telegram', Fixtures::CHAT, 0, [ 'type' => 'supergroup', 'status' => 'active' ] );
		HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the supergroup chat' ], 403 );
		$r = $this->send( $this->message( 'x' ) );
		$this->assertTrue( $r->permanent );
		$this->assertSame( 'kicked', ChatRepository::find_chat( 'telegram', Fixtures::CHAT )['status'] );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => 401, 'description' => 'Unauthorized' ], 401 );
		$channel = Registry::channel( 'telegram' );
		$this->assertSame( [], $channel->problems() );
		$this->assertTrue( $this->send( $this->message( 'x' ) )->permanent );
		$this->assertCount( 1, $channel->problems() );
		$this->assertTrue( $channel->is_ready(), 'a rejected token is reported by the failing message' );
		HttpMock::reset();
		$this->answer();
		$this->send( $this->message( 'x' ) );
		$this->assertSame( [], $channel->problems() );

		$this->assertTrue( $this->send( $this->message( 'x', 'telegram', 'nonsense' ) )->permanent );
		$this->assertTrue( $this->send( $this->message( '   ' ) )->permanent );
	}

	public function test_migration(): void {
		ChatRepository::upsert( 'telegram', '-500', 0, [ 'type' => 'group', 'title' => 'Sales', 'status' => 'active' ] );
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( '-500' ), Fixtures::receiver( '77' ) ] );
		Settings::set( 'alerts.channels', [ 'telegram' => [ 'enabled' => true, 'receivers' => [ Fixtures::receiver( '-500' ) ] ] ] );
		$form = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		FormRepository::save_config( $form, [ 'channels' => [ 'telegram' => [ 'receivers' => [ Fixtures::receiver( '-500/7' ) ] ], 'bale' => [ 'receivers' => [ Fixtures::receiver( '-500' ) ] ] ], 'rules' => [ [ 'effect' => 'add', 'receivers' => [ 'telegram' => [ Fixtures::receiver( '-500' ) ] ] ] ] ] );
		HttpMock::expect( 'POST', '~~', [ 'ok' => false, 'error_code' => 400, 'description' => 'upgraded', 'parameters' => [ 'migrate_to_chat_id' => -1005 ] ], 400 );
		$r = $this->send( $this->message( 'x', 'telegram', '-500' ) );
		$this->assertFalse( $r->permanent );
		$this->assertSame( '-1005', $r->meta['migrate_to'] );
		$this->assertSame( [ '-1005', '77' ], array_column( Settings::arr( 'channels.telegram.receivers' ), 'address' ) );
		$this->assertSame( '-1005', Settings::get( 'alerts.channels.telegram.receivers' )[0]['address'] );
		$config = FormRepository::find( $form )['config'];
		$this->assertSame( '-1005/7', $config['channels']['telegram']['receivers'][0]['address'] );
		$this->assertSame( '-500', $config['channels']['bale']['receivers'][0]['address'] );
		$this->assertSame( '-1005', $config['rules'][0]['receivers']['telegram'][0]['address'] );
		$this->assertSame( 'migrated', ChatRepository::find_chat( 'telegram', '-500' )['status'] );
		$this->assertSame( 'Sales', ChatRepository::find_chat( 'telegram', '-1005' )['title'] );
	}

	private function files( int $count, string $ext = 'pdf' ): array {
		$out = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = Fixtures::file( "f$i.$ext", 'content ' . $i );
		}
		return $out;
	}

	public function test_one_file(): void {
		$this->answer();
		$form                = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		$m                   = $this->message( 'x', 'telegram', Fixtures::CHAT . '/45' );
		$m->meta['form_id']  = $form;
		$m->attachments      = $this->files( 1 );
		$r                   = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertSame( [ 'sendMessage', 'sendDocument' ], $this->methods() );
		$parts = HttpMock::last()['parts'];
		$this->assertSame( 'f0.pdf · Contact', $parts['caption']['value'] );
		$this->assertSame( '45', $parts['message_thread_id']['value'] );
		$this->assertSame( (int) explode( ',', $r->remote_id )[0], json_decode( $parts['reply_parameters']['value'], true )['message_id'] );
		$this->assertFalse( isset( $parts['parse_mode'] ) );
	}

	public function test_three_and_twelve_documents(): void {
		$this->answer();
		$m              = $this->message( 'x' );
		$m->attachments = $this->files( 3 );
		$this->send( $m );
		$this->assertSame( [ 'sendMessage', 'sendMediaGroup' ], $this->methods() );
		$media = json_decode( HttpMock::last()['parts']['media']['value'], true );
		$this->assertSame( [ 'document', 'document', 'document' ], array_column( $media, 'type' ) );
		$this->assertSame( [ 'f0.pdf', 'f1.pdf', 'f2.pdf' ], array_column( $media, 'caption' ) );

		HttpMock::reset();
		$this->answer();
		$m->attachments = $this->files( 12 );
		$r              = $this->send( $m );
		$this->assertSame( [ 'sendMessage', 'sendMediaGroup', 'sendMediaGroup' ], $this->methods() );
		$this->assertCount( 10, json_decode( HttpMock::requests()[1]['parts']['media']['value'], true ) );
		$this->assertCount( 2, json_decode( HttpMock::requests()[2]['parts']['media']['value'], true ) );
		$this->assertCount( 13, explode( ',', $r->remote_id ) );

		HttpMock::reset();
		$this->answer();
		$m->attachments = $this->files( 11 );
		$this->send( $m );
		$this->assertSame( [ 'sendMessage', 'sendMediaGroup', 'sendDocument' ], $this->methods() );
	}

	public function test_photos_and_documents_are_not_mixed(): void {
		$this->answer();
		$m              = $this->message( 'x' );
		$m->attachments = array_merge( $this->files( 2, 'png' ), $this->files( 2 ) );
		$this->send( $m );
		$this->assertSame( [ 'sendMessage', 'sendMediaGroup', 'sendMediaGroup' ], $this->methods() );
		$this->assertSame( [ 'photo', 'photo' ], array_column( json_decode( HttpMock::requests()[1]['parts']['media']['value'], true ), 'type' ) );

		HttpMock::reset();
		$this->answer();
		Settings::set( 'channels.telegram.images_as_photos', false );
		$m->attachments = $this->files( 1, 'png' );
		$this->send( $m );
		$this->assertSame( [ 'sendMessage', 'sendDocument' ], $this->methods() );
	}

	public function test_refused_photo_becomes_a_document(): void {
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
		HttpMock::expect( 'POST', '~/sendPhoto$~', [ 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS' ], 400 );
		HttpMock::expect( 'POST', '~/sendDocument$~', [ 'ok' => true, 'result' => [ 'message_id' => 2 ] ] );
		$m              = $this->message( 'x' );
		$m->attachments = $this->files( 1, 'png' );
		$r              = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertFalse( isset( $r->meta['files_failed'] ) );
		$this->assertSame( '1,2', $r->remote_id );
	}

	public function test_oversize_file_becomes_a_link(): void {
		$this->answer();
		$this->filter( 'fnfe_bot_max_upload_bytes', static function () { return 20; } );
		$big      = Fixtures::file( 'big.pdf', str_repeat( 'x', 30 ) );
		$big->url = 'https://example.com/big.pdf';

		$m              = $this->message( 'x' );
		$m->attachments = [ $big, Fixtures::file( 'small.pdf', 'x' ) ];
		$r              = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertSame( [ 'sendMessage', 'sendDocument', 'sendMessage' ], $this->methods() );
		$this->assertContains( 'https://example.com/big.pdf', HttpMock::last()['json']['text'] );
	}

	public function test_failed_file_is_a_success_with_a_note(): void {
		HttpMock::expect( 'POST', '~/sendMessage$~', [ 'ok' => true, 'result' => [ 'message_id' => 1 ] ] );
		HttpMock::expect( 'POST', '~/sendDocument$~', [ 'ok' => false, 'error_code' => 500, 'description' => 'oops' ], 500 );
		$m              = $this->message( 'x' );
		$m->attachments = $this->files( 1 );
		$r              = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertSame( [ 'f0.pdf' ], $r->meta['files_failed'] );
		$this->assertContains( 'f0.pdf', $r->response );
	}

	public function test_missing_file(): void {
		$this->answer();
		$m              = $this->message( 'x' );
		$m->attachments = $this->files( 1 );
		unlink( $m->attachments[0]->path );
		$r = $this->send( $m );
		$this->assertTrue( $r->ok );
		$this->assertSame( [ 'f0.pdf' ], $r->meta['files_failed'] );
		$this->assertSame( [ 'sendMessage' ], $this->methods() );
	}

	public function test_links_block(): void {
		$this->answer();
		$m        = $this->message( 'Body' );
		$m->links = [ [ 'name' => 'a<b>.pdf', 'url' => 'https://example.com/f?a=1&b=2', 'size' => 2048 ], [ 'name' => 'bad', 'url' => 'javascript:x', 'size' => 1 ] ];
		$this->send( $m );
		$text = HttpMock::last()['json']['text'];
		$this->assertMatches( '/^Body\n\n/', $text );
		$this->assertContains( 'https://example.com/f?a=1', $text );
		$this->assertContains( 'a&lt;b&gt;.pdf', $text );
		$this->assertNotContains( 'javascript', $text );
	}

	public function test_bale(): void {
		$this->answer();
		$m              = $this->message( '*Hi*', 'bale', '4567890/45' );
		$m->attachments = array_merge( $this->files( 2, 'png' ), $this->files( 2 ) );
		$m->buttons     = [ [ [ 'text' => 'Copy', 'type' => 'copy', 'value' => 'abc' ] ] ];
		$r              = $this->send( $m );
		$this->assertTrue( $r->ok, $r->error );
		$this->assertSame( [ 'sendMessage', 'sendMediaGroup', 'sendDocument', 'sendDocument' ], $this->methods() );
		$this->assertKeys( [ 'chat_id', 'text', 'reply_markup' ], HttpMock::requests()[0]['json'] );
		foreach ( HttpMock::requests() as $request ) {
			$this->assertMatches( '~^https://tapi\.bale\.ai/~', $request['url'] );
			$this->assertFalse( isset( $request['parts']['message_thread_id'] ) );
			$this->assertFalse( isset( $request['parts']['parse_mode'] ) );
		}
		$this->assertTrue( isset( HttpMock::last()['parts']['reply_to_message_id'] ) );

		ChatRepository::upsert( 'bale', '4567890', 0, [ 'type' => 'private' ] );
		$this->send( $this->message( 'x', 'bale', '4567890/45' ) );
		$this->assertSame( 45, HttpMock::last()['json']['message_thread_id'] );
	}

	public function test_soroush(): void {
		$this->answer();
		$m              = $this->message( '<b>Hi</b>', 'soroush', '@my_channel' );
		$m->attachments = $this->files( 2 );
		$m->buttons     = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/', 'style' => 'danger' ], [ 'text' => 'Done', 'type' => 'callback', 'value' => 'done' ] ] ];
		$this->assertTrue( $this->send( $m )->ok );
		$this->assertSame( [ 'sendMessage', 'sendDocument', 'sendDocument' ], $this->methods() );
		$this->assertEquals( [ 'chat_id' => '@my_channel', 'text' => '<b>Hi</b>', 'parse_mode' => 'HTML', 'reply_markup' => [ 'inline_keyboard' => [ [ [ 'text' => 'Open', 'url' => 'https://example.com/' ] ] ] ] ], HttpMock::requests()[0]['json'] );
		$this->assertMatches( '~^https://api\.splus\.ir/bot~', HttpMock::last()['url'] );
		$this->assertSame( '-5', Registry::channel( 'soroush' )->normalize_address( '-5/7' ) );
		$this->assertSame( '@my_channel', Registry::channel( 'soroush' )->normalize_address( 'https://splus.ir/my_channel' ) );
	}

	public function test_format_follows_the_setting(): void {
		$this->assertSame( 'telegram_html', Registry::channel( 'telegram' )->format() );
		Settings::set( 'channels.telegram.parse_mode', 'MarkdownV2' );
		$this->assertSame( 'telegram_md', Registry::channel( 'telegram' )->format() );
		$this->assertSame( 'bale_md', Registry::channel( 'bale' )->format() );
	}

	public function test_test_message(): void {
		$this->answer();
		$r = Registry::channel( 'telegram' )->test( new Recipient( 'telegram', '111111', '', 'test' ) );
		$this->assertTrue( $r->ok );
		$this->assertSame( '111111', HttpMock::last()['json']['chat_id'] );
	}

	public function test_problems(): void {
		Db::reset();
		$channel = Registry::channel( 'telegram' );
		$this->assertCount( 1, $channel->problems() );
		$this->assertFalse( $channel->is_ready() );
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		$this->assertSame( [], $channel->problems() );
		Settings::set( 'channels.telegram.api_base', 'http://proxy.example.com' );
		$this->assertCount( 1, $channel->problems() );
		State::delete( 'bot' );
	}

	public function test_schema_is_complete(): void {
		foreach ( [ 'telegram', 'bale' ] as $id ) {
			$keys = [];
			foreach ( Registry::channel( $id )->settings_schema() as $section ) {
				foreach ( $section['fields'] as $field ) {
					$keys[] = $field['key'];
					if ( $field['type'] === 'guide' ) {
						$this->assertTrue( count( $field['steps'] ) >= 8 );
					}
				}
			}
			foreach ( [ 'enabled', 'token', 'api_base', 'receivers', 'update_mode', 'admins', 'callback_access', 'commands', 'max_upload_mb', 'images_as_photos', 'miniapp_short_name', 'buttons', 'fallback' ] as $key ) {
				$this->assertContains( "channels.$id.$key", $keys );
			}
		}
		$this->assertTrue( Settings::bool( 'channels.telegram.disable_preview' ) );
		$this->assertTrue( Settings::bool( 'channels.telegram.images_as_photos' ) );
		$this->assertSame( 'auto', Settings::str( 'channels.bale.update_mode' ) );
		$this->assertCount( 3, Registry::channel( 'telegram' )->form_schema() );
	}
}
