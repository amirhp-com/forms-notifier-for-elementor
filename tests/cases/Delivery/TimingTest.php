<?php
/**
 * Backoff steps and quiet hours (pure).
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Delivery\Backoff;
use AmirhpCom\FormsNotifier\Delivery\QuietHours;
use AmirhpCom\FormsNotifier\Delivery\Worker;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class TimingTest extends TestCase {

	const PURE = true;

	public function test_backoff_sequence_and_last_value_repeats() {
		$steps = [ 60, 300, 900 ];
		$this->assertSame( [ 60, 300, 900, 900, 900 ], array_map( function ( $n ) use ( $steps ) {
			return Backoff::base( $steps, $n );
		}, [ 1, 2, 3, 4, 50 ] ) );
	}

	public function test_backoff_with_bad_settings() {
		$this->assertSame( 60, Backoff::base( [], 1 ) );
		$this->assertSame( 60, Backoff::base( [ 'x', null ], 3 ) );
		$this->assertSame( 120, Backoff::base( [ '120' ], 0 ) );
		$this->assertSame( 1, Backoff::base( [ -5 ], 1 ) );
	}

	public function test_quiet_hours_across_midnight() {
		$tehran = new \DateTimeZone( 'Asia/Tehran' );
		$at     = function ( string $time ) use ( $tehran ): int {
			return ( new \DateTimeImmutable( $time, $tehran ) )->getTimestamp();
		};
		$ends   = function ( string $time ) use ( $at, $tehran ): int {
			return QuietHours::ends_at( $at( $time ), '23:00', '07:00', $tehran );
		};
		$this->assertSame( 0, $ends( '2026-01-10 22:59:59' ) );
		$this->assertSame( $at( '2026-01-11 07:00' ), $ends( '2026-01-10 23:00:00' ) );
		$this->assertSame( $at( '2026-01-11 07:00' ), $ends( '2026-01-11 00:30:00' ) );
		$this->assertSame( $at( '2026-01-11 07:00' ), $ends( '2026-01-11 06:59:59' ) );
		$this->assertSame( 0, $ends( '2026-01-11 07:00:00' ) );
		$this->assertSame( 0, $ends( '2026-01-11 12:00:00' ) );
	}

	public function test_quiet_hours_inside_one_day() {
		$utc = new \DateTimeZone( 'UTC' );
		$now = strtotime( '2026-01-10 13:15:00 UTC' );
		$this->assertSame( strtotime( '2026-01-10 14:00:00 UTC' ), QuietHours::ends_at( $now, '13:00', '14:00', $utc ) );
		$this->assertSame( 0, QuietHours::ends_at( $now, '14:00', '15:00', $utc ) );
	}

	public function test_quiet_hours_bad_values() {
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( [ [ '', '07:00' ], [ '23:00', '' ], [ '25:00', '07:00' ], [ '08:00', '08:00' ], [ 'x', 'y' ] ] as $pair ) {
			$this->assertSame( 0, QuietHours::ends_at( 1767261600, $pair[0], $pair[1], $utc ) );
		}
	}

	public function test_quiet_hours_over_clock_changes() {
		$berlin = new \DateTimeZone( 'Europe/Berlin' );
		// 29 March 2026: 02:00 becomes 03:00, the night is an hour shorter.
		$now = ( new \DateTimeImmutable( '2026-03-28 23:00', $berlin ) )->getTimestamp();
		$this->assertSame( 7 * 3600, QuietHours::ends_at( $now, '23:00', '07:00', $berlin ) - $now );
		// 25 October 2026: 03:00 becomes 02:00, the night is an hour longer.
		$now = ( new \DateTimeImmutable( '2026-10-24 23:00', $berlin ) )->getTimestamp();
		$this->assertSame( 9 * 3600, QuietHours::ends_at( $now, '23:00', '07:00', $berlin ) - $now );
	}

	public function test_delivery_status_of_mixed_outcomes() {
		$cases = [
			[ [], 'none' ],
			[ [ 'queued' => 1 ], 'queued' ],
			[ [ 'sent' => 2 ], 'sent' ],
			[ [ 'failed' => 2 ], 'failed' ],
			[ [ 'sent' => 1, 'failed' => 1 ], 'partial' ],
			[ [ 'sent' => 1, 'failed' => 1, 'retry' => 1 ], 'queued' ],
			[ [ 'sent' => 1, 'sending' => 1 ], 'queued' ],
			[ [ 'failed' => 1, 'retry' => 1 ], 'queued' ],
			[ [ 'sent' => 1, 'skipped' => 3 ], 'sent' ],
			[ [ 'skipped' => 1 ], 'failed' ],
			[ [ 'failed' => 1, 'cancelled' => 1 ], 'failed' ],
			[ [ 'cancelled' => 2 ], 'none' ],
		];
		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], Worker::delivery_status( $case[0] ), json_encode( $case[0] ) );
		}
	}
}
