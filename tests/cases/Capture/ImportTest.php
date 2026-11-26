<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Elementor\SubmissionImporter;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

/** Elementor's tables are the real ones; rows use an element id of their own and are removed again. */
final class ImportTest extends TestCase {

	use Records;

	private $element = '';
	private $post    = 0;

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		$this->element = 'i' . substr( md5( uniqid( '', true ) ), 0, 8 );
		if ( ! SubmissionImporter::is_available() ) {
			$this->fail( 'The tables of Elementor Submissions are needed.' );
		}
		$this->post = wp_insert_post( [ 'post_title' => 'Import test', 'post_type' => 'page', 'post_status' => 'private' ] );
		update_post_meta( $this->post, '__elementor_forms_snapshot', wp_slash( wp_json_encode( [ [ 'id' => $this->element, 'name' => 'Old contact', 'fields' => [
			[ 'id' => 'name', 'type' => 'text', 'label' => 'Your name' ],
			[ 'id' => 'mobile', 'type' => 'tel', 'label' => 'Mobile' ],
			[ 'id' => 'pw', 'type' => 'password', 'label' => 'Password' ],
			[ 'id' => 'cv', 'type' => 'upload', 'label' => 'CV' ],
		] ] ] ) ) );
	}

	public function tearDown(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}e_submissions WHERE element_id = %s", $this->element ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'e_submissions_values', [ 'submission_id' => $id ] );
			$wpdb->delete( $wpdb->prefix . 'e_submissions', [ 'id' => $id ] );
		}
		wp_delete_post( $this->post, true );
		wp_set_current_user( 0 );
	}

	private function key(): string {
		return $this->post . ':' . $this->element;
	}

	private function row( array $values, array $more = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'e_submissions', array_merge( [
			'type' => 'submission', 'hash_id' => wp_generate_uuid4(), 'main_meta_id' => 0, 'post_id' => $this->post, 'referer' => 'https://example.com/contact/?utm_source=x', 'referer_title' => 'Contact',
			'element_id' => $this->element, 'form_name' => 'Old contact', 'campaign_id' => 0, 'user_id' => 0, 'user_ip' => '203.0.113.77', 'user_agent' => 'UA', 'actions_count' => 0, 'actions_succeeded_count' => 0,
			'status' => 'new', 'is_read' => 0, 'meta' => '{}', 'created_at_gmt' => '2025-05-01 10:00:00', 'updated_at_gmt' => '2025-05-01 10:00:00', 'created_at' => '2025-05-01 10:00:00', 'updated_at' => '2025-05-01 10:00:00',
		], $more ) );
		$id = (int) $wpdb->insert_id;
		foreach ( $values as $key => $value ) {
			$wpdb->insert( $wpdb->prefix . 'e_submissions_values', [ 'submission_id' => $id, 'key' => $key, 'value' => $value ] );
		}
		return $id;
	}

	private function fields( array $more = [] ): array {
		return array_merge( [ 'name' => 'سارا', 'mobile' => '۰۹۱۲', 'pw' => 'Hunter2-Clear' ], $more );
	}

	public function test_import_of_one_form(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ], 'general.store_ip' => 'anonymize', 'general.capture_mode' => 'selected' ] );
		FakeChannel::install();
		$dir = Db::uploads() . '/elementor/forms';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/one.pdf', 'x' );
		$base = wp_upload_dir( null, false )['baseurl'] . '/elementor/forms/';

		$first = $this->row( $this->fields( [ 'cv' => $base . 'one.pdf , ' . $base . 'gone.pdf', 'extra' => '5' ] ), [ 'is_read' => 1 ] );
		$this->row( $this->fields( [ 'name' => 'Nima' ] ), [ 'created_at_gmt' => '2025-06-01 10:00:00' ] );
		$this->row( $this->fields(), [ 'status' => 'trash' ] );
		$this->row( [], [] );

		$captured = 0;
		$this->filter( 'fnfe_submission_captured', static function () use ( &$captured ) { ++$captured; } );
		$status = SubmissionImporter::status( $this->key() );
		$this->assertSame( [ true, 3, 0 ], [ $status['available'], $status['total'], $status['imported'] ] );
		$this->assertSame( 'Old contact', $status['forms'][0]['name'] );

		$r = SubmissionImporter::import( $this->key() );
		$this->assertSame( [ 2, 1, true ], [ $r['imported'], $r['skipped'], $r['done'] ] );
		$this->assertSame( 0, $captured );
		$this->assertCount( 0, FakeChannel::$sent );
		$this->assertSame( 0, MessageRepository::query()['total'] );

		$s = SubmissionRepository::query( [ 'order' => 'asc' ] )['items'][0];
		$this->assertSame( $first, $s->meta['elementor_submission_id'] );
		$this->assertSame( 'import', $s->meta['via'] );
		$this->assertSame( 'read', $s->status );
		$this->assertSame( '2025-05-01 10:00:00', $s->created_gmt );
		$this->assertSame( 'Your name', $s->field( 'name' )['label'] );
		$this->assertSame( '0912', $s->value( 'mobile' ) );
		$this->assertSame( '5', $s->value( 'extra' ) );
		$this->assertTrue( $s->field( 'pw' )['redacted'] );
		$this->assertSame( '203.0.113.0', $s->meta['user_ip'] );
		$this->assertCount( 1, $s->files );
		$this->assertSame( 'one.pdf', $s->files[0]->name );
		$this->assertFalse( $s->files[0]->stored );
		$this->assertNotContains( 'Hunter2', $this->dump() );

		$form = FormRepository::find_by_key( $this->key() );
		$this->assertSame( 2, $form['submissions_count'] );
		$this->assertSame( '2025-06-01 10:00:00', $form['last_submission_gmt'] );
		$this->assertSame( 'active', $form['status'] );

		// Again: nothing new.
		$r = SubmissionImporter::import( $this->key() );
		$this->assertSame( [ 0, 3 ], [ $r['imported'], $r['skipped'] ] );
		$this->assertSame( 2, SubmissionImporter::status( $this->key() )['imported'] );
	}

	/** Elementor keeps the posted text of an upload field that got no file: a typed URL of another upload is no file. */
	public function test_typed_upload_urls_are_not_files(): void {
		$base = wp_upload_dir( null, false )['baseurl'];
		wp_mkdir_p( Db::uploads() . '/woocommerce_uploads' );
		wp_mkdir_p( Db::uploads() . '/elementor/forms' );
		file_put_contents( Db::uploads() . '/woocommerce_uploads/book.pdf', 'x' );
		file_put_contents( Db::uploads() . '/elementor/forms/real.pdf', 'x' );
		$this->row( $this->fields( [ 'cv' => $base . '/woocommerce_uploads/book.pdf , ' . $base . '/elementor/forms/real.pdf' ] ) );
		SubmissionImporter::import( $this->key() );
		$s = SubmissionRepository::query()['items'][0];
		$this->assertSame( [ 'real.pdf' ], array_map( static function ( $f ) { return $f->name; }, $s->files ) );
	}

	public function test_captured_submissions_are_not_imported_again(): void {
		$id = $this->row( $this->fields() );
		SubmissionRepository::insert( Fixtures::submission( [ 'meta' => [ 'elementor_submission_id' => $id ] ] ) );
		$this->assertSame( 0, SubmissionImporter::import( $this->key() )['imported'] );
	}

	public function test_batches_and_unknown_posts(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->row( [ 'name' => "Person $i" ] );
		}
		$r = SubmissionImporter::import( $this->key(), 0, 2 );
		$this->assertSame( [ 2, false ], [ $r['imported'], $r['done'] ] );
		$r = SubmissionImporter::import( $this->key(), $r['cursor'], 2 );
		$r = SubmissionImporter::import( $this->key(), $r['cursor'], 2 );
		$this->assertSame( [ 1, true ], [ $r['imported'], $r['done'] ] );
		$this->assertSame( 5, SubmissionRepository::count() );

		$gone = $this->row( [ 'name' => 'x' ], [ 'post_id' => 99999999 ] );
		SubmissionImporter::import( '99999999:' . $this->element );
		$this->assertSame( 'missing', FormRepository::find_by_key( '99999999:' . $this->element )['status'] );
	}

	public function test_latest_id(): void {
		$id = $this->row( [ 'name' => 'x' ], [ 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ] );
		$this->assertSame( $id, SubmissionImporter::latest_id( $this->post, $this->element ) );
		$this->assertSame( 0, SubmissionImporter::latest_id( $this->post, 'other' ) );
		$this->freeze( time() + 60 );
		$this->assertSame( 0, SubmissionImporter::latest_id( $this->post, $this->element ) );
	}

	public function test_route(): void {
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$this->row( $this->fields() );
		$form = FormRepository::upsert( $this->key(), [ 'post_id' => $this->post, 'element_id' => $this->element, 'name' => 'Contact', 'source' => 'pro', 'status' => 'active' ] );

		$get = new \WP_REST_Request( 'GET', '/fnfe/v1/submissions/import' );
		$get->set_query_params( [ 'form_id' => $form ] );
		$d = rest_do_request( $get )->get_data();
		$this->assertKeys( [ 'available', 'total', 'imported', 'forms' ], $d );
		$this->assertSame( [ 'form_key' => $this->key(), 'form_id' => $form, 'name' => 'Contact', 'total' => 1, 'imported' => 0 ], $d['forms'][0] );

		$post = new \WP_REST_Request( 'POST', '/fnfe/v1/submissions/import' );
		$post->set_body_params( [ 'form_id' => $form ] );
		$d = rest_do_request( $post )->get_data();
		$this->assertKeys( [ 'ok', 'imported', 'skipped', 'cursor', 'done' ], $d );
		$this->assertSame( [ 1, true ], [ $d['imported'], $d['done'] ] );

		$post->set_body_params( [ 'form_id' => 99999 ] );
		$this->assertSame( 404, rest_do_request( $post )->get_status() );
		Settings::set( 'general.store_submissions', false );
		$post->set_body_params( [ 'form_id' => $form ] );
		$this->assertSame( 409, rest_do_request( $post )->get_status() );
	}
}
