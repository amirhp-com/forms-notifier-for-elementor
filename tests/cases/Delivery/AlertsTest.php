<?php
/**
 * Failure alerts.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Delivery\Alerts;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Ticker;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class AlertsTest extends TestCase {

	const NOW = 1767261600;

	public static function setUpClass(): void {
		FakeChannel::install( 'fake' );
		FakeChannel::install( 'second' );
		FakeChannel::install( 'data', [], 'json' );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many(
			[
				'channels.fake.enabled'            => true,
				'channels.second.enabled'          => true,
				'channels.data.enabled'            => true,
				'queue.ticker'                     => 'cron',
				'alerts.enabled'                   => true,
				'alerts.template'                  => [ '_' => 'ALERT {alert.channel} / {alert.recipient} / {form_name} / {submission_id} / {alert.error} / {alert.attempts} / {alert.log_url}' ],
				'alerts.channels.second.enabled'   => true,
				'alerts.channels.second.receivers' => [ Fixtures::receiver( 'boss' ) ],
			]
		);
		$this->freeze( self::NOW );
	}

	/** Push a message that fails for good with an error. */
	private function failing( string $error = 'boom', array $row = [], string $channel = 'fake' ): int {
		$id = Queue::push( Fixtures::message( $channel ) );
		if ( $row ) {
			MessageRepository::update( $id, $row );
		}
		FakeChannel::$results = [ SendResult::failure( $channel, $error, true ) ];
		Queue::run_ids( [ $id ] );
		return $id;
	}

	private function alerts(): array {
		return array_reverse( MessageRepository::query( [ 'purpose' => 'alert', 'per_page' => 100 ] )['items'] );
	}

	public function test_final_failure_alerts_once_with_the_facts() {
		$form = FormRepository::upsert( '1:a', [ 'name' => 'Contact {field:x}' ] );
		$id   = $this->failing( 'bad <b>{site_url}</b>', [ 'form_id' => $form, 'submission_id' => 42 ] );
		$rows = $this->alerts();
		$this->assertCount( 1, $rows );
		$this->assertSame( [ 'second', 'boss', 'sent', $id, 1 ], [ $rows[0]['channel'], $rows[0]['recipient'], $rows[0]['status'], $rows[0]['parent_id'], $rows[0]['max_attempts'] ] );
		// The receiver follows the site language: the number of attempts may be written in Persian digits, ids never.
		$this->assertContains( 'ALERT Fake fake / Desk / Contact {field:x} / 42 / bad <b>{site_url}</b> / ', $rows[0]['body'] );
		$this->assertContains( ' / 1 / ', \AmirhpCom\FormsNotifier\Core\Text::en_digits( $rows[0]['body'] ) );
		$this->assertContains( '#/log/' . $id, $rows[0]['body'] );
	}

	public function test_no_alert_before_the_last_attempt() {
		$id                   = Queue::push( Fixtures::message() );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'x' ) ];
		Queue::run_ids( [ $id ] );
		$this->assertCount( 0, $this->alerts() );
	}

	public function test_max_attempts_reached_alerts_once() {
		$this->filter( 'fnfe_max_attempts', function () {
			return 2;
		} );
		$id                   = Queue::push( Fixtures::message() );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'x' ), SendResult::failure( 'fake', 'x' ) ];
		Queue::run_ids( [ $id ] );
		Queue::run_ids( [ $id ] );
		$this->assertSame( 'failed', MessageRepository::find( $id )['status'] );
		$this->assertCount( 1, $this->alerts() );
	}

	public function test_first_failure_mode_does_not_repeat() {
		Settings::set( 'alerts.when', 'first' );
		$id                   = Queue::push( Fixtures::message() );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'x' ) ];
		Queue::run_ids( [ $id ] );
		$this->assertCount( 1, $this->alerts() );
		$this->freeze( self::NOW + 4000 );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'other', true ) ];
		Queue::run_ids( [ $id ] );
		$this->assertCount( 1, $this->alerts() );
	}

	public function test_off_means_off() {
		Settings::set( 'alerts.enabled', false );
		$this->failing();
		Alerts::send( 'a', 'b' );
		$this->assertCount( 0, $this->alerts() );
	}

	public function test_never_recursive() {
		foreach ( [ 'alert', 'test', 'digest' ] as $purpose ) {
			$this->failing( 'boom ' . $purpose, [ 'purpose' => $purpose ] );
		}
		$this->assertCount( 3, MessageRepository::query( [ 'status' => 'failed' ] )['items'] );
		$this->assertCount( 1, $this->alerts(), 'only the row that was itself an alert' );
	}

	public function test_a_failing_alert_only_logs() {
		$id                   = Queue::push( Fixtures::message() );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'boom', true ), SendResult::failure( 'second', 'alert broke', true ) ];
		Queue::run_ids( [ $id ] );
		$rows = $this->alerts();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['status'] );
		$this->assertSame( 0, Queue::pending(), 'no retry queue' );
		$log = LogRepository::query( [ 'context' => 'alert' ] )['items'];
		$this->assertContains( 'alert broke', $log[0]['message'] );
	}

	public function test_an_exception_in_an_alert_is_contained() {
		$this->filter( 'fnfe_alert_message', function () {
			throw new \RuntimeException( 'filter' );
		} );
		$id = $this->failing();
		$this->assertSame( 'failed', MessageRepository::find( $id )['status'] );
	}

	public function test_coalesced_and_counted() {
		$this->failing( 'Too many requests: retry after 12' );
		$this->failing( 'Too many requests: retry after 34' );
		$this->failing( 'Too many requests: retry after 56' );
		$this->assertCount( 1, $this->alerts() );
		$this->assertSame( 2, State::get( 'alerts_suppressed' ) );
		$this->failing( 'something else' );
		$rows = $this->alerts();
		$this->assertCount( 2, $rows );
		$this->assertContains( '2', \AmirhpCom\FormsNotifier\Core\Text::en_digits( substr( $rows[1]['body'], strpos( $rows[1]['body'], '#/log/' ) + 8 ) ) );
		$this->assertSame( 0, (int) State::get( 'alerts_suppressed' ) );

		$this->freeze( self::NOW + 601 );
		$this->failing( 'Too many requests: retry after 12' );
		$this->assertCount( 3, $this->alerts() );
	}

	public function test_hourly_limit() {
		Settings::set( 'alerts.max_per_hour', 3 );
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $error ) {
			$this->failing( $error );
		}
		$this->assertCount( 3, $this->alerts() );
		$this->assertSame( 2, State::get( 'alerts_suppressed' ) );
		$this->freeze( self::NOW + 3601 );
		$this->failing( 'f' );
		$this->assertCount( 4, $this->alerts() );
	}

	public function test_the_failed_channel_does_not_report_itself() {
		Settings::set_many(
			[
				'alerts.channels.fake.enabled'   => true,
				'alerts.channels.fake.receivers' => [ Fixtures::receiver( 'me' ) ],
			]
		);
		$this->failing();
		$this->assertSame( [ 'second' ], array_column( $this->alerts(), 'channel' ) );
		Settings::set( 'alerts.channels.second.enabled', false );
		$this->failing( 'only channel' );
		$this->assertSame( [ 'second', 'fake' ], array_column( $this->alerts(), 'channel' ) );
	}

	public function test_receivers_disabled_not_ready_and_duplicates() {
		Settings::set( 'alerts.channels.second.receivers', [ Fixtures::receiver( 'boss' ), Fixtures::receiver( 'boss' ), Fixtures::receiver( 'off', [ 'enabled' => false ] ) ] );
		$this->failing();
		$this->assertCount( 1, $this->alerts() );
		Settings::set( 'channels.second.enabled', false );
		$this->failing( 'two' );
		$this->assertCount( 1, $this->alerts() );
		$this->assertNull( State::get( 'alerts_suppressed' ), 'nobody to tell is not suppression' );
	}

	public function test_receiver_language() {
		Settings::set_many(
			[
				'alerts.template'                  => [ '_' => 'default', 'fa' => 'فارسی' ],
				'alerts.channels.second.receivers' => [ Fixtures::receiver( 'a', [ 'lang' => 'fa' ] ), Fixtures::receiver( 'b', [ 'lang' => 'de' ] ) ],
			]
		);
		$this->failing();
		$this->assertSame( [ 'فارسی', 'default' ], array_column( $this->alerts(), 'body' ) );
	}

	public function test_default_text() {
		Settings::set( 'alerts.template', [ '_' => '' ] );
		$this->failing( 'the reason' );
		$this->assertContains( 'the reason', $this->alerts()[0]['body'] );
	}

	public function test_filter_changes_the_text() {
		$this->filter( 'fnfe_alert_message', function ( $text, $row, $result ) {
			return 'custom ' . $row['channel'] . ' ' . $result->error;
		}, 10, 3 );
		$this->failing();
		$this->assertSame( 'custom fake boom', $this->alerts()[0]['body'] );
	}

	public function test_structured_data() {
		Settings::set_many(
			[
				'alerts.channels.second.enabled' => false,
				'alerts.channels.data.enabled'   => true,
				'alerts.channels.data.receivers' => [ Fixtures::receiver( 'e1' ) ],
			]
		);
		$id = $this->failing();
		$m  = end( FakeChannel::$sent );
		$this->assertSame( 'alert', $m->data['event'] );
		$this->assertSame( 'boom', $m->data['alert']['error'] );
		$this->assertContains( '#/log/' . $id, $m->data['alert']['log_url'] );
	}

	public function test_custom_alert() {
		Alerts::send( 'Webhook {lost}', 'The <b>webhook</b> was removed.', [ 'channel' => 'second' ] );
		Alerts::send( 'Webhook {lost}', 'The <b>webhook</b> was removed.' );
		$rows = $this->alerts();
		$this->assertCount( 1, $rows );
		$this->assertContains( 'Webhook {lost}', $rows[0]['body'] );
		$this->assertContains( 'The <b>webhook</b> was removed.', $rows[0]['body'] );
	}

	public function test_test_alert_ignores_switch_and_limits() {
		Settings::set( 'alerts.enabled', false );
		$this->assertTrue( Alerts::test()[0]['result']->ok );
		$this->assertTrue( Alerts::test()[0]['result']->ok );
	}

	public function test_stuck_queue_alerts_once() {
		Queue::push( Fixtures::message() );
		$this->freeze( self::NOW + 1000 );
		// Another run holds the lock, so the tick cannot work.
		\AmirhpCom\FormsNotifier\Core\Lock::acquire( 'queue', 120 );
		Ticker::tick();
		Ticker::tick();
		$this->assertCount( 1, $this->alerts() );
		\AmirhpCom\FormsNotifier\Core\Lock::release( 'queue' );
	}

	public function test_persian_alert_numbers_but_not_ids_or_errors() {
		Settings::set( 'alerts.channels.second.receivers', [ Fixtures::receiver( 'boss', [ 'lang' => 'fa' ] ) ] );
		$this->failing( 'HTTP 500 at 10:30', [ 'submission_id' => 42 ] );
		$rows = $this->alerts();
		$this->assertCount( 1, $rows );
		$this->assertContains( '/ 42 / HTTP 500 at 10:30 / ۱ / ', $rows[0]['body'] );
	}
}
