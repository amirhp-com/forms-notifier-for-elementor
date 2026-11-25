<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Tests\HttpMock;

require_once __DIR__ . '/ProviderTestCase.php';

final class IranProvidersTest extends ProviderTestCase {

	const KEY = 'fake-key-0123456789';

	public function test_kavenegar(): void {
		$base = 'https://api.kavenegar.com/v1/' . self::KEY . '/';
		$ok   = [ 'return' => [ 'status' => 200, 'message' => 'ok' ], 'entries' => [ [ 'messageid' => 8792343, 'status' => 1, 'cost' => 120 ] ] ];
		$this->golden( [
			'id'       => 'kavenegar',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '10000000' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام & a=b', 'POST', $base . 'sms/send.json', [], [ 'form' => [ 'receptor' => '09120000000', 'message' => 'سلام & a=b', 'sender' => '10000000' ] ], $ok, '8792343' ],
			'pattern'  => [
				[ $this->pattern( 'welcome', [ 'Ali Reza', 'x' ] ), 'POST', $base . 'verify/lookup.json', [], [ 'form' => [ 'receptor' => '09120000000', 'template' => 'welcome', 'token' => "Ali\u{200C}Reza", 'token2' => 'x' ] ], $ok, '8792343' ],
				[
					$this->pattern( 'welcome', [ 'token20' => 'one two', 'token' => '12', 'name' => 'a b' ] ),
					'POST',
					$base . 'verify/lookup.json',
					[],
					[ 'form' => [ 'receptor' => '09120000000', 'template' => 'welcome', 'token20' => 'one two', 'token' => '12', 'token2' => "a\u{200C}b" ] ],
					$ok,
					'8792343',
				],
			],
			'failures' => [
				[ [ 'return' => [ 'status' => 403, 'message' => 'bad key' ], 'entries' => null ], 403, true, '403 bad key' ],
				[ [ 'return' => [ 'status' => 411, 'message' => 'bad receptor' ], 'entries' => null ], 200, true, '411 bad receptor' ],
				[ [ 'return' => [ 'status' => 418, 'message' => 'no credit' ], 'entries' => null ], 418, false, '418 no credit' ],
			],
			'status'   => [ 'POST', '~/account/info\.json$~', [ 'return' => [ 'status' => 200 ], 'entries' => [ 'remaincredit' => 5000 ] ], '5' ],
		] );
	}

	public function test_kavenegar_tokens(): void {
		$provider = $this->provider( 'kavenegar', [ 'api_key' => self::KEY ] );
		$this->assertTrue( $provider->send( self::IR, $this->message( '', $this->pattern( 'x', [ 'token2' => 'a' ] ) ) )->permanent );
		HttpMock::assertNoRequests();
		HttpMock::expect( 'POST', '~.~', [ 'return' => [ 'status' => 200 ], 'entries' => [ [ 'messageid' => 1 ] ] ] );
		$provider->send( self::IR, $this->message( '', $this->pattern( 'x', [ 'token' => str_repeat( 'a', 150 ), 'token10' => '1 2 3 4 5 6 7 8', 'token3' => "a\n b" ] ) ) );
		$form = HttpMock::last()['form'];
		$this->assertSame( 100, strlen( $form['token'] ) );
		$this->assertSame( "1 2 3 4 5 6\u{200C}7\u{200C}8", $form['token10'] );
		$this->assertSame( "a\u{200C}b", $form['token3'] );
	}

	public function test_ippanel(): void {
		$ok = [ 'data' => [ 'message_outbox_ids' => [ 1123544244 ] ], 'meta' => [ 'status' => true, 'message' => 'ok', 'message_code' => '200-1' ] ];
		$this->golden( [
			'id'       => 'ippanel',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '3000505' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', 'https://edge.ippanel.com/v1/api/send', [ 'Authorization' => self::KEY ], [ 'sending_type' => 'webservice', 'from_number' => '+983000505', 'message' => 'سلام', 'params' => [ 'recipients' => [ self::IR ] ] ], $ok, '1123544244' ],
			'pattern'  => [
				[ $this->pattern( 'abc123', [ 'name' => "Ali\nReza" ] ), 'POST', 'https://edge.ippanel.com/v1/api/send', [ 'Authorization' => self::KEY ], [ 'sending_type' => 'pattern', 'from_number' => '+983000505', 'code' => 'abc123', 'recipients' => [ self::IR ], 'params' => [ 'name' => 'Ali Reza' ] ], $ok, '1123544244' ],
				[ $this->pattern( 'abc123', [] ), 'POST', 'https://edge.ippanel.com/v1/api/send', [], '{"sending_type":"pattern","from_number":"+983000505","code":"abc123","recipients":["+989120000000"],"params":{}}', $ok, '1123544244' ],
			],
			'failures' => [
				[ [ 'data' => null, 'meta' => [ 'status' => false, 'message' => 'invalid token' ] ], 401, true, 'invalid token' ],
				[ [ 'data' => null, 'meta' => [ 'status' => false, 'message' => 'pattern not found' ] ], 200, true, 'pattern not found' ],
				[ [ 'data' => null, 'meta' => [ 'status' => false, 'errors' => [ 'code' => [ 'required' ] ] ] ], 422, true, 'required' ],
			],
			'status'   => [ 'GET', '~/api/payment/credit/mine$~', [ 'data' => [ 'credit' => 12 ], 'meta' => [ 'status' => true ] ], '12' ],
		] );
		foreach ( [ '+983000505' => '+983000505', '983000505' => '+983000505', '03000505' => '+983000505' ] as $sender => $expected ) {
			$provider = $this->provider( 'ippanel', [ 'api_key' => self::KEY, 'sender' => (string) $sender ] );
			HttpMock::reset();
			HttpMock::expect( 'POST', '~.~', $ok );
			$provider->send( self::IR, $this->message() );
			$this->assertSame( $expected, HttpMock::last()['json']['from_number'] );
		}
	}

	public function test_farazsms(): void {
		$base = 'https://api.iranpayamak.com/ws/v1';
		$ok   = [ 'status' => 'success', 'message' => 'ok', 'data' => [ 'id' => 99 ] ];
		$this->golden( [
			'id'       => 'farazsms',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '50002178584000' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . '/sms/simple', [ 'Api-Key' => self::KEY ], [ 'text' => 'سلام', 'line_number' => '50002178584000', 'recipients' => [ '09120000000' ], 'number_format' => 'english' ], $ok, '99' ],
			'pattern'  => [ $this->pattern( 'SJ3F', [ 'var1' => '1' ] ), 'POST', $base . '/sms/pattern', [ 'Api-Key' => self::KEY ], [ 'code' => 'SJ3F', 'attributes' => [ 'var1' => '1' ], 'recipient' => '09120000000', 'line_number' => '50002178584000', 'number_format' => 'english' ], $ok, '99' ],
			'failures' => [
				[ [ 'status' => 'error', 'message' => 'Api-Key not valid', 'data' => null ], 401, true, 'Api-Key not valid' ],
				[ [ 'status' => 'error', 'message' => 'no credit', 'data' => null ], 200, true, 'no credit' ],
			],
			'status'   => [ 'GET', '~/account/balance$~', [ 'status' => 'success', 'data' => [ 'balance' => 77 ] ], '77' ],
		] );
	}

	public function test_melipayamak(): void {
		$base = 'https://rest.payamak-panel.com/api/SendSMS/';
		$ok   = [ 'Value' => '5312345678901234567', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ];
		$this->golden( [
			'id'       => 'melipayamak',
			'settings' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '50001' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ 'fake-password' ],
			'text'     => [ 'سلام', 'POST', $base . 'SendSMS', [], [ 'form' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'to' => '09120000000', 'from' => '50001', 'text' => 'سلام', 'isFlash' => 'false' ] ], $ok, '5312345678901234567' ],
			'pattern'  => [ $this->pattern( '590428', [ 1 => 'b;c', 0 => "a\nx" ] ), 'POST', $base . 'BaseServiceNumber', [], [ 'form' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'text' => 'a x;b c', 'to' => '09120000000', 'bodyId' => '590428' ] ], $ok, '5312345678901234567' ],
			'failures' => [
				[ [ 'Value' => '0', 'RetStatus' => 0, 'StrRetStatus' => 'UserNameAndPasswordFailed' ], 200, true, 'UserNameAndPasswordFailed' ],
				[ [ 'Value' => '-4', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ], 200, true, '' ],
				[ [ 'Value' => '-5', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ], 200, true, '' ],
				[ [ 'Value' => '-6', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ], 200, false, '' ],
				[ [ 'Value' => '11', 'RetStatus' => 35, 'StrRetStatus' => 'InvalidData' ], 200, true, 'InvalidData' ],
				[ [ 'Value' => '2', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ], 200, true, '(2)' ],
			],
			'status'   => [ 'POST', '~/GetCredit$~', [ 'Value' => '321', 'RetStatus' => 1, 'StrRetStatus' => 'Ok' ], '321' ],
		] );
		$this->needs_sender( 'melipayamak', [ 'username' => 'fakeuser', 'password' => 'fake-password' ] );
	}

	public function test_smsir(): void {
		$base = 'https://api.sms.ir/v1';
		$this->golden( [
			'id'       => 'smsir',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '30001234' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . '/send/bulk', [ 'X-API-KEY' => self::KEY ], [ 'lineNumber' => 30001234, 'messageText' => 'سلام', 'mobiles' => [ '09120000000' ], 'sendDateTime' => null ], [ 'status' => 1, 'message' => 'ok', 'data' => [ 'packId' => 'p', 'messageIds' => [ 86522023 ], 'cost' => 1 ] ], '86522023' ],
			'pattern'  => [ $this->pattern( '123456', [ 'Code' => '12345', 'Name' => str_repeat( 'ن', 30 ) ] ), 'POST', $base . '/send/verify', [ 'X-API-KEY' => self::KEY ], [ 'mobile' => '9120000000', 'templateId' => 123456, 'parameters' => [ [ 'name' => 'Code', 'value' => '12345' ], [ 'name' => 'Name', 'value' => str_repeat( 'ن', 25 ) ] ] ], [ 'status' => 1, 'message' => 'ok', 'data' => [ 'messageId' => 89545112, 'cost' => 1 ] ], '89545112' ],
			'failures' => [
				[ [ 'status' => 10, 'message' => 'bad key', 'data' => null ], 401, true, '10 bad key' ],
				[ [ 'status' => 102, 'message' => 'credit', 'data' => null ], 400, false, '102 credit' ],
				[ [ 'status' => 104, 'message' => 'mobile', 'data' => null ], 400, true, '104 mobile' ],
				[ [ 'status' => 1, 'message' => 'ok', 'data' => [ 'messageIds' => [ 0 ] ] ], 200, true, '' ],
				[ [ 'status' => 1, 'message' => 'ok', 'data' => [ 'messageIds' => [ null ] ] ], 200, true, '' ],
			],
			'status'   => [ 'GET', '~/credit$~', [ 'status' => 1, 'data' => 456 ], '456' ],
		] );
		$this->needs_sender( 'smsir', [ 'api_key' => self::KEY ] );
	}

	public function test_ghasedak(): void {
		$base = 'https://gateway.ghasedak.me/rest/api/v1/WebService';
		$ok   = [ 'isSuccess' => true, 'statusCode' => 200, 'message' => 'ok', 'data' => [ 'items' => [ [ 'messageId' => 55 ] ] ] ];
		$provider = $this->provider( 'ghasedak', [ 'api_key' => self::KEY, 'sender' => '30001' ] );

		// The reference is generated, or the id of the log row.
		$message                     = $this->message( 'سلام' );
		$message->meta['message_id'] = 17;
		HttpMock::expect( 'POST', '~/SendSingleSMS$~', [ 'IsSuccess' => true, 'StatusCode' => 200, 'Data' => [ 'MessageId' => 'm-1' ] ] );
		$result = $provider->send( self::IR, $message );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'm-1', $result->remote_id );
		$this->assertSame( [ 'lineNumber' => '30001', 'receptor' => '09120000000', 'message' => 'سلام', 'clientReferenceId' => '17', 'udh' => false ], HttpMock::last()['json'] );
		$this->assertSame( self::KEY, HttpMock::last()['headers']['apikey'] );
		$this->assertSame( $base . '/SendSingleSMS', HttpMock::last()['url'] );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok );
		$message->pattern = $this->pattern( 'welcome', [ 'a', 'b' ] );
		$result           = $provider->send( self::IR, $message );
		$this->assertSame( '55', $result->remote_id );
		$this->assertSame( $base . '/SendOtpWithParams', HttpMock::last()['url'] );
		$this->assertSame( [ 'receptors' => [ [ 'mobile' => '09120000000', 'clientReferenceId' => '17' ] ], 'templateName' => 'welcome', 'param1' => 'a', 'param2' => 'b', 'isVoice' => false, 'udh' => false ], HttpMock::last()['json'] );

		$message->pattern = $this->pattern( 'welcome', [ 'Name' => 'Ali' ] );
		$this->assertTrue( $provider->send( self::IR, $message )->ok );
		$this->assertSame( $base . '/SendOtpSMS', HttpMock::last()['url'] );
		$this->assertSame( [ 'receptors' => [ [ 'mobile' => '09120000000', 'clientReferenceId' => '17' ] ], 'templateName' => 'welcome', 'inputs' => [ [ 'param' => 'Name', 'value' => 'Ali' ] ], 'udh' => false ], HttpMock::last()['json'] );

		$this->filter( 'http_request_args', static function ( $args ) {
			$args['body'] = preg_replace( '/("clientReferenceId":")[^"]+/', '$1x', (string) ( $args['body'] ?? '' ) );
			return $args;
		} );
		$this->golden( [
			'id'       => 'ghasedak',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '30001' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . '/SendSingleSMS', [ 'ApiKey' => self::KEY ], [ 'lineNumber' => '30001', 'receptor' => '09120000000', 'message' => 'سلام', 'clientReferenceId' => 'x', 'udh' => false ], $ok, '55' ],
			'failures' => [
				[ [ 'isSuccess' => false, 'statusCode' => 401, 'message' => 'bad key' ], 401, true, 'bad key' ],
				[ [ 'IsSuccess' => false, 'StatusCode' => 400, 'Message' => 'bad line' ], 200, true, 'bad line' ],
			],
			'status'   => [ 'GET', '~/GetAccountInformation$~', [ 'isSuccess' => true, 'data' => [ 'credit' => 900, 'plan' => 'x' ] ], '900' ],
		] );
		$this->needs_sender( 'ghasedak', [ 'api_key' => self::KEY ] );
	}

	public function test_mediana(): void {
		$base = 'https://api.mediana.ir/sms/v1';
		$ok   = [ 'meta' => [ 'requestId' => 'r', 'code' => 'OK', 'errorMessage' => null ], 'data' => [ 'succeed' => true, 'requestId' => 187484315 ] ];
		$this->golden( [
			'id'       => 'mediana',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '3000', 'type' => 'Informational' ],
			'optional' => [ 'sender', 'type' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . '/send/sms', [ 'X-API-KEY' => self::KEY ], [ 'sendingNumber' => '3000', 'recipients' => [ '09120000000' ], 'messageText' => 'سلام' ], $ok, '187484315' ],
			'pattern'  => [ $this->pattern( 'p1', [ 'name' => 'Ali' ] ), 'POST', $base . '/send/pattern', [ 'X-API-KEY' => self::KEY ], [ 'sendingNumber' => '3000', 'recipients' => [ '09120000000' ], 'patternCode' => 'p1', 'parameters' => [ 'name' => 'Ali' ] ], $ok, '187484315' ],
			'failures' => [
				[ [ 'meta' => [ 'code' => 'Unauthorized', 'errorMessage' => 'bad key' ], 'data' => null ], 401, true, 'bad key' ],
				[ [ 'meta' => [ 'code' => '1042', 'errorMessage' => 'no credit' ], 'data' => null ], 400, false, '1042 no credit' ],
				[ [ 'meta' => [ 'code' => '1072', 'errorMessage' => 'pattern rejected' ], 'data' => [ 'succeed' => false ] ], 200, true, '1072' ],
			],
			'status'   => [ 'GET', '~/account/balance$~', [ 'meta' => [ 'code' => 'OK' ], 'data' => [ 'balance' => 43 ] ], '43' ],
		] );
		$provider = $this->provider( 'mediana', [ 'api_key' => self::KEY, 'type' => 'PromotionalAll' ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok );
		$provider->send( self::IR, $this->message() );
		$this->assertSame( [ 'type' => 'PromotionalAll', 'recipients' => [ '09120000000' ], 'messageText' => 'Hello' ], HttpMock::last()['json'] );
	}

	public function test_amoot(): void {
		$this->freeze( 1767225600 ); // 2026-01-01 00:00 UTC, 03:30 in Tehran.
		$base = 'https://portal.amootsms.com/rest/';
		$ok   = [ 'CampaignID' => 1, 'Status' => 'Success', 'Data' => [ [ 'Mobile' => 9120000000, 'MessageID' => 1234568, 'Status' => 'Success' ] ] ];
		$this->golden( [
			'id'       => 'amoot',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '3000' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . 'SendSimple', [ 'Authorization' => self::KEY ], [ 'form' => [ 'SendDateTime' => '2026-01-01 03:30:00', 'SMSMessageText' => 'سلام', 'LineNumber' => '3000', 'Mobiles' => '9120000000' ] ], $ok, '1234568' ],
			'pattern'  => [ $this->pattern( '12', [ 'a,b', 'c،d' ] ), 'POST', $base . 'SendWithPattern', [ 'Authorization' => self::KEY ], [ 'form' => [ 'Mobile' => '9120000000', 'PatternCodeID' => '12', 'PatternValues' => 'a b,c d' ] ], $ok, '1234568' ],
			'failures' => [
				[ [ 'Status' => 'Token_NotExists', 'Data' => null ], 200, true, 'Token_NotExists' ],
				[ [ 'Status' => 'Success', 'Data' => [ [ 'Status' => 'Mobiles_Invalid' ] ] ], 200, true, 'Mobiles_Invalid' ],
			],
			'status'   => [ 'GET', '~/AccountStatus$~', [ 'Status' => 'Success', 'RemaindCredit' => 610 ], '610' ],
		] );
		$provider = $this->provider( 'amoot', [ 'api_key' => self::KEY ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', $ok );
		$provider->send( self::IR, $this->message() );
		$this->assertSame( 'public', HttpMock::last()['form']['LineNumber'] );
	}

	public function test_limosms(): void {
		$base = 'https://api.limosms.com/api/';
		$ok   = [ 'Success' => true, 'Message' => 'ok', 'MessageId' => [ 12 ] ];
		$this->golden( [
			'id'       => 'limosms',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '1000' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . 'sendsms', [ 'ApiKey' => self::KEY ], [ 'Message' => 'سلام', 'SenderNumber' => '1000', 'MobileNumber' => [ '09120000000' ], 'SendToBlocksNumber' => true ], $ok, '12' ],
			'pattern'  => [ $this->pattern( '7', [ 'a', 'b' ] ), 'POST', $base . 'sendpatternmessage', [ 'ApiKey' => self::KEY ], [ 'OtpId' => '7', 'ReplaceToken' => [ 'a', 'b' ], 'MobileNumber' => '09120000000' ], $ok, '12' ],
			'failures' => [
				[ 'ApiKey is invalid', 401, true, 'ApiKey is invalid' ],
				[ [ 'Success' => false, 'Message' => 'bad sender' ], 200, true, 'bad sender' ],
			],
			'status'   => [ 'POST', '~/getcurrentcredit$~', [ 'Success' => true, 'Result' => 31 ], '31' ],
		] );
		$this->needs_sender( 'limosms', [ 'api_key' => self::KEY ] );
	}

	public function test_asanak(): void {
		$base = 'https://sms.asanak.ir/webservice/v2rest/';
		$ok   = [ 'meta' => [ 'status' => 200, 'message' => 'success' ], 'data' => [ 123456 ] ];
		$this->golden( [
			'id'       => 'asanak',
			'settings' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '9821000' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ 'fake-password' ],
			'text'     => [ 'سلام', 'POST', $base . 'sendsms', [], [ 'username' => 'fakeuser', 'password' => 'fake-password', 'source' => '9821000', 'destination' => '09120000000', 'message' => 'سلام', 'send_to_blacklist' => 0 ], $ok, '123456' ],
			'pattern'  => [ $this->pattern( '1234', [ 'name' => 'Ali' ] ), 'POST', $base . 'template', [], [ 'username' => 'fakeuser', 'password' => 'fake-password', 'template_id' => 1234, 'destination' => '09120000000', 'parameters' => [ 'name' => 'Ali' ] ], $ok, '123456' ],
			'failures' => [
				[ [ 'meta' => [ 'status' => 1008, 'message' => 'bad login' ], 'data' => [] ], 401, true, '1008 bad login' ],
				[ [ 'meta' => [ 'status' => 1012, 'message' => 'bad request' ], 'data' => [] ], 400, true, '1012' ],
				[ [ 'meta' => [ 'status' => 1012, 'message' => 'bad request' ], 'data' => [] ], 200, true, '1012' ],
			],
			'status'   => [ 'POST', '~/getcredit$~', [ 'meta' => [ 'status' => 200 ], 'data' => [ 'credit' => 88 ] ], '88' ],
		] );
		$this->needs_sender( 'asanak', [ 'username' => 'fakeuser', 'password' => 'fake-password' ] );
	}

	public function test_payamresan(): void {
		$base = 'https://api.sms-webservice.com/api/V3/';
		$ok   = [ 'Success' => true, 'ErrorCode' => 0, 'Error' => '', 'Result' => [ [ 'ID' => 9 ] ] ];
		$this->golden( [
			'id'       => 'payamresan',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '5000' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'a b&c', 'GET', $base . 'Send?ApiKey=' . self::KEY . '&Text=a%20b%26c&Sender=5000&Recipients=9120000000', [], null, $ok, '9' ],
			'pattern'  => [ $this->pattern( 'tpl', [ 'a', 'b' ] ), 'POST', $base . 'SendTokenSingle', [], [ 'ApiKey' => self::KEY, 'TemplateKey' => 'tpl', 'Destination' => 9120000000, 'p1' => 'a', 'p2' => 'b' ], $ok, '9' ],
			'failures' => [
				[ [ 'Success' => false, 'ErrorCode' => 3, 'Error' => 'bad key', 'Result' => null ], 200, true, '3 bad key' ],
				[ [ 'Success' => false, 'ErrorCode' => 9, 'Error' => 'bad sender', 'Result' => null ], 400, true, 'bad sender' ],
			],
			'status'   => [ 'POST', '~/AccountInfo$~', [ 'Success' => true, 'Result' => [ 'Credit' => 64 ] ], '64' ],
		] );
		$this->needs_sender( 'payamresan', [ 'api_key' => self::KEY ] );
	}

	public function test_parsgreen(): void {
		$base = 'https://sms.parsgreen.ir/Apiv2/Message/';
		$auth = [ 'Authorization' => 'BASIC APIKEY:' . self::KEY ];
		$ok   = [ 'R_Success' => true, 'R_Code' => 0, 'R_Message' => 'ok' ];
		$this->golden( [
			'id'       => 'parsgreen',
			'settings' => [ 'api_key' => self::KEY, 'sender' => '10001' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'سلام', 'POST', $base . 'SendSms', $auth, [ 'SmsBody' => 'سلام', 'Mobiles' => [ '09120000000' ], 'SmsNumber' => '10001' ], $ok, '' ],
			'pattern'  => [
				[ $this->pattern( '12', [ 'x' => 'a', 'code' => '5555' ] ), 'POST', $base . 'SendOtp', $auth, [ 'Mobile' => '09120000000', 'SmsCode' => '5555', 'TemplateId' => 12, 'AddName' => false ], $ok, '' ],
				[ $this->pattern( '12', [ 1 => 'b', 0 => 'a' ] ), 'POST', $base . 'SendOtp', $auth, [ 'Mobile' => '09120000000', 'SmsCode' => 'a', 'TemplateId' => 12, 'AddName' => false ], $ok, '' ],
			],
			'failures' => [
				[ 'Authorization Failed', 403, true, 'Authorization Failed' ],
				[ [ 'R_Success' => false, 'R_Code' => 7, 'R_Error' => 'x', 'R_Message' => 'bad line' ], 200, false, '7 bad line' ],
				[ [ 'R_Success' => false, 'R_Code' => 3, 'R_Error' => 'denied' ], 403, true, '3 denied' ],
			],
		] );
		$provider = $this->provider( 'parsgreen', [ 'api_key' => self::KEY ] );
		HttpMock::reset();
		$this->assertTrue( $provider->send( self::IR, $this->message( '', $this->pattern( '12', [] ) ) )->permanent );
		HttpMock::assertNoRequests();
	}

	public function test_farazlegacy(): void {
		$login = [ 'uname' => 'fakeuser', 'pass' => 'fake-password' ];
		$get   = 'https://ippanel.com/patterns/pattern?username=fakeuser&password=fake-password&from=3000505&to=' . rawurlencode( '["09120000000"]' ) . '&input_data=';
		$this->golden( [
			'id'       => 'farazlegacy',
			'settings' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '3000505' ],
			'secrets'  => [ 'fake-password' ],
			'text'     => [ 'سلام', 'POST', 'https://ippanel.com/services.jspd', [], [ 'form' => $login + [ 'from' => '3000505', 'message' => 'سلام', 'to' => '["09120000000"]', 'op' => 'send' ] ], '[0,"123456"]', '123456' ],
			'pattern'  => [
				[ $this->pattern( 'abc', [ 'code' => 'a&b' ] ), 'GET', $get . rawurlencode( '{"code":"a&b"}' ) . '&pattern_code=abc', [], null, '987654', '987654' ],
				[ $this->pattern( 'abc', [] ), 'GET', $get . rawurlencode( '{}' ) . '&pattern_code=abc', [], null, '["sent","55"]', '55' ],
				[ $this->pattern( 'abc', [] ), 'GET', $get . rawurlencode( '{}' ) . '&pattern_code=abc', [], null, '"987654"', '987654' ],
			],
			'failures' => [
				[ '["962","the username or password is incorrect"]', 200, true, '962 the username' ],
				[ '[101,"x"]', 200, true, '101' ],
				[ '[5,"credit"]', 200, false, '5' ],
				[ '[]', 200, true, '' ],
			],
			'status'   => [ 'POST', '~/services\.jspd$~', '[0,"4500"]', '4' ],
		] );
		$provider = $this->provider( 'farazlegacy', [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '3000505' ] );
		foreach ( [ [ '["962","bad login"]', true ], [ '-1', false ], [ '0', false ], [ '["x"]', true ], [ '<html></html>', true ] ] as $case ) {
			HttpMock::reset();
			HttpMock::expect( 'GET', '~.~', $case[0] );
			$result = $provider->send( self::IR, $this->message( '', $this->pattern( 'abc', [] ) ) );
			$this->assertFalse( $result->ok, $case[0] );
			$this->assertSame( $case[1], $result->permanent, $case[0] );
			$this->assert_scrubbed( $result, [ 'fake-password' ] );
		}
	}

	public function test_raygansms(): void {
		$this->golden( [
			'id'       => 'raygansms',
			'settings' => [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '5000' ],
			'secrets'  => [ 'fake-password' ],
			'text'     => [ 'سلام', 'POST', 'https://raygansms.com/SendMessageWithPost.ashx', [], [ 'form' => [ 'Username' => 'fakeuser', 'Password' => 'fake-password', 'PhoneNumber' => '5000', 'MessageBody' => 'سلام', 'RecNumber' => '09120000000', 'Smsclass' => '1' ] ], " 812345\r\n", '812345' ],
			'failures' => [
				[ '8', 200, true, '8' ],
				[ '2000', 200, true, '2000' ],
				[ '0', 200, true, '0' ],
				[ 'Object reference not set to an instance of an object.', 200, true, 'Object reference' ],
			],
		] );
		$provider = $this->provider( 'raygansms', [ 'username' => 'fakeuser', 'password' => 'fake-password', 'sender' => '5000' ] );
		$this->assertFalse( $provider->supports_pattern() );
	}

	public function test_foreign_numbers_are_refused(): void {
		foreach ( Registry::providers( 'sms' ) as $id => $provider ) {
			if ( $provider->region() !== 'iran' ) {
				continue;
			}
			$this->assertSame( [ 'IR' ], $provider->countries(), $id );
			$this->assertSame( $id !== 'raygansms', $provider->supports_pattern(), $id );
			$this->provider( $id, [ 'api_key' => self::KEY, 'sender' => '1000', 'username' => 'fakeuser', 'password' => 'fake-password' ] );
			$result = $provider->send( self::INTL, $this->message() );
			$this->assertFalse( $result->ok );
			$this->assertTrue( $result->permanent );
		}
		HttpMock::assertNoRequests();
	}

	private function needs_sender( string $id, array $settings ): void {
		$provider = $this->provider( $id, $settings );
		HttpMock::reset();
		$result = $provider->send( self::IR, $this->message() );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->permanent );
		HttpMock::assertNoRequests();
	}
}
