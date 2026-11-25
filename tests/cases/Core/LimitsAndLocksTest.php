<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Core\Settings; use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls; use AmirhpCom\FormsNotifier\MiniApp\Session;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class LimitsAndLocksTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	public function test_rate_limit(): void {
		$now = 1767261600;
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; }, PHP_INT_MAX );
		$this->assertSame( 0, RateLimit::count( 'x' ) );
		$this->assertTrue( RateLimit::hit( 'x', 2, 60 ) );
		$this->assertTrue( RateLimit::hit( 'x', 2, 60 ) );
		$this->assertFalse( RateLimit::hit( 'x', 2, 60 ) );
		$this->assertTrue( RateLimit::hit( 'Other bucket / 203.0.113.5', 2, 60 ) );
		$now += 59;
		$this->assertFalse( RateLimit::hit( 'x', 2, 60 ) );
		$this->assertSame( 4, RateLimit::count( 'x' ) );
		$now += 2;
		$this->assertSame( 0, RateLimit::count( 'x' ) );
		$this->assertTrue( RateLimit::hit( 'x', 2, 60 ) );
	}

	public function test_lock(): void {
		$now = 1767261600;
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; }, PHP_INT_MAX );
		$this->assertTrue( Lock::acquire( 'queue', 60 ) );
		$this->assertFalse( Lock::acquire( 'queue', 60 ) );
		$this->assertTrue( Lock::acquire( 'bot_telegram', 60 ) );
		$now += 59;
		$this->assertFalse( Lock::acquire( 'queue', 60 ) );
		$now += 1;
		$this->assertTrue( Lock::acquire( 'queue', 60 ), 'expired lock is taken over' );
		$this->assertFalse( Lock::acquire( 'queue', 60 ) );
		Lock::release( 'queue' );
		$this->assertTrue( Lock::acquire( 'queue', 60 ) );
		Lock::release( 'nothing' );
	}

	/** Another request that writes the option between two steps of this one. */
	private function write_behind( array $state ): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( $state ) ], [ 'option_name' => State::OPTION ] );
	}

	public function test_state_keeps_writes_of_other_requests(): void {
		State::set( 'bot.telegram.webhook_secret', 'old' );
		State::set( 'token_version', 1 );
		$this->assertSame( 'old', State::get( 'bot.telegram.webhook_secret' ) );
		$this->write_behind( [ 'bot' => [ 'telegram' => [ 'webhook_secret' => 'new' ] ], 'token_version' => 2 ] );
		State::set( 'bot.bale.last_poll', 5 );
		$this->assertSame( 'new', State::get( 'bot.telegram.webhook_secret' ) );

		$this->write_behind( [ 'bot' => [ 'telegram' => [ 'webhook_secret' => 'newer' ] ], 'scan_cursor' => [ 'a' => 1 ], 'token_version' => 3 ] );
		State::delete( 'scan_cursor' );
		$this->assertSame( 'newer', State::get( 'bot.telegram.webhook_secret' ) );
		$this->assertSame( null, State::get( 'scan_cursor' ) );

		$this->write_behind( [ 'token_version' => 7 ] );
		Session::revoke_all();
		$this->assertSame( 8, State::get( 'token_version' ) );
	}

	public function test_state_steps_are_serialised(): void {
		$inside = null;
		$new    = State::update(
			'counter',
			static function ( $value ) use ( &$inside ) {
				$inside = Lock::acquire( 'state', 5 );
				State::set( 'other', 1 ); // A nested write does not wait for itself.
				return $value + 1;
			},
			41
		);
		$this->assertSame( 42, $new );
		$this->assertFalse( $inside );
		$this->assertTrue( Lock::acquire( 'state', 5 ), 'released afterwards' );
		$start = microtime( true );
		State::set( 'counter', 1 );
		$this->assertTrue( microtime( true ) - $start > 1.5, 'waits for another request' );
		$this->assertSame( 1, State::get( 'counter' ), 'but never drops the write' );
		Lock::release( 'state' );
	}

	public function test_request(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->assertSame( '203.0.113.77', Request::ip() );
		$this->filter( 'fnfe_client_ip', static function () { return 'not an ip'; } );
		$this->assertSame( '', Request::ip() );
		$this->assertSame( '203.0.113.0', Request::anonymize_ip( '203.0.113.77' ) );
		$this->assertSame( '2001:db8:1::', Request::anonymize_ip( '2001:db8:1:2:3:4:5:6' ) );
		$this->assertSame( '', Request::anonymize_ip( 'x' ) );
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 900 ) . '<b>';
		$this->assertSame( 400, strlen( Request::user_agent() ) );
	}

	public function test_urls(): void {
		$this->assertContains( 'admin.php?page=fnfe#/channels/telegram', Urls::admin( 'channels/telegram' ) );
		$this->assertMatches( '~admin\.php\?page=fnfe$~', Urls::admin() );
		$this->assertContains( '#/submissions/12', Urls::submission_admin( 12 ) );
		$this->assertContains( '#/forms/3', Urls::form_admin( 3 ) );
		$this->assertContains( 'fnfe/v1/settings', Urls::rest( '/settings' ) );
		$hook = Urls::bot_webhook( 'telegram' );
		$this->assertMatches( '~fnfe/v1/bot/telegram/hook/[a-f0-9]{32}$~', $hook );
		$this->assertSame( $hook, Urls::bot_webhook( 'telegram' ) );
		$this->assertNotSame( $hook, Urls::bot_webhook( 'bale' ) );
		update_option( 'permalink_structure', '' );
		$this->assertContains( '?fnfe_app=bale', Urls::miniapp( 'bale' ) );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertContains( '/fnfe-app/bale/', Urls::miniapp( 'bale' ) );
	}

	public function test_cron(): void {
		$this->assertArrayHasKey( 'fnfe_minute', wp_get_schedules() );
		$this->assertSame( 60, wp_get_schedules()['fnfe_minute']['interval'] );
		Cron::clear_all();
		$this->assertFalse( wp_next_scheduled( Cron::CLEANUP ) );
		Settings::set( 'queue.ticker', 'cron' );
		Cron::ensure_events();
		foreach ( [ Cron::QUEUE => 'fnfe_minute', Cron::CLEANUP => 'daily', Cron::DIGEST => 'hourly', Cron::SCAN => 'twicedaily' ] as $hook => $schedule ) {
			$this->assertSame( $schedule, wp_get_schedule( $hook ) );
		}
		$this->assertFalse( wp_next_scheduled( Cron::POLL ) );
		Cron::ensure( Cron::POLL, 'fnfe_minute' );
		$this->assertSame( 'fnfe_minute', wp_get_schedule( Cron::POLL ) );
		Cron::clear( Cron::POLL );
		$this->assertFalse( wp_next_scheduled( Cron::POLL ) );
		Cron::clear_all();
		$this->assertFalse( wp_next_scheduled( Cron::QUEUE ) );
	}
}
