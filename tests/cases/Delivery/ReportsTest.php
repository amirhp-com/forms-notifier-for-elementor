<?php
/**
 * Digest, numbers, cleanup.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Delivery\Cleanup;
use AmirhpCom\FormsNotifier\Delivery\Digest;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class ReportsTest extends TestCase {

	/** @var string */
	private $zone = 'Asia/Tehran';

	public static function setUpClass(): void {
		FakeChannel::install( 'fake' );
		FakeChannel::install( 'second' );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many(
			[
				'channels.fake.enabled'          => true,
				'queue.ticker'                   => 'cron',
				'digest.enabled'                 => true,
				'digest.time'                    => '09:00',
				'digest.template'                => [ '_' => '{digest.submissions}|{digest.sent}|{digest.failed}|{digest.queue}|{digest.forms}' ],
				'digest.channels.fake.enabled'   => true,
				'digest.channels.fake.receivers' => [ Fixtures::receiver( 'boss' ) ],
			]
		);
		$this->zone = 'Asia/Tehran';
		$this->filter( 'pre_option_timezone_string', function () {
			return $this->zone;
		} );
	}

	private function at( string $local ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( $this->zone ) ) )->getTimestamp();
	}

	private function submission( string $local, int $form = 1, string $status = 'new' ): void {
		$s              = Fixtures::submission( [ 'form_id' => $form, 'form_name' => 'Form ' . $form, 'fields' => [ 'x' => Fixtures::field( 'x', uniqid() ) ] ] );
		$s->created_gmt = gmdate( 'Y-m-d H:i:s', $this->at( $local ) );
		$s->status      = $status;
		SubmissionRepository::insert( $s );
	}

	private function message( string $local, string $status, string $channel = 'fake', string $purpose = 'admin', int $form = 1 ): int {
		return MessageRepository::insert( [ 'channel' => $channel, 'status' => $status, 'purpose' => $purpose, 'form_id' => $form, 'body' => 'b', 'payload' => [ 'a' => 1 ], 'created_gmt' => gmdate( 'Y-m-d H:i:s', $this->at( $local ) ) ] );
	}

	public function test_due_logic_daily() {
		$this->freeze( $this->at( '2026-03-10 08:59:59' ) );
		$this->assertFalse( Digest::is_due() );
		$this->freeze( $this->at( '2026-03-10 09:00:00' ) );
		$this->assertTrue( Digest::is_due() );
		$this->freeze( $this->at( '2026-03-10 23:59:00' ) );
		$this->assertTrue( Digest::is_due(), 'late ticks catch up' );
		State::set( 'digest_last', '2026-03-10' );
		$this->assertFalse( Digest::is_due() );
		$this->freeze( $this->at( '2026-03-11 00:30:00' ) );
		$this->assertFalse( Digest::is_due() );
		$this->freeze( $this->at( '2026-03-11 09:30:00' ) );
		$this->assertTrue( Digest::is_due() );
		Settings::set( 'digest.enabled', false );
		$this->assertFalse( Digest::is_due() );
	}

	public function test_due_logic_uses_the_local_date() {
		// 22:00 UTC on 9 March is 01:30 on 10 March in Tehran and still 9 March in New York.
		$now = strtotime( '2026-03-09 22:00:00 UTC' );
		Settings::set( 'digest.time', '01:00' );
		State::set( 'digest_last', '2026-03-09' );
		$this->freeze( $now );
		$this->assertTrue( Digest::is_due() );
		$this->zone = 'America/New_York';
		$this->assertFalse( Digest::is_due() );
	}

	public function test_due_logic_weekly() {
		Settings::set_many( [ 'digest.frequency' => 'weekly', 'digest.weekday' => 6 ] );
		$this->freeze( $this->at( '2026-03-13 10:00' ) ); // Friday.
		$this->assertFalse( Digest::is_due() );
		$this->freeze( $this->at( '2026-03-14 10:00' ) ); // Saturday.
		$this->assertTrue( Digest::is_due() );
	}

	public function test_due_once_on_days_with_clock_changes() {
		foreach ( [ '2026-03-29', '2026-10-25' ] as $day ) {
			$this->zone = 'Europe/Berlin';
			Settings::set( 'digest.time', '02:30' ); // Does not exist, or exists twice.
			State::delete( 'digest_last' );
			$sent  = 0;
			$start = $this->at( "$day 00:00" );
			for ( $hour = 0; $hour < 26; $hour++ ) {
				$this->freeze( $start + $hour * 3600 );
				if ( Stats::now()->format( 'Y-m-d' ) === $day && Digest::is_due() ) {
					++$sent;
					State::set( 'digest_last', $day );
				}
			}
			$this->assertSame( 1, $sent, $day );
		}
	}

	public function test_daily_period_is_the_previous_local_day() {
		$this->freeze( $this->at( '2026-03-10 09:00' ) );
		$this->submission( '2026-03-08 23:59:59' );
		$this->submission( '2026-03-09 00:00:00' );
		$this->submission( '2026-03-09 23:59:59', 2 );
		$this->submission( '2026-03-10 00:00:00' );
		$this->submission( '2026-03-09 12:00:00', 1, 'spam' );
		$this->message( '2026-03-09 10:00', 'sent' );
		$this->message( '2026-03-09 10:00', 'sent', 'second', 'submitter' );
		$this->message( '2026-03-09 10:00', 'failed' );
		$this->message( '2026-03-09 10:00', 'sent', 'fake', 'test' );
		$this->message( '2026-03-09 10:00', 'failed', 'fake', 'alert' );
		$this->message( '2026-03-09 10:00', 'queued' );
		$data = Digest::data();
		$this->assertSame( [ '2026-03-09', '2026-03-09', 2, 2, 1, 1 ], [ $data['from'], $data['to'], $data['submissions'], $data['sent'], $data['failed'], $data['queue'] ] );
		$this->assertSame( 1, $data['previous']['submissions'] );
		$this->assertSame( [ 'sent' => 1, 'failed' => 1 ], $data['by_channel']['fake'] );
		$this->assertCount( 2, $data['by_form'] );
		$this->assertTrue( is_array( $data['health'] ) && count( $data['health'] ) > 5 );
	}

	public function test_weekly_period() {
		Settings::set( 'digest.frequency', 'weekly' );
		$this->freeze( $this->at( '2026-03-14 09:00' ) );
		$this->submission( '2026-03-07 00:00' );
		$this->submission( '2026-03-13 23:59' );
		$this->submission( '2026-03-14 00:00' );
		$this->submission( '2026-03-06 23:59' );
		$data = Digest::data();
		$this->assertSame( [ '2026-03-07', '2026-03-13', 2, 1 ], [ $data['from'], $data['to'], $data['submissions'], $data['previous']['submissions'] ] );
	}

	public function test_tick_sends_once_and_logs() {
		$this->freeze( $this->at( '2026-03-10 09:10' ) );
		$this->submission( '2026-03-09 12:00' );
		$sent = [];
		$this->filter( 'fnfe_digest_sent', function ( $data ) use ( &$sent ) {
			$sent[] = $data['submissions'];
		} );
		Digest::tick();
		Digest::tick();
		$rows = MessageRepository::query( [ 'purpose' => 'digest' ] )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( [ 'sent', 'boss' ], [ $rows[0]['status'], $rows[0]['recipient'] ] );
		$this->assertContains( '1|0|0|0|Form 1: 1', $rows[0]['body'] );
		$this->assertSame( [ 1 ], $sent );
		$this->assertSame( '2026-03-10', State::get( 'digest_last' ) );
	}

	public function test_skip_empty() {
		$this->freeze( $this->at( '2026-03-10 09:10' ) );
		Digest::tick();
		$this->assertCount( 0, FakeChannel::$sent );
		$this->assertSame( '2026-03-10', State::get( 'digest_last' ) );
		State::delete( 'digest_last' );
		Settings::set( 'digest.skip_empty', false );
		Digest::tick();
		$this->assertCount( 1, FakeChannel::$sent );
	}

	public function test_failures_are_not_empty() {
		$this->freeze( $this->at( '2026-03-10 09:10' ) );
		$this->message( '2026-03-09 12:00', 'failed' );
		Digest::tick();
		$this->assertCount( 1, FakeChannel::$sent );
	}

	public function test_send_now_ignores_the_schedule() {
		Settings::set( 'digest.enabled', false );
		$this->assertTrue( Digest::send()[0]['result']->ok );
		Settings::set( 'digest.channels.fake.enabled', false );
		$this->assertSame( [], Digest::send() );
	}

	public function test_default_digest_text_and_change() {
		Settings::set( 'digest.template', [ '_' => '' ] );
		Digest::send( [ 'frequency' => 'daily', 'from' => '2026-03-09', 'to' => '2026-03-09', 'submissions' => 12, 'previous' => [ 'submissions' => 10 ], 'sent' => 3, 'failed' => 4 ] );
		$this->assertNotSame( '', FakeChannel::$sent[0]->body );
		$tags = Digest::tags( [ 'submissions' => 12, 'sent' => 5, 'failed' => 0, 'previous' => [ 'submissions' => 10, 'sent' => 10, 'failed' => 0 ] ] );
		$this->assertContains( '20', $tags['digest.submissions'] );
		$this->assertContains( '+', $tags['digest.submissions'] );
		$this->assertContains( '-', $tags['digest.sent'] );
		$this->assertNotContains( '(', $tags['digest.failed'] );
	}

	public function test_digest_data_filter() {
		$this->filter( 'fnfe_digest_data', function ( $data ) {
			$data['submissions'] = 999;
			return $data;
		} );
		Digest::send();
		$this->assertContains( '999', str_replace( ',', '', \AmirhpCom\FormsNotifier\Core\Text::en_digits( FakeChannel::$sent[0]->body ) ) );
	}

	public function test_summary_on_a_seeded_dataset() {
		$this->freeze( $this->at( '2026-03-10 12:00' ) );
		foreach ( [ '2026-03-10 00:00', '2026-03-10 11:00', '2026-03-09 23:59', '2026-03-04 00:00', '2026-03-03 23:59', '2026-02-20 10:00', '2025-01-01 10:00' ] as $date ) {
			$this->submission( $date );
		}
		$this->submission( '2026-03-10 10:00', 2 );
		$this->submission( '2026-03-10 10:00', 2, 'trash' );
		$this->message( '2026-03-10 01:00', 'sent' );
		$this->message( '2026-03-10 01:00', 'failed', 'second', 'submitter', 2 );
		$this->message( '2026-03-10 01:00', 'retry' );
		$this->message( '2026-03-10 01:00', 'skipped' );
		$this->message( '2026-03-09 01:00', 'sent' );
		$this->message( '2026-03-01 01:00', 'sent' );

		$today = Stats::summary( 'today' );
		$this->assertSame( [ 'today', 3 ], [ $today['range'], $today['submissions'] ] );
		$this->assertSame( [ 'sent' => 1, 'failed' => 1, 'queued' => 1 ], $today['messages'] );
		$this->assertSame( [ 'fake' => [ 'sent' => 1, 'failed' => 0 ], 'second' => [ 'sent' => 0, 'failed' => 1 ] ], $today['by_channel'] );
		$this->assertSame( [ [ 'form_id' => 1, 'name' => 'Form 1', 'submissions' => 2, 'failed' => 0 ], [ 'form_id' => 2, 'name' => 'Form 2', 'submissions' => 1, 'failed' => 1 ] ], $today['by_form'] );
		$this->assertSame( [ [ 'date' => '2026-03-10', 'submissions' => 3, 'sent' => 1, 'failed' => 1 ] ], $today['series'] );
		$this->assertSame( [ 'submissions' => 1, 'sent' => 1, 'failed' => 0 ], $today['previous'] );

		$week = Stats::summary( '7d' );
		$this->assertSame( 5, $week['submissions'] );
		$this->assertCount( 7, $week['series'] );
		$this->assertSame( [ '2026-03-04', '2026-03-10' ], [ $week['series'][0]['date'], $week['series'][6]['date'] ] );
		$this->assertSame( [ 1, 1, 3 ], [ $week['series'][0]['submissions'], $week['series'][5]['submissions'], $week['series'][6]['submissions'] ] );
		$this->assertSame( [ 1, 1 ], [ $week['previous']['submissions'], $week['previous']['sent'] ] );

		$this->assertSame( 7, Stats::summary( '30d' )['submissions'] );
		$this->assertCount( 30, Stats::summary( '30d' )['series'] );
		$all = Stats::summary( 'all' );
		$this->assertSame( [ 8, 3 ], [ $all['submissions'], $all['messages']['sent'] ] );
		$this->assertCount( 90, $all['series'] );
		$this->assertSame( '7d', Stats::summary( 'junk' )['range'] );
		foreach ( [ 'range', 'submissions', 'messages', 'by_channel', 'by_form', 'series' ] as $key ) {
			$this->assertArrayHasKey( $key, $all );
		}
	}

	public function test_series_in_a_timezone_with_odd_minutes() {
		$this->zone = 'Asia/Kathmandu'; // +05:45.
		$this->freeze( $this->at( '2026-03-10 12:00' ) );
		$this->submission( '2026-03-09 23:59' );
		$this->submission( '2026-03-10 00:00' );
		$series = Stats::summary( '7d' )['series'];
		$this->assertSame( [ 1, 1 ], [ $series[5]['submissions'], $series[6]['submissions'] ] );
	}

	public function test_current_form_name_is_used() {
		$id = FormRepository::upsert( '9:z', [ 'name' => 'Renamed' ] );
		$this->freeze( $this->at( '2026-03-10 12:00' ) );
		$this->submission( '2026-03-10 10:00', $id );
		$this->assertSame( 'Renamed', Stats::summary( 'today' )['by_form'][0]['name'] );
	}

	public function test_cleanup_in_batches() {
		$this->freeze( $this->at( '2026-03-10 12:00' ) );
		for ( $i = 0; $i < 510; $i++ ) {
			$this->message( '2025-01-01 10:00', 'sent' );
		}
		$waiting = $this->message( '2025-01-01 10:00', 'retry' );
		$recent  = $this->message( '2026-03-01 10:00', 'sent' );
		LogRepository::insert( 'info', 'x', 'old', [] );
		$out = Cleanup::run();
		$this->assertSame( 510, $out['messages'] );
		$this->assertNotNull( MessageRepository::find( $waiting ) );
		$this->assertNotNull( MessageRepository::find( $recent ) );
		$this->assertSame( 0, $out['log'] );

		$this->freeze( $this->at( '2026-06-10 12:00' ) );
		$this->assertSame( 1, Cleanup::run()['log'] );
		Settings::set( 'privacy.retention_messages_days', 0 );
		$kept = $this->message( '2020-01-01 10:00', 'sent' );
		$this->assertSame( 0, Cleanup::run()['messages'] );
		$this->assertNotNull( MessageRepository::find( $kept ) );
	}

	public function test_cleanup_clears_bodies_when_they_must_not_be_kept() {
		$this->freeze( $this->at( '2026-03-10 12:00' ) );
		$done    = $this->message( '2026-03-09 10:00', 'sent' );
		$waiting = $this->message( '2026-03-09 10:00', 'queued' );
		Cleanup::run();
		$this->assertSame( 'b', MessageRepository::find( $done )['body'] );
		Settings::set( 'privacy.log_bodies', false );
		$this->assertSame( 1, Cleanup::run()['bodies'] );
		$this->assertSame( [ '', [] ], [ MessageRepository::find( $done )['body'], MessageRepository::find( $done )['payload'] ] );
		$this->assertSame( 'b', MessageRepository::find( $waiting )['body'] );
	}

	public function test_daily_hook_and_deleted_submissions() {
		$this->assertTrue( has_action( Cron::CLEANUP, [ Cleanup::class, 'daily' ] ) !== false );
		$this->assertTrue( has_action( Cron::DIGEST, [ Digest::class, 'tick' ] ) !== false );
		$s = Fixtures::submission();
		SubmissionRepository::insert( $s );
		$id = MessageRepository::insert( [ 'submission_id' => $s->id, 'channel' => 'fake', 'status' => 'queued' ] );
		SubmissionRepository::delete( $s->id );
		$this->assertNull( MessageRepository::find( $id ) );
	}


	// Review B, I5.

	public function test_a_late_time_is_not_skipped_by_hourly_ticks(): void {
		Settings::set_many( [ 'digest.time' => '23:30', 'digest.skip_empty' => false ] );
		$start = $this->at( '2026-03-10 00:05' );
		for ( $hour = 0; $hour < 30; $hour++ ) {
			$this->freeze( $start + $hour * HOUR_IN_SECONDS );
			Digest::tick();
		}
		$this->assertCount( 1, FakeChannel::$sent, 'the digest of 10 March, sent at 00:05 on 11 March' );
		$this->assertSame( '2026-03-10', State::get( 'digest_last' ) );
	}

	public function test_a_quiet_evening_does_not_lose_the_digest(): void {
		Settings::set_many( [ 'digest.time' => '22:00', 'digest.skip_empty' => false ] );
		$this->submission( '2026-03-09 12:00' );
		$this->submission( '2026-03-10 12:00' );
		$this->freeze( $this->at( '2026-03-10 21:05' ) );
		Digest::tick();
		$this->assertCount( 0, FakeChannel::$sent );
		// The next visitor comes after midnight.
		$this->freeze( $this->at( '2026-03-11 00:05' ) );
		Digest::tick();
		$this->assertCount( 1, FakeChannel::$sent );
		$this->assertContains( '1|', FakeChannel::$sent[0]->body, 'the period is 10 March' );
		$this->freeze( $this->at( '2026-03-11 01:05' ) );
		Digest::tick();
		$this->assertCount( 1, FakeChannel::$sent );
	}
}
