<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\WhatsApp;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\WhatsApp\WhatsAppModule;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\Sms\ProviderTestCase;

require_once dirname( __DIR__ ) . '/Sms/ProviderTestCase.php';

final class WhatsAppTest extends ProviderTestCase {

	const CHANNEL = 'whatsapp';

	const KEY = 'fake-key-0123456789';

	private function basic( string $user, string $pass ): string {
		return 'Basic ' . base64_encode( "$user:$pass" );
	}

	/** A file inside the uploads directory, with a public address. */
	private function file( string $name, string $mime, string $content = 'content' ): Attachment {
		$path = Db::uploads() . '/' . $name;
		file_put_contents( $path, $content );
		$file       = new Attachment();
		$file->name = $name;
		$file->path = $path;
		$file->url  = 'https://site.example.com/uploads/' . $name;
		$file->mime = $mime;
		$file->ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$file->size = strlen( $content );
		return $file;
	}

	// --- golden tests ---

	private function meta_settings(): array {
		return [ 'phone_number_id' => '1000001', 'token' => self::KEY, 'graph_version' => 'v27.0', 'template_lang' => 'en_US' ];
	}

	public function test_meta(): void {
		$url  = 'https://graph.facebook.com/v27.0/1000001/messages';
		$ok   = [ 'messaging_product' => 'whatsapp', 'contacts' => [ [ 'input' => '+1', 'wa_id' => '1' ] ], 'messages' => [ [ 'id' => 'wamid.1' ] ] ];
		$head = [ 'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => self::INTL ];
		$auth = [ 'Authorization' => 'Bearer ' . self::KEY ];
		$tpl  = static function ( array $template ) use ( $head ): array {
			return $head + [ 'type' => 'template', 'template' => $template ];
		};
		$this->golden( [
			'id'       => 'meta',
			'to'       => self::INTL,
			'settings' => $this->meta_settings(),
			'optional' => [ 'graph_version', 'template_lang' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ '*Hello*', 'POST', $url, $auth, $head + [ 'type' => 'text', 'text' => [ 'preview_url' => true, 'body' => '*Hello*' ] ], $ok, 'wamid.1' ],
			'pattern'  => [
				[ $this->pattern( 'order', [ 1 => 'b', 0 => "a\nx" ], 'fa' ), 'POST', $url, $auth, $tpl( [ 'name' => 'order', 'language' => [ 'code' => 'fa' ], 'components' => [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'text' => 'a x' ], [ 'type' => 'text', 'text' => 'b' ] ] ] ] ] ), $ok, 'wamid.1' ],
				[ $this->pattern( 'order', [ 'first_name' => 'Jessica' ] ), 'POST', $url, $auth, $tpl( [ 'name' => 'order', 'language' => [ 'code' => 'en_US' ], 'components' => [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'parameter_name' => 'first_name', 'text' => 'Jessica' ] ] ] ] ] ), $ok, 'wamid.1' ],
				[ $this->pattern( 'hello_world', [] ), 'POST', $url, $auth, $tpl( [ 'name' => 'hello_world', 'language' => [ 'code' => 'en_US' ] ] ), $ok, 'wamid.1' ],
			],
			'failures' => [
				[ [ 'error' => [ 'message' => 'Invalid token', 'code' => 190 ] ], 401, true, '#190 Invalid token' ],
				[ [ 'error' => [ 'message' => '(#131047) Re-engagement', 'code' => 131047, 'error_data' => [ 'details' => 'More than 24 hours' ] ] ], 400, true, '#131047 More than 24 hours The person' ],
				[ [ 'error' => [ 'message' => '(#130429) Rate limit hit', 'code' => 130429 ] ], 400, false, '130429' ],
				[ [ 'error' => [ 'message' => '(#132001) no template', 'code' => 132001, 'error_data' => [ 'details' => 'template name does not exist in fa' ] ] ], 404, true, 'does not exist in fa' ],
			],
			'status'   => [ 'GET', '~/v27\.0/1000001$~', [ 'id' => '1000001', 'verified_name' => 'Shop', 'display_phone_number' => '+1' ], 'Shop' ],
		] );
		$provider = $this->provider( 'meta', [ 'graph_version' => '../evil' ] + $this->meta_settings() );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~^https://graph\.facebook\.com/v27\.0/~', $ok );
		$this->assertTrue( $provider->send( self::INTL, $this->message() )->ok );
		$this->provider( 'meta', [ 'graph_version' => 'v28.0' ] + $this->meta_settings() );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~^https://graph\.facebook\.com/v28\.0/~', $ok );
		$this->assertTrue( $provider->send( self::INTL, $this->message() )->ok );
	}

	public function test_meta_media(): void {
		$provider = $this->provider( 'meta', $this->meta_settings() );
		HttpMock::queue( 'POST', '~/media$~', [ [ 'id' => 'M1' ], [ 'id' => 'M2' ] ] );
		HttpMock::queue( 'POST', '~/messages$~', [ [ 'messages' => [ [ 'id' => 'w1' ] ] ], [ 'messages' => [ [ 'id' => 'w2' ] ] ], [ 'messages' => [ [ 'id' => 'w3' ] ] ] ] );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'photo.png', 'image/png', 'PNGDATA' ), $this->file( 'doc.pdf', 'application/pdf', 'PDFDATA' ) ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertTrue( $result->ok, $result->error );
		$this->assertSame( 'w1,w2,w3', $result->remote_id );
		$uploads = HttpMock::requests( '~/media$~' );
		$this->assertSame( 'whatsapp', $uploads[0]['parts']['messaging_product']['value'] );
		$this->assertSame( [ 'value' => 'PNGDATA', 'filename' => 'photo.png', 'type' => 'image/png' ], $uploads[0]['parts']['file'] );
		$this->assertSame( 'Bearer ' . self::KEY, $uploads[0]['headers']['authorization'] );
		$sent = HttpMock::requests( '~/messages$~' );
		$this->assertSame( [ 'id' => 'M1' ], $sent[1]['json']['image'] );
		$this->assertSame( [ 'id' => 'M2', 'filename' => 'doc.pdf' ], $sent[2]['json']['document'] );
	}

	public function test_meta_media_falls_back_to_a_link(): void {
		$provider = $this->provider( 'meta', $this->meta_settings() );
		HttpMock::expect( 'POST', '~/media$~', [ 'error' => [ 'code' => 131053, 'message' => 'Media upload error' ] ], 400 );
		HttpMock::queue( 'POST', '~/messages$~', [ [ 'messages' => [ [ 'id' => 'w1' ] ] ], [ 'messages' => [ [ 'id' => 'w2' ] ] ] ] );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'a.zip', 'application/zip' ) ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertTrue( $result->ok );
		$this->assertSame( [ 'a.zip' ], $result->meta['files_failed'] );
		$this->assertContains( '131053', $result->meta['file_error'] );
		$sent = HttpMock::requests( '~/messages$~' );
		$this->assertCount( 2, $sent );
		$this->assertContains( 'https://site.example.com/uploads/a.zip', $sent[1]['json']['text']['body'] );
	}

	public function test_files_outside_of_the_uploads_are_not_read(): void {
		$provider   = $this->provider( 'waha', [ 'base' => 'https://waha.example.com' ] );
		$file       = $this->file( 'x.txt', 'text/plain' );
		$file->path = __FILE__;
		$file->url  = '';
		HttpMock::expect( 'POST', '~/sendText$~', [ 'id' => 'w' ], 201 );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $file ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertTrue( $result->ok );
		$this->assertSame( [ 'x.txt' ], $result->meta['files_failed'] );
		$this->assertCount( 1, HttpMock::requests() );
	}

	public function test_files_only(): void {
		$provider = $this->provider( 'wassenger', [ 'api_key' => self::KEY ] );
		HttpMock::expect( 'POST', '~.~', [ 'errorCode' => 'media', 'message' => 'bad media' ], 400 );
		$message              = $this->message( '' );
		$message->attachments = [ $this->file( 'x.pdf', 'application/pdf' ) ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertFalse( $result->ok );
		$this->assertContains( 'bad media', $result->error );
	}

	public function test_twilio(): void {
		$auth = $this->basic( 'ACfake', self::KEY );
		$url  = 'https://api.twilio.com/2010-04-01/Accounts/ACfake/Messages.json';
		$ok   = [ 'sid' => 'SM1', 'status' => 'queued' ];
		$head = [ 'From' => 'whatsapp:+15550000000', 'To' => 'whatsapp:' . self::INTL ];
		$this->golden( [
			'id'       => 'twilio',
			'to'       => self::INTL,
			'settings' => [ 'account_sid' => 'ACfake', 'auth_token' => self::KEY, 'sender' => 'whatsapp:+15550000000' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Hello', 'POST', $url, [ 'Authorization' => $auth ], [ 'form' => $head + [ 'Body' => 'Hello' ] ], $ok, 'SM1', 201 ],
			'pattern'  => [
				[ $this->pattern( 'HX1', [ 'a', 'b' ] ), 'POST', $url, [], [ 'form' => $head + [ 'ContentSid' => 'HX1', 'ContentVariables' => '{"1":"a","2":"b"}' ] ], $ok, 'SM1', 201 ],
				[ $this->pattern( 'HX1', [ 'name' => 'a' ] ), 'POST', $url, [], [ 'form' => $head + [ 'ContentSid' => 'HX1', 'ContentVariables' => '{"name":"a"}' ] ], $ok, 'SM1', 201 ],
			],
			'failures' => [
				[ [ 'code' => 20003, 'message' => 'Authenticate' ], 401, true, '20003' ],
				[ [ 'sid' => 'SM2', 'status' => 'failed', 'error_code' => 63016, 'error_message' => 'Outside window' ], 201, true, '63016 Outside window' ],
			],
		] );
		$provider = $this->provider( 'twilio', [ 'account_sid' => 'ACfake', 'auth_token' => self::KEY, 'sender' => '+15550000000' ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok, 201 );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'doc.pdf', 'application/pdf' ) ];
		$provider->send( self::INTL, $message );
		$this->assertSame( $head + [ 'MediaUrl' => 'https://site.example.com/uploads/doc.pdf' ], HttpMock::last()['form'] );
		$this->assertCount( 2, HttpMock::requests() );
	}

	public function test_ultramsg(): void {
		$base = 'https://api.ultramsg.com/instance77/messages/';
		$ok   = [ 'sent' => 'true', 'message' => 'ok', 'id' => 5 ];
		$this->golden( [
			'id'       => 'ultramsg',
			'to'       => self::INTL,
			'settings' => [ 'instance' => '77', 'token' => self::KEY ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', $base . 'chat', [], [ 'form' => [ 'token' => self::KEY, 'to' => self::INTL, 'body' => 'Hello' ] ], $ok, '5' ],
			'failures' => [
				[ [ 'error' => 'Wrong token.' ], 200, true, 'Wrong token.' ],
				[ [ 'error' => 'invalid to' ], 400, true, 'invalid to' ],
			],
		] );
		$provider = $this->provider( 'ultramsg', [ 'instance' => 'instance77', 'token' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'photo.jpg', 'image/jpeg', 'JPG' ), $this->file( 'doc.pdf', 'application/pdf', 'PDF' ) ];
		$this->assertTrue( $provider->send( self::INTL, $message )->ok );
		$requests = HttpMock::requests();
		$this->assertSame( $base . 'image', $requests[1]['url'] );
		$this->assertSame( base64_encode( 'JPG' ), $requests[1]['form']['image'] );
		$this->assertSame( $base . 'document', $requests[2]['url'] );
		$this->assertSame( [ 'token' => self::KEY, 'to' => self::INTL, 'filename' => 'doc.pdf', 'document' => base64_encode( 'PDF' ), 'caption' => 'doc.pdf' ], $requests[2]['form'] );
	}

	public function test_greenapi(): void {
		$base = 'https://7103.api.example.com/waInstance7103/';
		$this->golden( [
			'id'       => 'greenapi',
			'to'       => self::INTL,
			'settings' => [ 'base' => 'https://7103.api.example.com/', 'instance' => '7103', 'token' => self::KEY ],
			'optional' => [ 'base' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', $base . 'sendMessage/' . self::KEY, [], [ 'chatId' => '15550100000@c.us', 'message' => 'Hello' ], [ 'idMessage' => 'G1' ], 'G1' ],
			'failures' => [
				[ '<html><body>403 Forbidden</body></html>', 403, true, '' ],
				[ [ 'message' => 'Validation failed' ], 400, true, 'Validation failed' ],
				[ [ 'message' => 'Quota exceeded' ], 466, false, 'Quota exceeded' ],
			],
		] );
		$provider = $this->provider( 'greenapi', [ 'instance' => '7103', 'token' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~^https://api\.green-api\.com/waInstance7103/~', [ 'idMessage' => 'G2' ] );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'doc.pdf', 'application/pdf' ) ];
		$this->assertTrue( $provider->send( self::INTL, $message )->ok );
		$this->assertSame( [ 'chatId' => '15550100000@c.us', 'urlFile' => 'https://site.example.com/uploads/doc.pdf', 'fileName' => 'doc.pdf' ], HttpMock::last()['json'] );
		$this->assertContains( '/sendFileByUrl/', HttpMock::last()['url'] );
	}

	public function test_waha(): void {
		$this->golden_waha( [ 'id' => 'true_1@c.us_AA' ], 'true_1@c.us_AA' );
		$this->golden_waha( [ 'id' => [ 'fromMe' => true, '_serialized' => 'ser1' ] ], 'ser1' );
		$provider = $this->provider( 'waha', [ 'base' => 'https://waha.example.com' ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'W' ], 201 );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'photo.jpg', 'image/jpeg', 'JPG' ), $this->file( 'doc.pdf', 'application/pdf', 'PDF' ) ];
		$this->assertTrue( $provider->send( self::INTL, $message )->ok );
		$requests = HttpMock::requests();
		$this->assertFalse( isset( $requests[0]['headers']['x-api-key'] ) );
		$this->assertSame( 'https://waha.example.com/api/sendImage', $requests[1]['url'] );
		$this->assertSame( [ 'session' => 'default', 'chatId' => '15550100000@c.us', 'file' => [ 'mimetype' => 'application/pdf', 'filename' => 'doc.pdf', 'data' => base64_encode( 'PDF' ) ] ], $requests[2]['json'] );
		$this->assertSame( 'https://waha.example.com/api/sendFile', $requests[2]['url'] );
	}

	private function golden_waha( array $answer, string $id ): void {
		$provider = $this->provider( 'waha', [ 'base' => 'https://waha.example.com/', 'api_key' => self::KEY, 'session' => 'main' ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $answer, 201 );
		$result = $provider->send( self::INTL, $this->message( 'Hello' ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( $id, $result->remote_id );
		$request = HttpMock::last();
		$this->assertSame( 'https://waha.example.com/api/sendText', $request['url'] );
		$this->assertSame( self::KEY, $request['headers']['x-api-key'] );
		$this->assertSame( [ 'session' => 'main', 'chatId' => '15550100000@c.us', 'text' => 'Hello' ], $request['json'] );
		foreach ( [
			[ [ 'statusCode' => 401, 'message' => 'Unauthorized' ], 401, true, 'Unauthorized' ],
			[ [ 'error' => 'Session not found', 'message' => '' ], 200, true, 'Session not found' ],
			[ '<html>login</html>', 200, true, '' ],
			[ 'x', 502, false, '' ],
		] as $fail ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~.~', $fail[0], $fail[1] );
			$result = $provider->send( self::INTL, $this->message( 'Hello' ) );
			$this->assertFalse( $result->ok );
			$this->assertSame( $fail[2], $result->permanent );
			if ( $fail[3] !== '' ) {
				$this->assertContains( $fail[3], $result->error );
			}
			$this->assert_scrubbed( $result, [ self::KEY ] );
		}
		$this->provider( 'waha', [ 'base' => '' ] );
		$this->assertFalse( $provider->is_configured() );
	}

	public function test_evolution(): void {
		$base = 'https://evo.example.com/message/';
		$ok   = [ 'key' => [ 'id' => 'E1' ], 'status' => 'PENDING' ];
		$set  = [ 'base' => 'https://evo.example.com', 'api_key' => self::KEY, 'instance' => 'my shop' ];
		$this->golden( [
			'id'       => 'evolution',
			'to'       => self::INTL,
			'settings' => $set,
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', $base . 'sendText/my%20shop', [ 'apikey' => self::KEY ], [ 'number' => '15550100000', 'text' => 'Hello' ], $ok, 'E1', 201 ],
			'failures' => [
				[ '{"status":401,"error":"Unauthorized","response":{"message":"Unauthorized"}}', 401, true, 'Unauthorized' ],
				[ '{"status":400,"error":"Bad Request","response":{"message":[{"exists":false}]}}', 400, true, 'exists' ],
			],
		] );
		$provider = $this->provider( 'evolution', $set );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok, 201 );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'photo.jpg', 'image/jpeg', 'JPG' ) ];
		$provider->send( self::INTL, $message );
		$this->assertSame( [ 'number' => '15550100000', 'mediatype' => 'image', 'mimetype' => 'image/jpeg', 'media' => base64_encode( 'JPG' ), 'fileName' => 'photo.jpg' ], HttpMock::last()['json'] );
	}

	public function test_wassenger(): void {
		$url = 'https://api.wassenger.com/v1/messages';
		$this->golden( [
			'id'       => 'wassenger',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', $url, [ 'Token' => self::KEY ], [ 'phone' => self::INTL, 'message' => 'Hello' ], [ 'id' => 'W1', 'status' => 'queued' ], 'W1', 201 ],
			'failures' => [
				[ [ 'status' => 403, 'message' => 'Invalid token', 'errorCode' => 'auth:forbidden' ], 403, true, 'auth:forbidden Invalid token' ],
				[ [ 'status' => 400, 'message' => 'Invalid phone', 'errors' => [] ], 400, true, 'Invalid phone' ],
			],
		] );
		$provider = $this->provider( 'wassenger', [ 'api_key' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'W2' ], 201 );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'doc.pdf', 'application/pdf' ) ];
		$provider->send( self::INTL, $message );
		$this->assertSame( [ 'phone' => self::INTL, 'media' => [ 'url' => 'https://site.example.com/uploads/doc.pdf' ] ], HttpMock::last()['json'] );
	}

	public function test_dialog360(): void {
		$url  = 'https://waba-v2.360dialog.io/messages';
		$auth = [ 'D360-API-KEY' => self::KEY ];
		$head = [ 'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => self::INTL ];
		$ok   = [ 'messages' => [ [ 'id' => 'wamid.9', 'message_status' => 'accepted' ] ] ];
		$this->golden( [
			'id'       => 'dialog360',
			'to'       => self::INTL,
			'settings' => [ 'token' => self::KEY, 'template_lang' => 'fa' ],
			'optional' => [ 'template_lang' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', $url, $auth, $head + [ 'type' => 'text', 'text' => [ 'preview_url' => true, 'body' => 'Hello' ] ], $ok, 'wamid.9' ],
			'pattern'  => [ $this->pattern( 'order', [ 'a' ] ), 'POST', $url, $auth, $head + [ 'type' => 'template', 'template' => [ 'name' => 'order', 'language' => [ 'code' => 'fa' ], 'components' => [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'text' => 'a' ] ] ] ] ] ], $ok, 'wamid.9' ],
			'failures' => [
				[ [ 'error' => 'Invalid api token.' ], 401, true, 'Invalid api token.' ],
				[ [ 'error' => [ 'message' => 'x', 'code' => 131047 ] ], 400, true, '#131047' ],
				[ [ 'error' => 'Invalid request' ], 200, true, 'Invalid request' ],
			],
		] );
		$provider = $this->provider( 'dialog360', [ 'token' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~^https://waba-v2\.360dialog\.io/media$~', [ 'id' => 'M1' ] );
		HttpMock::expect( 'POST', '~/messages$~', $ok );
		$message              = $this->message( '' );
		$message->attachments = [ $this->file( 'doc.pdf', 'application/pdf', 'PDF' ) ];
		$this->assertTrue( $provider->send( self::INTL, $message )->ok );
		$this->assertSame( self::KEY, HttpMock::requests( '~/media$~' )[0]['headers']['d360-api-key'] );
		$this->assertSame( [ 'id' => 'M1', 'filename' => 'doc.pdf' ], HttpMock::last()['json']['document'] );
	}

	public function test_whapi(): void {
		$this->golden( [
			'id'       => 'whapi',
			'to'       => self::INTL,
			'settings' => [ 'token' => self::KEY ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://gate.whapi.cloud/messages/text', [ 'Authorization' => 'Bearer ' . self::KEY ], [ 'to' => '15550100000', 'body' => 'Hello' ], [ 'sent' => true, 'message' => [ 'id' => 'H1' ] ], 'H1' ],
			'failures' => [
				[ [ 'error' => [ 'code' => 401, 'message' => 'Need channel authorization' ] ], 401, true, 'Need channel' ],
				[ [ 'sent' => false, 'error' => 'no such number' ], 200, true, 'no such number' ],
			],
		] );
		$provider = $this->provider( 'whapi', [ 'token' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'sent' => true, 'message' => [ 'id' => 'H2' ] ] );
		$message              = $this->message( 'Hello' );
		$message->attachments = [ $this->file( 'doc.pdf', 'application/pdf' ) ];
		$this->assertTrue( $provider->send( self::INTL, $message )->ok );
		$this->assertSame( "Hello\ndoc.pdf: https://site.example.com/uploads/doc.pdf", HttpMock::last()['json']['body'] );
	}

	public function test_custom(): void {
		$provider = $this->provider( 'custom', [ 'url' => 'https://wa.example.com/send', 'token' => self::KEY, 'body' => '{"k":"{token}","n":"{to_digits}","t":"{text}"}' ] );
		$this->assertFalse( $provider->supports_media() );
		$this->assertFalse( $provider->supports_template() );
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'c1' ] );
		$message              = $this->message( 'Hi "you" {token}' );
		$message->links       = [ [ 'name' => 'a.pdf', 'url' => 'https://site.example.com/f/a', 'size' => 1 ] ];
		$message->attachments = [ $this->file( 'b.pdf', 'application/pdf' ), $this->file( 'a.pdf', 'application/pdf' ) ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertTrue( $result->ok );
		$this->assertSame(
			[ 'k' => self::KEY, 'n' => '15550100000', 't' => "Hi \"you\" {token}\na.pdf: https://site.example.com/f/a\nb.pdf: https://site.example.com/uploads/b.pdf" ],
			HttpMock::last()['json']
		);
		$this->assertCount( 1, HttpMock::requests() );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', self::KEY, 403 );
		$result = $provider->send( self::INTL, $this->message() );
		$this->assertTrue( ! $result->ok && $result->permanent );
		$this->assert_scrubbed( $result, [ self::KEY ] );
	}

	// --- shared behaviour ---

	public function test_links_are_not_repeated(): void {
		$provider = $this->provider( 'wassenger', [ 'api_key' => self::KEY ] );
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'W' ], 201 );
		$message        = $this->message( 'See https://site.example.com/f/a' );
		$message->links = [ [ 'name' => 'a', 'url' => 'https://site.example.com/f/a', 'size' => 1 ] ];
		$provider->send( self::INTL, $message );
		$this->assertSame( 'See https://site.example.com/f/a', HttpMock::last()['json']['message'] );
	}

	public function test_long_texts(): void {
		$provider = $this->provider( 'wassenger', [ 'api_key' => self::KEY ] );
		$text     = trim( str_repeat( "A line of the message.\n", 400 ) );
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'W' ], 201 );
		$this->assertTrue( $provider->send( self::INTL, $this->message( $text ) )->ok );
		$this->assertTrue( count( HttpMock::requests() ) > 1 );
		foreach ( HttpMock::requests() as $request ) {
			$this->assertTrue( mb_strlen( $request['json']['message'] ) <= 4096 );
		}
		HttpMock::reset();
		HttpMock::queue( 'POST', '~.~', [ [ 'code' => 201, 'body' => [ 'id' => 'W' ] ], [ 'code' => 500, 'body' => 'x' ] ] );
		$result = $provider->send( self::INTL, $this->message( $text ) );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->permanent );
		$this->assertMatches( '~1.*[23]~', $result->error );
	}

	public function test_template_with_files(): void {
		$provider = $this->provider( 'meta', $this->meta_settings() );
		HttpMock::expect( 'POST', '~/messages$~', [ 'messages' => [ [ 'id' => 'w' ] ] ] );
		$message              = $this->message( 'Hello', $this->pattern( 'x', [] ) );
		$message->attachments = [ $this->file( 'a.pdf', 'application/pdf' ) ];
		$result               = $provider->send( self::INTL, $message );
		$this->assertSame( 1, $result->meta['files_skipped'] );
		$this->assertCount( 1, HttpMock::requests() );
	}

	// --- channel ---

	private function choose( array $values, array $secrets = [] ): void {
		$v = $s = [];
		foreach ( $values as $k => $x ) {
			$v[ 'channels.whatsapp.' . $k ] = $x;
		}
		foreach ( $secrets as $k => $x ) {
			$s[ 'channels.whatsapp.' . $k ] = $x;
		}
		Settings::clear_overlay();
		Settings::overlay( $v, $s );
	}

	public function test_channel_fallback(): void {
		$channel = Registry::channel( 'whatsapp' );
		$this->assertSame( 'whatsapp', $channel->format() );
		$this->assertTrue( $channel->capabilities()['attachments'] );
		$this->assertTrue( $channel->send( $this->message() )->permanent );
		$this->assertCount( 1, $channel->problems() );

		$this->choose(
			[ 'enabled' => true, 'provider' => 'meta', 'fallback_provider' => 'wassenger', 'providers.meta.phone_number_id' => '1' ],
			[ 'providers.meta.token' => self::KEY, 'providers.wassenger.api_key' => self::KEY ]
		);
		$this->assertTrue( $channel->is_ready() );
		HttpMock::expect( 'POST', '~facebook~', [ 'error' => [ 'code' => 131047, 'message' => 'x' ] ], 400 );
		HttpMock::expect( 'POST', '~wassenger~', [ 'id' => 'W' ], 201 );

		// The template goes to the first gateway, the normal text to the second one.
		$message       = $this->message( 'plain', $this->pattern( 'tpl', [] ) );
		$message->body = '*formatted*';
		$result        = $channel->send( $message );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'wassenger', $result->provider );
		$this->assertSame( 'template', HttpMock::requests( '~facebook~' )[0]['json']['type'] );
		$this->assertSame( '*formatted*', HttpMock::last()['json']['message'] );

		// A template without normal text cannot use the second one.
		$message       = $this->message( '', $this->pattern( 'tpl', [] ) );
		$result        = $channel->send( $message );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->permanent );
		$this->assertMatches( '~^meta: .* \| wassenger: ~', $result->error );

		// Only a plain text.
		HttpMock::reset();
		HttpMock::expect( 'POST', '~facebook~', [ 'messages' => [ [ 'id' => 'w' ] ] ] );
		$message       = $this->message( 'only plain' );
		$message->body = '';
		$this->assertTrue( $channel->send( $message )->ok );
		$this->assertContains( 'only', HttpMock::last()['json']['text']['body'] );
	}

	public function test_readiness(): void {
		$channel = Registry::channel( 'whatsapp' );
		$this->choose( [ 'enabled' => true, 'provider' => 'nope' ] );
		$this->assertCount( 1, $channel->problems() );
		$this->choose( [ 'enabled' => true, 'provider' => 'ultramsg' ] );
		$this->assertCount( 2, $channel->problems() );
		$this->assertFalse( $channel->is_ready() );
	}

	public function test_schema_and_notices(): void {
		Schema::flush();
		$fields = Schema::fields();
		foreach ( [ 'provider', 'fallback_provider', 'edit_provider', 'test_to', 'test_text', 'receivers', 'template.admin', 'template.submitter', 'fallback', 'providers.meta.graph_version' ] as $key ) {
			$this->assertArrayHasKey( 'channels.whatsapp.' . $key, $fields );
		}
		$this->assertSame( 'v27.0', $fields['channels.whatsapp.providers.meta.graph_version']['default'] );
		$actions = Registry::channel( 'whatsapp' )->actions();
		foreach ( WhatsAppModule::provider_classes() as $id => $class ) {
			$provider = Registry::provider( 'whatsapp', $id );
			$this->assertInstanceOf( $class, $provider );
			$this->assertArrayHasKey( 'test_' . $id, $actions );
			$this->assertArrayHasKey( 'status_' . $id, $actions );
			$risky = in_array( $id, [ 'ultramsg', 'greenapi', 'waha', 'evolution', 'wassenger', 'whapi' ], true );
			$this->assertSame( $risky, in_array( 'note_account', array_column( $provider->fields(), 'key' ), true ), $id );
			$this->assertSame( in_array( $id, [ 'meta', 'dialog360', 'twilio' ], true ), $provider->supports_template(), $id );
			$this->assertSame( ! in_array( $id, [ 'custom', 'whapi' ], true ), $provider->supports_media(), $id );
		}
	}

	public function test_test_action(): void {
		$this->choose( [ 'provider' => 'wassenger' ], [ 'providers.wassenger.api_key' => self::KEY, 'providers.meta.token' => self::KEY ] );
		Settings::overlay( [ 'channels.whatsapp.providers.meta.phone_number_id' => '1' ] );
		HttpMock::expect( 'POST', '~wassenger~', [ 'id' => 'W' ], 201 );
		HttpMock::expect( 'POST', '~facebook~', [ 'messages' => [ [ 'id' => 'w' ] ] ] );
		$actions = Registry::channel( 'whatsapp' )->actions();
		$out     = $actions['test_whatsapp']( [ 'to' => self::INTL ] );
		$this->assertTrue( $out['ok'], $out['message'] );
		$out = $actions['test_meta']( [ 'to' => self::INTL, 'text' => "template:hello\nlang:en_US" ] );
		$this->assertTrue( $out['ok'], $out['message'] );
		$this->assertSame( [ 'name' => 'hello', 'language' => [ 'code' => 'en_US' ] ], HttpMock::last()['json']['template'] );
	}


	// Review B, I1: links signed a second later are the same file.

	public function test_a_signed_link_of_another_second_is_not_repeated(): void {
		$provider = $this->provider( 'wassenger', [ 'api_key' => self::KEY ] );
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'W' ], 201 );
		$message        = $this->message( 'See https://site.example.com/wp-json/fnfe/v1/file/7?t=aaa&e=100' );
		$message->links = [ [ 'name' => 'cv', 'url' => 'https://site.example.com/wp-json/fnfe/v1/file/7?t=bbb&e=101', 'size' => 1 ] ];
		$provider->send( self::INTL, $message );
		$this->assertSame( 'See https://site.example.com/wp-json/fnfe/v1/file/7?t=aaa&e=100', HttpMock::last()['json']['message'] );
	}
}
