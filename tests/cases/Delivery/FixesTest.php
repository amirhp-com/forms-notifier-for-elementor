<?php
/**
 * Regressions of the delivery review.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Delivery\Alerts;
use AmirhpCom\FormsNotifier\Delivery\Broadcast;
use AmirhpCom\FormsNotifier\Delivery\Cleanup;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Extras;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class FixesTest extends TestCase {

	public static function setUpClass(): void {
		remove_all_filters( 'fnfe_message' );
		FakeChannel::install( 'fake' );
		FakeChannel::install( 'second' );
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
		FakeChannel::install( 'texter', [ 'receiver_kind' => 'phone', 'submitter' => true ] );
		FakeChannel::install( 'html', [], 'telegram_html' );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many(
			[
				'channels.fake.enabled'     => true,
				'channels.fake.receivers'   => [ Fixtures::receiver( 'desk' ) ],
				'channels.second.enabled'   => true,
				'channels.second.receivers' => [ Fixtures::receiver( 'sec1' ) ],
				'queue.ticker'              => 'cron',
			]
		);
	}

	public function tearDown(): void {
		Queue::reset();
	}

	private function saved( array $overrides = [] ): Submission {
		$s          = Fixtures::submission( $overrides );
		$s->form_id = FormRepository::upsert( $s->form_key, [ 'name' => 'Contact' ] );
		SubmissionRepository::insert( $s );
		return $s;
	}

	private function unsaved(): Submission {
		$s          = Fixtures::submission();
		$s->form_id = FormRepository::upsert( $s->form_key, [ 'name' => 'Contact' ] );
		return $s;
	}

	private function failed( bool $permanent = true ): SendResult {
		return SendResult::failure( 'fake', 'down', $permanent );
	}

	// I1: one link per file.

	public function test_a_file_link_appears_once_with_the_default_template(): void {
		Settings::set_many( [ 'channels.html.enabled' => true, 'channels.html.receivers' => [ Fixtures::receiver( 'x' ) ] ] );
		$s              = $this->saved();
		$file           = Fixtures::file( 'cv.pdf' );
		$file->stored   = true;
		$file->id       = FileRepository::insert( $s->id, $file );
		$s->files       = [ $file ];
		$s->fields['cv'] = Fixtures::field( 'cv', 'cv.pdf', 'upload' );
		$seen           = 0;
		$this->filter( 'fnfe_attachments', function ( $r ) use ( &$seen ) {
			++$seen;
			return $r;
		} );
		$delivery           = new Delivery( 'html', new Recipient( 'html', 'x' ) );
		$delivery->template = DefaultTemplates::for_format( 'telegram_html', 'admin' );
		$delivery->lang     = 'en';
		$delivery->attachment_policy = [ 'mode' => 'link', 'types' => 'all', 'max_mb' => 20, 'max_files' => 10, 'link_ttl_days' => 30 ];
		$m = Dispatcher::build( $delivery, $s, FormRepository::config( $s->form_id ) );
		$this->assertSame( 1, preg_match_all( '~fnfe/v1/file/' . $file->id . '(?!\d)~', rawurldecode( $m->body ) ), $m->body );
		$this->assertCount( 1, $m->links );
		$this->assertContains( $m->links[0]['url'], str_replace( '&amp;', '&', $m->body ), 'the body shows the link of the message' );
		$this->assertSame( [], Extras::missing_links( $m->body, $m->links ) );
		$this->assertSame( 1, $seen, 'files are resolved once per message' );
	}

	// I2: fallback without stored submissions.

	public function test_fallback_works_when_submissions_are_not_stored(): void {
		Settings::set( 'channels.fake.fallback', 'second' );
		$s   = $this->unsaved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		$this->assertCount( 1, $ids );
		FakeChannel::$results = [ $this->failed() ];
		Queue::run_ids( $ids );
		$rows = MessageRepository::query( [ 'channel' => 'second' ] )['items'];
		$this->assertCount( 1, $rows, 'one row on the backup channel' );
		$this->assertSame( [ $ids[0], 0 ], [ $rows[0]['parent_id'], $rows[0]['submission_id'] ] );
		$this->assertFalse( isset( $rows[0]['payload']['meta']['submission'] ), 'a fallback row carries no submission' );
		$this->assertFalse( isset( MessageRepository::find( $ids[0] )['payload']['meta']['submission'] ), 'removed once final' );
		Queue::run_ids( [ $rows[0]['id'] ] );
		$this->assertSame( 'sent', MessageRepository::find( $rows[0]['id'] )['status'] );
		$this->assertContains( 'Contact', FakeChannel::$sent[1]->body );

		// A second failure of the same submission creates none.
		$this->assertTrue( Queue::retry( $ids[0] ) );
		FakeChannel::$results = [ $this->failed() ];
		Queue::run_ids( [ $ids[0] ] );
		$this->assertCount( 1, MessageRepository::query( [ 'channel' => 'second' ] )['items'] );
	}

	public function test_unsaved_fallback_is_once_per_submission_even_without_bodies(): void {
		Settings::set_many( [ 'channels.fake.fallback' => 'second', 'privacy.log_bodies' => false, 'channels.fake.receivers' => [ Fixtures::receiver( 'a' ), Fixtures::receiver( 'b' ) ] ] );
		$s   = $this->unsaved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		FakeChannel::$results = [ $this->failed() ];
		Queue::run_ids( [ $ids[0] ] );
		$fallback = MessageRepository::query( [ 'channel' => 'second' ] )['items'];
		Queue::run_ids( [ $fallback[0]['id'] ] );
		FakeChannel::$results = [ $this->failed() ];
		Queue::run_ids( [ $ids[1] ] );
		$this->assertCount( 1, MessageRepository::query( [ 'channel' => 'second' ] )['items'] );
		$this->assertSame( [ 'meta' => [ 'uuid' => $s->uuid ] ], MessageRepository::find( $ids[0] )['payload'], 'only the uuid is kept' );
	}

	public function test_files_of_an_unsaved_submission_stay_until_its_fallback_is_queued(): void {
		Settings::set( 'channels.fake.fallback', 'second' );
		FakeChannel::install( 'second', [ 'attachments' => true ] );
		$dir = \AmirhpCom\FormsNotifier\Attachments\Storage::dir();
		wp_mkdir_p( $dir );
		$path = $dir . str_repeat( 'b', 32 ) . '.pdf';
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$s            = $this->unsaved();
		$file         = Fixtures::file();
		$file->path   = $path;
		$file->stored = true;
		$s->files     = [ $file ];
		FormRepository::save_config( $s->form_id, [ 'attachments' => [ 'mode' => 'send' ] ] );
		$config       = FormRepository::config( $s->form_id );
		$ids          = Dispatcher::dispatch( $s, $config, [ 'fake' ] );
		$this->assertTrue( is_file( $path ) );
		FakeChannel::$results = [ $this->failed() ];
		Queue::run_ids( $ids );
		$this->assertTrue( is_file( $path ), 'the fallback row needs it' );
		Queue::run();
		$this->assertFalse( is_file( $path ), 'removed after the fallback' );
		FakeChannel::install( 'second' );
	}

	// I6: migration on the queue and for alerts.

	private function telegram(): void {
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set_many(
			[
				'channels.telegram.enabled'          => true,
				'channels.telegram.receivers'        => [ Fixtures::receiver( '-500/7' ) ],
				'alerts.channels.telegram.enabled'   => true,
				'alerts.channels.telegram.receivers' => [ Fixtures::receiver( '-500' ) ],
				'digest.channels.telegram.enabled'   => true,
				'digest.channels.telegram.receivers' => [ Fixtures::receiver( '-500/9' ) ],
			]
		);
	}

	private function upgraded(): array {
		return [ 'code' => 400, 'body' => [ 'ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => [ 'migrate_to_chat_id' => -1005 ] ] ];
	}

	public function test_a_migrated_group_is_retried_once_at_the_new_address(): void {
		$this->telegram();
		HttpMock::queue( 'POST', '~/sendMessage$~', [ $this->upgraded(), [ 'ok' => true, 'result' => [ 'message_id' => 3 ] ] ] );
		$message = Fixtures::message( 'telegram', '-500/7' );
		$message->format = 'telegram_html';
		$id = Queue::push( $message );
		Queue::run_ids( [ $id ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'sent', '-1005/7', 1 ], [ $row['status'], $row['recipient'], $row['attempts'] ] );
		$this->assertSame( '-1005/7', $row['payload']['recipient']['address'] );
		$requests = HttpMock::requests( '~/sendMessage$~' );
		$this->assertCount( 2, $requests );
		$this->assertSame( [ '-500', '-1005' ], [ (string) $requests[0]['json']['chat_id'], (string) $requests[1]['json']['chat_id'] ] );
		$this->assertSame( '-1005/7', Settings::arr( 'channels.telegram.receivers' )[0]['address'] );
		$this->assertSame( '-1005', Settings::arr( 'alerts.channels.telegram.receivers' )[0]['address'] );
		$this->assertSame( '-1005/9', Settings::arr( 'digest.channels.telegram.receivers' )[0]['address'] );
	}

	public function test_an_alert_follows_a_migrated_group(): void {
		$this->telegram();
		HttpMock::queue( 'POST', '~/sendMessage$~', [ $this->upgraded(), [ 'ok' => true, 'result' => [ 'message_id' => 3 ] ] ] );
		$message         = Fixtures::message( 'telegram', '-500' );
		$message->format = 'telegram_html';
		$message->purpose = 'alert';
		$this->assertTrue( Dispatcher::send_now( $message )->ok );
		$row = MessageRepository::find( (int) $message->meta['message_id'] );
		$this->assertSame( [ 'sent', '-1005' ], [ $row['status'], $row['recipient'] ] );
		$this->assertSame( '-1005', Settings::arr( 'alerts.channels.telegram.receivers' )[0]['address'] );
	}

	// M3: retry without bodies finds the right masked receiver.

	public function test_retry_without_bodies_keeps_a_masked_receiver(): void {
		Settings::set_many(
			[
				'privacy.log_bodies'      => false,
				'channels.hook.enabled'   => true,
				'channels.hook.receivers' => [ Fixtures::receiver( 'https://one.example.com/a' ), Fixtures::receiver( 'https://two.example.com/b' ) ],
			]
		);
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'hook' ] );
		$this->assertCount( 2, $ids );
		FakeChannel::$results = [ SendResult::success( 'hook' ), $this->failed() ];
		Queue::run_ids( $ids );
		$row = MessageRepository::find( $ids[1] );
		$this->assertSame( [ '', '••••••' ], [ $row['body'], $row['recipient'] ] );
		$this->assertFalse( isset( $row['payload']['body'] ), 'no content kept' );
		$this->assertTrue( Queue::retry( $ids[1] ) );
		Queue::run_ids( [ $ids[1] ] );
		$this->assertSame( 'https://two.example.com/b', end( FakeChannel::$sent )->recipient->address );
	}

	// M4: no fallback of a fallback, even without its message.

	public function test_a_fallback_row_without_payload_has_no_fallback(): void {
		Settings::set_many( [ 'channels.fake.fallback' => 'second', 'channels.second.fallback' => 'fake' ] );
		$s      = $this->saved();
		$parent = MessageRepository::insert( [ 'submission_id' => $s->id, 'form_id' => $s->form_id, 'channel' => 'fake', 'purpose' => 'admin', 'status' => 'failed', 'recipient' => 'x' ] );
		$child  = MessageRepository::insert( [ 'submission_id' => $s->id, 'form_id' => $s->form_id, 'channel' => 'second', 'purpose' => 'admin', 'status' => 'queued', 'recipient' => 'gone', 'parent_id' => $parent, 'attempts' => 5, 'max_attempts' => 5 ] );
		Queue::run_ids( [ $child ] );
		$this->assertSame( 'failed', MessageRepository::find( $child )['status'] );
		$this->assertCount( 2, MessageRepository::for_submission( $s->id ) );
	}

	// M10 + review A I2: results of a lost claim and refused writes.

	public function test_a_send_that_lost_its_claim_does_not_store(): void {
		$id = Queue::push( Fixtures::message() );
		$this->filter( 'fnfe_before_send', function () use ( $id ) {
			// Another process took the row over meanwhile.
			MessageRepository::update( $id, [ 'lock_token' => str_repeat( 'f', 32 ) ] );
		} );
		Queue::run_ids( [ $id ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'sending', str_repeat( 'f', 32 ) ], [ $row['status'], $row['lock_token'] ] );
	}

	public function test_a_sent_row_released_meanwhile_is_still_marked_sent(): void {
		$id = Queue::push( Fixtures::message() );
		$this->filter( 'fnfe_before_send', function () use ( $id ) {
			MessageRepository::update( $id, [ 'status' => 'retry', 'lock_token' => '', 'locked_until_gmt' => null ] );
		} );
		Queue::run_ids( [ $id ] );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
	}

	public function test_the_status_is_saved_when_the_answer_is_refused(): void {
		$id = Queue::push( Fixtures::message() );
		FakeChannel::$results = [ SendResult::success( 'fake', 'REFUSE-ME' ) ];
		$this->filter( 'query', function ( $sql ) {
			// The database refuses the row because of its text.
			return strpos( (string) $sql, 'REFUSE-ME' ) !== false ? '' : $sql;
		} );
		Queue::run_ids( [ $id ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'sent', 'The answer could not be stored.' ], [ $row['status'], $row['response'] ] );
	}

	public function test_a_sending_row_is_reserved_for_the_whole_send(): void {
		$id    = Queue::push( Fixtures::message() );
		$until = '';
		$this->filter( 'fnfe_before_send', function () use ( $id, &$until ) {
			$until = (string) MessageRepository::find( $id )['locked_until_gmt'];
		} );
		Queue::run_ids( [ $id ] );
		$this->assertTrue( $until >= Queue::gmt( 800 ), $until );
	}

	// Alerts in mode "first" after a process died.

	public function test_first_failure_is_reported_after_a_killed_attempt(): void {
		Settings::set_many( [ 'alerts.enabled' => true, 'alerts.when' => 'first', 'alerts.channels.second.enabled' => true, 'alerts.channels.second.receivers' => [ Fixtures::receiver( 'boss' ) ] ] );
		$id = Queue::push( Fixtures::message() );
		MessageRepository::update( $id, [ 'attempts' => 1 ] ); // The first attempt killed its process.
		FakeChannel::$results = [ $this->failed( false ) ];
		Queue::run_ids( [ $id ] );
		$this->assertCount( 1, MessageRepository::query( [ 'purpose' => 'alert' ] )['items'] );
	}

	// M5: language of alerts and digests.

	public function test_alert_language_follows_the_linked_user(): void {
		$user = wp_insert_user( [ 'user_login' => 'fnfe_lang_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'locale' => 'en_US' ] );
		try {
			Settings::set( 'channels.telegram.admins', [ [ 'id' => 'a_1', 'uid' => '424242', 'name' => 'Sara', 'wp_user' => $user, 'perms' => [ 'view' ] ] ] );
			$langs = [];
			Broadcast::send( [ 'fake' => [ [ 'address' => 'x' ] ] ], 'alert', function ( string $lang ) use ( &$langs ) {
				$langs[] = $lang;
				return [ 'template' => 'x', 'subject' => '', 'extra' => [], 'data' => [] ];
			} );
			$ref = new \ReflectionMethod( Broadcast::class, 'user_lang' );
			$ref->setAccessible( true );
			$this->assertSame( 'en', $ref->invoke( null, 'telegram', '424242' ) );
			$this->assertSame( \AmirhpCom\FormsNotifier\Core\I18n::default_lang(), $ref->invoke( null, 'telegram', '-100' ) );
			$this->assertSame( [ \AmirhpCom\FormsNotifier\Core\I18n::default_lang() ], $langs );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		}
	}

	// M13: auto-replies to a phone.

	public function test_auto_replies_to_one_number_are_limited(): void {
		Settings::set( 'channels.texter.enabled', true );
		$config = [ 'submitter' => [ 'enabled' => true, 'channels' => [ 'texter' => [ 'enabled' => true, 'field' => 'phone' ] ] ] ];
		$sent   = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$s = $this->saved( [ 'fields' => [ 'phone' => Fixtures::field( 'phone', Fixtures::PHONE, 'tel' ), 'm' => Fixtures::field( 'm', 'text ' . $i ) ] ] );
			FormRepository::save_config( $s->form_id, $config );
			foreach ( Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'texter' ] ) as $id ) {
				$sent[] = MessageRepository::find( $id )['status'];
			}
		}
		$this->assertSame( [ 'queued', 'queued', 'queued', 'skipped', 'skipped' ], $sent );
		$this->filter( 'fnfe_submitter_limit', function () {
			return 0;
		} );
		$s = $this->saved( [ 'fields' => [ 'phone' => Fixtures::field( 'phone', Fixtures::PHONE, 'tel' ) ] ] );
		$this->assertSame( 'queued', MessageRepository::find( Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'texter' ] )[0] )['status'] );
	}

	// M12: same default text, different translations.

	public function test_buttons_with_the_same_text_keep_their_own_translation(): void {
		defined( 'ICL_SITEPRESS_VERSION' ) || define( 'ICL_SITEPRESS_VERSION', '4.9' );
		$this->filter( 'wpml_active_languages', function () {
			return [ 'en' => [ 'code' => 'en', 'default_locale' => 'en_US', 'native_name' => 'English' ], 'fa' => [ 'code' => 'fa', 'default_locale' => 'fa_IR', 'native_name' => 'فارسی' ] ];
		} );
		$this->filter( 'wpml_default_language', function () {
			return 'en';
		} );
		$this->filter( 'wpml_translate_single_string', function ( $value, $context, $name, $lang ) {
			$map = [ 'setting:channels.fake.buttons.b_1.text' => 'باز کردن', 'setting:channels.fake.buttons.b_2.text' => 'دیدن' ];
			return $lang === 'fa' && isset( $map[ $name ] ) ? $map[ $name ] : $value;
		}, 10, 4 );
		FakeChannel::install( 'fake', [ 'buttons' => [ 'url' ] ] );
		$buttons = [
			[ 'id' => 'b_1', 'text' => [ '_' => 'Open' ], 'type' => 'url', 'value' => 'https://example.com/1', 'row' => 0, 'style' => '' ],
			[ 'id' => 'b_2', 'text' => [ '_' => 'Open' ], 'type' => 'url', 'value' => 'https://example.com/2', 'row' => 0, 'style' => '' ],
		];
		\AmirhpCom\FormsNotifier\I18n\Strings::forget();
		// Registered texts (the fake channel declares no buttons field, so they are given here).
		set_transient( 'fnfe_strings', [ 'setting:channels.fake.buttons.b_1.text' => 'Open', 'setting:channels.fake.buttons.b_2.text' => 'Open' ] );
		$s                  = $this->saved();
		$delivery           = new Delivery( 'fake', new Recipient( 'fake', 'desk' ) );
		$delivery->lang     = 'fa';
		$delivery->template = 'x';
		$delivery->buttons  = $buttons;
		$m                  = Dispatcher::build( $delivery, $s, FormRepository::config( $s->form_id ) );
		$this->assertSame( [ 'باز کردن', 'دیدن' ], array_column( $m->buttons[0], 'text' ) );
		\AmirhpCom\FormsNotifier\I18n\Strings::forget();
		FakeChannel::install( 'fake' );
	}

	// M6.

	public function test_fnfe_send_tells_an_unsaved_success_from_a_failure(): void {
		Settings::set( 'general.store_submissions', false );
		$this->assertSame( -1, fnfe_send( [ 'form_name' => 'API', 'fields' => [ 'a' => 'b' ], 'channels' => [ 'fake' ] ] ) );
		Settings::set( 'general.store_submissions', true );
		$this->assertTrue( fnfe_send( [ 'form_name' => 'API', 'fields' => [ 'a' => 'b' ], 'channels' => [ 'fake' ] ] ) > 0 );
	}

	// Cleanup keeps what a retry needs.

	public function test_cleanup_keeps_a_masked_receiver(): void {
		Settings::set( 'privacy.log_bodies', false );
		$message = Fixtures::message( 'hook', 'https://one.example.com/a' );
		$id      = MessageRepository::insert( array_merge( Queue::row( $message, 'failed' ), [ 'submission_id' => 0 ] ) );
		Cleanup::run();
		$this->assertSame( 'https://one.example.com/a', MessageRepository::find( $id )['payload']['recipient']['address'] );
		$this->assertSame( '', MessageRepository::find( $id )['body'] );
	}
}
