<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Elementor\FormScanner;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

final class ScannerTest extends TestCase {

	use Records;

	private $posts = [];

	public function setUp(): void {
		Db::reset();
		$this->posts = [];
		if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
			$this->fail( 'Elementor Pro is needed.' );
		}
	}

	public function tearDown(): void {
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
	}

	private function page( array $elements, array $post = [] ): int {
		$id            = $this->document( $elements, $post );
		$this->posts[] = $id;
		return $id;
	}

	private function scan(): array {
		$guard = 0;
		do {
			$result = FormScanner::run( 10 );
		} while ( ! $result['done'] && ++$guard < 50 );
		$this->assertTrue( $result['done'] );
		return $result;
	}

	private function status( int $post, string $element ): string {
		return (string) ( FormRepository::find_by_key( "$post:$element" )['status'] ?? '' );
	}

	public function test_finds_forms_in_every_kind_of_document(): void {
		$rows    = [ $this->row( 'name' ), $this->row( 'topic', 'select', [ 'field_options' => "Sales|s\nSupport" ] ), $this->row( 'step', 'step' ), $this->row( 'x', 'text', [ 'field_label' => '' ] ) ];
		$page    = $this->page( [ $this->element( 'frm0001', $rows, [ 'submit_actions' => [ 'fnfe' ], 'fnfe_channels' => [ 'email' ] ] ) ] );
		$popup   = $this->page( [ $this->element( 'frm0002', $rows ) ], [ 'post_type' => 'elementor_library', 'post_title' => 'Popup' ] );
		$header  = $this->page( [ [ 'id' => 'in', 'elType' => 'container', 'elements' => [ $this->element( 'frm0003', $rows, [ 'form_name' => '' ] ) ] ] ], [ 'post_type' => 'elementor_library' ] );
		$draft   = $this->page( [ $this->element( 'frm0004', $rows ) ], [ 'post_status' => 'draft' ] );
		$auto    = $this->page( [ $this->element( 'frm0005', $rows ) ], [ 'post_status' => 'auto-draft' ] );
		$plain   = $this->page( [ $this->element( 'txt0001', [], [], 'text-editor' ) ] );
		$result  = $this->scan();

		$form = FormRepository::find_by_key( "$page:frm0001" );
		$this->assertSame( 'Form frm0001', $form['name'] );
		$this->assertSame( 'pro', $form['source'] );
		$this->assertSame( 'Scan test', $form['post_title'] );
		$this->assertSame( 'page', $form['post_type'] );
		$this->assertSame( [ 'fnfe_channels' => [ 'email' ] ], $form['editor'] );
		$this->assertSame( [
			[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
			[ 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => [ 'Sales|s', 'Support' ] ],
			[ 'id' => 'x', 'type' => 'text', 'label' => 'x' ],
		], $form['fields'] );
		$this->assertSame( 'active', $this->status( $popup, 'frm0002' ) );
		$this->assertContains( 'frm0003', FormRepository::find_by_key( "$header:frm0003" )['name'] );
		$this->assertSame( 'active', $this->status( $draft, 'frm0004' ) );
		$this->assertSame( '', $this->status( $auto, 'frm0005' ) );
		$this->assertSame( '', $this->status( $plain, 'txt0001' ) );
		$this->assertTrue( $result['added'] >= 4 );
		$this->assertTrue( (int) State::get( 'scan_last' ) > 0 );
		$this->assertNull( State::get( 'scan_cursor' ) );

		$again = $this->scan();
		$this->assertSame( 0, $again['added'] );
		$this->assertSame( 0, $again['updated'] );
		$this->assertSame( $result['found'], $again['found'] );

		update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( [ $this->element( 'frm0001', $rows, [ 'form_name' => 'Renamed' ] ) ] ) ) );
		$this->assertSame( 1, $this->scan()['updated'] );
		$this->assertSame( 'Renamed', FormRepository::find_by_key( "$page:frm0001" )['name'] );
	}

	public function test_global_widget_reference_is_ignored(): void {
		$template = $this->page( [ $this->element( 'glb0001', [ $this->row( 'name' ) ] ) ], [ 'post_type' => 'elementor_library' ] );
		$page     = $this->page( [ [ 'id' => 'ref0001', 'elType' => 'widget', 'widgetType' => 'global', 'templateID' => $template, 'settings' => [], 'elements' => [] ] ] );
		$inline   = $this->page( [ array_merge( $this->element( 'ref0002', [ $this->row( 'name' ) ] ), [ 'templateID' => $template ] ) ] );
		$this->scan();
		$this->assertSame( 'active', $this->status( $template, 'glb0001' ) );
		$this->assertSame( '', $this->status( $page, 'ref0001' ) );
		$this->assertSame( '', $this->status( $inline, 'ref0002' ) );
	}

	public function test_missing_and_back(): void {
		$page = $this->page( [ $this->element( 'frm0011', [ $this->row( 'name' ) ] ) ] );
		$this->scan();
		$id = FormRepository::find_by_key( "$page:frm0011" )['id'];
		FormRepository::save_config( $id, [ 'enabled' => 'on' ] );
		FormRepository::upsert( 'api:custom', [ 'name' => 'API', 'source' => 'api' ] );

		$this->freeze( time() + 60 );
		update_post_meta( $page, '_elementor_data', '[]' );
		$this->assertSame( 1, $this->scan()['missing'] );
		$this->assertSame( 'missing', $this->status( $page, 'frm0011' ) );
		$this->assertSame( 'on', FormRepository::find_by_key( "$page:frm0011" )['config']['enabled'] );
		$this->assertSame( 'active', FormRepository::find_by_key( 'api:custom' )['status'] );

		update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( [ $this->element( 'frm0011', [ $this->row( 'name' ) ] ) ] ) ) );
		$this->scan();
		$this->assertSame( 'active', $this->status( $page, 'frm0011' ) );
		$this->assertSame( $id, FormRepository::find_by_key( "$page:frm0011" )['id'] );
	}

	public function test_trash(): void {
		$page = $this->page( [ $this->element( 'frm0021', [ $this->row( 'name' ) ] ) ], [ 'post_status' => 'publish' ] );
		$this->scan();
		wp_trash_post( $page );
		$this->assertSame( 'trashed', $this->status( $page, 'frm0021' ) );
		$this->freeze( time() + 60 );
		$this->scan();
		$this->assertSame( 'trashed', $this->status( $page, 'frm0021' ) );
		wp_untrash_post( $page );
		$this->assertSame( 'active', $this->status( $page, 'frm0021' ) );
		wp_trash_post( $page );
		wp_delete_post( $page, true );
		$this->assertSame( 'missing', $this->status( $page, 'frm0021' ) );
	}

	public function test_trashed_while_inactive(): void {
		$page = $this->page( [ $this->element( 'frm0022', [ $this->row( 'name' ) ] ) ], [ 'post_status' => 'publish' ] );
		$this->scan();
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'trash' ], [ 'ID' => $page ] );
		clean_post_cache( $page );
		$this->freeze( time() + 60 );
		$this->scan();
		$this->assertSame( 'trashed', $this->status( $page, 'frm0022' ) );
		$wpdb->delete( $wpdb->posts, [ 'ID' => $page ] );
		clean_post_cache( $page );
		$this->freeze( time() + 120 );
		$this->scan();
		$this->assertSame( 'missing', $this->status( $page, 'frm0022' ) );
		delete_post_meta( $page, '_elementor_data' );
		delete_post_meta( $page, '_elementor_edit_mode' );
	}

	public function test_pagination_and_resume(): void {
		for ( $i = 0; $i < 54; $i++ ) {
			$this->page( [ $this->element( sprintf( 'pag%04d', $i ), [ $this->row( 'name' ) ] ) ] );
		}
		// Budget of zero work: one document per call at least, never an endless loop.
		State::set( 'scan_cursor', [ 'meta_id' => 0, 'started' => gmdate( 'Y-m-d H:i:s' ), 'found' => 0 ] );
		$result = $this->scan();
		$this->assertTrue( $result['found'] >= 54 );
		$this->assertTrue( $result['documents'] >= 54 );
		$this->assertSame( 54, FormRepository::query( [ 'search' => ':pag', 'per_page' => 100 ] )['total'] );

		// A stored position is continued.
		Db::reset();
		global $wpdb;
		$metas = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key = %s AND post_id IN ( ' . implode( ',', $this->posts ) . ' ) ORDER BY meta_id', $wpdb->postmeta, '_elementor_data' ) );
		State::set( 'scan_cursor', [ 'meta_id' => (int) $metas[49], 'started' => gmdate( 'Y-m-d H:i:s' ), 'found' => 50, 'documents' => 50 ] );
		$result = $this->scan();
		$this->assertSame( 4, FormRepository::query( [ 'search' => ':pag', 'per_page' => 100 ] )['total'] );
		$this->assertTrue( $result['found'] >= 54 );
	}

	public function test_lock(): void {
		Lock::acquire( 'scan', 60 );
		$this->assertFalse( FormScanner::run( 1 )['done'] );
		Lock::release( 'scan' );
	}

	public function test_saved_document(): void {
		$page     = $this->page( [ $this->element( 'frm0031', [ $this->row( 'name' ) ] ), $this->element( 'frm0032', [ $this->row( 'name' ) ] ) ] );
		$document = \Elementor\Plugin::$instance->documents->get( $page );
		FormScanner::on_document_saved( $document );
		$this->assertSame( 'active', $this->status( $page, 'frm0032' ) );
		update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( [ $this->element( 'frm0031', [ $this->row( 'name' ) ] ) ] ) ) );
		FormScanner::on_document_saved( $document );
		$this->assertSame( 'active', $this->status( $page, 'frm0031' ) );
		$this->assertSame( 'missing', $this->status( $page, 'frm0032' ) );
		FormScanner::on_document_saved( null );
		FormScanner::on_document_saved( new \stdClass() );
	}

	public function test_translation_links_to_the_original(): void {
		if ( I18n::provider() !== '' ) {
			return;
		}
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '0-test' );
		}
		// The translation has the smaller id, so it is seen first.
		$translation = $this->page( [ $this->element( 'trn0001', [ $this->row( 'name' ) ] ), $this->element( 'trn0002', [ $this->row( 'name' ) ] ) ] );
		$original    = $this->page( [ $this->element( 'trn0001', [ $this->row( 'name' ) ] ) ] );
		$this->filter( 'wpml_active_languages', static function () { return [ 'fa' => [ 'code' => 'fa' ], 'en' => [ 'code' => 'en' ] ]; } );
		$this->filter( 'wpml_default_language', static function () { return 'fa'; } );
		$this->filter( 'wpml_object_id', static function ( $id ) use ( $translation, $original ) { return (int) $id === $translation ? $original : $id; } );
		$this->scan();
		$first = FormRepository::find_by_key( "$original:trn0001" );
		$this->assertSame( $first['id'], FormRepository::find_by_key( "$translation:trn0001" )['config']['link'] );
		$this->assertSame( 0, $first['config']['link'] );
		$this->assertSame( 0, FormRepository::find_by_key( "$translation:trn0002" )['config']['link'] );

		// Unlinking is respected by later scans.
		FormRepository::save_config( FormRepository::find_by_key( "$translation:trn0001" )['id'], [ 'link' => 0 ] );
		$this->scan();
		$this->assertSame( 0, FormRepository::find_by_key( "$translation:trn0001" )['config']['link'] );
	}

	public function test_hostile_data(): void {
		$page = $this->page( [
			array_merge( $this->element( 'bad0001', [ 'x', [ 'custom_id' => [ 'a' ] ], [ 'custom_id' => 'ok', 'field_type' => [ 1 ], 'field_label' => [ 2 ], 'field_options' => [ [ 'value' => '<b>1</b>' ], 5 ] ] ] ), [ 'settings' => [ 'form_name' => [ 'x' ], 'form_fields' => [ 'x', [ 'custom_id' => [ 'a' ] ], [ 'custom_id' => 'ok', 'field_type' => [ 1 ], 'field_label' => '<b>L</b>', 'field_options' => [ [ 'value' => '<b>1</b>' ], 5 ] ] ] ] ] ),
			[ 'id' => [ 'x' ], 'elType' => 'widget', 'widgetType' => 'form' ],
			[ 'id' => 'nos0001', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => 'x', 'elements' => 'y' ],
		] );
		$this->scan();
		$form = FormRepository::find_by_key( "$page:bad0001" );
		$this->assertSame( [ [ 'id' => 'ok', 'type' => 'text', 'label' => 'L', 'options' => [ '1', '5' ] ] ], array_map( static function ( $f ) { $f['type'] = $f['type'] === '' ? 'text' : $f['type']; return $f; }, $form['fields'] ) );
		$this->assertSame( 'active', $this->status( $page, 'nos0001' ) );
	}
}
