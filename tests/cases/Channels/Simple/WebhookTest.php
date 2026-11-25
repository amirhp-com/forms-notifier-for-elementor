<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Channels\Simple;

use AmirhpCom\FormsNotifier\Channels\Webhook\Presets;
use AmirhpCom\FormsNotifier\Channels\Webhook\Signer;
use AmirhpCom\FormsNotifier\Channels\Webhook\WebhookChannel;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class WebhookTest extends TestCase {

	const URL = 'https://hook.example.com/in/abc';

	public function tearDown(): void {
		Settings::clear_overlay();
		wp_set_current_user( 0 );
	}

	private function endpoint( array $overrides = [], array $secrets = [] ): void {
		Settings::overlay(
			[
				'channels.webhook.endpoints' => [
					array_merge(
						[ 'id' => 'e_1', 'name' => 'Main', 'enabled' => true, 'preset' => 'custom', 'url' => self::URL, 'method' => 'POST', 'format' => 'json', 'body_template' => '', 'headers' => [], 'auth' => 'none', 'auth_user' => '', 'timeout' => 20 ],
						$overrides
					),
				],
			],
			$secrets
		);
	}

	private function message( string $purpose = 'admin' ): Message {
		$delivery           = new Delivery( 'webhook', new Recipient( 'webhook', 'e_1' ), $purpose );
		$delivery->template = 'Plain {form_name}';
		$delivery->lang     = 'en';
		$s                  = Fixtures::submission( [ 'id' => 981, 'uuid' => str_repeat( 'ab', 16 ), 'form_id' => 12 ] );
		$s->fields['message']['value'] = "Line \"one\"\nسلام \\ {site_url}";
		return Dispatcher::build( $delivery, $s, Fixtures::config() );
	}

	private function send( ?Message $m = null ) {
		return ( new WebhookChannel() )->send( $m ?? $this->message() );
	}

	public function test_standard_json(): void {
		$this->endpoint();
		$this->freeze( strtotime( '2026-01-01 10:00:05 UTC' ) );
		HttpMock::expect( 'POST', '~^https://hook\.example\.com/in/abc$~', 'ok' );
		$m      = $this->message();
		$result = $this->send( $m );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'ok', $result->response );
		$json = HttpMock::last()['json'];
		$this->assertSame( [ 'event', 'delivery', 'sent_at', 'site', 'form', 'submission', 'fields', 'fields_meta', 'files', 'text' ], array_keys( $json ) );
		$this->assertSame( 'submission.created', $json['event'] );
		$this->assertMatches( '/^[a-f0-9]{32}$/', $json['delivery'] );
		$this->assertSame( '2026-01-01T10:00:05Z', $json['sent_at'] );
		$this->assertSame( [ 'name', 'url' ], array_keys( $json['site'] ) );
		$this->assertSame( [ 'id' => 12, 'key' => '45:a1b2c3d', 'name' => 'Contact', 'source' => 'pro' ], $json['form'] );
		$this->assertSame( [ 'id', 'uuid', 'status', 'lang', 'created_at', 'page_url', 'page_title', 'user_id', 'user_ip', 'admin_url' ], array_keys( $json['submission'] ) );
		$this->assertSame( '2026-01-01T10:00:00Z', $json['submission']['created_at'] );
		$this->assertSame( [ 'sales', 'support' ], $json['fields']['topics'] );
		$this->assertSame( Fixtures::EMAIL, $json['fields']['email'] );
		$this->assertSame( [ 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'سارا 🙂' ], $json['fields_meta'][0] );
		$this->assertSame( [], $json['files'] );
		$this->assertSame( 'Plain Contact', $json['text'] );

		// The same delivery id on every attempt, also after a round trip through the queue.
		$again = Message::from_array( json_decode( wp_json_encode( $m->to_array() ), true ) );
		$this->send( $again );
		$this->assertSame( $json['delivery'], HttpMock::last()['json']['delivery'] );
		$this->assertSame( $json['delivery'], HttpMock::last()['headers']['x-fnfe-delivery'] );
	}

	public function test_empty_fields_are_an_object(): void {
		$this->endpoint();
		HttpMock::expect( 'POST', '~.~', 'ok' );
		$m                 = $this->message();
		$m->data['fields'] = [];
		$this->send( $m );
		$this->assertContains( '"fields":{}', HttpMock::last()['body'] );
	}

	public function test_presets(): void {
		HttpMock::expect( 'POST', '~.~', 'ok' );
		foreach ( [ 'n8n', 'make' ] as $preset ) {
			$this->endpoint( [ 'preset' => $preset ] );
			$this->send();
			$this->assertArrayHasKey( 'fields_meta', HttpMock::last()['json'] );
		}
		foreach ( [ 'zapier', 'pabbly' ] as $preset ) {
			$this->endpoint( [ 'preset' => $preset ] );
			$this->send();
			$flat = HttpMock::last()['json'];
			$this->assertSame( [ 'event', 'form_name', 'form_key', 'submission_id', 'page_url', 'created_at', 'text', 'field_name', 'field_email', 'field_phone', 'field_topics', 'field_message', 'field_empty' ], array_keys( $flat ) );
			$this->assertSame( 'sales, support', $flat['field_topics'] );
			$this->assertSame( 981, $flat['submission_id'] );
		}
		$this->endpoint( [ 'preset' => 'ifttt' ] );
		$this->send();
		$this->assertSame( [ 'value1', 'value2', 'value3' ], array_keys( HttpMock::last()['json'] ) );
		$this->assertSame( 'Contact', HttpMock::last()['json']['value1'] );
		$this->assertContains( 'Email: sara@example.com', HttpMock::last()['json']['value2'] );
		$this->assertSame( 'https://example.com/contact/', HttpMock::last()['json']['value3'] );
		$this->assertSame( [ 'custom', 'n8n', 'make', 'zapier', 'pabbly', 'ifttt' ], array_keys( Presets::all() ) );
	}

	public function test_file_urls(): void {
		$this->endpoint( [ 'preset' => 'zapier' ] );
		HttpMock::expect( 'POST', '~.~', 'ok' );
		$m                = $this->message();
		$m->data['files'] = [ [ 'field' => 'cv', 'name' => 'cv.pdf', 'size' => 3, 'mime' => 'application/pdf', 'url' => 'https://example.com/f' ] ];
		$this->send( $m );
		$this->assertSame( 'https://example.com/f', HttpMock::last()['json']['file_1_url'] );
	}

	public function test_form_template_and_get(): void {
		HttpMock::expect( '*', '~.~', 'ok' );
		$this->endpoint( [ 'format' => 'form' ] );
		$this->send();
		$this->assertSame( 'application/x-www-form-urlencoded; charset=utf-8', HttpMock::last()['headers']['content-type'] );
		$this->assertSame( Fixtures::EMAIL, HttpMock::last()['form']['field_email'] );

		$this->endpoint( [ 'format' => 'template', 'body_template' => "{ \"n\": \"{field:name}\", \"m\": \"{field:message}\", \"id\": {submission_id}, \"all\": \"{all_fields:only=email}\", \"x\": {\"y\": \"<b>\"} }" ] );
		$this->send();
		$this->assertSame( [ 'n' => 'سارا 🙂', 'm' => "Line \"one\"\nسلام \\ {site_url}", 'id' => 981, 'all' => 'Email: sara@example.com', 'x' => [ 'y' => '<b>' ] ], HttpMock::last()['json'] );
		$this->assertSame( 'application/json; charset=utf-8', HttpMock::last()['headers']['content-type'] );

		$this->endpoint( [ 'format' => 'template', 'body_template' => 'Hello {field:message} <b>' ] );
		$this->send();
		$this->assertSame( "Hello Line \"one\"\nسلام \\ {site_url} <b>", HttpMock::last()['body'] );
		$this->assertSame( 'text/plain; charset=utf-8', HttpMock::last()['headers']['content-type'] );

		$this->endpoint( [ 'method' => 'GET', 'url' => self::URL . '?a=1' ] );
		$this->send();
		$last = HttpMock::last();
		$this->assertSame( 'GET', $last['method'] );
		$this->assertSame( '', $last['body'] );
		parse_str( wp_parse_url( $last['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( '1', $query['a'] );
		$this->assertSame( Fixtures::EMAIL, $query['field_email'] );
		foreach ( [ 'PUT', 'PATCH' ] as $method ) {
			$this->endpoint( [ 'method' => $method ] );
			$this->send();
			$this->assertSame( $method, HttpMock::last()['method'] );
		}
	}

	public function test_headers_auth_and_signature(): void {
		HttpMock::expect( 'POST', '~.~', 'ok' );
		$this->freeze( 1767261605 );
		$this->endpoint(
			[ 'headers' => [ 'X-Custom' => "a\r\nX-Evil: 1", 'x-fnfe-event' => 'fake', 'Host' => 'evil.example', 'content-length' => '1', 'Bad Name' => 'x', 'content-type' => 'application/vnd.x+json' ], 'auth' => 'basic', 'auth_user' => 'u' ],
			[ 'channels.webhook.endpoints.e_1.auth_secret' => 'p-secret', 'channels.webhook.endpoints.e_1.sign_secret' => 'sign-me' ]
		);
		$this->send();
		$last = HttpMock::last();
		$h    = $last['headers'];
		$this->assertSame( 'a X-Evil: 1', $h['x-custom'] );
		$this->assertSame( 'submission.created', $h['x-fnfe-event'] );
		$this->assertFalse( isset( $h['host'] ) );
		$this->assertFalse( isset( $h['content-length'] ) );
		$this->assertFalse( isset( $h['bad name'] ) );
		$this->assertSame( 'application/vnd.x+json', $h['content-type'] );
		$this->assertSame( 'Basic ' . base64_encode( 'u:p-secret' ), $h['authorization'] );
		$this->assertSame( 'FormsNotifier/' . FNFE_VERSION, $h['user-agent'] );
		$this->assertSame( '1767261605', $h['x-fnfe-timestamp'] );
		// Independent computation.
		$mac = hash( 'sha256', ( str_pad( 'sign-me', 64, "\0" ) ^ str_repeat( "\x5c", 64 ) ) . hash( 'sha256', ( str_pad( 'sign-me', 64, "\0" ) ^ str_repeat( "\x36", 64 ) ) . '1767261605.' . $last['body'], true ) );
		$this->assertSame( 'v1=' . $mac, $h['x-fnfe-signature'] );
		$this->assertTrue( Signer::verify( 'sign-me', 1767261605, $last['body'], $h['x-fnfe-signature'] ) );
		$this->assertFalse( Signer::verify( 'sign-me', 1767261606, $last['body'], $h['x-fnfe-signature'] ) );
		$this->assertFalse( Signer::verify( '', 1767261605, $last['body'], $h['x-fnfe-signature'] ) );

		$this->endpoint( [ 'auth' => 'bearer' ], [ 'channels.webhook.endpoints.e_1.auth_secret' => 'tok', 'channels.webhook.endpoints.e_1.sign_secret' => '' ] );
		$this->send();
		$this->assertSame( 'Bearer tok', HttpMock::last()['headers']['authorization'] );
		$this->assertFalse( isset( HttpMock::last()['headers']['x-fnfe-signature'] ) );
		$this->endpoint( [ 'auth' => 'header', 'auth_user' => 'X-Api-Key' ], [ 'channels.webhook.endpoints.e_1.auth_secret' => 'k' ] );
		$this->send();
		$this->assertSame( 'k', HttpMock::last()['headers']['x-api-key'] );
		$this->endpoint( [ 'auth' => 'header', 'auth_user' => 'X-FNFE-Delivery' ] );
		$this->send();
		$this->assertTrue( HttpMock::last()['headers']['x-fnfe-delivery'] !== 'k' );

		$this->filter( 'fnfe_webhook_headers', static function ( array $headers ) { return $headers + [ 'X-More' => '1', 'X-FNFE-Event' => 'fake' ]; } );
		$this->filter( 'fnfe_webhook_payload', static function ( array $payload, Message $m, array $endpoint ) { return [ 'mine' => $endpoint['id'] ]; }, 10, 3 );
		$this->send();
		$this->assertSame( '1', HttpMock::last()['headers']['x-more'] );
		$this->assertTrue( HttpMock::last()['headers']['x-fnfe-event'] !== 'fake' );
		$this->assertSame( [ 'mine' => 'e_1' ], HttpMock::last()['json'] );
	}

	public function test_status_classification(): void {
		$this->endpoint();
		$this->assertTrue( HttpMock::last() === null );
		$cases = [ 200 => [ true, false ], 202 => [ true, false ], 204 => [ true, false ], 301 => [ false, true ], 302 => [ false, true ], 400 => [ false, true ], 401 => [ false, true ], 404 => [ false, true ], 408 => [ false, false ], 410 => [ false, true ], 425 => [ false, false ], 429 => [ false, false ], 500 => [ false, false ], 503 => [ false, false ] ];
		foreach ( $cases as $code => $expected ) {
			HttpMock::reset();
			HttpMock::expect( 'POST', '~.~', str_repeat( 'b', 900 ), $code, $code === 429 ? [ 'retry-after' => '7' ] : [] );
			$result = $this->send();
			$this->assertSame( $expected, [ $result->ok, $result->permanent ], (string) $code );
			$this->assertSame( $code, $result->http_code );
			$this->assertTrue( mb_strlen( $result->response ) <= 500 );
			$this->assertSame( $code === 429 ? 7 : 0, $result->retry_after );
			$this->assertSame( 0, HttpMock::last()['args']['redirection'] );
			if ( $code === 410 ) {
				$this->assertContains( '410', $result->error );
			}
		}
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', new \WP_Error( 'http', 'timeout' ) );
		$result = $this->send();
		$this->assertSame( [ false, false, 0 ], [ $result->ok, $result->permanent, $result->http_code ] );
	}

	public function test_private_hosts(): void {
		foreach ( [ 'http://localhost/x', 'http://127.0.0.1/x', 'http://10.0.0.5:5678/x', 'http://192.168.1.2/x', 'http://169.254.169.254/latest', 'http://[::1]/x', 'https://n8n.local/x', 'http://0.0.0.0/' ] as $url ) {
			$this->endpoint( [ 'url' => $url ] );
			$result = $this->send();
			$this->assertTrue( $result->permanent, $url );
			$this->assertFalse( $result->ok );
		}
		HttpMock::assertNoRequests();
		$this->endpoint( [ 'url' => 'http://10.0.0.5:5678/x' ] );
		Settings::overlay( [ 'advanced.allow_private_hosts' => true ] );
		HttpMock::expect( 'POST', '~10\.0\.0\.5~', 'ok' );
		$this->assertTrue( $this->send()->ok );
		$this->assertTrue( empty( HttpMock::last()['args']['reject_unsafe_urls'] ) );
		Settings::clear_overlay();
		$this->endpoint();
		HttpMock::expect( 'POST', '~hook~', 'ok' );
		$this->send();
		$this->assertTrue( HttpMock::last()['args']['reject_unsafe_urls'] );
		foreach ( [ 'ftp://example.com/x', 'file:///etc/passwd', 'gopher://x', '' ] as $url ) {
			$this->endpoint( [ 'url' => $url ] );
			$this->assertTrue( $this->send()->permanent );
		}
	}

	public function test_other_events(): void {
		$this->endpoint();
		HttpMock::expect( 'POST', '~.~', 'ok' );
		$m          = new Message( 'webhook', new Recipient( 'webhook', 'e_1' ) );
		$m->purpose = 'alert';
		$m->plain   = 'Delivery failed';
		$m->data    = [ 'event' => 'alert', 'alert' => [ 'channel' => 'telegram', 'error' => 'x' ] ];
		$this->send( $m );
		$json = HttpMock::last()['json'];
		$this->assertSame( [ 'event', 'delivery', 'sent_at', 'site', 'alert', 'text' ], array_keys( $json ) );
		$this->assertSame( 'alert', HttpMock::last()['headers']['x-fnfe-event'] );
		$m->purpose = 'digest';
		$m->data    = [];
		$this->send( $m );
		$this->assertSame( 'digest', HttpMock::last()['json']['event'] );
	}

	public function test_endpoint_problems_and_picker(): void {
		$channel = new WebhookChannel();
		$this->assertCount( 1, $channel->problems() );
		$this->endpoint();
		$this->assertSame( [], $channel->problems() );
		$this->assertSame( 'e_1', $channel->normalize_address( ' e_1 ' ) );
		$this->assertSame( '', $channel->normalize_address( 'https://example.com' ) );
		$rows = $channel->receiver_options( [ 'search' => 'main' ] )['rows'];
		$this->assertSame( 'e_1', $rows[0]['address'] );
		$this->assertKeys( [ 'address', 'label', 'group', 'hint', 'meta' ], $rows[0] );
		$this->assertSame( [], $channel->receiver_options( [ 'search' => 'zzz' ] )['rows'] );
		$this->endpoint( [ 'enabled' => false ] );
		$this->assertTrue( $this->send()->permanent );
		$m                     = $this->message();
		$m->recipient->address = 'e_gone';
		$this->assertTrue( $this->send( $m )->permanent );
		HttpMock::assertNoRequests();
	}

	public function test_settings_round_trip_and_test_route(): void {
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$save = new \WP_REST_Request( 'POST', '/fnfe/v1/settings' );
		$save->set_header( 'content-type', 'application/json' );
		$save->set_body( wp_json_encode( [
			'values'  => [ 'channels.webhook.enabled' => true, 'channels.webhook.endpoints' => [ [ 'id' => 'e_9', 'name' => 'N', 'url' => self::URL, 'headers' => [ 'A' => 'b' ] ] ], 'channels.webhook.receivers' => [ [ 'id' => 'r_1', 'address' => 'e_9' ] ] ],
			'secrets' => [ 'channels.webhook.endpoints.e_9.sign_secret' => 'topsecretvalue' ],
		] ) );
		$data = rest_do_request( $save )->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertTrue( empty( (array) $data['errors'] ), wp_json_encode( $data['errors'] ) );
		$this->assertNotContains( 'topsecretvalue', wp_json_encode( $data ) );
		$this->assertTrue( Registry::channel( 'webhook' )->is_ready() );
		HttpMock::expect( 'POST', '~.~', 'ok' );
		$test = new \WP_REST_Request( 'POST', '/fnfe/v1/channels/webhook/test' );
		$this->assertTrue( rest_do_request( $test )->get_data()['ok'] );
		$json = HttpMock::last()['json'];
		$this->assertSame( 'test', $json['event'] );
		$this->assertTrue( count( $json['fields'] ) >= 4 );
		$this->assertTrue( isset( HttpMock::last()['headers']['x-fnfe-signature'] ) );
	}
}
