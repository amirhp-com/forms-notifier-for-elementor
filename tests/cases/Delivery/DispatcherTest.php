<?php
/**
 * Dispatcher.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Module;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class DispatcherTest extends TestCase {

	public static function setUpClass(): void {
		// What other modules add to a message is tested with them.
		remove_all_filters( 'fnfe_message' );
		FakeChannel::install( 'fake', [ 'buttons' => [ 'url', 'callback', 'copy' ], 'subject' => true, 'attachments' => true ] );
		FakeChannel::install( 'telegram', [ 'buttons' => [ 'url', 'callback', 'web_app', 'copy' ] ] );
		FakeChannel::install( 'data', [], 'json' );
		FakeChannel::install( 'texter', [ 'pattern' => true, 'submitter' => true, 'receiver_kind' => 'phone' ] );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many(
			[
				'channels.fake.enabled'   => true,
				'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ],
				'queue.ticker'            => 'cron',
			]
		);
	}

	public function tearDown(): void {
		Queue::reset();
		Settings::clear_overlay();
	}

	private function saved( array $overrides = [] ): Submission {
		$s          = Fixtures::submission( $overrides );
		$s->form_id = FormRepository::upsert( $s->form_key, [ 'name' => 'Contact' ] );
		SubmissionRepository::insert( $s );
		return $s;
	}

	private function build( Submission $s, array $config = [], string $channel = 'fake' ): Message {
		$plan = Resolver::plan( $s, Fixtures::config( $config, $s->form_id ), [ $channel ] );
		return Dispatcher::build( $plan[0], $s, Fixtures::config( $config, $s->form_id ) );
	}

	private function button( string $type, string $value, int $row = 0, string $text = 'Go' ): array {
		return [ 'id' => 'b_' . md5( $type . $value . $row ), 'text' => [ '_' => $text ], 'type' => $type, 'value' => $value, 'row' => $row, 'style' => '' ];
	}

	public function test_build_renders_body_plain_and_subject() {
		Settings::set( 'channels.fake.template.admin', [ '_' => '<b>Hi</b> {field:email}' ] );
		Settings::set( 'channels.fake.subject.admin', [ '_' => 'New from {field:email}' ] );
		$s = $this->saved();
		$m = $this->build( $s );
		$this->assertSame( 'Hi sara@example.com', $m->body );
		$this->assertSame( 'Hi sara@example.com', $m->plain );
		$this->assertSame( 'New from sara@example.com', $m->subject );
		$this->assertSame( [ $s->id, $s->form_id, $s->uuid ], [ $m->meta['submission_id'], $m->meta['form_id'], $m->meta['uuid'] ] );
		$this->assertSame( I18n::default_lang(), $m->lang );
	}

	public function test_empty_template_uses_the_channel_default() {
		$this->assertSame( 'admin: Contact', $this->build( $this->saved() )->body );
		$this->assertNotSame( '', $this->build( $this->saved() )->subject );
	}

	public function test_values_are_never_templates() {
		Settings::set( 'channels.fake.template.admin', [ '_' => '{field:message}' ] );
		$this->assertContains( '{site_url}', $this->build( $this->saved() )->body );
	}

	public function test_fnfe_message_filter() {
		$this->filter( 'fnfe_message', function ( $m ) {
			$m->body = 'filtered';
			return $m;
		}, 10, 3 );
		$this->assertSame( 'filtered', $this->build( $this->saved() )->body );
		$this->filter( 'fnfe_message', function () {
			return null;
		} );
		$this->assertTrue( $this->build( $this->saved() ) instanceof Message );
	}

	public function test_background_mode_makes_no_request_before_shutdown() {
		$this->filter( 'fnfe_before_send', function () {
			Http::get( 'https://example.com/would-be-remote' );
		} );
		$s = $this->saved();
		Module::on_captured( $s, FormRepository::config( $s->form_id ) );
		HttpMock::assertNoRequests();
		$this->assertCount( 0, FakeChannel::$sent );
		$this->assertTrue( Queue::is_kicked() );
		$this->assertSame( 1, Queue::pending() );

		HttpMock::expect( 'GET', '~would-be-remote~' );
		Queue::run();
		$this->assertCount( 1, HttpMock::requests() );
		$this->assertCount( 1, FakeChannel::$sent );
	}

	public function test_shutdown_runs_the_queue() {
		$s = $this->saved();
		Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		Queue::on_shutdown();
		$this->assertCount( 1, FakeChannel::$sent );
		$this->assertFalse( Queue::is_kicked() );
	}

	public function test_captured_hook_is_wired() {
		$s = $this->saved();
		do_action( 'fnfe_submission_captured', $s, FormRepository::config( $s->form_id ) );
		$this->assertCount( 1, MessageRepository::for_submission( $s->id ) );
	}

	public function test_inline_mode_sends_at_once() {
		Settings::set( 'general.delivery_mode', 'inline' );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$this->assertSame( 'sent', MessageRepository::find( $ids[0] )['status'] );
		$this->assertFalse( Queue::is_kicked() );
		$this->assertSame( 'sent', SubmissionRepository::find( $s->id )->meta['delivery_status'] );
	}

	public function test_not_ready_channel_is_logged_as_skipped() {
		FakeChannel::$problems = [ 'Token missing.' ];
		$s                     = $this->saved();
		$ids                   = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$row                   = MessageRepository::find( $ids[0] );
		$this->assertSame( [ 'skipped', 'Token missing.', 'desk' ], [ $row['status'], $row['error'], $row['recipient'] ] );
		$this->assertSame( 'failed', SubmissionRepository::find( $s->id )->meta['delivery_status'] );
	}

	public function test_invalid_submitter_address_is_logged_as_skipped() {
		Settings::set( 'channels.texter.enabled', true );
		$s = $this->saved( [ 'fields' => [ 'phone' => Fixtures::field( 'phone', 'bad', 'tel' ) ] ] );
		FormRepository::save_config( $s->form_id, [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'texter' => [ 'enabled' => true ] ] ] ] );
		Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'texter' ] );
		$rows = MessageRepository::for_submission( $s->id );
		$this->assertSame( [ 'skipped', 'submitter' ], [ $rows[0]['status'], $rows[0]['purpose'] ] );
		$this->assertNotSame( '', $rows[0]['error'] );
	}

	public function test_broken_rendering_does_not_stop_the_others() {
		Settings::set( 'channels.fake.receivers', [ Fixtures::receiver( 'a' ), Fixtures::receiver( 'b' ) ] );
		$calls = 0;
		$this->filter( 'fnfe_buttons', function ( $buttons ) use ( &$calls ) {
			if ( ++$calls === 1 ) {
				throw new \RuntimeException( 'render' );
			}
			return $buttons;
		} );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$row = MessageRepository::find( $ids[0] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertContains( 'render', $row['error'] );
		$this->assertSame( 'queued', MessageRepository::find( $ids[1] )['status'] );
		$this->assertTrue( Queue::retry( $ids[0] ), 'rendered again' );
	}

	public function test_broken_message_filter_keeps_the_message() {
		$this->filter( 'fnfe_message', function () {
			throw new \RuntimeException( 'listener' );
		} );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$this->assertSame( 'queued', MessageRepository::find( $ids[0] )['status'] );
	}

	public function test_quiet_hours_delay_admin_messages_only() {
		$zone = wp_timezone();
		$now  = ( new \DateTimeImmutable( '2026-01-10 23:30', $zone ) )->getTimestamp();
		$end  = ( new \DateTimeImmutable( '2026-01-11 07:00', $zone ) )->getTimestamp();
		$this->freeze( $now );
		Settings::set_many(
			[
				'general.quiet_hours.enabled'  => true,
				'general.quiet_hours.channels' => [ 'fake', 'texter' ],
				'channels.texter.enabled'      => true,
				'channels.texter.receivers'    => [ Fixtures::receiver( '+989120000001' ) ],
			]
		);
		$s = $this->saved();
		FormRepository::save_config( $s->form_id, [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'texter' => [ 'enabled' => true ] ] ] ] );
		Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		foreach ( MessageRepository::for_submission( $s->id ) as $row ) {
			$this->assertSame( gmdate( 'Y-m-d H:i:s', $row['purpose'] === 'admin' ? $end : $now ), $row['next_attempt_gmt'], $row['channel'] . ' ' . $row['purpose'] );
		}

		$s = $this->saved( [ 'fields' => [ 'a' => Fixtures::field( 'a', '1' ) ] ] );
		FormRepository::save_config( $s->form_id, [ 'quiet_hours' => 'off' ] );
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now ), MessageRepository::find( $ids[0] )['next_attempt_gmt'] );

		FormRepository::save_config( $s->form_id, [] );
		$s->meta['via'] = 'test';
		$ids            = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now ), MessageRepository::find( $ids[0] )['next_attempt_gmt'] );
		$this->assertSame( true, MessageRepository::find( $ids[0] )['payload']['meta']['test'] );

		Settings::set( 'general.quiet_hours.channels', [ 'texter' ] );
		$s->meta['via'] = 'auto';
		$ids            = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now ), MessageRepository::find( $ids[0] )['next_attempt_gmt'] );
	}

	public function test_quiet_hours_hold_in_inline_mode_too() {
		$this->freeze( ( new \DateTimeImmutable( '2026-01-10 23:30', wp_timezone() ) )->getTimestamp() );
		Settings::set_many( [ 'general.delivery_mode' => 'inline', 'general.quiet_hours.enabled' => true, 'general.quiet_hours.channels' => [ 'fake' ] ] );
		$s = $this->saved();
		Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$this->assertCount( 0, FakeChannel::$sent );
		$this->assertSame( 1, Queue::pending() );
	}

	public function test_buttons() {
		Settings::set(
			'channels.fake.buttons',
			[
				$this->button( 'copy', '{field:email}', 2, 'Copy' ),
				$this->button( 'url', 'https://example.com/?q={field:message}', 0, 'Search {field:name}' ),
				$this->button( 'url', '{page_url}', 0, 'Page' ),
				$this->button( 'callback', 'done', 1, 'Done' ),
				$this->button( 'web_app', '', 1, 'App' ),
				$this->button( 'submission', '', 3, 'Open' ),
				$this->button( 'url', '{field:empty}', 4, 'Empty' ),
				$this->button( 'copy', str_repeat( 'x', 400 ), 5, 'Long' ),
				$this->button( 'url', 'https://example.com', 6, '' ),
			]
		);
		$s    = $this->saved();
		$rows = $this->build( $s )->buttons;
		$this->assertCount( 5, $rows );
		$this->assertSame( 'Search سارا 🙂', $rows[0][0]['text'] );
		$this->assertContains( 'https://example.com/?q=First%20line', $rows[0][0]['value'] );
		$this->assertNotContains( '{site_url}', $rows[0][0]['value'] );
		$this->assertNotContains( '<b>', $rows[0][0]['value'] );
		$this->assertSame( 'https://example.com/contact/', $rows[0][1]['value'] );
		$this->assertSame( [ [ 'callback', 'done' ] ], array_map( function ( $b ) {
			return [ $b['type'], $b['value'] ];
		}, $rows[1] ) );
		$this->assertSame( [ 'copy', 'sara@example.com' ], [ $rows[2][0]['type'], $rows[2][0]['value'] ] );
		$this->assertSame( [ 'url', Urls::submission_admin( $s->id ) ], [ $rows[3][0]['type'], $rows[3][0]['value'] ] );
		$this->assertSame( 256, mb_strlen( $rows[4][0]['value'] ) );
	}

	public function test_button_url_from_a_field_must_be_a_web_address() {
		Settings::set( 'channels.fake.buttons', [ $this->button( 'url', '{field:x}' ) ] );
		foreach ( [ 'javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', '//example.com', 'x' ] as $bad ) {
			$this->assertSame( [], $this->build( $this->saved( [ 'fields' => [ 'x' => Fixtures::field( 'x', $bad ) ] ] ) )->buttons, $bad );
		}
		$this->assertCount( 1, $this->build( $this->saved( [ 'fields' => [ 'x' => Fixtures::field( 'x', 'https://example.com/a?b=c' ) ] ] ) )->buttons );
	}

	public function test_callback_and_submission_buttons_need_a_saved_submission() {
		Settings::set( 'channels.fake.buttons', [ $this->button( 'callback', 'done' ), $this->button( 'submission', '' ) ] );
		$this->assertSame( [], $this->build( Fixtures::submission() )->buttons );
	}

	public function test_mini_app_buttons() {
		$this->filter( 'home_url', function ( $url ) {
			return preg_replace( '~^http://[^/]+~', 'https://example.com', $url );
		} );
		Settings::set_many(
			[
				'channels.telegram.enabled'   => true,
				'channels.telegram.receivers' => [ Fixtures::receiver( '-100' ) ],
				'channels.telegram.buttons'   => [ $this->button( 'web_app', '' ), $this->button( 'submission', '', 1 ) ],
			]
		);
		$s = $this->saved();
		$this->assertSame( [ 'url' ], array_column( array_merge( ...$this->build( $s, [], 'telegram' )->buttons ), 'type' ), 'mini app is off' );
		Settings::set( 'miniapp.enabled', true );
		$rows = $this->build( $s, [], 'telegram' )->buttons;
		$this->assertSame( [ 'web_app', 'web_app' ], [ $rows[0][0]['type'], $rows[1][0]['type'] ] );
		$this->assertContains( 'https://example.com/', $rows[0][0]['value'] );
		$this->assertContains( 'startapp=sub_' . $s->id, $rows[1][0]['value'] );
	}

	public function test_json_channels_have_no_rendered_body() {
		Settings::set_many( [ 'channels.data.enabled' => true, 'channels.data.receivers' => [ Fixtures::receiver( 'e1' ) ] ] );
		$m = $this->build( $this->saved(), [], 'data' );
		$this->assertSame( [ 'json', '' ], [ $m->format, $m->body ] );
		$this->assertNotSame( '', $m->plain );
	}

	public function test_pattern_text() {
		Settings::set_many(
			[
				'channels.texter.enabled'        => true,
				'channels.texter.receivers'      => [ Fixtures::receiver( Fixtures::PHONE ) ],
				'channels.texter.template.admin' => [ '_' => "pattern:5904\nname:{field:email}\n---\nHello {field:email}" ],
			]
		);
		$m = $this->build( $this->saved(), [], 'texter' );
		$this->assertSame( '5904', $m->pattern['code'] );
		$this->assertSame( [ 'name' => 'sara@example.com' ], $m->pattern['vars'] );
		$this->assertSame( 'Hello sara@example.com', $m->plain );
		// A visitor cannot make one.
		Settings::set( 'channels.texter.template.admin', [ '_' => '{field:x}' ] );
		$this->assertSame( [], $this->build( $this->saved( [ 'fields' => [ 'x' => Fixtures::field( 'x', "pattern:1\na:b" ) ] ] ), [], 'texter' )->pattern );
	}

	public function test_message_survives_the_database() {
		Settings::set( 'channels.fake.buttons', [ $this->button( 'url', 'https://example.com' ) ] );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		Queue::run_ids( $ids );
		$m = FakeChannel::$sent[0];
		$this->assertSame( 'https://example.com', $m->buttons[0][0]['value'] );
		$this->assertSame( 'desk', $m->recipient->address );
		$this->assertSame( 'admin', $m->purpose );
	}

	public function test_unsaved_submissions_are_delivered_and_their_files_removed() {
		$dir = \AmirhpCom\FormsNotifier\Attachments\Storage::dir();
		wp_mkdir_p( $dir );
		$path = $dir . str_repeat( 'a', 32 ) . '.pdf';
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$s             = Fixtures::submission();
		$file          = Fixtures::file();
		$file->path    = $path;
		$file->stored  = true;
		$s->files      = [ $file ];
		$config        = Fixtures::config( [ 'attachments' => [ 'mode' => 'send' ] ], 0 );
		$ids           = Dispatcher::dispatch( $s, $config );
		$this->assertCount( 1, MessageRepository::find( $ids[0] )['payload']['attachments'] );
		$this->assertTrue( is_file( $path ), 'still needed' );
		Queue::run();
		$this->assertCount( 1, FakeChannel::$sent[0]->attachments );
		$this->assertFalse( is_file( $path ), 'removed after the last attempt' );

		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Dispatcher::dispatch( $s, Fixtures::config( [ 'channels' => [ 'fake' => [ 'enabled' => 'off' ] ] ], 0 ) );
		$this->assertFalse( is_file( $path ), 'nobody needs it' );
	}
}
