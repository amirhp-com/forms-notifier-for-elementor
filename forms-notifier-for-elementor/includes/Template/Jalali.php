<?php
/**
 * Jalali calendar.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Gregorian <-> Jalali conversion and formatting. */
final class Jalali {

	private const FA_MONTHS   = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
	private const FA_WEEKDAYS = [ 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' ];
	private const FA_SHORT    = [ 'ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش' ];

	/** Days before each Gregorian month in a common year. */
	private const DAYS_BEFORE = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];

	/**
	 * Gregorian to Jalali.
	 *
	 * @param int $gy Year.
	 * @param int $gm Month.
	 * @param int $gd Day.
	 * @return int[] year, month, day
	 */
	public static function from_gregorian( int $gy, int $gm, int $gd ): array {
		$gy2   = $gm > 2 ? $gy + 1 : $gy;
		$days  = 355666 + 365 * $gy + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + self::DAYS_BEFORE[ $gm - 1 ];
		$jy    = -1595 + 33 * intdiv( $days, 12053 );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			--$days;
			$jy   += intdiv( $days, 365 );
			$days %= 365;
		}
		return $days < 186
			? [ $jy, 1 + intdiv( $days, 31 ), 1 + $days % 31 ]
			: [ $jy, 7 + intdiv( $days - 186, 30 ), 1 + ( $days - 186 ) % 30 ];
	}

	/**
	 * Jalali to Gregorian.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 * @param int $jd Day.
	 * @return int[] year, month, day
	 */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy   += 1595;
		$days  = -355668 + 365 * $jy + intdiv( $jy, 33 ) * 8 + intdiv( $jy % 33 + 3, 4 ) + $jd + ( $jm < 7 ? ( $jm - 1 ) * 31 : ( $jm - 7 ) * 30 + 186 );
		$gy    = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			--$days;
			$gy   += 100 * intdiv( $days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				++$days;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			--$days;
			$gy   += intdiv( $days, 365 );
			$days %= 365;
		}
		$leap   = ( $gy % 4 === 0 && $gy % 100 !== 0 ) || $gy % 400 === 0;
		$months = [ 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$day    = $days + 1;
		$month  = 0;
		while ( $month < 11 && $day > $months[ $month ] ) {
			$day -= $months[ $month ];
			++$month;
		}
		return [ $gy, $month + 1, $day ];
	}

	/**
	 * Leap year.
	 *
	 * @param int $jy Jalali year.
	 */
	public static function is_leap( int $jy ): bool {
		list( $gy, $gm, $gd ) = self::to_gregorian( $jy, 12, 30 );
		return self::from_gregorian( $gy, $gm, $gd ) === [ $jy, 12, 30 ];
	}

	/**
	 * Days of a month.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 */
	public static function month_days( int $jy, int $jm ): int {
		if ( $jm < 7 ) {
			return 31;
		}
		return $jm < 12 || self::is_leap( $jy ) ? 30 : 29;
	}

	/**
	 * Valid date.
	 *
	 * @param int $jy Year.
	 * @param int $jm Month.
	 * @param int $jd Day.
	 */
	public static function is_valid( int $jy, int $jm, int $jd ): bool {
		return $jy > 0 && $jy < 3000 && $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::month_days( $jy, $jm );
	}

	/**
	 * Format a moment with a PHP date format, calendar parts in Jalali. Digits stay ASCII.
	 *
	 * @param string             $format PHP date format.
	 * @param \DateTimeInterface $date   Moment in the wanted timezone.
	 * @param bool               $fa     Persian names; otherwise translated Latin names.
	 */
	public static function format( string $format, \DateTimeInterface $date, bool $fa ): string {
		list( $y, $m, $d ) = self::from_gregorian( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), (int) $date->format( 'j' ) );

		$weekday = (int) $date->format( 'w' );
		$out     = '';
		$length  = strlen( $format );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $format[ $i ];
			switch ( $char ) {
				case '\\':
					++$i;
					$out .= $i < $length ? $format[ $i ] : '';
					break;
				case 'd':
					$out .= sprintf( '%02d', $d );
					break;
				case 'j':
					$out .= $d;
					break;
				case 'm':
					$out .= sprintf( '%02d', $m );
					break;
				case 'n':
					$out .= $m;
					break;
				case 'Y':
				case 'o':
					$out .= $y;
					break;
				case 'y':
					$out .= sprintf( '%02d', $y % 100 );
					break;
				case 'F':
				case 'M':
					$out .= $fa ? self::FA_MONTHS[ $m - 1 ] : self::latin_months()[ $m - 1 ];
					break;
				case 'l':
					$out .= $fa ? self::FA_WEEKDAYS[ $weekday ] : self::translated( 'l', $date );
					break;
				case 'D':
					$out .= $fa ? self::FA_SHORT[ $weekday ] : self::translated( 'D', $date );
					break;
				case 't':
					$out .= self::month_days( $y, $m );
					break;
				case 'L':
					$out .= self::is_leap( $y ) ? 1 : 0;
					break;
				case 'z':
					$out .= ( $m < 7 ? ( $m - 1 ) * 31 : 186 + ( $m - 7 ) * 30 ) + $d - 1;
					break;
				case 'S':
					break;
				case 'a':
				case 'A':
					$out .= $fa ? ( (int) $date->format( 'G' ) < 12 ? 'ق.ظ' : 'ب.ظ' ) : $date->format( $char );
					break;
				default:
					$out .= strpos( 'wNWgGhHisuveITPpOZcrUB', $char ) !== false ? $date->format( $char ) : $char;
			}
		}
		return $out;
	}

	/**
	 * Weekday name in the current locale.
	 *
	 * @param string             $format l or D.
	 * @param \DateTimeInterface $date   Moment.
	 */
	private static function translated( string $format, \DateTimeInterface $date ): string {
		return (string) wp_date( $format, $date->getTimestamp(), $date->getTimezone() );
	}

	/** Month names outside Persian. */
	private static function latin_months(): array {
		return [
			_x( 'Farvardin', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Ordibehesht', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Khordad', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Tir', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Mordad', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Shahrivar', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Mehr', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Aban', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Azar', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Dey', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Bahman', 'Jalali month', 'forms-notifier-for-elementor' ),
			_x( 'Esfand', 'Jalali month', 'forms-notifier-for-elementor' ),
		];
	}
}
