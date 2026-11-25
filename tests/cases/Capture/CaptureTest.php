<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Elementor\AbstractRecordSource;
use AmirhpCom\FormsNotifier\Elementor\ProFormSource;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

final class CaptureTest extends TestCase {

	use Records;

	private $server = [];

	public function setUp(): void {
		Db::reset();
		$this->skipped  = [];
		$this->captured = [];
		$this->server   = $_SERVER;
		$_POST          = [];
		$_FILES         = [];
		$_SERVER['REMOTE_ADDR']     = '203.0.113.57';
		$_SERVER['HTTP_USER_AGENT'] = 'Test Agent/1.0';
		$this->listen();
	}

	public function tearDown(): void {
		$_SERVER = $this->server;
		$_POST   = [];
		$_FILES  = [];
		Settings::clear_overlay();
	}

	public function test_form_without_action_is_captured_once(): void {
		$s = $this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 1, $this->total() );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( '45:abc1234', $s->form_key );
		$this->assertSame( 'auto', $s->meta['via'] );
		$this->assertSame( 'Contact', $s->form_name );
		$this->assertMatches( '/^[a-f0-9]{32}$/', $s->uuid );
		$form = FormRepository::find_by_key( '45:abc1234' );
		$this->assertSame( 1, $form['submissions_count'] );
		$this->assertSame( [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ] ], $form['fields'] );
	}

	public function test_form_with_action_is_still_captured_once(): void {
		$s = $this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe', 'email' ], 'fnfe_channels' => [ 'email' ] ] ) );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( 'action', $s->meta['via'] );
		$this->assertSame( [ 'email' ], $s->meta['editor']['fnfe_channels'] );
	}

	public function test_selected_mode(): void {
		Settings::set( 'general.capture_mode', 'selected' );
		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 0, $this->total() );
		$this->assertSame( [ 'disabled' ], $this->skipped );

		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe' ] ] ) );
		$this->assertSame( 1, $this->total() );

		$this->submit( [ 'name' => 'Nima' ], $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe' ], 'fnfe_enabled' => 'no' ] ) );
		$this->assertSame( 1, $this->total() );

		$form = FormRepository::find_by_key( '45:abc1234' );
		FormRepository::save_config( $form['id'], [ 'enabled' => 'on' ] );
		$this->submit( [ 'name' => 'Omid' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 2, $this->total() );

		FormRepository::save_config( $form['id'], [ 'enabled' => 'off' ] );
		$this->submit( [ 'name' => 'Ali' ], $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe' ] ] ) );
		$this->assertSame( 2, $this->total() );
	}

	public function test_editor_switch_needs_the_action(): void {
		// Elementor hides the controls of an action that is not added, so leftovers do not count.
		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ], [ 'fnfe_enabled' => 'no' ] ) );
		$this->assertSame( 1, $this->total() );
	}

	public function test_event_key_is_handled_once(): void {
		$draft = Fixtures::submission();
		$this->assertFalse( Capture::seen( 'test:1' ) );
		$this->assertNotNull( Capture::handle( $draft, 'test:1' ) );
		$this->assertTrue( Capture::seen( 'test:1' ) );
		$this->assertNull( Capture::handle( Fixtures::submission( [ 'form_key' => '46:zzz' ] ), 'test:1' ) );
		$this->assertSame( 1, $this->total() );
	}

	public function test_shutdown_fallback_captures_marked_records(): void {
		$source   = new ProFormSource();
		$settings = $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe' ] ] );
		$record   = new FakeRecord( [ 'name' => 'Sara' ], $settings );
		$handler  = new FakeHandler( [ 'id' => 'abc1234' ] );
		$source->on_process( $record, $handler );
		$source->mark_action( $record, $handler );
		$this->assertSame( 0, $this->total() );
		AbstractRecordSource::on_shutdown();
		$this->assertSame( 1, $this->total() );
		AbstractRecordSource::on_shutdown();
		$source->on_new_record( $record, $handler );
		$this->assertSame( 1, $this->total() );
	}

	public function test_veto(): void {
		$this->filter( 'fnfe_should_capture', '__return_false' );
		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 0, $this->total() );
		$this->assertSame( [ 'filtered' ], $this->skipped );
	}

	public function test_empty_submission_is_skipped(): void {
		$this->submit( [ 'name' => '  ' ], $this->settings( [ $this->row( 'name' ), $this->row( 'ok', 'acceptance' ) ] ) );
		$this->assertSame( [ 'empty' ], $this->skipped );
		$this->assertSame( 0, $this->total() );
	}

	public function test_duplicate_guard(): void {
		$settings = $this->settings( [ $this->row( 'name' ) ] );
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( [ 'duplicate' ], $this->skipped );

		$this->submit( [ 'name' => 'Sara 2' ], $settings );
		$this->assertSame( 2, $this->total() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.58';
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$this->assertSame( 3, $this->total() );

		$draft = Fixtures::submission();
		Capture::ingest( $draft );
		Capture::ingest( Fixtures::submission() );
		$this->assertSame( 5, $this->total() );

		Settings::set( 'general.duplicate_window', 0 );
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$this->assertSame( 6, $this->total() );
	}

	public function test_duplicate_guard_after_the_window(): void {
		$now      = time();
		$settings = $this->settings( [ $this->row( 'name' ) ] );
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; }, PHP_INT_MAX );
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$now += 31;
		$this->submit( [ 'name' => 'Sara' ], $settings );
		$this->assertSame( 2, $this->total() );
	}

	public function test_duplicate_guard_with_shortened_or_no_address(): void {
		$settings = $this->settings( [ $this->row( 'vote', 'radio' ) ] );
		foreach ( [ 'anonymize', 'none' ] as $i => $mode ) {
			Db::reset();
			Settings::set( 'general.store_ip', $mode );
			$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
			$this->submit( [ 'vote' => 'yes' ], $settings );
			$this->submit( [ 'vote' => 'yes' ], $settings );
			$this->assertSame( 1, $this->total(), $mode );
			$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
			$this->submit( [ 'vote' => 'yes' ], $settings );
			$this->assertSame( 2, $this->total(), $mode );
			$this->assertNotContains( '198.51.100.2', $this->dump(), $mode );
		}
	}

	public function test_all_native_types(): void {
		$_POST = [ 'referrer' => 'https://example.com/contact/' ];
		$rows  = [
			$this->row( 'text' ),
			$this->row( 'email', 'email' ),
			$this->row( 'area', 'textarea' ),
			$this->row( 'url', 'url' ),
			$this->row( 'tel', 'tel' ),
			$this->row( 'radio', 'radio' ),
			$this->row( 'select', 'select' ),
			$this->row( 'multi', 'select', [ 'allow_multiple' => 'true' ] ),
			$this->row( 'boxes', 'checkbox', [ 'field_options' => "a\nb" ] ),
			$this->row( 'onebox', 'checkbox' ),
			$this->row( 'ok', 'acceptance' ),
			$this->row( 'number', 'number' ),
			$this->row( 'date', 'date' ),
			$this->row( 'time', 'time' ),
			$this->row( 'file', 'upload' ),
			$this->row( 'secret', 'password' ),
			$this->row( 'html', 'html' ),
			$this->row( 'hidden', 'hidden' ),
			$this->row( 'step', 'step' ),
			$this->row( 'honey', 'honeypot' ),
			$this->row( 'captcha', 'recaptcha' ),
			$this->row( 'captcha3', 'recaptcha_v3' ),
			$this->row( 'fancy', 'star_rating' ),
			$this->row( 'nested', 'matrix' ),
		];
		$path = $this->upload();
		$sent = [
			'text' => 'سارا 🙂 <b>x</b>', 'email' => Fixtures::EMAIL, 'area' => "Line 1\nLine 2", 'url' => 'https://example.com/a%20b?x=1',
			'tel' => '۰۹۱۲۰۰۰۰۰۰۰', 'radio' => 'one', 'select' => 'two', 'multi' => [ 'x', 'y, z' ], 'boxes' => [ 'a', 'b' ], 'onebox' => 'a',
			'ok' => 'on', 'number' => '٤٢', 'date' => '۱۴۰۴-۰۱-۰۲', 'time' => '۱۰:۳۰', 'secret' => 'Hunter2-Clear', 'html' => 'zz', 'hidden' => 'h۱',
			'step' => 's', 'honey' => 'bee', 'captcha' => 'token', 'captcha3' => 'token', 'fancy' => '5', 'nested' => [ [ 'a' => '1' ], [ 'b' => [ '2', '3' ] ] ],
		];
		$_FILES = [ 'form_fields' => [ 'file' => [ [ 'name' => 'My CV.pdf' ] ] ] ];
		$s      = $this->submit( $sent, $this->settings( $rows ), [ 'file' => [ 'path' => [ $path ], 'url' => [ 'https://example.com/u.pdf' ] ] ] );

		$this->assertSame( [ 'text', 'email', 'area', 'url', 'tel', 'radio', 'select', 'multi', 'boxes', 'onebox', 'ok', 'number', 'date', 'time', 'file', 'secret', 'hidden', 'fancy', 'nested' ], array_keys( $s->fields ) );
		$this->assertSame( 'سارا 🙂 x', $s->value( 'text' ) );
		$this->assertSame( "Line 1\nLine 2", $s->value( 'area' ) );
		$this->assertSame( 'https://example.com/a%20b?x=1', $s->value( 'url' ) );
		$this->assertSame( '09120000000', $s->value( 'tel' ) );
		$this->assertSame( [ 'x', 'y, z' ], $s->field( 'multi' )['values'] );
		$this->assertSame( 'x, y, z', $s->value( 'multi' ) );
		$this->assertSame( [ 'a', 'b' ], $s->field( 'boxes' )['values'] );
		$this->assertSame( [ 'a' ], $s->field( 'onebox' )['values'] );
		$this->assertSame( __( 'Accepted', 'forms-notifier-for-elementor' ), $s->value( 'ok' ) );
		$this->assertSame( 'on', $s->field( 'ok' )['raw'] );
		$this->assertSame( '42', $s->value( 'number' ) );
		$this->assertSame( '1404-01-02', $s->value( 'date' ) );
		$this->assertSame( '10:30', $s->value( 'time' ) );
		$this->assertSame( 'h۱', $s->value( 'hidden' ), 'free text keeps its digits' );
		$this->assertTrue( $s->field( 'file' )['is_file'] );
		$this->assertSame( [ 'My-CV.pdf' ], $s->field( 'file' )['values'] );
		$this->assertSame( 'star_rating', $s->field( 'fancy' )['type'] );
		$this->assertSame( '5', $s->value( 'fancy' ) );
		$this->assertSame( [ '1', '2', '3' ], $s->field( 'nested' )['values'] );
		$this->assertTrue( $s->field( 'secret' )['redacted'] );
		$this->assertSame( '', $s->field( 'secret' )['raw'] );
		$this->assertNotContains( 'Hunter2', $this->dump() );
		$this->assertNotContains( 'Hunter2', wp_json_encode( $this->captured[0]->to_array() ) );
	}

	public function test_empty_password_is_dropped_and_sensitive_fields_are_masked(): void {
		$before = count( $this->stored() );
		$rows   = [ $this->row( 'name' ), $this->row( 'pass', 'password' ), $this->row( 'iban' ), $this->row( 'scan', 'upload' ) ];
		$this->submit( [ 'name' => 'a' ], $this->settings( $rows ) );
		$form = FormRepository::find_by_key( '45:abc1234' );
		FormRepository::save_config( $form['id'], [ 'fields' => [ 'sensitive' => [ 'iban', 'scan' ], 'exclude' => [ 'name' ] ] ] );
		$s = $this->submit( [ 'name' => 'Sara', 'pass' => '', 'iban' => 'IR-CLEAR-1' ], $this->settings( $rows ), [ 'scan' => [ 'path' => [ $this->upload() ], 'url' => [ 'https://example.com/scan.pdf' ] ] ] );
		$this->assertNull( $s->field( 'pass' ) );
		$this->assertTrue( $s->field( 'iban' )['redacted'] );
		$this->assertTrue( $s->field( 'name' )['hidden'] );
		$this->assertNotContains( 'IR-CLEAR-1', $this->dump() );
		$this->assertSame( [], $s->files );
		$this->assertSame( $before, count( $this->stored() ) );
	}

	public function test_text_survives_as_typed(): void {
		$rows = [ $this->row( 'text' ), $this->row( 'area', 'textarea' ) ];
		$s    = $this->submit( [ 'text' => 'a < b and 20%de', 'area' => "5 <3\n100%25 <script>alert(1)</script>" ], $this->settings( $rows ) );
		$this->assertSame( 'a < b and 20%de', $s->value( 'text' ) );
		$this->assertSame( "5 <3\n100%25", trim( $s->value( 'area' ) ) );
	}

	public function test_value_changed_by_another_plugin_wins(): void {
		$s = $this->submit( [ 'code' => 'abc' ], $this->settings( [ $this->row( 'code' ) ] ), [], [ 'after_process' => static function ( $record ) { $record->data['fields']['code']['value'] = 'ABC-1'; } ] );
		$this->assertSame( 'ABC-1', $s->value( 'code' ) );
	}

	public function test_label_fallbacks(): void {
		$rows = [
			$this->row( 'a', 'text', [ 'field_label' => '<b>Your</b> &amp; name' ] ),
			$this->row( 'b', 'text', [ 'field_label' => '', 'placeholder' => 'E.g. Sara' ] ),
			$this->row( 'c', 'text', [ 'field_label' => ' ' ] ),
		];
		$s = $this->submit( [ 'a' => '1', 'b' => '2', 'c' => '3' ], $this->settings( $rows ) );
		$this->assertSame( [ 'Your & name', 'E.g. Sara', 'c' ], array_column( $s->fields, 'label' ) );
	}

	public function test_digits_stay_when_switched_off_and_change_in_mapped_fields(): void {
		$rows = [ $this->row( 'mobile' ), $this->row( 'tel', 'tel' ) ];
		$this->submit( [ 'mobile' => '۱', 'tel' => '۲' ], $this->settings( $rows ) );
		$form = FormRepository::find_by_key( '45:abc1234' );
		FormRepository::save_config( $form['id'], [ 'fields' => [ 'phone' => 'mobile' ] ] );
		$s = $this->submit( [ 'mobile' => '۱۲', 'tel' => '۲' ], $this->settings( $rows ) );
		$this->assertSame( '12', $s->value( 'mobile' ) );
		Settings::set( 'general.normalize_digits', false );
		$s = $this->submit( [ 'mobile' => '۱۲۳', 'tel' => '۲' ], $this->settings( $rows ) );
		$this->assertSame( '۱۲۳', $s->value( 'mobile' ) );
		$this->assertSame( '۲', $s->value( 'tel' ) );
	}

	public function test_meta(): void {
		$_POST = [
			'referrer'      => 'https://example.com/en/contact/?utm_source=news&utm_campaign=spring%20sale&utm_term[]=x',
			'referer_title' => 'Contact \"us\" <b>',
			'queried_id'    => '77',
		];
		$s = $this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 'https://example.com/en/contact/?utm_source=news&utm_campaign=spring%20sale&utm_term%5B%5D=x', str_replace( [ '[', ']' ], [ '%5B', '%5D' ], $s->meta['page_url'] ) );
		$this->assertContains( 'Contact "us"', $s->meta['page_title'] );
		$this->assertSame( 77, $s->meta['queried_id'] );
		$this->assertSame( [ 'source' => 'news', 'campaign' => 'spring sale' ], $s->meta['utm'] );
		$this->assertSame( '203.0.113.57', $s->meta['user_ip'] );
		$this->assertSame( 'Test Agent/1.0', $s->meta['user_agent'] );
		$this->assertFalse( isset( $s->meta['form'] ) );
	}

	public function test_hostile_meta(): void {
		$_POST = [ 'referrer' => 'javascript:alert(1)', 'referer_title' => [ 'x' ], 'queried_id' => [ '1' ] ];
		$s     = $this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( '', $s->meta['page_url'] );
		$this->assertSame( '', $s->meta['page_title'] );
		$this->assertSame( 0, $s->meta['queried_id'] );
	}

	public function test_ip_and_agent_settings(): void {
		$settings = $this->settings( [ $this->row( 'name' ) ] );
		Settings::set_many( [ 'general.store_ip' => 'anonymize', 'general.store_user_agent' => false ] );
		$s = $this->submit( [ 'name' => 'a' ], $settings );
		$this->assertSame( '203.0.113.0', $s->meta['user_ip'] );
		$this->assertSame( '', $s->meta['user_agent'] );
		Settings::set( 'general.store_ip', 'none' );
		$s = $this->submit( [ 'name' => 'b' ], $settings );
		$this->assertSame( '', $s->meta['user_ip'] );
	}

	public function test_storing_off(): void {
		Settings::set( 'general.store_submissions', false );
		$path   = $this->upload();
		$exists = null;
		// Without any delivery the copy is released as soon as the hook is done.
		$this->filter( 'fnfe_submission_captured', static function ( $s ) use ( &$exists ) { $exists = $s->files[0]->stored && $s->files[0]->exists(); }, 2 );
		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ), $this->row( 'cv', 'upload' ) ] ), [ 'cv' => [ 'path' => [ $path ], 'url' => [ 'https://example.com/cv.pdf' ] ] ] );
		$this->assertSame( 0, $this->total() );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( 0, $this->captured[0]->id );
		$this->assertTrue( $exists );
		// The same again is a duplicate although nothing is stored.
		$this->submit( [ 'name' => 'Sara' ], $this->settings( [ $this->row( 'name' ), $this->row( 'cv', 'upload' ) ] ), [ 'cv' => [ 'path' => [ $path ], 'url' => [ 'https://example.com/cv.pdf' ] ] ] );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( [ 'duplicate' ], $this->skipped );
	}

	public function test_form_row_is_refreshed(): void {
		$this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->submit( [ 'name' => 'b' ], $this->settings( [ $this->row( 'name' ), $this->row( 'city' ) ], [ 'form_name' => 'New name', 'submit_actions' => [ 'fnfe' ], 'fnfe_attach' => 'send' ] ) );
		$form = FormRepository::find_by_key( '45:abc1234' );
		$this->assertSame( 'New name', $form['name'] );
		$this->assertSame( [ 'name', 'city' ], array_column( $form['fields'], 'id' ) );
		$this->assertSame( [ 'fnfe_attach' => 'send' ], $form['editor'] );
		$this->assertSame( 2, $form['submissions_count'] );
		$this->assertSame( 1, FormRepository::query()['total'] );
	}

	public function test_unnamed_form(): void {
		$s = $this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ], [ 'form_name' => '' ] ) );
		$this->assertContains( 'abc1234', $s->form_name );
	}

	public function test_action_errors(): void {
		$fail = static function ( $record, $handler ) {
			$handler->is_success            = false;
			$handler->messages['error'][]   = 'Mail <b>failed</b>';
		};
		$s = $this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ] ), [], [ 'after_process' => $fail ] );
		$this->assertSame( [ 'Mail failed' ], $s->meta['action_errors'] );

		Settings::set( 'general.notify_on_action_errors', false );
		$this->submit( [ 'name' => 'b' ], $this->settings( [ $this->row( 'name' ) ], [ 'submit_actions' => [ 'fnfe' ] ] ), [], [ 'after_process' => $fail ] );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( [ 'filtered' ], $this->skipped );
		AbstractRecordSource::on_shutdown();
		$this->assertSame( 1, $this->total() );
	}

	public function test_uploads_in_every_attachment_type(): void {
		foreach ( [ 'link', 'attach', 'both' ] as $type ) {
			foreach ( [ 1, 3 ] as $count ) {
				Db::reset();
				$paths = [];
				$urls  = [];
				$names = [];
				for ( $i = 0; $i < $count; $i++ ) {
					$paths[] = $this->upload( 'pdf', "file $i" );
					$urls[]  = $type === 'attach' ? 'attached' : 'https://example.com/f' . $i . '.pdf';
					$names[] = [ 'name' => "doc $i.pdf" ];
				}
				$_FILES = [ 'form_fields' => [ 'cv' => $names ] ];
				// The e-mail action deletes attached files before the final hook fires.
				$after = static function () use ( $type, $paths ) {
					if ( $type === 'attach' ) {
						array_map( 'unlink', $paths );
					}
				};
				$s = $this->submit( [], $this->settings( [ $this->row( 'cv', 'upload', [ 'attachment_type' => $type ] ) ] ), [ 'cv' => [ 'path' => $paths, 'url' => $urls ] ], [ 'after_process' => $after ] );
				$this->assertCount( $count, $s->files, "$type $count" );
				foreach ( $s->files as $i => $file ) {
					$this->assertTrue( $file->stored );
					$this->assertTrue( $file->exists() );
					$this->assertSame( "file $i", file_get_contents( $file->path ) );
					$this->assertSame( "doc-$i.pdf", $file->name );
					$this->assertSame( $type === 'attach' ? '' : $urls[ $i ], $file->url );
					$this->assertTrue( $file->id > 0 );
				}
				$this->assertSame( $count, count( FileRepository::for_submission( $s->id ) ) );
			}
		}
	}

	public function test_copies_only_where_needed(): void {
		Settings::set( 'attachments.keep_copy', false );
		$link   = $this->upload();
		$attach = $this->upload();
		$s      = $this->submit( [], $this->settings( [ $this->row( 'l', 'upload', [ 'attachment_type' => 'link' ] ), $this->row( 'a', 'upload', [ 'attachment_type' => 'attach' ] ) ] ), [
			'l' => [ 'path' => [ $link ], 'url' => [ 'https://example.com/l.pdf' ] ],
			'a' => [ 'path' => [ $attach ], 'url' => [ 'attached' ] ],
		] );
		$this->assertFalse( $s->files_for( 'l' )[0]->stored );
		$this->assertSame( $link, $s->files_for( 'l' )[0]->path );
		$this->assertTrue( $s->files_for( 'a' )[0]->stored );
	}

	public function test_unused_copies_are_removed(): void {
		$before   = count( $this->stored() );
		$files    = [ 'cv' => [ 'path' => [ $this->upload() ], 'url' => [ 'https://example.com/a.pdf' ] ] ];
		$settings = $this->settings( [ $this->row( 'cv', 'upload' ) ] );
		$source   = new ProFormSource();

		// The final hook never fires and the action was not added.
		$source->on_process( new FakeRecord( [], $settings, $files ), new FakeHandler( [ 'id' => 'abc1234' ] ) );
		$this->assertSame( $before + 1, count( $this->stored() ) );
		AbstractRecordSource::on_shutdown();
		$this->assertSame( $before, count( $this->stored() ) );

		// A veto.
		$this->filter( 'fnfe_should_capture', '__return_false' );
		$this->submit( [], $settings, $files );
		$this->assertSame( $before, count( $this->stored() ) );
	}

	public function test_inactive_form_is_not_copied(): void {
		Settings::set( 'general.capture_mode', 'selected' );
		$before = count( $this->stored() );
		$source = new ProFormSource();
		$source->on_process( new FakeRecord( [], $this->settings( [ $this->row( 'cv', 'upload' ) ] ), [ 'cv' => [ 'path' => [ $this->upload() ], 'url' => [ 'x' ] ] ] ), new FakeHandler( [ 'id' => 'abc1234' ] ) );
		$this->assertSame( $before, count( $this->stored() ) );
	}

	public function test_dangerous_uploads_are_dropped(): void {
		$php = Db::uploads() . '/elementor/forms/x.php';
		wp_mkdir_p( dirname( $php ) );
		file_put_contents( $php, '<?php echo 1;' );
		$s = $this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ), $this->row( 'f', 'upload' ) ] ), [ 'f' => [ 'path' => [ $php, '/etc/hosts', Db::uploads() . '/elementor/forms/../../../../etc/hosts' ], 'url' => [ 'https://example.com/x.php', '', '' ] ] ] );
		$this->assertSame( [], $s->files );
	}

	public function test_errors_never_reach_the_visitor(): void {
		$this->filter( 'fnfe_submission', static function () { throw new \RuntimeException( 'boom' ); } );
		$this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 0, $this->total() );
		$source = new ProFormSource();
		$source->on_process( null, null );
		$source->on_new_record( null, null );
		$source->mark_action( 'x', 'y' );
		$source->register_action( null );
	}

	public function test_filters_change_fields_and_submission(): void {
		$this->filter( 'fnfe_field_value', static function ( $field ) { $field['value'] = strtoupper( $field['value'] ); return $field; } );
		$this->filter( 'fnfe_submission', static function ( $s ) { $s->meta['crm'] = '7'; return $s; } );
		$s = $this->submit( [ 'name' => 'sara' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( 'SARA', $s->value( 'name' ) );
		$this->assertSame( '7', $s->meta['crm'] );
	}

	public function test_global_widget_is_one_form(): void {
		// Two pages embed template 900: Elementor reports the template as form_post_id.
		$this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ], [ 'form_post_id' => 900, 'edit_post_id' => 11, 'id' => 'aaaaaaa' ] ), [], [ 'element' => 'tpl0001' ] );
		$this->submit( [ 'name' => 'b' ], $this->settings( [ $this->row( 'name' ) ], [ 'form_post_id' => 900, 'edit_post_id' => 12, 'id' => 'bbbbbbb' ] ), [], [ 'element' => 'tpl0001' ] );
		$this->assertSame( 1, FormRepository::query()['total'] );
		$this->assertSame( 2, FormRepository::find_by_key( '900:tpl0001' )['submissions_count'] );
	}

	public function test_public_api(): void {
		$id = fnfe_send( [ 'form_name' => 'API', 'fields' => [ 'note' => "a\nb", 'list' => [ 'value' => [ 'x', 'y' ], 'label' => 'List', 'type' => 'checkbox' ], 'pw' => [ 'value' => 'Hunter2-Clear', 'type' => 'password' ] ] ] );
		$s  = SubmissionRepository::find( $id );
		$this->assertSame( "a\nb", $s->value( 'note' ) );
		$this->assertSame( [ 'x', 'y' ], $s->field( 'list' )['values'] );
		$this->assertTrue( $s->field( 'pw' )['redacted'] );
		$this->assertNotContains( 'Hunter2', $this->dump() );
		$this->assertSame( 0, fnfe_send( [ 'fields' => [] ] ) );
	}

	public function test_language_filter_and_default(): void {
		$s = $this->submit( [ 'name' => 'a' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( \AmirhpCom\FormsNotifier\Core\I18n::default_lang(), $s->lang );
		$this->filter( 'fnfe_submission_language', static function () { return 'xx'; } );
		$s = $this->submit( [ 'name' => 'b' ], $this->settings( [ $this->row( 'name' ) ] ) );
		$this->assertSame( \AmirhpCom\FormsNotifier\Core\I18n::default_lang(), $s->lang, 'unknown languages are ignored' );
	}

	public function test_real_elementor_record(): void {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Form_Record' ) ) {
			return;
		}
		$settings = $this->settings( [ $this->row( 'name' ), $this->row( 'boxes', 'checkbox' ), $this->row( 'pass', 'password' ) ], [ 'form_metadata' => [] ] );
		$record   = new \ElementorPro\Modules\Forms\Classes\Form_Record( [ 'name' => 'O\\\'Neil', 'boxes' => [ 'a', 'b' ], 'pass' => 'Hunter2-Clear' ], [ 'widgetType' => 'form', 'settings' => $settings ] );
		$handler  = new FakeHandler( [ 'id' => 'abc1234' ] );
		( new ProFormSource() )->on_new_record( $record, $handler );
		$s = $this->latest();
		$this->assertSame( "O'Neil", $s->value( 'name' ) );
		$this->assertSame( [ 'a', 'b' ], $s->field( 'boxes' )['values'] );
		$this->assertNotContains( 'Hunter2', $this->dump() );
	}
}
