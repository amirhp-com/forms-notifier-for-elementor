<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\MiniApp;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\MiniApp\Session;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Launch.php';

final class AppRestTest extends TestCase {

	private $form = 0;
	private $submission = 0;

	private const FORM = [ 'id', 'form_key', 'name', 'source', 'post_id', 'post_title', 'post_type', 'edit_url', 'status', 'enabled', 'active', 'submissions_count', 'last_submission', 'fields_count', 'channels', 'unread' ];
	private const SUMMARY = [ 'id', 'form_id', 'form_name', 'status', 'lang', 'created', 'created_local', 'preview', 'files_count', 'delivery_status' ];
	private const MESSAGE = [ 'id', 'submission_id', 'form_id', 'form_name', 'channel', 'channel_label', 'provider', 'purpose', 'recipient', 'recipient_label', 'lang', 'status', 'attempts', 'max_attempts', 'error', 'created', 'sent', 'next_attempt' ];

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		FakeChannel::install();
		wp_set_current_user( 0 );
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set_many(
			[
				'miniapp.enabled'          => true,
				'channels.telegram.admins' => [ Launch::admin( '111111', [ 'view', 'act' ] ), Launch::admin( '222222', [ 'view' ] ) ],
			]
		);
		$this->form       = FormRepository::upsert( '45:a1b2c3d', [ 'post_id' => 45, 'element_id' => 'a1b2c3d', 'source' => 'pro', 'name' => 'Contact', 'post_title' => 'Contact page', 'fields' => Fixtures::form_fields(), 'status' => 'active' ] );
		$this->submission = $this->submit();
		FormRepository::touch_submission( $this->form );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
	}

	private function submit( array $overrides = [] ): int {
		return SubmissionRepository::insert( Fixtures::submission( array_merge( [ 'form_id' => $this->form ], $overrides ) ) );
	}

	private function message( array $overrides = [] ): int {
		return MessageRepository::insert( array_merge( [ 'submission_id' => $this->submission, 'form_id' => $this->form, 'channel' => 'fake', 'purpose' => 'admin', 'recipient' => 'desk', 'status' => 'failed', 'attempts' => 5, 'max_attempts' => 5, 'error' => 'Refused', 'body' => 'SECRET BODY', 'response' => 'RAW RESPONSE', 'payload' => Fixtures::message()->to_array() ], $overrides ) );
	}

	private function token( string $uid = '111111', array $perms = [ 'view', 'act' ] ): string {
		return Session::issue( 'telegram', $uid, $perms )['token'];
	}

	private function call( string $method, string $path, array $params = [], ?string $token = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/fnfe/v1' . $path );
		if ( $token !== null ) {
			$request->set_header( 'X-FNFE-Token', $token );
		}
		if ( $method === 'GET' ) {
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

	private function get( string $path, array $params = [] ): array {
		$response = $this->call( 'GET', $path, $params, $this->token() );
		$this->assertSame( 200, $response->get_status(), $path );
		$this->assertContains( 'no-store', $response->get_headers()['Cache-Control'] ?? '', $path );
		return $this->data( $response );
	}

	private function init_data( string $uid, int $age = 10 ): string {
		return Launch::sign( [ 'user' => '{"id":' . $uid . ',"first_name":"Sara","last_name":"Example","username":"sara_example","photo_url":"https://example.com/p.jpg"}', 'auth_date' => (string) ( time() - $age ) ] );
	}

	private function auth( string $init_data, string $platform = 'telegram' ): \WP_REST_Response {
		return $this->call( 'POST', '/app/auth', [ 'platform' => $platform, 'init_data' => $init_data ] );
	}

	private function routes(): array {
		return [
			[ 'GET', '/app/digest', [], 'view' ],
			[ 'GET', '/app/forms', [], 'view' ],
			[ 'GET', '/app/forms/' . $this->form . '/submissions', [], 'view' ],
			[ 'GET', '/app/submissions/' . $this->submission, [], 'view' ],
			[ 'POST', '/app/submissions/' . $this->submission . '/status', [ 'status' => 'done' ], 'act' ],
			[ 'GET', '/app/messages', [], 'view' ],
			[ 'POST', '/app/messages/1/retry', [], 'act' ],
		];
	}

	public function test_auth(): void {
		$seen = [];
		$this->filter(
			'fnfe_miniapp_auth',
			static function ( $platform, $user, $allowed ) use ( &$seen ) {
				$seen[] = [ $platform, $user['id'], $allowed ];
			},
			10,
			3
		);
		$r = $this->auth( $this->init_data( '111111' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertContains( 'no-store', $r->get_headers()['Cache-Control'] );
		$d = $this->data( $r );
		$this->assertKeys( [ 'token', 'expires', 'user', 'perms', 'site' ], $d );
		$this->assertSame( [ 'id' => '111111', 'name' => 'Sara Example', 'username' => 'sara_example', 'photo' => 'https://example.com/p.jpg' ], $d['user'] );
		$this->assertSame( [ 'view', 'act' ], $d['perms'] );
		$this->assertKeys( [ 'name', 'url', 'lang', 'rtl' ], $d['site'] );
		$this->assertSame( 200, $this->call( 'GET', '/app/digest', [], $d['token'] )->get_status() );
		$this->assertSame( [ [ 'telegram', '111111', true ] ], $seen );
		$this->assertSame( [ 'view' ], $this->data( $this->auth( $this->init_data( '222222' ) ) )['perms'] );
	}

	public function test_auth_not_allowed_carries_the_id(): void {
		$r = $this->auth( $this->init_data( '987654' ) );
		$this->assertSame( 403, $r->get_status() );
		$d = $this->data( $r );
		$this->assertSame( 'fnfe_app_not_allowed', $d['code'] );
		$this->assertSame( '987654', $d['data']['fields']['user_id'] );
		$this->assertContains( '987654', $d['message'] );
	}

	public function test_auth_invalid(): void {
		$bad = [
			''                                   => 'fnfe_app_invalid',
			'hash=abc'                           => 'fnfe_app_invalid',
			$this->init_data( '111111' ) . 'x'   => 'fnfe_app_invalid',
			$this->init_data( '111111', 90000 )  => 'fnfe_app_expired',
		];
		foreach ( $bad as $data => $code ) {
			$r = $this->auth( (string) $data );
			$this->assertSame( 401, $r->get_status() );
			$this->assertSame( $code, $this->data( $r )['code'] );
			$this->assertNotContains( 'token', array_keys( $this->data( $r ) ) );
		}
		$this->assertSame( 401, $this->auth( $this->init_data( '111111' ), 'bale' )->get_status(), 'other platform' );
		$this->assertSame( 400, $this->auth( $this->init_data( '111111' ), 'icq' )->get_status() );
		$this->assertSame( 400, $this->auth( str_repeat( 'a', 9000 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/app/auth', [ 'init_data' => 'x' ] )->get_status() );
		$log = LogRepository::query( [ 'context' => 'miniapp' ] );
		$this->assertTrue( $log['total'] >= 5, 'refusals are logged' );
		$this->assertNotContains( Fixtures::TOKEN, wp_json_encode( $log ) );
	}

	public function test_auth_rate_limit(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 401, $this->auth( 'hash=x' )->get_status() );
		}
		$r = $this->auth( $this->init_data( '111111' ) );
		$this->assertSame( 429, $r->get_status() );
		$this->assertSame( 'fnfe_rate_limited', $this->data( $r )['code'] );
	}

	public function test_disabled(): void {
		$token = $this->token();
		Settings::set( 'miniapp.enabled', false );
		$this->assertSame( 'fnfe_app_disabled', $this->data( $this->auth( $this->init_data( '111111' ) ) )['code'] );
		foreach ( $this->routes() as $route ) {
			$r = $this->call( $route[0], $route[1], $route[2], $token );
			$this->assertSame( 403, $r->get_status(), $route[1] );
			$this->assertSame( 'fnfe_app_disabled', $this->data( $r )['code'] );
		}
	}

	public function test_every_route_needs_a_token(): void {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$token  = $this->token();
		foreach ( $this->routes() as $route ) {
			foreach ( [ null, '', 'x.y', $token . 'a', strrev( $token ) ] as $bad ) {
				$r = $this->call( $route[0], $route[1], $route[2], $bad );
				$this->assertSame( 401, $r->get_status(), $route[1] );
				$this->assertSame( [ 'code', 'message', 'data' ], array_keys( $this->data( $r ) ) );
			}
			wp_set_current_user( (int) $admins[0] );
			$this->assertSame( 401, $this->call( $route[0], $route[1], $route[2] )->get_status(), 'a WordPress login is no substitute' );
			wp_set_current_user( 0 );
		}
		$this->assertSame( 'new', SubmissionRepository::find( $this->submission )->status );
	}

	public function test_act_permission(): void {
		foreach ( $this->routes() as $route ) {
			$status = $this->call( $route[0], $route[1], $route[2], $this->token( '222222', [ 'view', 'act' ] ) )->get_status();
			$this->assertSame( $route[3] === 'act' ? 403 : 200, $status, $route[1] );
		}
		$this->assertSame( 'new', SubmissionRepository::find( $this->submission )->status );
		Settings::set( 'miniapp.allow_actions', false );
		$this->assertSame( 403, $this->call( 'POST', '/app/submissions/' . $this->submission . '/status', [ 'status' => 'done' ], $this->token() )->get_status() );
	}

	public function test_removed_admin_loses_access(): void {
		$token = $this->token();
		$this->assertSame( 200, $this->call( 'GET', '/app/forms', [], $token )->get_status() );
		Settings::set( 'channels.telegram.admins', [] );
		$this->assertSame( 401, $this->call( 'GET', '/app/forms', [], $token )->get_status() );
	}

	public function test_digest(): void {
		$d = $this->get( '/app/digest', [ 'range' => 'today' ] );
		$this->assertKeys( [ 'stats', 'health', 'queue', 'site' ], $d );
		$this->assertKeys( [ 'range', 'submissions', 'messages', 'by_channel', 'by_form', 'series' ], $d['stats'] );
		$this->assertSame( [ 'pending' ], array_keys( $d['queue'] ) );
		$this->assertKeys( [ 'name', 'url', 'lang', 'rtl', 'forms', 'unread', 'total', 'pages', 'posts', 'users', 'comments', 'updates', 'wp', 'php', 'plugin', 'theme', 'time', 'channels' ], $d['site'] );
		$this->assertSame( 1, $d['site']['forms'] );
		$this->assertSame( 1, $d['site']['unread'] );
		foreach ( $d['health'] as $check ) {
			$this->assertKeys( [ 'id', 'label', 'status', 'message' ], $check );
		}
		$this->assertSame( 400, $this->call( 'GET', '/app/digest', [ 'range' => 'year' ], $this->token() )->get_status() );
	}

	public function test_forms(): void {
		$other = FormRepository::upsert( '46:ffffff1', [ 'name' => 'Newsletter', 'post_id' => 46 ] );
		$d     = $this->get( '/app/forms' );
		$this->assertKeys( [ 'items', 'total', 'page', 'per_page' ], $d );
		$this->assertSame( [ $this->form, $other ], array_column( $d['items'], 'id' ), 'recent activity first' );
		$this->assertKeys( self::FORM, $d['items'][0] );
		$this->assertSame( '', $d['items'][0]['edit_url'] );
		$this->assertSame( 1, $d['items'][0]['unread'] );
		$this->assertSame( 0, $d['items'][1]['unread'] );
		$this->assertSame( [ $other ], array_column( $this->get( '/app/forms', [ 'search' => 'news' ] )['items'], 'id' ) );
		$this->assertSame( 20, $d['per_page'] );
		Settings::set( 'miniapp.page_size', 5 );
		$this->assertSame( 5, $this->get( '/app/forms' )['per_page'] );
	}

	public function test_submissions(): void {
		$done  = $this->submit( [ 'status' => 'done' ] );
		$trash = $this->submit( [ 'status' => 'trash' ] );
		$this->submit( [ 'form_id' => 999 ] );
		$path = '/app/forms/' . $this->form . '/submissions';
		$d    = $this->get( $path );
		$this->assertKeys( self::SUMMARY, $d['items'][0] );
		$this->assertSame( 2, $d['total'], 'without trash and other forms' );
		$this->assertSame( [ $done ], array_column( $this->get( $path, [ 'status' => 'done' ] )['items'], 'id' ) );
		$this->assertSame( 2, $this->get( $path, [ 'status' => 'all', 'search' => 'sara' ] )['total'] );
		$this->assertSame( 0, $this->get( $path, [ 'search' => 'nobody' ] )['total'] );
		$this->assertSame( 400, $this->call( 'GET', $path, [ 'status' => 'trash' ], $this->token() )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', $path, [ 'page' => 0 ], $this->token() )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/app/forms/9999/submissions', [], $this->token() )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/app/submissions/' . $trash, [], $this->token() )->get_status(), 'trash is not shown' );
	}

	public function test_submission(): void {
		$this->message();
		$this->message( [ 'purpose' => 'test' ] );
		$d = $this->get( '/app/submissions/' . $this->submission );
		$this->assertKeys( [ 'submission', 'messages' ], $d );
		$this->assertKeys( array_merge( self::SUMMARY, [ 'uuid', 'fields', 'files', 'meta' ] ), $d['submission'] );
		$this->assertKeys( [ 'id', 'type', 'label', 'value', 'values', 'is_file', 'redacted' ], $d['submission']['fields'][0] );
		$this->assertKeys( [ 'page_url', 'page_title', 'user_id', 'user_login', 'user_ip', 'user_agent', 'via' ], $d['submission']['meta'] );
		$this->assertSame( '', $d['submission']['meta']['user_ip'], 'hidden by default' );
		$this->assertSame( '', $d['submission']['meta']['user_agent'] );
		$this->assertCount( 1, $d['messages'] );
		$this->assertKeys( self::MESSAGE, $d['messages'][0] );
		$this->assertNotContains( 'SECRET BODY', wp_json_encode( $d ) );
		$this->assertNotContains( 'RAW RESPONSE', wp_json_encode( $d ) );
		Settings::set( 'miniapp.show_ip', true );
		$this->assertSame( '203.0.113.5', $this->get( '/app/submissions/' . $this->submission )['submission']['meta']['user_ip'] );
		$this->assertSame( 404, $this->call( 'GET', '/app/submissions/99999', [], $this->token() )->get_status() );
	}

	public function test_file_links_live_one_hour(): void {
		$this->freeze( 1767261600 );
		$file = FileRepository::insert( $this->submission, Fixtures::file() );
		global $wpdb;
		$wpdb->update( SubmissionRepository::table(), [ 'files_count' => 1 ], [ 'id' => $this->submission ] );
		$clock = [];
		$this->filter(
			'rest_url',
			static function ( $url ) use ( &$clock ) {
				$clock[] = (int) apply_filters( 'fnfe_now', time() );
				return $url;
			}
		);
		$d = $this->get( '/app/submissions/' . $this->submission );
		$this->assertCount( 1, $d['submission']['files'] );
		$this->assertKeys( [ 'id', 'field_id', 'name', 'size', 'mime', 'is_image', 'url' ], $d['submission']['files'][0] );
		$url = $d['submission']['files'][0]['url'];
		$this->assertNotContains( '_wpnonce', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 1767261600 + HOUR_IN_SECONDS, (int) $query['e'] );
		$this->assertTrue( Storage::verify( $file, $query['t'], (int) $query['e'] ) );
		$this->assertFalse( Storage::verify( $file, $query['t'], (int) $query['e'] + 1 ) );
		$this->assertTrue( count( $clock ) > 0 );
		$this->assertSame( [ 1767261600 ], array_values( array_unique( $clock ) ), 'the clock is never shifted' );
	}

	public function test_status(): void {
		$seen = [];
		$this->filter(
			'fnfe_submission_status_changed',
			static function ( ...$args ) use ( &$seen ) {
				$seen[] = $args;
			},
			10,
			4
		);
		$path = '/app/submissions/' . $this->submission . '/status';
		$r    = $this->call( 'POST', $path, [ 'status' => 'done' ], $this->token() );
		$this->assertSame( 200, $r->get_status() );
		$d = $this->data( $r );
		$this->assertKeys( [ 'ok', 'submission' ], $d );
		$this->assertSame( 'done', $d['submission']['status'] );
		$this->assertSame( 'done', SubmissionRepository::find( $this->submission )->status );
		$this->call( 'POST', $path, [ 'status' => 'done' ], $this->token() );
		$this->assertSame( [ [ $this->submission, 'done', 'new', 'app' ] ], $seen, 'fired once, only for a change' );
		foreach ( [ 'trash', 'x', '' ] as $bad ) {
			$this->assertSame( 400, $this->call( 'POST', $path, [ 'status' => $bad ], $this->token() )->get_status() );
		}
		$this->assertSame( 400, $this->call( 'POST', $path, [], $this->token() )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/app/submissions/99999/status', [ 'status' => 'done' ], $this->token() )->get_status() );
	}

	public function test_messages(): void {
		$failed = $this->message();
		$retry  = $this->message( [ 'status' => 'retry' ] );
		$sent   = $this->message( [ 'status' => 'sent' ] );
		$this->message( [ 'purpose' => 'test' ] );
		$d = $this->get( '/app/messages' );
		$this->assertSame( [ $retry, $failed ], array_column( $d['items'], 'id' ) );
		$this->assertKeys( self::MESSAGE, $d['items'][0] );
		$this->assertSame( [ $failed ], array_column( $this->get( '/app/messages', [ 'status' => 'failed' ] )['items'], 'id' ) );
		$this->assertSame( [ $sent ], array_column( $this->get( '/app/messages', [ 'status' => 'sent' ] )['items'], 'id' ) );
		$this->assertSame( 3, $this->get( '/app/messages', [ 'status' => 'all' ] )['total'] );
		$this->assertSame( 400, $this->call( 'GET', '/app/messages', [ 'status' => 'x' ], $this->token() )->get_status() );
	}

	public function test_retry(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ] ] );
		Settings::set_secret( 'channels.fake.token', 'x-token-x' );
		$id = $this->message();
		$r  = $this->call( 'POST', "/app/messages/$id/retry", [], $this->token() );
		$this->assertSame( 200, $r->get_status() );
		$d = $this->data( $r );
		$this->assertKeys( [ 'ok', 'message' ], $d );
		$this->assertKeys( self::MESSAGE, $d['message'] );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
		$this->assertSame( 409, $this->call( 'POST', "/app/messages/$id/retry", [], $this->token() )->get_status(), 'already sent' );
		$this->assertSame( 404, $this->call( 'POST', '/app/messages/99999/retry', [], $this->token() )->get_status() );
		$test = $this->message( [ 'purpose' => 'test' ] );
		$this->assertSame( 404, $this->call( 'POST', "/app/messages/$test/retry", [], $this->token() )->get_status() );
	}

	public function test_panel_routes(): void {
		$this->assertSame( 401, $this->call( 'GET', '/app/setup' )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', '/app/revoke' )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', '/app/revoke', [], $this->token() )->get_status(), 'an app session is not enough' );
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		wp_set_current_user( (int) $admins[0] );
		$d = $this->data( $this->call( 'GET', '/app/setup' ) );
		$this->assertSame( [ 'telegram', 'bale' ], array_column( $d['platforms'], 'id' ) );
		$this->assertKeys( [ 'id', 'label', 'url', 'bot_url', 'direct_link', 'checks', 'ready' ], $d['platforms'][0] );
		$this->assertTrue( $d['platforms'][0]['checks']['bot'] );
		$this->assertTrue( $d['platforms'][0]['checks']['admins'] );
		$this->assertFalse( $d['platforms'][1]['checks']['bot'] );
		$this->assertNotContains( Fixtures::TOKEN, wp_json_encode( $d ) );
		$token = $this->token();
		$this->assertTrue( $this->data( $this->call( 'POST', '/app/revoke' ) )['ok'] );
		$this->assertNull( Session::verify( $token ) );
	}
}
