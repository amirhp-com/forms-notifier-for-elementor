<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Template\Jalali;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class JalaliTest extends TestCase {

	const PURE = true;

	/** Gregorian => Jalali. */
	const DATES = [
		'1979-02-11' => [ 1357, 11, 22 ],
		'1980-03-20' => [ 1358, 12, 30 ],
		'1980-03-21' => [ 1359, 1, 1 ],
		'1996-03-20' => [ 1375, 1, 1 ],
		'2000-01-01' => [ 1378, 10, 11 ],
		'2000-03-20' => [ 1379, 1, 1 ],
		'2001-03-20' => [ 1379, 12, 30 ],
		'2001-03-21' => [ 1380, 1, 1 ],
		'2004-03-20' => [ 1383, 1, 1 ],
		'2009-03-20' => [ 1387, 12, 30 ],
		'2013-03-20' => [ 1391, 12, 30 ],
		'2016-02-29' => [ 1394, 12, 10 ],
		'2016-03-19' => [ 1394, 12, 29 ],
		'2017-03-20' => [ 1395, 12, 30 ],
		'2021-03-20' => [ 1399, 12, 30 ],
		'2021-03-21' => [ 1400, 1, 1 ],
		'2022-09-23' => [ 1401, 7, 1 ],
		'2024-02-29' => [ 1402, 12, 10 ],
		'2024-03-20' => [ 1403, 1, 1 ],
		'2025-03-20' => [ 1403, 12, 30 ],
		'2025-03-21' => [ 1404, 1, 1 ],
		'2026-01-01' => [ 1404, 10, 11 ],
		'2026-11-25' => [ 1405, 9, 4 ],
		'2030-03-20' => [ 1408, 12, 30 ],
	];

	public function test_known_dates_both_ways(): void {
		foreach ( self::DATES as $gregorian => $jalali ) {
			list( $y, $m, $d ) = array_map( 'intval', explode( '-', $gregorian ) );
			$this->assertSame( $jalali, Jalali::from_gregorian( $y, $m, $d ), $gregorian );
			$this->assertSame( [ $y, $m, $d ], Jalali::to_gregorian( ...$jalali ), $gregorian );
		}
	}

	public function test_round_trip_of_every_day(): void {
		$date = new \DateTimeImmutable( '1990-01-01' );
		$bad  = 0;
		for ( $i = 0; $i < 18000; $i++ ) {
			$parts = array_map( 'intval', explode( '-', $date->modify( "+$i days" )->format( 'Y-n-j' ) ) );
			$bad  += Jalali::to_gregorian( ...Jalali::from_gregorian( ...$parts ) ) === $parts ? 0 : 1;
		}
		$this->assertSame( 0, $bad );
	}

	public function test_leap_years_and_month_lengths(): void {
		foreach ( [ 1375, 1379, 1383, 1387, 1391, 1395, 1399, 1403, 1408 ] as $year ) {
			$this->assertTrue( Jalali::is_leap( $year ), (string) $year );
		}
		foreach ( [ 1400, 1401, 1402, 1404, 1405, 1406, 1407 ] as $year ) {
			$this->assertFalse( Jalali::is_leap( $year ), (string) $year );
		}
		$this->assertSame( 31, Jalali::month_days( 1404, 6 ) );
		$this->assertSame( 30, Jalali::month_days( 1404, 11 ) );
		$this->assertSame( 29, Jalali::month_days( 1404, 12 ) );
		$this->assertFalse( Jalali::is_valid( 1404, 12, 30 ) );
		$this->assertTrue( Jalali::is_valid( 1403, 12, 30 ) );
	}

	public function test_format(): void {
		$date = new \DateTimeImmutable( '2026-11-25 14:05:09', new \DateTimeZone( 'Asia/Tehran' ) );
		$this->assertSame( '1405/09/04 14:05', Jalali::format( 'Y/m/d H:i', $date, true ) );
		$this->assertSame( 'چهارشنبه 4 آذر 1405', Jalali::format( 'l j F Y', $date, true ) );
		$this->assertSame( '4 Azar 1405', Jalali::format( 'j F Y', $date, false ) );
		$this->assertSame( '05-9 Y 30 ب.ظ', Jalali::format( 'y-n \\Y t a', $date, true ) );
		$this->assertSame( '249', Jalali::format( 'z', $date, true ) );
	}
}
