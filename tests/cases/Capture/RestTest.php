<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Rest\SubmissionsController;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

final class RestTest extends TestCase {

	use Records;

	private static $admin = 0;

	public static function setUpClass(): void {
		self::$admin = (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
	}

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		FakeChannel::install();
		wp_set_current_user( self::$admin );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		Settings::clear_overlay();
	}

	private function call( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/fnfe/v1' . $path );
		if ( $method === 'GET' || $method === 'DELETE' ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( (object) $params ) );
		}
		return rest_do_request( $request );
	}

	private function json( string $method, string $path, array $params = [], int $status = 200 ): array {
		$response = $this->call( $method, $path, $params );
		$this->assertSame( $status, $response->get_status(), "$method $path " . wp_json_encode( $response->get_data() ) );
		return json_decode( wp_json_encode( rest_get_server()->response_to_data( $response, false ) ), true );
	}

	private function form( string $key = '45:a1b2c3d', array $data = [] ): int {
		return FormRepository::upsert( $key, array_merge( [ 'post_id' => (int) $key, 'element_id' => substr( strrchr( $key, ':' ), 1 ), 'source' => 'pro', 'name' => 'Contact', 'fields' => Fixtures::form_fields(), 'status' => 'active' ], $data ) );
	}

	private function submission( array $more = [] ): int {
		return SubmissionRepository::insert( Fixtures::submission( $more ) );
	}

	public function test_permissions(): void {
		$routes = [
			[ 'GET', '/forms' ], [ 'POST', '/forms/scan' ], [ 'GET', '/forms/1' ], [ 'GET', '/forms/key/1:abc' ], [ 'PUT', '/forms/1', [ 'config' => [ 'enabled' => 'on' ] ] ], [ 'DELETE', '/forms/1' ], [ 'POST', '/forms/1/test' ],
			[ 'GET', '/submissions' ], [ 'GET', '/submissions/1' ], [ 'PATCH', '/submissions/1', [ 'status' => 'read' ] ], [ 'DELETE', '/submissions/1' ], [ 'POST', '/submissions/1/resend' ], [ 'POST', '/submissions/bulk', [ 'action' => 'read', 'ids' => [ 1 ] ] ], [ 'GET', '/submissions/export' ],
			[ 'GET', '/submissions/import' ], [ 'POST', '/submissions/import' ],
		];
		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->call( $route[0], $route[1], $route[2] ?? [] )->get_status(), $route[1] );
		}
		// Only submissions: no delete, no import, no resend, no forms.
		wp_set_current_user( self::$admin );
		$this->filter( 'fnfe_capability', static function ( $cap, $own ) { return $own === 'fnfe_view_submissions' ? 'manage_options' : 'do_not_allow'; }, 10, 2 );
		$id = $this->submission();
		$this->assertSame( 200, $this->call( 'GET', '/submissions' )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/submissions/bulk', [ 'action' => 'read', 'ids' => [ $id ] ] )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/submissions/bulk', [ 'action' => 'delete', 'ids' => [ $id ] ] )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/submissions/' . $id )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/submissions/import' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/submissions/' . $id . '/resend' )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/forms' )->get_status() );
		$this->assertNotNull( SubmissionRepository::find( $id ) );
	}

	public function test_forms_list(): void {
		$this->form();
		$this->form( '46:bbb', [ 'name' => 'Alpha', 'source' => 'atomic' ] );
		$this->form( '47:ccc', [ 'name' => 'Gone', 'status' => 'missing' ] );
		$d = $this->json( 'GET', '/forms' );
		$this->assertKeys( [ 'items', 'total', 'page', 'per_page', 'last_scan' ], $d );
		$this->assertSame( [ 'Alpha', 'Contact', 'Gone' ], array_column( $d['items'], 'name' ) );
		$this->assertKeys( [ 'id', 'form_key', 'name', 'source', 'post_id', 'post_title', 'post_type', 'edit_url', 'status', 'enabled', 'active', 'submissions_count', 'last_submission', 'fields_count', 'channels' ], $d['items'][0] );
		$this->assertSame( 1, $this->json( 'GET', '/forms', [ 'status' => 'missing' ] )['total'] );
		$this->assertSame( 2, $this->json( 'GET', '/forms', [ 'source' => 'pro' ] )['total'] );
		$this->assertSame( 1, $this->json( 'GET', '/forms', [ 'search' => 'Alph' ] )['total'] );
		$this->assertSame( 400, $this->call( 'GET', '/forms', [ 'orderby' => 'password' ] )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/forms', [ 'per_page' => 1000 ] )->get_status() );
	}

	public function test_form_detail(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ] ] );
		$id = $this->form( '45:a1b2c3d', [ 'editor' => [ 'fnfe_receivers_fake' => 'x' ] ] );
		$d  = $this->json( 'GET', '/forms/' . $id );
		$this->assertKeys( [ 'form', 'fields', 'config', 'editor', 'effective' ], $d );
		$this->assertCount( 3, $d['fields'] );
		$this->assertSame( 'editor', $d['effective']['channels']['fake']['source'] );
		$this->assertTrue( $d['effective']['channels']['fake']['active'] );
		$this->assertSame( 1, $d['effective']['channels']['fake']['receivers'] );
		$this->assertContains( '"channels":{}', wp_json_encode( $this->call( 'GET', '/forms/' . $id )->get_data()['config'] ) );
		$this->assertSame( $id, $this->json( 'GET', '/forms/key/45:a1b2c3d' )['form']['id'] );
		$this->assertSame( 404, $this->call( 'GET', '/forms/999' )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/forms/key/999999:none' )->get_status() );
	}

	public function test_form_by_key_is_found_before_a_scan(): void {
		$post = $this->document( [ $this->element( 'key0001', [ $this->row( 'name' ) ] ) ] );
		$d    = $this->json( 'GET', "/forms/key/$post:key0001" );
		$this->assertSame( 'Form key0001', $d['form']['name'] );
		wp_delete_post( $post, true );
	}

	public function test_form_update(): void {
		$id = $this->form();
		$d  = $this->json( 'PUT', '/forms/' . $id, [ 'config' => [
			'enabled'  => 'on',
			'evil'     => 1,
			'channels' => [ 'fake' => [ 'enabled' => 'on', 'receivers_mode' => 'replace', 'receivers' => [ [ 'address' => 'DESK' ], [ 'address' => 'invalid' ] ] ] ],
			'link'     => 9999,
		] ] );
		$this->assertSame( 'on', $d['config']['enabled'] );
		$this->assertFalse( isset( $d['config']['evil'] ) );
		$this->assertSame( 'desk', $d['config']['channels']['fake']['receivers'][0]['address'] );
		$this->assertSame( 0, $d['config']['link'] );
		$this->assertArrayHasKey( 'link', $d['errors'] );
		$this->assertFalse( $d['ok'] );
		$this->assertSame( 400, $this->call( 'PUT', '/forms/' . $id, [] )->get_status() );
		$this->assertSame( 404, $this->call( 'PUT', '/forms/999', [ 'config' => [ 'a' => 1 ] ] )->get_status() );
		$ok = $this->json( 'PUT', '/forms/' . $id, [ 'config' => [ 'enabled' => 'off' ] ] );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( [], $ok['errors'] );
	}

	public function test_form_test_sends_now(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ], 'general.delivery_mode' => 'background' ] );
		$id = $this->form();
		$d  = $this->json( 'POST', "/forms/$id/test" );
		$this->assertTrue( $d['ok'] );
		$this->assertCount( 1, FakeChannel::$sent );
		$this->assertSame( [ 'channel' => 'fake', 'recipient' => 'Receiver desk', 'ok' => true, 'error' => '' ], $d['messages'][0] );
		$this->assertSame( 0, SubmissionRepository::count() );
		$this->assertSame( 'background', Settings::str( 'general.delivery_mode' ), 'the overlay is gone' );

		FakeChannel::$results = [ SendResult::failure( 'fake', 'No route', true ) ];
		$d = $this->json( 'POST', "/forms/$id/test", [ 'channels' => [ 'fake', 'unknown' ] ] );
		$this->assertFalse( $d['ok'] );
		$this->assertContains( 'No route', $d['messages'][0]['error'] );
		$this->assertSame( 404, $this->call( 'POST', '/forms/999/test' )->get_status() );
	}

	public function test_form_delete(): void {
		$id = $this->form();
		$this->assertSame( 409, $this->call( 'DELETE', '/forms/' . $id )->get_status() );
		FormRepository::upsert( '45:a1b2c3d', [ 'status' => 'missing' ] );
		$s = $this->submission( [ 'form_id' => $id ] );
		MessageRepository::insert( [ 'submission_id' => $s, 'form_id' => $id, 'channel' => 'fake', 'status' => 'sent' ] );
		$this->assertTrue( $this->json( 'DELETE', '/forms/' . $id )['ok'] );
		$this->assertNotNull( SubmissionRepository::find( $s ) );

		$id = $this->form( '45:a1b2c3d', [ 'status' => 'missing' ] );
		$s  = $this->submission( [ 'form_id' => $id ] );
		$this->json( 'DELETE', '/forms/' . $id, [ 'with_submissions' => '1' ] );
		$this->assertNull( SubmissionRepository::find( $s ) );
	}

	public function test_scan_route(): void {
		$this->assertKeys( [ 'found', 'added', 'updated', 'missing', 'done', 'documents' ], $this->json( 'POST', '/forms/scan' ) );
	}

	public function test_submissions_list(): void {
		$form = $this->form();
		$a    = $this->submission( [ 'form_id' => $form ] );
		$b    = $this->submission( [ 'form_id' => $form, 'status' => 'done', 'created_gmt' => '2026-01-02 10:00:00' ] );
		$c    = $this->submission( [ 'form_id' => 99, 'status' => 'spam' ] );
		$d    = $this->json( 'GET', '/submissions' );
		$this->assertSame( [ $b, $a ], array_column( $d['items'], 'id' ) );
		$this->assertSame( [ 'new' => 1, 'read' => 0, 'done' => 1, 'spam' => 1, 'trash' => 0 ], $d['counts'] );
		$this->assertKeys( [ 'id', 'form_id', 'form_name', 'status', 'lang', 'created', 'created_local', 'preview', 'files_count', 'delivery_status' ], $d['items'][0] );
		$this->assertSame( [ $c ], array_column( $this->json( 'GET', '/submissions', [ 'status' => 'spam' ] )['items'], 'id' ) );
		$this->assertSame( 1, $this->json( 'GET', '/submissions', [ 'form_id' => $form, 'status' => 'done,trash' ] )['total'] );
		$this->assertSame( 1, $this->json( 'GET', '/submissions', [ 'from' => '2026-01-02', 'to' => '2026-01-02' ] )['total'] );
		$this->assertSame( 2, $this->json( 'GET', '/submissions', [ 'search' => 'سارا' ] )['total'] );
		$this->assertSame( 0, $this->json( 'GET', '/submissions', [ 'search' => "' OR 1=1 --" ] )['total'] );
	}

	public function test_submission_item_status_and_delete(): void {
		$id = $this->submission();
		MessageRepository::insert( [ 'submission_id' => $id, 'channel' => 'fake', 'status' => 'sent', 'recipient' => 'desk' ] );
		$d = $this->json( 'GET', '/submissions/' . $id );
		$this->assertKeys( [ 'submission', 'messages' ], $d );
		$this->assertSame( 'fake', $d['messages'][0]['channel'] );
		$this->assertArrayHasKey( 'fields', $d['submission'] );

		$events = [];
		$this->filter( 'fnfe_submission_status_changed', static function ( ...$args ) use ( &$events ) { $events[] = $args; }, 10, 4 );
		$this->assertSame( 'done', $this->json( 'PATCH', '/submissions/' . $id, [ 'status' => 'done' ] )['submission']['status'] );
		$this->json( 'PATCH', '/submissions/' . $id, [ 'status' => 'done' ] );
		$this->assertSame( [ [ $id, 'done', 'new', 'admin' ] ], $events );
		$this->assertSame( 400, $this->call( 'PATCH', '/submissions/' . $id, [ 'status' => 'gone' ] )->get_status() );
		$this->assertSame( 404, $this->call( 'PATCH', '/submissions/999', [ 'status' => 'done' ] )->get_status() );

		$this->assertTrue( $this->json( 'DELETE', '/submissions/' . $id )['ok'] );
		$this->assertSame( 404, $this->call( 'GET', '/submissions/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/submissions/' . $id )->get_status() );
	}

	public function test_bulk(): void {
		$ids = [ $this->submission(), $this->submission() ];
		$this->assertSame( 2, $this->json( 'POST', '/submissions/bulk', [ 'action' => 'spam', 'ids' => array_merge( $ids, [ 999 ] ) ] )['affected'] );
		$this->assertSame( 2, SubmissionRepository::count( [ 'status' => 'spam' ] ) );
		$this->assertSame( 2, $this->json( 'POST', '/submissions/bulk', [ 'action' => 'delete', 'ids' => $ids ] )['affected'] );
		$this->assertSame( 0, SubmissionRepository::count() );
		$this->assertSame( 400, $this->call( 'POST', '/submissions/bulk', [ 'action' => 'drop', 'ids' => [ 1 ] ] )->get_status() );
	}

	public function test_resend(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ] ] );
		$id = $this->submission( [ 'form_id' => $this->form() ] );
		$this->assertSame( 1, $this->json( 'POST', "/submissions/$id/resend" )['queued'] );
		$this->assertCount( 1, MessageRepository::for_submission( $id ) );
		$this->assertSame( 404, $this->call( 'POST', '/submissions/999/resend' )->get_status() );
	}

	public function test_csv(): void {
		$form = $this->form();
		$this->submission( [ 'form_id' => $form, 'form_name' => 'Persian, "quoted"', 'fields' => [
			'a' => Fixtures::field( 'a', '=HYPERLINK("http://evil")', 'text', '=Label' ),
			'b' => Fixtures::field( 'b', '+98912' ),
			'c' => Fixtures::field( 'c', '-1' ),
			'd' => Fixtures::field( 'd', "@SUM(1)\nسارا" ),
			'e' => Fixtures::field( 'e', "\tx" ),
			'f' => Fixtures::field( 'f', 'normal \\" text' ),
		] ] );
		$this->submission( [ 'form_id' => $form, 'fields' => [ 'z' => Fixtures::field( 'z', 'last' ) ], 'created_gmt' => '2026-01-02 10:00:00' ] );
		$handle = fopen( 'php://temp', 'w+b' );
		SubmissionsController::write_csv( $handle, [ 'form_id' => $form, 'order' => 'asc' ] );
		rewind( $handle );
		$this->assertSame( "\xEF\xBB\xBF", fread( $handle, 3 ) );
		$rows = [];
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			$rows[] = $row;
		}
		$this->assertCount( 3, $rows );
		$this->assertSame( [ "'=Label", 'B', 'C', 'D', 'E', 'F', 'Z' ], array_slice( $rows[0], 7 ) );
		$this->assertSame( "'=HYPERLINK(\"http://evil\")", $rows[1][7] );
		$this->assertSame( "'+98912", $rows[1][8] );
		$this->assertSame( "'-1", $rows[1][9] );
		$this->assertSame( "'@SUM(1)\nسارا", $rows[1][10] );
		$this->assertSame( "'\tx", $rows[1][11] );
		$this->assertSame( 'normal \\" text', $rows[1][12] );
		$this->assertSame( '', $rows[1][13] );
		$this->assertSame( 'last', $rows[2][13] );

		$response = $this->call( 'GET', '/submissions/export', [ 'format' => 'csv' ] );
		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertContains( 'attachment; filename="submissions-', $response->get_headers()['Content-Disposition'] );
		remove_all_filters( 'rest_pre_serve_request' );
	}
}
