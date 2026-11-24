<?php
/**
 * Queue and worker.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Delivery\Backoff;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Ticker;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class QueueTest extends TestCase {

	const NOW = 1767261600; // 2026-01-01 10:00:00 UTC.

	public static function setUpClass(): void {
		// What other modules add to a message is tested with them.
		remove_all_filters( 'fnfe_message' );
		FakeChannel::install( 'fake', [ 'buttons' => [ 'url' ] ] );
		FakeChannel::install( 'second' );
		FakeChannel::install( 'third' );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many(
			[
				'channels.fake.enabled'    => true,
				'channels.fake.receivers'  => [ Fixtures::receiver( 'desk' ) ],
				'channels.second.enabled'  => true,
				'channels.second.receivers' => [ Fixtures::receiver( 'sec1' ), Fixtures::receiver( 'sec2' ) ],
				'channels.third.enabled'   => true,
				'channels.third.receivers' => [ Fixtures::receiver( 'thi' ) ],
				'queue.ticker'             => 'cron',
			]
		);
	}

	public function tearDown(): void {
		Queue::reset();
	}

	private function push( int $delay = 0, array $meta = [], string $channel = 'fake' ): int {
		$message       = Fixtures::message( $channel );
		$message->meta = $meta;
		return Queue::push( $message, $delay );
	}

	/** Saved submission with its form. */
	private function saved( array $overrides = [] ): Submission {
		$s          = Fixtures::submission( $overrides );
		$s->form_id = FormRepository::upsert( $s->form_key, [ 'name' => 'Contact' ] );
		SubmissionRepository::insert( $s );
		return $s;
	}

	private function failure( bool $permanent = false, string $error = 'boom' ): SendResult {
		return SendResult::failure( 'fake', $error, $permanent );
	}

	public function test_push_and_run() {
		$this->freeze( self::NOW );
		$id  = $this->push();
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'queued', 0, 5, '2026-01-01 10:00:00', 'desk' ], [ $row['status'], $row['attempts'], $row['max_attempts'], $row['next_attempt_gmt'], $row['recipient'] ] );
		$this->assertSame( [ 'processed' => 1, 'sent' => 1, 'failed' => 0, 'remaining' => 0 ], Queue::run() );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'sent', 1, 'fake', 'fake ok', '1', '2026-01-01 10:00:00', '', null ], [ $row['status'], $row['attempts'], $row['provider'], $row['response'], $row['remote_id'], $row['sent_gmt'], $row['lock_token'], $row['next_attempt_gmt'] ] );
		$this->assertSame( [ $id, 1 ], [ FakeChannel::$sent[0]->meta['message_id'], FakeChannel::$sent[0]->meta['attempt'] ] );
	}

	public function test_delayed_rows_wait() {
		$this->freeze( self::NOW );
		$this->push( 60 );
		$this->assertSame( 0, Queue::run()['processed'] );
		$this->freeze( self::NOW + 60 );
		$this->assertSame( 1, Queue::run()['sent'] );
	}

	public function test_two_interleaved_claims_never_share_a_row() {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->push();
		}
		$second = null;
		$nested = false;
		// The second process claims right after the first one has read its candidates.
		$this->filter( 'query', function ( $sql ) use ( &$second, &$nested ) {
			if ( ! $nested && strpos( $sql, "SET status = 'sending'" ) !== false ) {
				$nested = true;
				$second = MessageRepository::claim( 6 );
			}
			return $sql;
		} );
		$first = MessageRepository::claim( 6 );
		$a     = array_column( $first, 'id' );
		$b     = array_column( (array) $second, 'id' );
		$this->assertSame( [], array_intersect( $a, $b ) );
		$this->assertCount( 6, array_merge( $a, $b ) );
	}

	public function test_lock_prevents_overlapping_runs() {
		$this->push();
		$this->assertTrue( Lock::acquire( 'queue', 120 ) );
		$this->assertSame( [ 'processed' => 0, 'sent' => 0, 'failed' => 0, 'remaining' => 1 ], Queue::run() );
		Lock::release( 'queue' );
		$this->assertSame( 1, Queue::run()['sent'] );
		$this->assertTrue( Lock::acquire( 'queue', 120 ) );
		Lock::release( 'queue' );
	}

	public function test_stale_lock_recovery() {
		$this->freeze( self::NOW );
		$id = $this->push();
		MessageRepository::claim( 1, 300 );
		MessageRepository::release_stale();
		$this->assertSame( 'sending', MessageRepository::find( $id )['status'] );
		$this->freeze( self::NOW + 301 );
		Ticker::tick();
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
	}

	public function test_a_message_that_kills_its_process_does_not_loop() {
		$this->freeze( self::NOW );
		$id = $this->push();
		MessageRepository::update( $id, [ 'attempts' => 5 ] );
		Queue::run();
		$row = MessageRepository::find( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertCount( 0, FakeChannel::$sent );
	}

	public function test_backoff_sequence_until_final_failure() {
		Settings::set( 'queue.backoff', [ 60, 300 ] );
		$this->filter( 'fnfe_max_attempts', function () {
			return 4;
		} );
		FakeChannel::$results = [ $this->failure(), $this->failure(), $this->failure(), $this->failure() ];
		$seen                 = [];
		$this->filter( 'fnfe_message_failed', function ( $row, $result, $final ) use ( &$seen ) {
			$seen[] = [ $row['attempts'], $final ];
		}, 10, 3 );

		$now = self::NOW;
		$this->freeze( $now );
		$id    = $this->push();
		$waits = [];
		for ( $i = 0; $i < 4; $i++ ) {
			Queue::run();
			$row     = MessageRepository::find( $id );
			$waits[] = $row['next_attempt_gmt'] ? strtotime( $row['next_attempt_gmt'] . ' UTC' ) - $now : null;
			$this->assertSame( $i < 3 ? 'retry' : 'failed', $row['status'] );
			if ( $row['next_attempt_gmt'] ) {
				$this->freeze( strtotime( $row['next_attempt_gmt'] . ' UTC' ) - 1 );
				$this->assertSame( 0, Queue::run()['processed'], 'not before its time' );
				$now = strtotime( $row['next_attempt_gmt'] . ' UTC' );
				$this->freeze( $now );
			}
		}
		foreach ( [ 60, 300, 300 ] as $i => $base ) {
			$this->assertTrue( $waits[ $i ] >= $base && $waits[ $i ] <= $base * 1.1, "wait $i: " . $waits[ $i ] );
		}
		$this->assertNull( $waits[3] );
		$this->assertSame( [ [ 1, false ], [ 2, false ], [ 3, false ], [ 4, true ] ], $seen );
		$this->assertSame( 'boom', MessageRepository::find( $id )['error'] );
	}

	public function test_retry_after_is_respected_and_capped() {
		$result              = $this->failure();
		$result->retry_after = 17;
		$this->assertSame( 17, Backoff::delay( 1, [], $result ) );
		$result->retry_after = 999999;
		$this->assertSame( 3600, Backoff::delay( 1, [], $result ) );
		$this->filter( 'fnfe_retry_delay', function () {
			return 5;
		} );
		$this->assertSame( 5, Backoff::delay( 1, [], $result ) );
	}

	public function test_retry_after_is_stored() {
		$this->freeze( self::NOW );
		$result               = $this->failure();
		$result->retry_after  = 90;
		FakeChannel::$results = [ $result ];
		$id                   = $this->push();
		Queue::run();
		$this->assertSame( '2026-01-01 10:01:30', MessageRepository::find( $id )['next_attempt_gmt'] );
	}

	public function test_permanent_failure_stops() {
		FakeChannel::$results = [ $this->failure( true, 'chat not found' ) ];
		$id                   = $this->push();
		Queue::run();
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'failed', 1, 'chat not found' ], [ $row['status'], $row['attempts'], $row['error'] ] );
	}

	public function test_exceptions_do_not_kill_the_run() {
		FakeChannel::$results = [ new \RuntimeException( 'crash' ), SendResult::success( 'fake' ) ];
		$a                    = $this->push();
		$b                    = $this->push();
		$totals               = Queue::run();
		$this->assertSame( [ 2, 1, 1 ], [ $totals['processed'], $totals['sent'], $totals['failed'] ] );
		$this->assertSame( [ 'retry', 'crash' ], [ MessageRepository::find( $a )['status'], MessageRepository::find( $a )['error'] ] );
		$this->assertSame( 'sent', MessageRepository::find( $b )['status'] );
	}

	public function test_a_broken_listener_does_not_kill_the_run() {
		$this->filter( 'fnfe_message_sent', function () {
			throw new \RuntimeException( 'listener' );
		} );
		$a = $this->push();
		$b = $this->push();
		$this->assertSame( 2, Queue::run()['processed'] );
		$this->assertSame( [ 'sent', 'sent' ], [ MessageRepository::find( $a )['status'], MessageRepository::find( $b )['status'] ] );
	}

	public function test_time_budget_releases_the_rest() {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->push();
		}
		$this->filter( 'fnfe_after_send', function () {
			usleep( 600000 );
		} );
		$totals = Queue::run( 0, 1 );
		$this->assertSame( 2, $totals['processed'] );
		$this->assertSame( 2, $totals['remaining'] );
		$this->assertSame( [ 'queued' => 2, 'sent' => 2 ], MessageRepository::counts() );
	}

	public function test_batch_limit() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->push();
		}
		$this->assertSame( 3, Queue::run( 3 )['processed'] );
		Settings::set( 'queue.batch', 1 );
		$this->assertSame( 1, Queue::run()['processed'] );
	}

	public function test_same_chat_is_paced() {
		FakeChannel::install( 'telegram' );
		Settings::set( 'channels.telegram.enabled', true );
		$times = [];
		$this->filter( 'fnfe_before_send', function () use ( &$times ) {
			$times[] = microtime( true );
		} );
		foreach ( [ '-100/1', '-100/2', '-200' ] as $address ) {
			Queue::push( Fixtures::message( 'telegram', $address ) );
		}
		$totals = Queue::run( 0, 6 );
		$this->assertSame( 3, $totals['sent'] );
		$this->assertTrue( $times[1] - $times[0] < 0.9, 'another chat does not wait' );
		$this->assertTrue( $times[2] - $times[0] >= 1.0, 'topics of one chat share the limit' );
	}

	public function test_retry_button() {
		FakeChannel::$results = [ $this->failure( true ) ];
		$id                   = $this->push();
		Queue::run();
		$this->assertTrue( Queue::retry( $id ) );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'queued', 1 ], [ $row['status'], $row['attempts'] ] );
		$this->assertSame( 1, Queue::run_ids( [ $id ] )['sent'] );
		$this->assertSame( 2, MessageRepository::find( $id )['attempts'] );
		$this->assertFalse( Queue::retry( $id ), 'sent' );
		$this->assertFalse( Queue::retry( 999 ) );
	}

	public function test_retry_after_the_last_attempt_gives_one_more() {
		$id = $this->push();
		MessageRepository::update( $id, [ 'status' => 'failed', 'attempts' => 5 ] );
		$this->assertTrue( Queue::retry( $id ) );
		FakeChannel::$results = [ $this->failure() ];
		Queue::run_ids( [ $id ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'failed', 6, 6 ], [ $row['status'], $row['attempts'], $row['max_attempts'] ] );
	}

	public function test_a_row_that_is_being_sent_cannot_be_retried() {
		$id = $this->push();
		MessageRepository::take( $id );
		$this->assertFalse( Queue::retry( $id ) );
	}

	public function test_cancel() {
		$id = $this->push();
		$this->assertTrue( Queue::cancel( $id ) );
		$this->assertSame( 'cancelled', MessageRepository::find( $id )['status'] );
		$this->assertFalse( Queue::cancel( $id ) );
		$this->assertSame( 0, Queue::run()['processed'] );
		$this->assertTrue( Queue::retry( $id ), 'an admin can change their mind' );
	}

	public function test_not_ready_channel_becomes_skipped() {
		$id = $this->push();
		Settings::set( 'channels.fake.enabled', false );
		Queue::run();
		$row = MessageRepository::find( $id );
		$this->assertSame( 'skipped', $row['status'] );
		$this->assertNotSame( '', $row['error'] );
		$this->assertCount( 0, FakeChannel::$sent );
	}

	public function test_unknown_channel_becomes_skipped() {
		$id = $this->push( 0, [], 'gone' );
		Queue::run();
		$this->assertSame( 'skipped', MessageRepository::find( $id )['status'] );
	}

	public function test_log_bodies_off_clears_content_and_retry_renders_again() {
		Settings::set( 'privacy.log_bodies', false );
		$s  = $this->saved();
		$id = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] )[0];
		$this->assertNotSame( '', MessageRepository::find( $id )['body'], 'needed until it is sent' );
		FakeChannel::$results = [ $this->failure( true ) ];
		Queue::run_ids( [ $id ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( [ 'failed', '', [] ], [ $row['status'], $row['body'], $row['payload'] ] );

		$this->assertTrue( Queue::retry( $id ) );
		Queue::run_ids( [ $id ] );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
		$this->assertContains( 'Contact', FakeChannel::$sent[1]->body );

		MessageRepository::update( $id, [ 'status' => 'failed', 'payload' => null ] );
		SubmissionRepository::delete( $s->id );
		$orphan = $this->push( 0, [ 'submission_id' => 12345 ] );
		MessageRepository::update( $orphan, [ 'status' => 'failed', 'payload' => null ] );
		$this->assertFalse( Queue::retry( $orphan ) );
	}

	public function test_skipped_row_can_be_retried_once_the_channel_works() {
		$s = $this->saved();
		Settings::set( 'channels.fake.enabled', false );
		$config = Fixtures::config( [ 'channels' => [ 'fake' => [ 'enabled' => 'on' ] ] ], $s->form_id );
		$id     = Dispatcher::dispatch( $s, $config, [ 'fake' ] )[0];
		$this->assertSame( 'skipped', MessageRepository::find( $id )['status'] );
		Settings::set( 'channels.fake.enabled', true );
		$this->assertTrue( Queue::retry( $id ) );
		Queue::run_ids( [ $id ] );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
	}

	public function test_delivery_status_follows_the_rows() {
		$s  = $this->saved();
		$of = function () use ( $s ) {
			return SubmissionRepository::find( $s->id )->meta['delivery_status'];
		};
		Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake', 'third' ] );
		$this->assertSame( 'queued', $of() );
		FakeChannel::$results = [ SendResult::success( 'fake' ), $this->failure() ];
		Queue::run();
		$this->assertSame( 'queued', $of(), 'one retry is waiting' );
		$rows = MessageRepository::for_submission( $s->id );
		MessageRepository::update( $rows[1]['id'], [ 'attempts' => 4 ] );
		FakeChannel::$results = [ $this->failure() ];
		Queue::run_ids( [ $rows[1]['id'] ] );
		$this->assertSame( 'partial', $of() );
		Queue::retry( $rows[1]['id'] );
		Queue::run_ids( [ $rows[1]['id'] ] );
		$this->assertSame( 'sent', $of() );

		$s = $this->saved( [ 'fields' => [ 'x' => Fixtures::field( 'x', 'y' ) ] ] );
		$f = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		FakeChannel::$results = [ $this->failure( true ) ];
		Queue::run_ids( $f );
		$this->assertSame( 'failed', SubmissionRepository::find( $s->id )->meta['delivery_status'] );

		$s = $this->saved( [ 'fields' => [ 'x' => Fixtures::field( 'x', 'z' ) ] ] );
		$f = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		Queue::cancel( $f[0] );
		$this->assertSame( 'none', SubmissionRepository::find( $s->id )->meta['delivery_status'] );
	}

	public function test_fallback_fires_once_and_not_for_fallback_rows() {
		Settings::set( 'channels.fake.fallback', 'second' );
		Settings::set( 'channels.second.fallback', 'third' );
		Settings::set( 'channels.fake.receivers', [ Fixtures::receiver( 'a' ), Fixtures::receiver( 'b' ) ] );
		$s      = $this->saved();
		$config = [ 'channels' => [ 'second' => [ 'enabled' => 'off' ], 'third' => [ 'enabled' => 'off' ] ] ];
		FormRepository::save_config( $s->form_id, $config );
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ) );
		$this->assertCount( 2, $ids );

		FakeChannel::$results = [ $this->failure( true ), $this->failure( true ) ];
		Queue::run_ids( $ids );
		$rows = MessageRepository::for_submission( $s->id );
		$this->assertSame( [ 'fake', 'fake', 'second', 'second' ], array_column( $rows, 'channel' ) );
		$this->assertSame( [ 0, 0, $ids[0], $ids[0] ], array_column( $rows, 'parent_id' ) );
		$this->assertSame( $ids[0], $rows[2]['payload']['meta']['fallback_of'] );

		FakeChannel::$results = [ $this->failure( true ), $this->failure( true ) ];
		Queue::run();
		$this->assertCount( 4, MessageRepository::for_submission( $s->id ), 'a fallback has no fallback' );

		Queue::retry( $ids[0] );
		FakeChannel::$results = [ $this->failure( true ) ];
		Queue::run_ids( [ $ids[0] ] );
		$this->assertCount( 4, MessageRepository::for_submission( $s->id ), 'once per submission' );
	}

	public function test_fallback_skips_receivers_that_have_the_message() {
		Settings::set( 'channels.fake.fallback', 'second' );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake', 'second' ] );
		$this->assertCount( 3, $ids );
		FakeChannel::$results = [ $this->failure( true ) ];
		Queue::run_ids( [ $ids[0] ] );
		$this->assertCount( 3, MessageRepository::for_submission( $s->id ) );
	}

	public function test_fallback_needs_a_ready_channel_and_an_admin_row() {
		Settings::set( 'channels.fake.fallback', 'second' );
		Settings::set( 'channels.second.enabled', false );
		$s   = $this->saved();
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), [ 'fake' ] );
		FakeChannel::$results = [ $this->failure( true ) ];
		Queue::run_ids( $ids );
		$this->assertCount( 1, MessageRepository::for_submission( $s->id ) );
	}

	public function test_chat_migration_updates_receivers_and_retries_at_once() {
		FakeChannel::install( 'telegram' );
		Settings::set_many(
			[
				'channels.telegram.enabled'          => true,
				'channels.telegram.receivers'        => [ Fixtures::receiver( '-5/7' ), Fixtures::receiver( '-55' ) ],
				'alerts.channels.telegram.receivers' => [ Fixtures::receiver( '-5' ) ],
			]
		);
		ChatRepository::upsert( 'telegram', '-5', 0, [ 'type' => 'group', 'title' => 'Old' ] );
		$s = $this->saved();
		FormRepository::save_config(
			$s->form_id,
			[
				'channels' => [ 'telegram' => [ 'receivers_mode' => 'replace', 'receivers' => [ Fixtures::receiver( '-5/7' ) ] ] ],
				'rules'    => [ [ 'id' => 'q_1', 'effect' => 'add', 'channels' => [ 'telegram' ], 'receivers' => [ 'telegram' => [ Fixtures::receiver( '-5' ) ] ] ] ],
			]
		);
		$result               = $this->failure( true, 'group chat was upgraded' )->with_meta( [ 'migrate_to' => '-100500' ] );
		FakeChannel::$results = [ $result, SendResult::success( 'telegram' ) ];
		$ids                  = [ Queue::push( Fixtures::message( 'telegram', '-5/7' ) ) ];
		Queue::run_ids( $ids );

		$row = MessageRepository::find( $ids[0] );
		$this->assertSame( [ 'sent', '-100500/7', 1 ], [ $row['status'], $row['recipient'], $row['attempts'] ] );
		$this->assertSame( '-100500/7', FakeChannel::$sent[1]->recipient->address );
		$this->assertSame( [ '-100500/7', '-55' ], array_column( Settings::arr( 'channels.telegram.receivers' ), 'address' ) );
		$this->assertSame( [ '-100500' ], array_column( Settings::arr( 'alerts.channels.telegram.receivers' ), 'address' ) );
		$config = FormRepository::config( $s->form_id );
		$this->assertSame( '-100500/7', $config->route( 'telegram' )['receivers'][0]['address'] );
		$this->assertSame( '-100500', $config->get( 'rules.0.receivers.telegram.0.address' ) );
		$this->assertNotNull( ChatRepository::find_chat( 'telegram', '-100500' ) );
	}

	public function test_hooks_around_a_send() {
		$seen = [];
		$this->filter( 'fnfe_before_send', function ( $m ) use ( &$seen ) {
			$seen[] = 'before ' . get_class( $m );
			$m->body = 'changed';
		} );
		$this->filter( 'fnfe_after_send', function ( $m, $r ) use ( &$seen ) {
			$seen[] = 'after ' . ( $r->ok ? 'ok' : 'no' );
		}, 10, 2 );
		$this->filter( 'fnfe_message_sent', function ( $row, $r ) use ( &$seen ) {
			$seen[] = 'sent ' . $row['status'];
		}, 10, 2 );
		$this->push();
		Queue::run();
		$this->assertSame( [ 'before ' . Message::class, 'after ok', 'sent sent' ], $seen );
		$this->assertSame( 'changed', FakeChannel::$sent[0]->body );
	}

	public function test_send_now_is_logged_and_never_queued() {
		$message       = Fixtures::message();
		$message->meta = [ 'parent_id' => 77 ];
		$this->assertTrue( Dispatcher::send_now( $message )->ok );
		$row = MessageRepository::find( (int) $message->meta['message_id'] );
		$this->assertSame( [ 'sent', 1, 1, 77, '' ], [ $row['status'], $row['attempts'], $row['max_attempts'], $row['parent_id'], $row['error'] ] );

		FakeChannel::$results = [ new \RuntimeException( 'down' ) ];
		$this->assertFalse( Dispatcher::send_now( Fixtures::message() )->ok );
		$this->assertSame( 0, Queue::pending() );
		$this->assertSame( 1, MessageRepository::counts()['failed'] );
		$this->assertFalse( Dispatcher::send_now( Fixtures::message( 'gone' ) )->ok );
	}

	public function test_secret_addresses_are_masked_in_the_log() {
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
		$row = Queue::row( Fixtures::message( 'hook', 'https://hooks.example.com/T0/KEY' ), 'queued' );
		$this->assertNotContains( 'KEY', $row['recipient'] );
		$this->assertSame( 'secret:r_1', Queue::row( Fixtures::message( 'hook', 'secret:r_1' ), 'queued' )['recipient'] );
	}

	public function test_ticker_with_wp_cron() {
		wp_clear_scheduled_hook( 'fnfe_queue_tick' );
		Ticker::ensure();
		$this->assertTrue( (bool) wp_next_scheduled( 'fnfe_queue_tick' ) );
		$this->assertSame( 'cron', Ticker::driver() );
		$this->assertSame( wp_next_scheduled( 'fnfe_queue_tick' ), Ticker::next_run() );
		$this->push();
		do_action( 'fnfe_queue_tick' );
		$this->assertSame( 0, Queue::pending() );
		$this->assertTrue( (int) State::get( 'queue_last_tick' ) > 0 );
	}

	public function test_oldest_due_age() {
		$this->freeze( self::NOW );
		$this->assertSame( 0, Queue::oldest_due_age() );
		$this->push( 100 );
		$this->assertSame( 0, Queue::oldest_due_age() );
		$this->freeze( self::NOW + 1100 );
		$this->assertSame( 1000, Queue::oldest_due_age() );
	}
}
