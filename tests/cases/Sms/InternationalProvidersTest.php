<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Tests\HttpMock;

require_once __DIR__ . '/ProviderTestCase.php';

final class InternationalProvidersTest extends ProviderTestCase {

	const KEY = 'fake-key-0123456789';

	private function basic( string $user, string $pass ): string {
		return 'Basic ' . base64_encode( "$user:$pass" );
	}

	public function test_twilio(): void {
		$sid  = 'ACfake0000';
		$url  = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
		$auth = $this->basic( $sid, self::KEY );
		$ok   = [ 'sid' => 'SM1', 'status' => 'queued', 'error_code' => null, 'error_message' => null ];
		$this->golden( [
			'id'       => 'twilio',
			'to'       => self::INTL,
			'settings' => [ 'account_sid' => $sid, 'auth_token' => self::KEY, 'sender' => '+15550000000' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Hello & bye', 'POST', $url, [ 'Authorization' => $auth ], [ 'form' => [ 'To' => self::INTL, 'From' => '+15550000000', 'Body' => 'Hello & bye' ] ], $ok, 'SM1', 201 ],
			'pattern'  => [
				[ $this->pattern( 'HX1', [ 'Ali', 'x' ] ), 'POST', $url, [ 'Authorization' => $auth ], [ 'form' => [ 'To' => self::INTL, 'From' => '+15550000000', 'ContentSid' => 'HX1', 'ContentVariables' => '{"1":"Ali","2":"x"}' ] ], $ok, 'SM1', 201 ],
				[ $this->pattern( 'HX1', [ 'name' => 'علی' ] ), 'POST', $url, [], [ 'form' => [ 'To' => self::INTL, 'From' => '+15550000000', 'ContentSid' => 'HX1', 'ContentVariables' => '{"name":"علی"}' ] ], $ok, 'SM1', 201 ],
				[ $this->pattern( 'HX1', [] ), 'POST', $url, [], [ 'form' => [ 'To' => self::INTL, 'From' => '+15550000000', 'ContentSid' => 'HX1', 'ContentVariables' => '{}' ] ], $ok, 'SM1', 201 ],
			],
			'failures' => [
				[ [ 'code' => 20003, 'message' => 'Authenticate', 'status' => 401 ], 401, true, '20003 Authenticate' ],
				[ [ 'code' => 21211, 'message' => 'Invalid To', 'status' => 400 ], 400, true, '21211' ],
				[ [ 'sid' => 'SM2', 'status' => 'failed', 'error_code' => 30007, 'error_message' => 'Filtered' ], 201, true, '30007 Filtered' ],
			],
			'status'   => [ 'GET', '~/Balance\.json$~', [ 'balance' => '12.5', 'currency' => 'USD' ], 'USD' ],
		] );
		$mg       = 'MG' . str_repeat( '0', 32 );
		$provider = $this->provider( 'twilio', [ 'account_sid' => $sid, 'auth_token' => self::KEY, 'sender' => $mg ] );
		HttpMock::expect( 'POST', '~.~', $ok );
		$provider->send( self::INTL, $this->message() );
		$this->assertSame( [ 'To' => self::INTL, 'MessagingServiceSid' => $mg, 'Body' => 'Hello' ], HttpMock::last()['form'] );
	}

	public function test_vonage(): void {
		$auth = $this->basic( 'fakeid', self::KEY );
		$ok   = [ 'message-count' => '1', 'messages' => [ [ 'to' => '1', 'message-id' => 'V1', 'status' => '0' ] ] ];
		$this->golden( [
			'id'       => 'vonage',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => 'fakeid', 'api_secret' => self::KEY, 'sender' => 'Shop' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [
				[ 'Hello', 'POST', 'https://rest.nexmo.com/sms/json', [ 'Authorization' => $auth ], [ 'form' => [ 'from' => 'Shop', 'to' => '15550100000', 'text' => 'Hello' ] ], $ok, 'V1' ],
				[ 'سلام', 'POST', 'https://rest.nexmo.com/sms/json', [ 'Authorization' => $auth ], [ 'form' => [ 'from' => 'Shop', 'to' => '15550100000', 'text' => 'سلام', 'type' => 'unicode' ] ], $ok, 'V1' ],
			],
			'failures' => [
				[ [ 'messages' => [ [ 'status' => '4', 'error-text' => 'Bad Credentials' ] ] ], 200, true, '4 Bad Credentials' ],
				[ [ 'messages' => [ [ 'status' => '1', 'error-text' => 'Throttled' ] ] ], 200, false, 'Throttled' ],
				[ [ 'title' => 'Unauthorized', 'detail' => 'You did not provide correct credentials.' ], 401, true, 'correct credentials' ],
			],
			'status'   => [ 'GET', '~/account/get-balance$~', [ 'value' => 3.5, 'autoReload' => false ], 'EUR' ],
		] );
	}

	public function test_plivo(): void {
		$auth = $this->basic( 'MAFAKE', self::KEY );
		$this->golden( [
			'id'       => 'plivo',
			'to'       => self::INTL,
			'settings' => [ 'auth_id' => 'MAFAKE', 'auth_token' => self::KEY, 'sender' => '15550000000' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Hello', 'POST', 'https://api.plivo.com/v1/Account/MAFAKE/Message/', [ 'Authorization' => $auth ], [ 'src' => '15550000000', 'dst' => '15550100000', 'text' => 'Hello' ], [ 'message' => 'message(s) queued', 'message_uuid' => [ 'P1' ], 'api_id' => 'x' ], 'P1', 202 ],
			'failures' => [
				[ 'Could not verify your access level for that URL.', 401, true, 'Could not verify' ],
				[ [ 'api_id' => 'x', 'error' => 'dst is invalid' ], 400, true, 'dst is invalid' ],
			],
			'status'   => [ 'GET', '~/v1/Account/MAFAKE/$~', [ 'cash_credits' => '9.25' ], 'USD' ],
		] );
	}

	public function test_bird(): void {
		$this->golden( [
			'id'       => 'bird',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'region' => 'us1', 'sender' => 'Shop' ],
			'optional' => [ 'region' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://us1.platform.bird.com/v1/sms/messages', [ 'Authorization' => 'Bearer ' . self::KEY ], [ 'to' => self::INTL, 'from' => 'Shop', 'text' => 'Hello', 'category' => 'transactional' ], [ 'id' => 'B1', 'status' => 'accepted' ], 'B1', 202 ],
			'failures' => [
				[ [ 'error' => [ 'type' => 'auth', 'code' => 'unauthorized', 'name' => 'n', 'message' => 'Invalid key' ] ], 401, true, 'Invalid key' ],
				[ [ 'error' => [ 'type' => 'x', 'code' => 'invalid_to' ] ], 422, true, 'invalid_to' ],
			],
		] );
		$provider = $this->provider( 'bird', [ 'api_key' => 'bk_eu1_fake000', 'region' => 'us1', 'sender' => 'Shop' ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~^https://eu1\.~', [ 'id' => 'B2' ], 202 );
		$this->assertTrue( $provider->send( self::INTL, $this->message() )->ok );
	}

	public function test_messagebird(): void {
		$this->golden( [
			'id'       => 'messagebird',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'sender' => 'Shop' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://rest.messagebird.com/messages', [ 'Authorization' => 'AccessKey ' . self::KEY ], [ 'form' => [ 'originator' => 'Shop', 'recipients' => '15550100000', 'body' => 'Hello', 'datacoding' => 'auto' ] ], [ 'id' => 'MB1', 'recipients' => [ 'items' => [] ] ], 'MB1', 201 ],
			'failures' => [
				[ [ 'errors' => [ [ 'code' => 2, 'description' => 'Request not allowed', 'parameter' => 'access_key' ] ] ], 401, true, 'Request not allowed' ],
				[ [ 'errors' => [ [ 'code' => 9, 'description' => 'no (correct) recipients found' ] ] ], 422, true, 'recipients' ],
			],
			'status'   => [ 'GET', '~/balance$~', [ 'payment' => 'prepaid', 'type' => 'credits', 'amount' => 25 ], '25 credits' ],
		] );
	}

	public function test_clicksend(): void {
		$auth = $this->basic( 'fakeuser', self::KEY );
		$this->golden( [
			'id'       => 'clicksend',
			'to'       => self::INTL,
			'settings' => [ 'username' => 'fakeuser', 'api_key' => self::KEY, 'sender' => 'Shop' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Hello', 'POST', 'https://rest.clicksend.com/v3/sms/send', [ 'Authorization' => $auth ], [ 'messages' => [ [ 'source' => 'wp', 'to' => self::INTL, 'body' => 'Hello', 'from' => 'Shop' ] ] ], [ 'http_code' => 200, 'response_code' => 'SUCCESS', 'data' => [ 'messages' => [ [ 'status' => 'SUCCESS', 'message_id' => 'C1' ] ] ] ], 'C1' ],
			'failures' => [
				[ [ 'http_code' => 401, 'response_code' => 'UNAUTHORIZED', 'response_msg' => 'Authorization failed.' ], 401, true, 'Authorization failed.' ],
				[ [ 'response_code' => 'SUCCESS', 'data' => [ 'messages' => [ [ 'status' => 'INVALID_RECIPIENT' ] ] ] ], 200, true, 'INVALID_RECIPIENT' ],
				[ [ 'response_code' => 'SUCCESS', 'data' => [ 'messages' => [ [ 'status' => 'INSUFFICIENT_CREDIT' ] ] ] ], 200, false, 'INSUFFICIENT_CREDIT' ],
				[ [ 'response_code' => 'SUCCESS', 'data' => [ 'messages' => [] ] ], 200, true, '' ],
			],
			'status'   => [ 'GET', '~/v3/account$~', [ 'data' => [ 'balance' => '7', '_currency' => [ 'currency_name_short' => 'AUD' ] ] ], '7 AUD' ],
		] );
	}

	public function test_infobip(): void {
		$ok = [ 'bulkId' => 'b', 'messages' => [ [ 'messageId' => 'I1', 'status' => [ 'groupId' => 1, 'groupName' => 'PENDING' ] ] ] ];
		$this->golden( [
			'id'       => 'infobip',
			'to'       => self::INTL,
			'settings' => [ 'base' => 'https://xxxxx.api.infobip.com/', 'api_key' => self::KEY, 'sender' => 'Shop' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://xxxxx.api.infobip.com/sms/3/messages', [ 'Authorization' => 'App ' . self::KEY ], [ 'messages' => [ [ 'sender' => 'Shop', 'destinations' => [ [ 'to' => '15550100000' ] ], 'content' => [ 'text' => 'Hello' ] ] ] ], $ok, 'I1' ],
			'failures' => [
				[ [ 'errorCode' => 'E401', 'description' => 'Invalid credentials', 'action' => 'x', 'violations' => [] ], 401, true, 'Invalid credentials' ],
				[ [ 'messages' => [ [ 'messageId' => 'I2', 'status' => [ 'groupName' => 'REJECTED', 'description' => 'No route' ] ] ] ], 200, true, 'REJECTED No route' ],
			],
			'status'   => [ 'GET', '~/account/1/balance$~', [ 'balance' => 4, 'currency' => 'EUR' ], '4 EUR' ],
		] );
	}

	public function test_telnyx(): void {
		$this->golden( [
			'id'       => 'telnyx',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'sender' => '+15550000000' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://api.telnyx.com/v2/messages', [ 'Authorization' => 'Bearer ' . self::KEY ], [ 'from' => '+15550000000', 'to' => self::INTL, 'text' => 'Hello' ], [ 'data' => [ 'id' => 'T1' ] ], 'T1' ],
			'failures' => [
				[ [ 'errors' => [ [ 'code' => '10009', 'title' => 'Authentication failed', 'detail' => 'No key found' ] ] ], 401, true, '10009 No key found' ],
				[ [ 'errors' => [ [ 'code' => '40310', 'title' => 'Invalid to' ] ] ], 400, true, '40310 Invalid to' ],
			],
			'status'   => [ 'GET', '~/v2/balance$~', [ 'data' => [ 'balance' => '30', 'currency' => 'USD' ] ], '30 USD' ],
		] );
		$profile  = '00000000-0000-0000-0000-000000000000';
		$provider = $this->provider( 'telnyx', [ 'api_key' => self::KEY, 'sender' => $profile ] );
		HttpMock::expect( 'POST', '~.~', [ 'data' => [ 'id' => 'T1' ] ] );
		$provider->send( self::INTL, $this->message() );
		$this->assertSame( [ 'messaging_profile_id' => $profile, 'to' => self::INTL, 'text' => 'Hello' ], HttpMock::last()['json'] );
	}

	public function test_brevo(): void {
		$url = 'https://api.brevo.com/v3/transactionalSMS/sms';
		$ok  = [ 'messageId' => 1511882900176220, 'reference' => 'r', 'smsCount' => 1 ];
		$this->golden( [
			'id'       => 'brevo',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'sender' => 'Shop' ],
			'secrets'  => [ self::KEY ],
			'text'     => [
				[ 'Hello', 'POST', $url, [ 'api-key' => self::KEY ], [ 'sender' => 'Shop', 'recipient' => '15550100000', 'type' => 'transactional', 'content' => 'Hello', 'unicodeEnabled' => false ], $ok, '1511882900176220', 201 ],
				[ 'سلام', 'POST', $url, [], [ 'sender' => 'Shop', 'recipient' => '15550100000', 'type' => 'transactional', 'content' => 'سلام', 'unicodeEnabled' => true ], $ok, '1511882900176220', 201 ],
			],
			'pattern'  => [ $this->pattern( '123', [ 'NAME' => 'Ali' ] ), 'POST', $url, [], [ 'sender' => 'Shop', 'recipient' => '15550100000', 'type' => 'transactional', 'templateId' => 123, 'params' => [ 'NAME' => 'Ali' ], 'unicodeEnabled' => true ], $ok, '1511882900176220', 201 ],
			'failures' => [
				[ [ 'code' => 'unauthorized', 'message' => 'Key not found' ], 401, true, 'Key not found' ],
				[ [ 'code' => 'invalid_parameter', 'message' => 'Invalid telephone number' ], 400, true, 'Invalid telephone number' ],
				[ [ 'code' => 'not_enough_credits', 'message' => 'No credits' ], 402, false, 'No credits' ],
			],
		] );
	}

	public function test_smsto(): void {
		$this->golden( [
			'id'       => 'smsto',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'sender' => 'Shop' ],
			'optional' => [ 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://api.sms.to/sms/send', [ 'Authorization' => 'Bearer ' . self::KEY ], [ 'message' => 'Hello', 'to' => self::INTL, 'sender_id' => 'Shop' ], [ 'success' => true, 'message' => 'Message is queued', 'message_id' => 'S1' ], 'S1' ],
			'failures' => [
				[ [ 'success' => false, 'message' => 'Invalid API key', 'data' => [] ], 401, true, 'Invalid API key' ],
				[ [ 'success' => false, 'message' => 'Invalid number', 'data' => [] ], 200, true, 'Invalid number' ],
			],
			'status'   => [ 'GET', '~^https://auth\.sms\.to/api/balance$~', [ 'balance' => 6, 'currency' => 'EUR' ], '6 EUR' ],
		] );
	}

	public function test_elks(): void {
		$auth = $this->basic( 'fakeuser', self::KEY );
		$this->golden( [
			'id'       => 'elks',
			'to'       => self::INTL,
			'settings' => [ 'username' => 'fakeuser', 'password' => self::KEY, 'sender' => 'Shop' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Hello', 'POST', 'https://api.46elks.com/a1/sms', [ 'Authorization' => $auth ], [ 'form' => [ 'from' => 'Shop', 'to' => self::INTL, 'message' => 'Hello' ] ], [ 'id' => 's1', 'status' => 'created', 'cost' => 3500 ], 's1' ],
			'failures' => [
				[ 'API access requires Basic HTTP authentication', 401, true, 'requires Basic' ],
				[ 'Invalid to number', 400, true, 'Invalid to number' ],
				[ [ 'id' => 's2', 'status' => 'failed' ], 200, true, 'failed' ],
			],
			'status'   => [ 'GET', '~/a1/me$~', [ 'balance' => 50000, 'currency' => 'SEK' ], '5 SEK' ],
		] );
	}

	public function test_smsapi(): void {
		$this->golden( [
			'id'       => 'smsapi',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY, 'host' => 'api.smsapi.pl', 'sender' => 'Shop' ],
			'optional' => [ 'host', 'sender' ],
			'secrets'  => [ self::KEY ],
			'text'     => [ 'Hello', 'POST', 'https://api.smsapi.pl/sms.do', [ 'Authorization' => 'Bearer ' . self::KEY ], [ 'form' => [ 'to' => '15550100000', 'message' => 'Hello', 'encoding' => 'utf-8', 'format' => 'json', 'from' => 'Shop' ] ], [ 'count' => 1, 'list' => [ [ 'id' => 'A1', 'status' => 'QUEUE' ] ] ], 'A1' ],
			'failures' => [
				[ [ 'error' => 101, 'message' => 'Authorization failed' ], 200, true, '101 Authorization failed' ],
				[ [ 'error' => 13, 'message' => 'No correct phone numbers' ], 200, true, '13 No correct' ],
				[ [ 'count' => 0, 'list' => [] ], 200, true, '' ],
			],
			'status'   => [ 'GET', '~/profile$~', [ 'points' => 11 ], '11' ],
		] );
		$provider = $this->provider( 'smsapi', [ 'api_key' => self::KEY, 'host' => 'evil.example.com' ] );
		HttpMock::expect( 'POST', '~^https://api\.smsapi\.com/~', [ 'list' => [ [ 'id' => 'A2' ] ] ] );
		$this->assertTrue( $provider->send( self::INTL, $this->message() )->ok );
	}

	public function test_msg91(): void {
		$url = 'https://control.msg91.com/api/v5/flow';
		$ok  = [ 'type' => 'success', 'message' => 'M1' ];
		$this->golden( [
			'id'       => 'msg91',
			'to'       => self::INTL,
			'settings' => [ 'api_key' => self::KEY ],
			'secrets'  => [ self::KEY ],
			'pattern'  => [
				[ $this->pattern( 'T1', [ 'Name' => 'Ali', 'mobiles' => '999' ] ), 'POST', $url, [ 'authkey' => self::KEY ], [ 'template_id' => 'T1', 'short_url' => '0', 'recipients' => [ [ 'mobiles' => '15550100000', 'Name' => 'Ali' ] ] ], $ok, 'M1' ],
				[ $this->pattern( 'T1', [ 1 => 'b', 0 => 'a' ] ), 'POST', $url, [], [ 'template_id' => 'T1', 'short_url' => '0', 'recipients' => [ [ 'mobiles' => '15550100000', 'VAR1' => 'a', 'VAR2' => 'b' ] ] ], $ok, 'M1' ],
			],
			'failures' => [
				[ [ 'type' => 'error', 'message' => 'Invalid authkey' ], 200, true, 'Invalid authkey' ],
				[ [ 'type' => 'error', 'message' => 'Template not found' ], 400, true, 'Template not found' ],
			],
		] );
		$provider = $this->provider( 'msg91', [ 'api_key' => self::KEY ] );
		HttpMock::reset();
		$result = $provider->send( self::INTL, $this->message() );
		$this->assertTrue( ! $result->ok && $result->permanent );
		HttpMock::assertNoRequests();
	}

	public function test_netgsm(): void {
		$auth = $this->basic( 'fakeuser', self::KEY );
		$this->golden( [
			'id'       => 'netgsm',
			'to'       => '+905320000000',
			'settings' => [ 'username' => 'fakeuser', 'password' => self::KEY, 'sender' => 'SHOP' ],
			'secrets'  => [ self::KEY, substr( $auth, 6 ) ],
			'text'     => [ 'Merhaba', 'POST', 'https://api.netgsm.com.tr/sms/rest/v2/send', [ 'Authorization' => $auth ], [ 'msgheader' => 'SHOP', 'encoding' => 'TR', 'messages' => [ [ 'msg' => 'Merhaba', 'no' => '5320000000' ] ] ], [ 'code' => '00', 'jobid' => 'N1' ], 'N1' ],
			'failures' => [
				[ [ 'code' => '30', 'description' => 'credentials' ], 406, true, '30 credentials' ],
				[ [ 'code' => '40', 'description' => 'header' ], 200, true, '40 header' ],
			],
		] );
		$provider = $this->provider( 'netgsm', [ 'username' => 'fakeuser', 'password' => self::KEY, 'sender' => 'SHOP' ] );
		HttpMock::reset();
		$this->assertTrue( $provider->send( self::INTL, $this->message() )->permanent );
		HttpMock::assertNoRequests();
	}
}
