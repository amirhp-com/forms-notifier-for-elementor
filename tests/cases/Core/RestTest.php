<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Rest\Controller;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class RestTest extends TestCase {

	private static $admin = 0;
	private static $subscriber = 0;

	public static function setUpClass(): void {
		$ids              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		self::$admin      = (int) $ids[0];
		self::$subscriber = (int) wp_insert_user( [ 'user_login' => 'fnfe_test_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
	}

	public static function tearDownClass(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( self::$subscriber );
		wp_set_current_user( 0 );
	}

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		FakeChannel::install();
		wp_set_current_user( self::$admin );
	}

	private function call( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/fnfe/v1' . $path );
		if ( $method === 'GET' || $method === 'DELETE' ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}

	private function data( \WP_REST_Response $response ): array {
		return json_decode( wp_json_encode( rest_get_server()->response_to_data( $response, false ) ), true );
	}

	public function test_bootstrap_shape(): void {
		$r = $this->call( 'GET', '/bootstrap' );
		$this->assertSame( 200, $r->get_status() );
		$d = $this->data( $r );
		$this->assertKeys( [ 'version', 'rest', 'urls', 'user', 'site', 'i18n', 'channels', 'groups', 'tabs', 'sources', 'features', 'options', 'fonts', 'routes' ], $d );
		$this->assertKeys( [ 'root', 'nonce' ], $d['rest'] );
		$this->assertKeys( [ 'admin', 'assets', 'help', 'elementor_submissions' ], $d['urls'] );
		$this->assertKeys( [ 'id', 'name', 'locale', 'caps', 'ui' ], $d['user'] );
		$this->assertSame( [ 'manage' => true, 'forms' => true, 'submissions' => true, 'logs' => true ], $d['user']['caps'] );
		$this->assertKeys( [ 'name', 'url', 'lang', 'locale', 'rtl', 'timezone', 'calendar' ], $d['site'] );
		$this->assertKeys( [ 'provider', 'default', 'languages' ], $d['i18n'] );
		$this->assertKeys( [ 'code', 'name', 'locale', 'rtl', 'default' ], $d['i18n']['languages'][0] );
		$this->assertKeys( [ 'messenger', 'sms', 'email', 'automation', 'chat', 'push' ], $d['groups'] );
		$this->assertKeys( [ 'general', 'attachments', 'queue', 'alerts', 'digest', 'miniapp', 'privacy', 'advanced' ], $d['tabs'] );
		$this->assertKeys( [ 'elementor_pro', 'atomic_forms', 'action_scheduler', 'pretty_permalinks', 'https', 'miniapp' ], $d['features'] );
		$this->assertKeys( [ 'countries', 'sms_providers', 'whatsapp_providers', 'roles' ], $d['options'] );
		$this->assertKeys( [ 'value', 'label' ], $d['options']['countries'][0] );
		$this->assertSame( [ 'dashboard', 'channels', 'forms', 'submissions', 'log', 'settings', 'help' ], array_column( $d['routes'], 'path' ) );
		$this->assertKeys( [ 'path', 'label', 'icon', 'cap' ], $d['routes'][0] );
		$this->assertContains( 'fake', array_column( $d['channels'], 'id' ) );
	}

	public function test_bootstrap_ui(): void {
		$before = get_user_meta( self::$admin, 'fnfe_ui', true );
		$r = $this->call( 'POST', '/bootstrap/ui', [ 'theme' => 'dark', 'dismissed' => [ 'a', 'a', 'B!' ] ] );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( [ 'theme' => 'dark', 'dismissed' => [ 'a', 'b' ] ], array_intersect_key( $this->data( $r )['ui'], [ 'theme' => 1, 'dismissed' => 1 ] ) );
		$this->assertSame( 'dark', $this->data( $this->call( 'GET', '/bootstrap' ) )['user']['ui']['theme'] );
		$this->assertSame( 400, $this->call( 'POST', '/bootstrap/ui', [ 'theme' => 'pink' ] )->get_status() );
		$before ? update_user_meta( self::$admin, 'fnfe_ui', $before ) : delete_user_meta( self::$admin, 'fnfe_ui' );
	}

	public function test_schema(): void {
		$d = $this->data( $this->call( 'GET', '/schema', [ 'tab' => 'queue' ] ) );
		$this->assertKeys( [ 'tabs', 'sections' ], $d );
		foreach ( $d['sections'] as $section ) {
			$this->assertSame( 'queue', $section['tab'] );
			$this->assertKeys( [ 'id', 'tab', 'title', 'desc', 'priority', 'collapsed', 'fields' ], $section );
		}
		$this->assertTrue( count( $this->data( $this->call( 'GET', '/schema' ) )['sections'] ) > count( $d['sections'] ) );
	}

	public function test_settings_read(): void {
		Settings::set_secret( 'channels.fake.token', 'very-hidden-1234' );
		$r = $this->call( 'GET', '/settings', [ 'tab' => 'fake' ] );
		$d = $this->data( $r );
		$this->assertKeys( [ 'values', 'secrets', 'defaults' ], $d );
		$this->assertSame( 'a', $d['values']['channels.fake.mode'] );
		$this->assertSame( 'a', $d['defaults']['channels.fake.mode'] );
		$this->assertSame( [ 'set' => true, 'masked' => '••••••1234', 'constant' => false ], $d['secrets']['channels.fake.token'] );
		$this->assertFalse( isset( $d['values']['channels.fake.token'] ) );
		$this->assertFalse( isset( $d['values']['queue.batch'] ) );
		$this->assertNotContains( 'very-hidden', wp_json_encode( $r->get_data() ) );
		$all = $this->data( $this->call( 'GET', '/settings' ) );
		$this->assertSame( 20, $all['values']['queue.batch'] );
		$this->assertNotContains( 'very-hidden', wp_json_encode( $all ) );
	}

	public function test_settings_partial_errors(): void {
		$r = $this->call( 'POST', '/settings', [ 'values' => [ 'queue.batch' => 15, 'general.capture_mode' => 'zzz', 'made.up' => 1, 'channels.fake.receivers' => [ [ 'address' => 'bad' ] ] ], 'secrets' => [ 'channels.fake.token' => 'new-token-abcd', 'queue.batch' => 'x' ] ] );
		$this->assertSame( 200, $r->get_status() );
		$d = $this->data( $r );
		$this->assertKeys( [ 'ok', 'values', 'secrets', 'errors' ], $d );
		$this->assertFalse( $d['ok'] );
		$this->assertSame( [ 'queue.batch' => 15 ], $d['values'] );
		$this->assertSame( '••••••abcd', $d['secrets']['channels.fake.token']['masked'] );
		$this->assertKeys( [ 'general.capture_mode', 'made.up', 'channels.fake.receivers', 'queue.batch' ], $d['errors'] );
		$this->assertSame( 15, Settings::get( 'queue.batch' ) );
		$this->assertSame( 'new-token-abcd', Settings::secret( 'channels.fake.token' ) );
		$this->assertMatches( '/^fnfe1:/', get_option( 'fnfe_secrets' )['channels.fake.token'] );
		$this->assertNotContains( 'new-token-abcd', wp_json_encode( $d ) );
	}

	public function test_settings_nothing_valid(): void {
		$r = $this->call( 'POST', '/settings', [ 'values' => [ 'made.up' => 1 ] ] );
		$this->assertSame( 400, $r->get_status() );
		$d = $this->data( $r );
		$this->assertSame( 'fnfe_invalid_settings', $d['code'] );
		$this->assertKeys( [ 'made.up' ], $d['data']['fields'] );
	}

	public function test_settings_secret_clear_and_constant(): void {
		Settings::set_secret( 'channels.fake.token', 'x-value-x' );
		$this->assertSame( 200, $this->call( 'POST', '/settings', [ 'secrets' => [ 'channels.fake.token' => null ] ] )->get_status() );
		$this->assertFalse( Settings::has_secret( 'channels.fake.token' ) );
		FakeChannel::install( 'locked' );
		defined( 'FNFE_CHANNELS_LOCKED_TOKEN' ) || define( 'FNFE_CHANNELS_LOCKED_TOKEN', 'constant-value-9' );
		$d = $this->data( $this->call( 'GET', '/settings', [ 'tab' => 'locked' ] ) );
		$this->assertTrue( $d['secrets']['channels.locked.token']['constant'] );
		$this->assertSame( 400, $this->call( 'POST', '/settings', [ 'secrets' => [ 'channels.locked.token' => 'other' ] ] )->get_status() );
		$this->assertSame( 'constant-value-9', Settings::secret( 'channels.locked.token' ) );
	}

	public function test_settings_repeater_secrets(): void {
		$rows = [ [ 'id' => 'e_one', 'name' => 'One', 'sign_secret' => 'inline-secret-1' ], [ 'id' => 'e_two', 'name' => 'Two' ] ];
		$d    = $this->data( $this->call( 'POST', '/settings', [ 'values' => [ 'channels.fake.endpoints' => $rows ], 'secrets' => [ 'channels.fake.endpoints.e_two.sign_secret' => 'object-secret-2', 'channels.fake.endpoints.e_zzz.sign_secret' => 'nope' ] ] ) );
		$this->assertKeys( [ 'channels.fake.endpoints.e_zzz.sign_secret' ], $d['errors'] );
		$this->assertSame( 'inline-secret-1', Settings::secret( 'channels.fake.endpoints.e_one.sign_secret' ) );
		$this->assertSame( 'object-secret-2', Settings::secret( 'channels.fake.endpoints.e_two.sign_secret' ) );
		$this->assertTrue( $d['secrets']['channels.fake.endpoints.e_one.sign_secret']['set'] );
		$this->assertNotContains( 'inline-secret-1', serialize( get_option( 'fnfe_settings' ) ) );
		$this->call( 'POST', '/settings', [ 'values' => [ 'channels.fake.endpoints' => [ $rows[1] ] ] ] );
		$this->assertFalse( Settings::has_secret( 'channels.fake.endpoints.e_one.sign_secret' ) );
		$this->assertTrue( Settings::has_secret( 'channels.fake.endpoints.e_two.sign_secret' ) );
	}

	public function test_settings_secret_receivers(): void {
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
		$r = $this->call( 'POST', '/settings', [ 'values' => [ 'channels.hook.receivers' => [ [ 'id' => 'r_a', 'address' => 'https://hooks.example.com/t0k3n', 'label' => 'Sales' ] ] ] ] );
		$d = $this->data( $r );
		$this->assertSame( 'secret:r_a', $d['values']['channels.hook.receivers'][0]['address'] );
		$this->assertTrue( $d['secrets']['channels.hook.receiver_secrets.r_a']['set'] );
		$this->assertNotContains( 'hooks.example.com', wp_json_encode( $d ) . serialize( get_option( 'fnfe_settings' ) ) );
		$this->assertSame( 'https://hooks.example.com/t0k3n', Settings::secret( 'channels.hook.receiver_secrets.r_a' ) );
		$this->call( 'POST', '/settings', [ 'values' => [ 'channels.hook.receivers' => [ [ 'id' => 'r_a', 'address' => 'secret:r_a', 'label' => 'Renamed' ] ] ] ] );
		$this->assertSame( 'https://hooks.example.com/t0k3n', Settings::secret( 'channels.hook.receiver_secrets.r_a' ) );
		$this->call( 'POST', '/settings', [ 'values' => [ 'channels.hook.receivers' => [] ] ] );
		$this->assertFalse( Settings::has_secret( 'channels.hook.receiver_secrets.r_a' ) );
	}

	public function test_channels(): void {
		$d    = $this->data( $this->call( 'GET', '/channels' ) );
		$meta = array_values( array_filter( $d['items'], static function ( $c ) { return $c['id'] === 'fake'; } ) )[0];
		$this->assertKeys( [ 'id', 'label', 'description', 'group', 'icon', 'enabled', 'ready', 'problems', 'capabilities', 'format', 'receivers_count', 'has_schema' ], $meta );
		$this->assertKeys( [ 'receivers', 'receiver_kind', 'submitter', 'buttons', 'attachments', 'links', 'subject', 'pattern', 'max_length', 'discover' ], $meta['capabilities'] );
		$d = $this->data( $this->call( 'GET', '/channels/fake' ) );
		$this->assertKeys( [ 'channel', 'sections', 'form_fields' ], $d );
		$this->assertContains( 'channels.fake.endpoints', array_column( $d['sections'][0]['fields'], 'key' ) );
		$this->assertContains( 'hint', array_map( static function ( $f ) { return substr( $f['key'], -4 ); }, $d['sections'][0]['fields'] ) );
		$this->assertSame( [ 'silent' ], array_column( $d['form_fields'], 'key' ) );
		$mode = array_values( array_filter( $d['sections'][0]['fields'], static function ( $f ) { return $f['key'] === 'channels.fake.mode'; } ) )[0];
		$this->assertSame( [ [ 'value' => 'a', 'label' => 'A' ], [ 'value' => 'b', 'label' => 'B' ] ], $mode['options'] );
		$this->assertSame( 404, $this->call( 'GET', '/channels/nope' )->get_status() );
	}

	public function test_channel_test_with_overlay(): void {
		$d = $this->data( $this->call( 'POST', '/channels/fake/test' ) );
		$this->assertFalse( $d['ok'] );
		$this->assertKeys( [ 'ok', 'message', 'level', 'rows', 'columns', 'data', 'html' ], $d );
		$d = $this->data( $this->call( 'POST', '/channels/fake/test', [ 'to' => 'Desk', 'values' => [ 'channels.fake.mode' => 'b', 'made.up' => 1 ], 'secrets' => [ 'channels.fake.token' => 'unsaved-token', 'queue.batch' => 'x' ] ] ) );
		$this->assertTrue( $d['ok'] );
		$this->assertSame( 'success', $d['level'] );
		$this->assertSame( 'desk', FakeChannel::$sent[0]->recipient->address );
		$this->assertSame( 'mode=b token=unsaved-token', FakeChannel::$sent[0]->body );
		$this->assertSame( 'a', Settings::get( 'channels.fake.mode' ), 'overlay is gone' );
		$this->assertFalse( Settings::has_secret( 'channels.fake.token' ) );
		$this->assertSame( 1, MessageRepository::query( [ 'purpose' => 'test', 'status' => 'sent' ] )['total'] );
		$d = $this->data( $this->call( 'POST', '/channels/fake/test', [ 'to' => 'bad' ] ) );
		$this->assertFalse( $d['ok'] );
	}

	public function test_channel_test_default_receiver_and_failure(): void {
		Settings::set( 'channels.fake.receivers', [ Fixtures::receiver( 'off', [ 'enabled' => false ] ), Fixtures::receiver( 'first', [ 'lang' => 'fa' ] ) ] );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'No credit', true, '', 402 ), new \RuntimeException( 'boom' ) ];
		$d = $this->data( $this->call( 'POST', '/channels/fake/test' ) );
		$this->assertSame( 'first', FakeChannel::$sent[0]->recipient->address );
		$this->assertSame( 'fa', FakeChannel::$sent[0]->recipient->lang );
		$this->assertSame( 'No credit', $d['message'] );
		$this->assertSame( 402, $d['data']['http_code'] );
		$this->assertSame( 1, MessageRepository::query( [ 'status' => 'failed' ] )['total'] );
		$r = $this->call( 'POST', '/channels/fake/test' );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'boom', $this->data( $r )['message'] );
	}

	public function test_channel_without_receivers(): void {
		FakeChannel::install( 'single', [ 'receivers' => 'none' ] );
		$this->assertTrue( $this->data( $this->call( 'POST', '/channels/single/test' ) )['ok'] );
		$this->assertSame( 'default', FakeChannel::$sent[0]->recipient->address );
	}

	public function test_channel_action(): void {
		$d = $this->data( $this->call( 'POST', '/channels/fake/action/ping', [ 'params' => [ 'a' => 1 ] ] ) );
		$this->assertTrue( $d['ok'] );
		$this->assertSame( 'pong', $d['message'] );
		$this->assertSame( [ [ 'a' => 1 ] ], FakeChannel::$pings );
		$this->assertContains( '<strong>ok</strong>', $d['html'] );
		$this->assertNotContains( '<script', $d['html'] );
		$this->assertSame( [ [ 'a' => 1 ] ], $d['rows'] );
		$this->assertFalse( $this->data( $this->call( 'POST', '/channels/fake/action/boom' ) )['ok'] );
		$this->assertSame( 404, $this->call( 'POST', '/channels/fake/action/nope' )->get_status() );
	}

	public function test_providers(): void {
		$this->assertSame( [ 'items' => [] ], $this->data( $this->call( 'GET', '/providers/nothing' ) ) );
		$provider = new class() extends \AmirhpCom\FormsNotifier\Channels\AbstractProvider implements \AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface {
			public $calls = 0;
			protected function channel(): string { return 'fake'; }
			public function id(): string { return 'gate'; }
			public function label(): string { return 'Gate'; }
			public function fields(): array { return [ $this->field( 'api_key', 'secret', 'Key' ), $this->note( 'Hello' ) ]; }
			public function is_configured(): bool { return $this->secret( 'api_key' ) !== ''; }
			public function supports_pattern(): bool { return true; }
			public function countries(): array { return [ 'IR' ]; }
			public function status(): ?string { ++$this->calls; return 'Credit: 10'; }
			public function send( string $to, \AmirhpCom\FormsNotifier\Model\Message $message ): SendResult {
				return $this->ok( $to . '|' . $message->plain . '|' . implode( ',', $this->pattern_values( $message ) ) . '|' . implode( ',', array_keys( $this->pattern_map( $message ) ) ) );
			}
		};
		$this->filter( 'fnfe_fake_providers', static function ( $map ) use ( $provider ) { return $map + [ 'gate' => $provider ]; } );
		\AmirhpCom\FormsNotifier\Core\Registry::reset();
		$item = $this->data( $this->call( 'GET', '/providers/fake' ) )['items'][0];
		$this->assertKeys( [ 'id', 'label', 'region', 'configured', 'problems', 'supports', 'panel_url', 'fields' ], $item );
		$this->assertSame( [ 'pattern' => true, 'countries' => [ 'IR' ] ], $item['supports'] );
		$this->assertSame( 'channels.fake.providers.gate.api_key', $item['fields'][0]['key'] );
		$this->assertFalse( $item['configured'] );
		$this->assertCount( 1, $item['problems'] );
		$this->assertContains( 'Credit: 10', $this->data( $this->call( 'POST', '/providers/fake/gate/status' ) )['message'] );
		$this->call( 'POST', '/providers/fake/gate/status' );
		$this->assertSame( 1, $provider->calls, 'status is cached' );
		$d = $this->data( $this->call( 'POST', '/providers/fake/gate/test', [ 'to' => 'Desk', 'text' => 'Hi', 'pattern' => [ 'code' => '1', 'vars' => [ 'a' => 'x', 'b' => 'y' ] ] ] ) );
		$this->assertTrue( $d['ok'] );
		$this->assertContains( 'desk|Hi|x,y|a,b', MessageRepository::query( [ 'purpose' => 'test' ] )['items'][0]['response'] );
		$this->assertSame( 404, $this->call( 'POST', '/providers/fake/nope/test' )->get_status() );
	}

	public function test_export_import_round_trip_without_secrets(): void {
		$id = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		FormRepository::save_config( $id, [ 'enabled' => 'on', 'channels' => [ 'fake' => [ 'enabled' => 'off' ] ] ] );
		Settings::set_many( [ 'queue.batch' => 44, 'digest.template' => [ '_' => 'x' ], 'channels.fake.greeting' => [ '_' => 'Hi', 'fa' => 'سلام' ] ] );
		Settings::set_secret( 'channels.fake.token', 'never-exported-1' );
		$r = $this->call( 'GET', '/tools/export' );
		$this->assertContains( 'attachment', $r->get_headers()['Content-Disposition'] );
		$export = $this->data( $r );
		$this->assertSame( 'forms-notifier-for-elementor', $export['plugin'] );
		$this->assertSame( [ 'channels.fake.token' ], $export['secrets_needed'] );
		$this->assertNotContains( 'never-exported-1', wp_json_encode( $export ) );
		$this->assertSame( 44, $export['settings']['queue']['batch'] );

		Db::reset();
		FakeChannel::install();
		FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		$export['settings']['evil'] = [ 'x' => 1 ];
		$export['forms'][]          = [ 'form_key' => '9:missing', 'name' => 'Gone', 'config' => [] ];
		$d = $this->data( $this->call( 'POST', '/tools/import', [ 'data' => $export, 'mode' => 'replace' ] ) );
		$this->assertTrue( $d['ok'] );
		$this->assertSame( 1, $d['imported']['forms'] );
		$this->assertTrue( $d['imported']['settings'] > 10 );
		$this->assertSame( 44, Settings::get( 'queue.batch' ) );
		$this->assertSame( 'سلام', Settings::text( 'channels.fake.greeting', 'fa' ) );
		$this->assertSame( 'off', FormRepository::config_by_key( '45:a1b2c3d' )->route( 'fake' )['enabled'] );
		$warnings = implode( "\n", $d['warnings'] );
		$this->assertContains( 'evil', $warnings );
		$this->assertContains( '9:missing', $warnings );
		$this->assertContains( 'channels.fake.token', $warnings );
	}

	public function test_import_modes_and_errors(): void {
		Settings::set_many( [ 'queue.batch' => 44, 'queue.ticker' => 'cron' ] );
		$envelope = [ 'plugin' => 'forms-notifier-for-elementor', 'version' => '1.0.0', 'settings' => [ 'queue' => [ 'batch' => 7 ], 'general' => [ 'capture_mode' => 'zzz' ] ] ];
		$d = $this->data( $this->call( 'POST', '/tools/import', [ 'data' => $envelope ] ) );
		$this->assertSame( 7, Settings::get( 'queue.batch' ) );
		$this->assertSame( 'cron', Settings::get( 'queue.ticker' ), 'merge keeps other values' );
		$this->assertContains( 'general.capture_mode', implode( "\n", $d['warnings'] ) );
		$this->call( 'POST', '/tools/import', [ 'data' => $envelope, 'mode' => 'replace' ] );
		$this->assertSame( 'auto', Settings::get( 'queue.ticker' ), 'replace resets other values' );
		$this->assertSame( 400, $this->call( 'POST', '/tools/import', [ 'data' => [ 'plugin' => 'other' ] ] )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/tools/import', [] )->get_status() );
		$this->assertSame( 413, $this->call( 'POST', '/tools/import', [ 'data' => [ 'x' => str_repeat( 'x', 2100000 ) ] ] )->get_status() );
	}

	public function test_system(): void {
		$d = $this->data( $this->call( 'GET', '/tools/system' ) );
		$this->assertKeys( [ 'forms', 'submissions', 'files', 'messages', 'chats', 'log' ], $d['database']['tables'] );
		$this->assertTrue( $d['database']['tables']['forms']['exists'] );
		$this->assertContains( 'fake', $d['channels']['registered'] );
	}

	public function test_logs(): void {
		Logger::info( 'bot', 'one' );
		Logger::error( 'sms', 'two', [ 'a' => 1 ] );
		$d = $this->data( $this->call( 'GET', '/logs', [ 'per_page' => 1 ] ) );
		$this->assertKeys( [ 'items', 'total', 'page', 'per_page' ], $d );
		$this->assertSame( 2, $d['total'] );
		$this->assertKeys( [ 'id', 'level', 'context', 'message', 'data', 'created' ], $d['items'][0] );
		$this->assertMatches( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $d['items'][0]['created'] );
		$this->assertSame( 1, $this->data( $this->call( 'GET', '/logs', [ 'level' => 'error' ] ) )['total'] );
		$this->assertSame( 400, $this->call( 'GET', '/logs', [ 'per_page' => 500 ] )->get_status() );
		$this->assertTrue( $this->data( $this->call( 'DELETE', '/logs' ) )['ok'] );
		$this->assertSame( 0, $this->data( $this->call( 'GET', '/logs' ) )['total'] );
	}

	public function test_permissions_matrix(): void {
		$routes = [
			[ 'GET', '/bootstrap', 'any' ], [ 'POST', '/bootstrap/ui', 'any' ], [ 'GET', '/schema', Capabilities::MANAGE ],
			[ 'GET', '/settings', Capabilities::MANAGE ], [ 'POST', '/settings', Capabilities::MANAGE ], [ 'GET', '/channels', 'any' ],
			[ 'GET', '/channels/fake', Capabilities::MANAGE ], [ 'POST', '/channels/fake/test', Capabilities::MANAGE ],
			[ 'POST', '/channels/fake/action/ping', Capabilities::MANAGE ], [ 'GET', '/providers/sms', Capabilities::MANAGE ],
			[ 'POST', '/providers/sms/x/status', Capabilities::MANAGE ], [ 'GET', '/tools/export', Capabilities::MANAGE ],
			[ 'POST', '/tools/import', Capabilities::MANAGE ], [ 'GET', '/tools/system', Capabilities::MANAGE ],
			[ 'GET', '/logs', Capabilities::MANAGE ], [ 'DELETE', '/logs', Capabilities::MANAGE ],
		];
		foreach ( $routes as $route ) {
			$params = $route[1] === '/tools/import' ? [ 'data' => [ 'x' => 1 ] ] : [];
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->call( $route[0], $route[1], $params )->get_status(), 'visitor ' . $route[1] );
			wp_set_current_user( self::$subscriber );
			$this->assertSame( 403, $this->call( $route[0], $route[1], $params )->get_status(), 'subscriber ' . $route[1] );
		}

		// A user with one lesser capability reaches only what "any" allows.
		$this->filter( 'fnfe_capability', static function ( $wp_cap, $cap ) { return $cap === Capabilities::LOGS ? 'read' : $wp_cap; }, 10, 2 );
		foreach ( $routes as $route ) {
			$params   = $route[1] === '/tools/import' ? [ 'data' => [ 'x' => 1 ] ] : [];
			$expected = $route[2] === 'any' ? 200 : 403;
			$this->assertSame( $expected, $this->call( $route[0], $route[1], $params )->get_status(), 'limited ' . $route[1] );
		}
	}

	public function test_every_route_declares_args_and_permissions(): void {
		$count = 0;
		foreach ( rest_get_server()->get_routes( 'fnfe/v1' ) as $path => $handlers ) {
			if ( $path === '/fnfe/v1' ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				++$count;
				$this->assertTrue( is_callable( $handler['permission_callback'] ), $path );
				foreach ( $handler['args'] as $name => $arg ) {
					foreach ( [ 'type', 'sanitize_callback', 'validate_callback' ] as $key ) {
						$this->assertArrayHasKey( $key, $arg, "$path $name" );
					}
				}
			}
		}
		$this->assertTrue( $count >= 16 );
	}

	public function test_serialisers(): void {
		$form = FormRepository::upsert( '45:a1b2c3d', [ 'post_id' => 45, 'name' => 'Contact', 'fields' => Fixtures::form_fields() ] );
		FormRepository::touch_submission( $form );
		Settings::set( 'channels.fake.enabled', true );
		$summary = Controller::form_summary( FormRepository::find( $form ) );
		$this->assertKeys( [ 'id', 'form_key', 'name', 'source', 'post_id', 'post_title', 'post_type', 'edit_url', 'status', 'enabled', 'active', 'submissions_count', 'last_submission', 'fields_count', 'channels' ], $summary );
		$this->assertSame( [ 'fake' ], $summary['channels'] );
		$this->assertSame( 3, $summary['fields_count'] );

		$s = Fixtures::submission( [ 'form_id' => $form ] );
		$s->files[] = Fixtures::file();
		SubmissionRepository::insert( $s );
		$s = SubmissionRepository::find( $s->id );
		$keys = [ 'id', 'form_id', 'form_name', 'status', 'lang', 'created', 'created_local', 'preview', 'files_count', 'delivery_status' ];
		$d = Controller::submission_summary( $s );
		$this->assertKeys( $keys, $d );
		$this->assertSame( '2026-01-01T10:00:00Z', $d['created'] );
		$this->assertContains( 'Name: سارا 🙂', $d['preview'] );
		$this->assertTrue( mb_strlen( $d['preview'] ) <= 160 );
		$d = Controller::submission_full( $s );
		$this->assertKeys( array_merge( $keys, [ 'uuid', 'fields', 'files', 'meta' ] ), $d );
		$this->assertKeys( [ 'id', 'type', 'label', 'value', 'values', 'is_file', 'redacted' ], $d['fields'][0] );
		$this->assertKeys( [ 'id', 'field_id', 'name', 'size', 'mime', 'is_image', 'url' ], $d['files'][0] );
		$this->assertKeys( [ 'page_url', 'page_title', 'user_id', 'user_login', 'user_ip', 'user_agent', 'via' ], $d['meta'] );
		$this->assertContains( '_wpnonce=', $d['files'][0]['url'] );
		$signed = Controller::submission_full( $s, true )['files'][0]['url'];
		$this->assertNotContains( '_wpnonce', $signed );
		parse_str( (string) wp_parse_url( $signed, PHP_URL_QUERY ), $query );
		$this->assertTrue( \AmirhpCom\FormsNotifier\Attachments\Storage::verify( $s->files[0]->id, $query['t'], (int) $query['e'] ) );

		$id = MessageRepository::insert( [ 'submission_id' => $s->id, 'form_id' => $form, 'channel' => 'fake', 'recipient' => 'desk', 'body' => 'b', 'payload' => Fixtures::message()->to_array() ] );
		$keys = [ 'id', 'submission_id', 'form_id', 'form_name', 'channel', 'channel_label', 'provider', 'purpose', 'recipient', 'recipient_label', 'lang', 'status', 'attempts', 'max_attempts', 'error', 'created', 'sent', 'next_attempt' ];
		$d = Controller::message_summary( MessageRepository::find( $id ) );
		$this->assertKeys( $keys, $d );
		$this->assertSame( 'Fake fake', $d['channel_label'] );
		$this->assertSame( 'Contact', $d['form_name'] );
		$this->assertNull( $d['sent'] );
		$this->assertKeys( array_merge( $keys, [ 'subject', 'body', 'response', 'http_code', 'remote_id', 'parent_id', 'buttons', 'attachments', 'links' ] ), Controller::message_full( MessageRepository::find( $id ) ) );
	}

	public function test_local_dates(): void {
		update_option( 'timezone_string', 'Asia/Tehran' );
		Settings::set( 'general.calendar', 'jalali' );
		$digits = static function ( string $text ): string { return \AmirhpCom\FormsNotifier\Core\Text::en_digits( $text ); };
		$this->assertSame( '1404/10/11 13:30', $digits( Controller::local_datetime( '2026-01-01 10:00:00' ) ) );
		$this->assertSame( '1403/12/30 00:30', $digits( Controller::local_datetime( '2025-03-19 21:00:00' ) ) );
		$this->assertSame( '1404/01/01 12:00', $digits( Controller::local_datetime( '2025-03-21 08:30:00' ) ) );
		Settings::set( 'general.calendar', 'gregorian' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$this->assertSame( '2026-01-01 13:30', $digits( Controller::local_datetime( '2026-01-01 10:00:00' ) ) );
		$this->assertSame( '', Controller::local_datetime( '' ) );
	}
}
