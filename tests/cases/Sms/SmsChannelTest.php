<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Channels\AbstractProvider;
use AmirhpCom\FormsNotifier\Channels\Sms\SmsModule;
use AmirhpCom\FormsNotifier\Channels\Sms\SmsRouter;
use AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\HttpMock;

require_once __DIR__ . '/ProviderTestCase.php';

/** Scripted gateway. */
final class ScriptedProvider extends AbstractProvider implements SmsProviderInterface {

	public $sent = [];
	public $result;
	public $configured = true;
	public $pattern;
	private $id;

	public function __construct( string $id, bool $pattern ) {
		$this->id      = $id;
		$this->pattern = $pattern;
		$this->result  = SendResult::success( $id, 'ok', $id . '-1' );
	}

	protected function channel(): string { return 'sms'; }
	public function id(): string { return $this->id; }
	public function label(): string { return strtoupper( $this->id ); }
	public function fields(): array { return []; }
	public function is_configured(): bool { return $this->configured; }
	public function supports_pattern(): bool { return $this->pattern; }
	public function countries(): array { return []; }
	public function send( string $to, Message $message ): SendResult {
		$this->sent[] = [ $to, $message ];
		if ( $this->result instanceof \Throwable ) {
			throw $this->result;
		}
		return clone $this->result;
	}
}

final class SmsChannelTest extends ProviderTestCase {

	/** @var ScriptedProvider[] */
	private $p = [];

	public function setUp(): void {
		parent::setUp();
		$this->p = [
			'pa' => new ScriptedProvider( 'pa', true ),
			'pb' => new ScriptedProvider( 'pb', true ),
			'pc' => new ScriptedProvider( 'pc', false ),
		];
		$this->filter( 'fnfe_sms_providers', function ( $map ) {
			return $map + $this->p;
		} );
		Registry::reset();
	}

	public function tearDown(): void {
		parent::tearDown();
		Registry::reset();
	}

	private function route( array $settings ): void {
		$values = [];
		foreach ( $settings as $key => $value ) {
			$values[ 'channels.sms.' . $key ] = $value;
		}
		Settings::overlay( $values );
	}

	private function channel() {
		return Registry::channel( 'sms' );
	}

	// --- router ---

	public function test_router(): void {
		$this->assertSame( [], SmsRouter::chain( self::IR ) );
		$this->route( [
			'default_provider'  => 'pa',
			'default_fallbacks' => [ 'pb', '', 'missing', 'pa', 'pb' ],
			'routes'            => [
				[ 'id' => 'e_1', 'countries' => [ 'TR', 'AZ' ], 'provider' => 'pb', 'fallbacks' => [ 'pc', 'pa' ] ],
				[ 'id' => 'e_2', 'countries' => [ 'TR' ], 'provider' => 'pc', 'fallbacks' => [] ],
				[ 'id' => 'e_3', 'countries' => [ 'DE' ], 'provider' => '', 'fallbacks' => [ 'pc' ] ],
				[ 'id' => 'e_4', 'countries' => [ 'FR' ], 'provider' => '', 'fallbacks' => [] ],
				'broken',
			],
		] );
		$this->assertSame( [ 'pa', 'pb' ], SmsRouter::chain( self::IR ) );
		$this->assertSame( [ 'pb', 'pc', 'pa' ], SmsRouter::chain( '+905320000000' ) );
		$this->assertSame( [ 'pb', 'pc', 'pa' ], SmsRouter::chain( '+994500000000' ) );
		$this->assertSame( [ 'pc' ], SmsRouter::chain( '+4915100000000' ) );
		$this->assertSame( [], SmsRouter::chain( '+33600000000' ) );
		$this->assertSame( [ 'pa', 'pb' ], SmsRouter::chain( '+999000000' ) );
		$this->assertSame( [ 'pa', 'pb', 'pc' ], SmsRouter::used() );
	}

	public function test_router_filter(): void {
		$this->route( [ 'default_provider' => 'pa' ] );
		$seen = [];
		$this->filter( 'fnfe_sms_route', function ( $ids, $e164, $country, $message ) use ( &$seen ) {
			$seen = [ $ids, $e164, $country, get_class( $message ), $message->plain ];
			return [ 'pc', 'nope', 'pc', 7, 'pa' ];
		}, 10, 4 );
		$this->assertSame( [ 'pc', 'pa' ], SmsRouter::chain( self::IR, $this->message( 'x' ) ) );
		$this->assertSame( [ [ 'pa' ], self::IR, 'IR', Message::class, 'x' ], $seen );
		$this->assertSame( [ 'pc', 'pa' ], SmsRouter::chain( self::IR ) );
	}

	// --- sending ---

	public function test_no_provider(): void {
		$result = $this->channel()->send( $this->message() );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->permanent );
		$message                     = $this->message();
		$message->recipient->address = 'abc';
		$this->assertTrue( $this->channel()->send( $message )->permanent );
	}

	public function test_first_success_wins(): void {
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb' ] ] );
		$message                     = $this->message();
		$message->recipient->address = '۰۹۱۲۰۰۰۰۰۰۰';
		Settings::overlay( [ 'general.default_country' => 'IR' ] + [ 'channels.sms.default_provider' => 'pa', 'channels.sms.default_fallbacks' => [ 'pb' ] ] );
		$result = $this->channel()->send( $message );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'pa', $result->provider );
		$this->assertSame( self::IR, $this->p['pa']->sent[0][0] );
		$this->assertCount( 0, $this->p['pb']->sent );
		$this->assertSame( [ [ 'provider' => 'pa', 'ok' => true, 'pattern' => false, 'error' => '' ] ], $result->meta['chain'] );
	}

	public function test_pattern_on_primary_text_on_fallback(): void {
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb' ] ] );
		$this->p['pa']->result = SendResult::failure( 'pa', 'down' );
		$result                = $this->channel()->send( $this->message( 'normal', $this->pattern( '1', [ 'a' => 'b' ] ) ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'pb', $result->provider );
		$this->assertSame( '1', $this->p['pa']->sent[0][1]->pattern['code'] );
		$this->assertSame( [ 'a' => 'b' ], $this->p['pa']->sent[0][1]->pattern['vars'] );
		$this->assertSame( [], $this->p['pb']->sent[0][1]->pattern );
		$this->assertSame( 'normal', $this->p['pb']->sent[0][1]->plain );
		$this->assertSame( [ true, false ], array_column( $result->meta['chain'], 'pattern' ) );
	}

	public function test_fallback_with_its_own_code(): void {
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb', 'pc' ] ] );
		$this->p['pa']->result    = SendResult::failure( 'pa', 'down' );
		$pattern                  = $this->pattern( '1', [ 'x' ] );
		$pattern['codes']         = [ 'pb' => '22', 'pc' => '33' ];
		$this->assertTrue( $this->channel()->send( $this->message( '', $pattern ) )->ok );
		$this->assertSame( '22', $this->p['pb']->sent[0][1]->pattern['code'] );

		// A code of the primary provider replaces the general one; the same message is not changed for the others.
		$pattern['codes'] = [ 'pa' => '11' ];
		$message          = $this->message( 'text', $pattern );
		$this->channel()->send( $message );
		$this->assertSame( '11', $this->p['pa']->sent[1][1]->pattern['code'] );
		$this->assertSame( [], $this->p['pb']->sent[1][1]->pattern );
		$this->assertSame( '1', $message->pattern['code'] );
	}

	public function test_pattern_only_message(): void {
		$this->route( [ 'default_provider' => 'pc', 'default_fallbacks' => [ 'pb', 'pa' ] ] );
		$pattern          = $this->pattern( '1', [ 'x' ] );
		$pattern['codes'] = [ 'pc' => '9', 'pa' => '5' ];
		$result           = $this->channel()->send( $this->message( '', $pattern ) );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'pa', $result->provider );
		$this->assertCount( 0, $this->p['pc']->sent );
		$this->assertCount( 0, $this->p['pb']->sent );
		$this->assertSame( [ 'pc', 'pb', 'pa' ], array_column( $result->meta['chain'], 'provider' ) );
	}

	public function test_aggregated_error(): void {
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb', 'pc' ] ] );
		$this->p['pa']->result             = SendResult::failure( 'pa', 'bad number', true, 'raw a', 400 );
		$this->p['pb']->configured         = false;
		$this->p['pc']->result             = SendResult::failure( 'pc', 'busy', false, 'raw c', 429 );
		$this->p['pc']->result->retry_after = 30;
		$result                            = $this->channel()->send( $this->message() );
		$this->assertFalse( $result->ok );
		$this->assertFalse( $result->permanent );
		$this->assertMatches( '~^pa: bad number \| pb: .+ \| pc: busy$~', $result->error );
		$this->assertSame( 30, $result->retry_after );
		$this->assertContains( 'raw a', $result->response );
		$this->assertContains( 'raw c', $result->response );
		$this->assertCount( 3, $result->meta['chain'] );

		$this->p['pc']->result = SendResult::failure( 'pc', 'blocked', true );
		$this->assertTrue( $this->channel()->send( $this->message() )->permanent );

		$this->p['pc']->result = new \RuntimeException( 'boom' );
		$result                = $this->channel()->send( $this->message() );
		$this->assertContains( 'pc: boom', $result->error );
		$this->assertFalse( $result->permanent );
	}

	public function test_single_failure_is_passed_on(): void {
		$this->route( [ 'default_provider' => 'pa' ] );
		$this->p['pa']->result = SendResult::failure( 'pa', 'bad number', true, 'raw', 400 );
		$result                = $this->channel()->send( $this->message() );
		$this->assertSame( [ 'pa', 'bad number', true, 'raw', 400 ], [ $result->provider, $result->error, $result->permanent, $result->response, $result->http_code ] );
	}

	public function test_text_options(): void {
		$this->route( [ 'default_provider' => 'pa', 'fa_digits' => true, 'max_parts' => 1, 'append_links' => true ] );
		$message        = $this->message( 'شماره 12 https://example.com/a1 me1@example.com' );
		$message->links = [ [ 'name' => 'f', 'url' => 'https://example.com/f/9', 'size' => 1 ] ];
		$this->channel()->send( $message );
		$this->assertSame( 70, mb_strlen( $this->p['pa']->sent[0][1]->plain ) );
		$this->assertContains( 'شماره ۱۲ https://example.com/a1 me1@example.com', $this->p['pa']->sent[0][1]->plain );

		$this->route( [ 'default_provider' => 'pa', 'fa_digits' => false, 'max_parts' => 0, 'append_links' => true ] );
		$this->channel()->send( $message );
		$this->assertSame( "شماره 12 https://example.com/a1 me1@example.com\nhttps://example.com/f/9", $this->p['pa']->sent[1][1]->plain );

		$this->route( [ 'default_provider' => 'pa', 'append_links' => false ] );
		$this->channel()->send( $message );
		$this->assertNotContains( '/f/9', $this->p['pa']->sent[2][1]->plain );

		// Without a plain text the body is used, but never for a pattern.
		$message        = $this->message( '' );
		$message->body  = 'body text';
		$this->channel()->send( $message );
		$this->assertSame( 'body text', $this->p['pa']->sent[3][1]->plain );
		$message->pattern = $this->pattern( '1', [] );
		$this->channel()->send( $message );
		$this->assertSame( '', $this->p['pa']->sent[4][1]->plain );
	}

	// --- channel ---

	public function test_contract(): void {
		$channel = $this->channel();
		$this->assertSame( 'text', $channel->format() );
		$this->assertSame( 'phone', $channel->capabilities()['receiver_kind'] );
		$this->assertTrue( $channel->capabilities()['pattern'] );
		$this->assertTrue( $channel->capabilities()['submitter'] );
		$this->assertSame( '+905320000000', $channel->normalize_address( '0532 000 00 00', [ 'country' => 'TR' ] ) );
		$this->assertSame( '', $channel->normalize_address( 'x', [ 'country' => [ 'TR' ] ] ) );
	}

	public function test_readiness(): void {
		$this->route( [ 'enabled' => true ] );
		$this->assertCount( 1, $this->channel()->problems() );
		$this->assertFalse( $this->channel()->is_ready() );
		$this->p['pa']->configured = false;
		$this->route( [ 'enabled' => true, 'default_provider' => 'pa' ] );
		$this->assertCount( 1, $this->channel()->problems() );
		$this->route( [ 'enabled' => true, 'default_provider' => 'pa', 'routes' => [ [ 'id' => 'e_1', 'countries' => [ 'TR' ], 'provider' => 'pb', 'fallbacks' => [] ] ] ] );
		$this->assertTrue( $this->channel()->is_ready() );
	}

	public function test_built_in_providers(): void {
		$providers = Registry::providers( 'sms' );
		foreach ( SmsModule::provider_classes() as $id => $class ) {
			$this->assertInstanceOf( $class, $providers[ $id ] );
			$this->assertSame( $id, $providers[ $id ]->id() );
			$this->assertTrue( $providers[ $id ]->label() !== '' );
			$this->assertTrue( in_array( $providers[ $id ]->region(), [ 'iran', 'international', 'passthrough', 'any' ], true ) );
		}
	}

	public function test_schema(): void {
		Schema::flush();
		$fields = Schema::fields();
		foreach ( [ 'default_provider', 'default_fallbacks', 'routes', 'max_parts', 'fa_digits', 'append_links', 'receivers', 'template.admin', 'template.submitter', 'fallback', 'edit_provider', 'test_to', 'test_text' ] as $key ) {
			$this->assertArrayHasKey( 'channels.sms.' . $key, $fields );
		}
		foreach ( SmsModule::provider_classes() as $id => $class ) {
			foreach ( Registry::provider( 'sms', $id )->fields() as $field ) {
				if ( in_array( $field['type'], Schema::VALUE_TYPES, true ) ) {
					$key = "channels.sms.providers.$id." . $field['key'];
					$this->assertArrayHasKey( $key, $fields );
					$this->assertSame( [ 'key' => 'channels.sms.edit_provider', 'op' => 'is', 'value' => $id ], $fields[ $key ]['show_if'][0] );
					$secret = in_array( $field['key'], [ 'api_key', 'api_secret', 'password', 'auth_token', 'token' ], true ) && ! ( $id === 'vonage' && $field['key'] === 'api_key' );
					$this->assertSame( $secret, Schema::is_secret( $key ), $key );
				}
			}
		}
		// Every action of the schema exists, and every key it sends is a known field.
		$actions = $this->channel()->actions();
		foreach ( Schema::sections_for( 'sms' ) as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( $field['type'] === 'action' ) {
					$this->assertArrayHasKey( $field['action']['id'], $actions );
					foreach ( $field['action']['send'] as $key ) {
						$this->assertArrayHasKey( $key, $fields, $key );
					}
				}
			}
		}
	}

	public function test_routes_are_saved(): void {
		list( $clean, $error ) = \AmirhpCom\FormsNotifier\Core\FieldSanitizer::sanitize(
			Schema::field( 'channels.sms.routes' ),
			[ [ 'id' => 'e_1', 'countries' => [ 'TR', 'AZ' ], 'provider' => 'twilio', 'fallbacks' => [ 'vonage', 'plivo' ] ] ]
		);
		$this->assertSame( '', $error );
		$this->assertSame( [ [ 'id' => 'e_1', 'countries' => [ 'TR', 'AZ' ], 'provider' => 'twilio', 'fallbacks' => [ 'vonage', 'plivo' ] ] ], $clean );
	}

	// --- admin actions ---

	public function test_test_action(): void {
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb' ] ] );
		$actions = $this->channel()->actions();
		$out     = $actions['test_sms']( [] );
		$this->assertSame( 'warning', $out['level'] );
		$out = $actions['test_sms']( [ 'to' => 'nonsense' ] );
		$this->assertFalse( $out['ok'] );

		$this->p['pa']->result = SendResult::failure( 'pa', 'down', true );
		$out                   = $actions['test_sms']( [ 'to' => self::IR, 'text' => "pattern:7\nname:Ali\n---\nHi" ] );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( [ 'PA', 'PB' ], array_column( $out['rows'], 'provider' ) );
		$this->assertSame( 'down', $out['rows'][0]['result'] );
		$sent = $this->p['pa']->sent[0][1];
		$this->assertSame( [ '7', [ 'name' => 'Ali' ], 'Hi' ], [ $sent->pattern['code'], $sent->pattern['vars'], $sent->plain ] );
		$this->assertSame( 'test', $sent->purpose );

		// Only one gateway, from the stored test settings.
		$this->route( [ 'default_provider' => 'pa', 'default_fallbacks' => [ 'pb' ], 'test_to' => self::IR ] );
		$out = $actions['test_pc']( [] );
		$this->assertTrue( $out['ok'] );
		$this->assertTrue( $this->p['pc']->sent[0][1]->plain !== '' );
		$this->assertSame( [ 'PC' ], array_column( $out['rows'], 'provider' ) );

		$rows = \AmirhpCom\FormsNotifier\Repository\MessageRepository::query( [ 'channel' => 'sms' ] );
		$this->assertSame( 2, $rows['total'] );

		$this->assertSame( 'info', $actions['status_pa']( [] )['level'] );
		$this->p['pa']->configured = false;
		$this->assertFalse( $actions['status_pa']( [] )['ok'] );
	}

	public function test_rest_test_with_unsaved_values(): void {
		wp_set_current_user( self::admin() );
		HttpMock::expect( 'POST', '~^https://api\.twilio\.com/~', [ 'sid' => 'SM9', 'status' => 'queued' ], 201 );
		$request = new \WP_REST_Request( 'POST', '/fnfe/v1/channels/sms/action/test_twilio' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'params'  => [ 'to' => self::INTL, 'text' => 'Hi' ],
			'values'  => [ 'channels.sms.providers.twilio.account_sid' => 'ACx', 'channels.sms.providers.twilio.sender' => '+15550000000' ],
			'secrets' => [ 'channels.sms.providers.twilio.auth_token' => 'fake-token-123' ],
		] ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'], wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Hi', HttpMock::last()['form']['Body'] );
		$this->assertFalse( Settings::has_secret( 'channels.sms.providers.twilio.auth_token' ) );

		HttpMock::reset();
		HttpMock::expect( 'GET', '~/Balance\.json$~', [ 'balance' => '5', 'currency' => 'USD' ] );
		$request->set_route( '/fnfe/v1/providers/sms/twilio/status' );
		$this->assertContains( 'USD', rest_do_request( $request )->get_data()['message'] );
		wp_set_current_user( 0 );
	}

	private static function admin(): int {
		return (int) ( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 0 );
	}

	public function test_help_topics(): void {
		$topics = \AmirhpCom\FormsNotifier\Channels\WhatsApp\WhatsAppModule::help( \AmirhpCom\FormsNotifier\Channels\Sms\SmsHelp::topics( [] ) );
		$this->assertTrue( has_filter( 'fnfe_help_topics' ) !== false );
		foreach ( [ 'sms_routing', 'sms_pattern', 'sms_credentials', 'sms_custom', 'whatsapp' ] as $id ) {
			$this->assertTrue( $topics[ $id ]['title'] !== '' && $topics[ $id ]['body'] !== '', $id );
			$this->assertSame( $topics[ $id ]['body'], wp_kses_post( $topics[ $id ]['body'] ) );
		}
		foreach ( array_keys( SmsModule::provider_classes() ) as $id ) {
			if ( $id !== 'custom' ) {
				$this->assertContains( esc_html( Registry::provider( 'sms', $id )->label() ), $topics['sms_credentials']['body'] );
			}
		}
	}


	// Review B, I7 and I1.

	public function test_an_unclear_answer_is_never_sent_again_by_a_backup(): void {
		$this->provider( 'farazsms', [ 'api_key' => 'fake-key', 'sender' => '5000' ] );
		Settings::overlay( [ 'channels.sms.default_provider' => 'farazsms', 'channels.sms.default_fallbacks' => [ 'pb' ], 'general.default_country' => 'IR' ] );
		HttpMock::expect( 'POST', '~iranpayamak~', [ 'state' => 'queued', 'info' => 'done' ] );
		$result = $this->channel()->send( $this->message() );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->permanent, 'no retry either' );
		$this->assertTrue( $result->meta['ambiguous'] );
		$this->assertCount( 0, $this->p['pb']->sent );

		// A clear refusal still goes on to the backup.
		HttpMock::reset();
		HttpMock::expect( 'POST', '~iranpayamak~', [ 'status' => 'error', 'message' => 'no credit' ] );
		$this->assertTrue( $this->channel()->send( $this->message() )->ok );
		$this->assertCount( 1, $this->p['pb']->sent );

		// So does an HTML page that names an error, but not one that does not.
		HttpMock::reset();
		HttpMock::expect( 'POST', '~iranpayamak~', '<html><body>Welcome</body></html>' );
		$this->assertTrue( ! empty( $this->channel()->send( $this->message() )->meta['ambiguous'] ) );
		$this->assertCount( 1, $this->p['pb']->sent );
	}

	public function test_a_signed_file_link_is_appended_once(): void {
		$this->route( [ 'default_provider' => 'pa', 'append_links' => true ] );
		$message        = $this->message( "Files: <https://site.example.com/wp-json/fnfe/v1/file/7?t=aaa&e=100>" );
		$message->plain = str_replace( '<https://site.example.com/wp-json/fnfe/v1/file/7?t=aaa&e=100>', 'https://site.example.com/wp-json/fnfe/v1/file/7?t=aaa&e=100', $message->plain );
		$message->links = [ [ 'name' => 'cv.pdf', 'url' => 'https://site.example.com/wp-json/fnfe/v1/file/7?t=bbb&e=101', 'size' => 1 ] ];
		$this->channel()->send( $message );
		$this->assertSame( 1, substr_count( $this->p['pa']->sent[0][1]->plain, 'fnfe/v1/file/7' ) );
	}
}
