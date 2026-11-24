<?php
/**
 * Message routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Rest\MessagesController;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class MessagesRestTest extends TestCase {

	/** @var int */
	private static $admin = 0;

	public static function setUpClass(): void {
		FakeChannel::install( 'fake' );
		$users       = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
		self::$admin = (int) ( $users[0] ?? 0 );
	}

	public static function tearDownClass(): void {
		wp_set_current_user( 0 );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ], 'queue.ticker' => 'cron' ] );
		wp_set_current_user( self::$admin );
	}

	public function tearDown(): void {
		Queue::reset();
		Settings::clear_overlay();
	}

	private function request( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/fnfe/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function row( array $overrides = [] ): int {
		return MessageRepository::insert( $overrides + Queue::row( Fixtures::message(), 'sent' ) );
	}

	public function test_permissions() {
		$id     = $this->row();
		$routes = [
			[ 'GET', '/messages', [] ],
			[ 'GET', "/messages/$id", [] ],
			[ 'POST', "/messages/$id/retry", [] ],
			[ 'POST', '/messages/bulk', [ 'action' => 'delete', 'ids' => [ $id ] ] ],
			[ 'GET', '/messages/export', [] ],
			[ 'POST', '/queue/run', [] ],
			[ 'GET', '/stats', [] ],
			[ 'POST', '/alerts/test', [] ],
			[ 'POST', '/digest/send', [] ],
		];
		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->request( ...$route )->get_status(), $route[1] );
		}
		$this->filter( 'fnfe_capability', function () {
			return 'fnfe_nobody_has_this';
		} );
		wp_set_current_user( self::$admin );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->request( ...$route )->get_status(), $route[1] );
		}
		$this->assertNotNull( MessageRepository::find( $id ) );
	}

	public function test_list_shape_and_filters() {
		$a = $this->row( [ 'status' => 'failed', 'error' => 'chat not found', 'form_id' => 3, 'submission_id' => 9 ] );
		$b = $this->row( [ 'purpose' => 'digest', 'channel' => 'other', 'created_gmt' => '2026-01-01 10:00:00' ] );
		$r = $this->request( 'GET', '/messages' )->get_data();
		$this->assertSame( [ 'items', 'total', 'page', 'per_page' ], array_keys( $r ) );
		$this->assertSame( [ $b, $a ], array_column( $r['items'], 'id' ) );
		$this->assertSame(
			[ 'id', 'submission_id', 'form_id', 'form_name', 'channel', 'channel_label', 'provider', 'purpose', 'recipient', 'recipient_label', 'lang', 'status', 'attempts', 'max_attempts', 'error', 'created', 'sent', 'next_attempt' ],
			array_keys( $r['items'][0] )
		);
		foreach ( [
			[ [ 'status' => 'failed' ], [ $a ] ],
			[ [ 'status' => 'failed,sent' ], [ $b, $a ] ],
			[ [ 'status' => [ 'queued' ] ], [] ],
			[ [ 'channel' => 'other' ], [ $b ] ],
			[ [ 'purpose' => 'digest' ], [ $b ] ],
			[ [ 'form_id' => 3 ], [ $a ] ],
			[ [ 'submission_id' => 9 ], [ $a ] ],
			[ [ 'search' => 'not found' ], [ $a ] ],
			[ [ 'from' => '2026-01-01', 'to' => '2026-01-02' ], [ $b ] ],
			[ [ 'order' => 'asc', 'per_page' => 1 ], [ $a ] ],
		] as $case ) {
			$this->assertSame( $case[1], array_column( $this->request( 'GET', '/messages', $case[0] )->get_data()['items'], 'id' ), wp_json_encode( $case[0] ) );
		}
	}

	public function test_bad_filters_are_refused() {
		foreach ( [ [ 'status' => 'junk' ], [ 'purpose' => 'junk' ], [ 'from' => '1 OR 1=1' ], [ 'per_page' => 1000 ], [ 'order' => 'x' ] ] as $params ) {
			$this->assertSame( 400, $this->request( 'GET', '/messages', $params )->get_status(), wp_json_encode( $params ) );
		}
	}

	public function test_show() {
		$id = $this->row( [ 'response' => 'ok' ] );
		$m  = $this->request( 'GET', "/messages/$id" )->get_data()['message'];
		foreach ( [ 'subject', 'body', 'response', 'http_code', 'remote_id', 'parent_id', 'buttons', 'attachments', 'links' ] as $key ) {
			$this->assertArrayHasKey( $key, $m );
		}
		$this->assertSame( 'Hello', $m['body'] );
		$this->assertFalse( array_key_exists( 'payload', $m ) );
		$this->assertSame( 404, $this->request( 'GET', '/messages/9999' )->get_status() );
	}

	public function test_retry() {
		$id = $this->row( [ 'status' => 'failed', 'attempts' => 5 ] );
		$r  = $this->request( 'POST', "/messages/$id/retry" )->get_data();
		$this->assertSame( [ true, 'sent' ], [ $r['ok'], $r['message']['status'] ] );
		$this->assertSame( 409, $this->request( 'POST', "/messages/$id/retry" )->get_status() );
		$id                   = $this->row( [ 'status' => 'failed' ] );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'again', true ) ];
		$r                    = $this->request( 'POST', "/messages/$id/retry" )->get_data();
		$this->assertSame( [ false, 'again' ], [ $r['ok'], $r['message']['error'] ] );
		$gone     = $this->row( [ 'status' => 'failed', 'payload' => null ] );
		$response = $this->request( 'POST', "/messages/$gone/retry" );
		$this->assertSame( [ 409, 'fnfe_cannot_retry' ], [ $response->get_status(), $response->get_data()['code'] ] );
		$this->assertSame( 404, $this->request( 'POST', '/messages/9999/retry' )->get_status() );
	}

	public function test_bulk() {
		$failed = $this->row( [ 'status' => 'failed' ] );
		$queued = $this->row( [ 'status' => 'queued' ] );
		$sent   = $this->row();
		$busy   = $this->row( [ 'status' => 'queued' ] );
		MessageRepository::take( $busy );
		$all = [ $failed, $queued, $sent, $busy, 9999 ];
		$this->assertSame( 1, $this->request( 'POST', '/messages/bulk', [ 'action' => 'cancel', 'ids' => $all ] )->get_data()['affected'] );
		$this->assertSame( 2, $this->request( 'POST', '/messages/bulk', [ 'action' => 'retry', 'ids' => $all ] )->get_data()['affected'] );
		$this->assertTrue( Queue::is_kicked() );
		$this->assertSame( [ 'ok' => true, 'affected' => 3 ], $this->request( 'POST', '/messages/bulk', [ 'action' => 'delete', 'ids' => $all ] )->get_data() );
		$this->assertNotNull( MessageRepository::find( $busy ) );
		$this->assertSame( 400, $this->request( 'POST', '/messages/bulk', [ 'action' => 'drop', 'ids' => [ 1 ] ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/messages/bulk', [ 'action' => 'delete' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/messages/bulk', [ 'action' => 'delete', 'ids' => range( 1, 201 ) ] )->get_status() );
	}

	public function test_csv_export() {
		$this->row( [ 'error' => '=HYPERLINK("http://evil")', 'recipient' => '+989120000000', 'recipient_label' => '', 'status' => 'failed' ] );
		for ( $i = 0; $i < 120; $i++ ) {
			$this->row( [ 'recipient_label' => 'سارا, "x"' ] );
		}
		$response = $this->request( 'GET', '/messages/export' );
		$csv      = $response->get_data();
		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( "\xEF\xBB\xBF" . '"id","date","channel","provider","purpose","recipient","status","attempts","error","form","submission"', strtok( $csv, "\r\n" ) );
		$this->assertSame( 122, substr_count( $csv, "\r\n" ) );
		$this->assertContains( '"\'=HYPERLINK(""http://evil"")"', $csv );
		$this->assertContains( '"\'+989120000000"', $csv );
		$this->assertContains( '"سارا, ""x"" (desk)"', $csv );
		$this->assertSame( 2, substr_count( $this->request( 'GET', '/messages/export', [ 'status' => 'failed' ] )->get_data(), "\r\n" ) );

		ob_start();
		$served = MessagesController::serve_csv( false, $response );
		$this->assertSame( [ true, $csv ], [ $served, ob_get_clean() ] );
		$this->assertSame( false, MessagesController::serve_csv( false, new \WP_REST_Response( [ 'a' => 1 ] ) ) );
		remove_filter( 'rest_pre_serve_request', [ MessagesController::class, 'serve_csv' ] );
	}

	public function test_csv_cells() {
		foreach ( [ '=1' => "'=1", '+1' => "'+1", '-x' => "'-x", '@a' => "'@a", "\tx" => "'\tx", '-100' => '-100', '12' => '12', 'a=b' => 'a=b', '' => '' ] as $in => $out ) {
			$this->assertSame( '"' . $out . '"' . "\r\n", MessagesController::csv_line( [ (string) $in ] ) );
		}
	}

	public function test_queue_run() {
		Queue::push( Fixtures::message() );
		$this->assertSame( [ 'processed' => 1, 'sent' => 1, 'failed' => 0, 'remaining' => 0 ], $this->request( 'POST', '/queue/run' )->get_data() );
	}

	public function test_stats() {
		$data = $this->request( 'GET', '/stats', [ 'range' => '30d' ] )->get_data();
		foreach ( [ 'range', 'submissions', 'messages', 'by_channel', 'by_form', 'series', 'health', 'queue' ] as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( '{}', wp_json_encode( $data['by_channel'] ) );
		$this->assertSame( [ 'pending', 'next_run' ], array_keys( $data['queue'] ) );
		$this->assertSame( [ 'id', 'label', 'status', 'message', 'route' ], array_keys( $data['health'][0] ) );
		$this->assertSame( 400, $this->request( 'GET', '/stats', [ 'range' => 'year' ] )->get_status() );
	}

	public function test_test_alert_and_digest_with_unsaved_values() {
		foreach ( [ '/alerts/test' => 'alerts', '/digest/send' => 'digest' ] as $path => $root ) {
			$empty = $this->request( 'POST', $path )->get_data();
			$this->assertSame( [ false, 'warning' ], [ $empty['ok'], $empty['level'] ] );
			$values = [ "$root.channels.fake.enabled" => true, "$root.channels.fake.receivers" => [ Fixtures::receiver( 'Boss' ) ] ];
			$done   = $this->request( 'POST', $path, [ 'values' => $values ] )->get_data();
			$this->assertSame( [ true, 'success' ], [ $done['ok'], $done['level'] ], $path );
			$this->assertSame( 'boss', end( FakeChannel::$sent )->recipient->address );
			FakeChannel::$results = [ SendResult::failure( 'fake', 'nope' ) ];
			$bad                  = $this->request( 'POST', $path, [ 'values' => $values ] )->get_data();
			$this->assertSame( [ false, 'nope' ], [ $bad['ok'], $bad['rows'][0]['result'] ] );
			$this->assertSame( [], Settings::arr( "$root.channels.fake.receivers" ), 'nothing is saved' );
		}
	}

	public function test_alert_and_digest_settings_can_be_saved() {
		$values = [
			'alerts.enabled'                 => true,
			'alerts.when'                    => 'first',
			'alerts.max_per_hour'            => 500,
			'alerts.channels.fake.enabled'   => true,
			'alerts.channels.fake.receivers' => [ Fixtures::receiver( 'A' ) ],
			'alerts.template'                => [ '_' => 'x <script>1</script>', 'fa' => 'ی' ],
			'digest.time'                    => '08:30',
			'digest.weekday'                 => '3',
			'digest.channels.fake.enabled'   => true,
		];
		$r      = $this->request( 'POST', '/settings', [ 'values' => $values ] )->get_data();
		$this->assertSame( [], (array) $r['errors'] );
		$this->assertSame( 100, Settings::int( 'alerts.max_per_hour' ) );
		$this->assertSame( 'a', Settings::get( 'alerts.channels.fake.receivers' )[0]['address'] );
		$this->assertNotContains( '<script', Settings::get( 'alerts.template' )['_'] );
		$this->assertSame( [ '08:30', 3 ], [ Settings::str( 'digest.time' ), Settings::get( 'digest.weekday' ) ] );
		$tabs = array_column( $this->request( 'GET', '/schema', [ 'tab' => 'alerts' ] )->get_data()['sections'], 'id' );
		$this->assertSame( [ 'alerts_main', 'alerts_channels', 'alerts_template' ], $tabs );
	}
}
