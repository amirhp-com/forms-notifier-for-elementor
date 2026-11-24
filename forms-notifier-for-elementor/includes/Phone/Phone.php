<?php
/**
 * Phone numbers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Phone;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** E.164 normalisation and country detection. */
final class Phone {

	/** Countries whose national numbers keep their leading zero after the dial code. */
	private const KEEP_ZERO = [ 'IT', 'VA', 'SM', 'CI', 'CG', 'BF', 'BJ' ];

	/**
	 * Digits (ASCII) with an optional leading +.
	 *
	 * @param string $raw Raw number.
	 */
	public static function clean( string $raw ): string {
		$raw  = str_replace( '＋', '+', Text::en_digits( $raw ) );
		$plus = preg_match( '/^\D*\+/', $raw ) === 1 ? '+' : '';
		return $plus . preg_replace( '/\D/', '', $raw );
	}

	/**
	 * E.164 with leading +, null when not a plausible number.
	 *
	 * @param string $raw     Raw number.
	 * @param string $country ISO2 or dial code hint ('' = general.default_country).
	 */
	public static function normalize( string $raw, string $country = '' ): ?string {
		$raw = Text::en_digits( trim( $raw ) );
		// "+44 (0)20 ...": the zero in brackets is only dialled inside the country.
		$raw = preg_match( '/^\D*(?:\+|00)/', $raw ) === 1 ? (string) preg_replace( '/\(\s*0\s*\)/', '', $raw ) : $raw;
		// An extension would otherwise become part of the number.
		$raw    = (string) preg_replace( '/(?<=\d)[\s,]*(?:ext\.?|extension|x|#|;ext=|داخلی)\s*:?\s*\d{1,6}\s*$/iu', '', $raw );
		$number = self::clean( $raw );
		$digits = ltrim( $number, '+' );

		if ( strncmp( $number, '+', 1 ) === 0 ) {
			$international = $digits;
		} elseif ( strncmp( $digits, '00', 2 ) === 0 ) {
			$international = substr( $digits, 2 );
		} elseif ( preg_match( '/^0?(989\d{9})$/', $digits, $m ) ) {
			$international = $m[1];
		} else {
			$international = self::from_national( $digits, trim( $country ) === '' ? Settings::str( 'general.default_country' ) : $country );
		}

		if ( strncmp( $international, '98', 2 ) === 0 ) {
			$international = '98' . ltrim( substr( $international, 2 ), '0' );
			if ( ! preg_match( '/^98(?:9\d{9}|[1-8]\d{9})$/', $international ) ) {
				return null;
			}
		}
		return preg_match( '/^[1-9]\d{6,14}$/', $international ) ? '+' . $international : null;
	}

	/**
	 * ISO2 of an E.164 number by longest dial-prefix match ('' unknown).
	 *
	 * @param string $e164 Number.
	 */
	public static function country( string $e164 ): string {
		return DialCodes::country( self::digits( $e164 ) );
	}

	/**
	 * 'IR' -> '98'
	 *
	 * @param string $country ISO2.
	 */
	public static function dial_for( string $country ): string {
		return DialCodes::dial( $country );
	}

	/**
	 * Iranian number.
	 *
	 * @param string $e164 Number.
	 */
	public static function is_iran( string $e164 ): bool {
		return strncmp( $e164, '+98', 3 ) === 0;
	}

	/**
	 * 09121234567
	 *
	 * @param string $e164 Number.
	 */
	public static function iran_local( string $e164 ): string {
		return '0' . self::iran_bare( $e164 );
	}

	/**
	 * 9121234567
	 *
	 * @param string $e164 Number.
	 */
	public static function iran_bare( string $e164 ): string {
		return (string) preg_replace( '/^98/', '', self::digits( $e164 ) );
	}

	/**
	 * Without +.
	 *
	 * @param string $e164 Number.
	 */
	public static function digits( string $e164 ): string {
		return ltrim( $e164, '+' );
	}

	/**
	 * +98912***4567
	 *
	 * @param string $e164 Number.
	 */
	public static function mask( string $e164 ): string {
		return strlen( $e164 ) > 10 ? substr( $e164, 0, 6 ) . '***' . substr( $e164, -4 ) : '***';
	}

	/**
	 * International digits of a national number, '' when the country is unknown.
	 *
	 * @param string $digits  National digits.
	 * @param string $country ISO2 or dial code.
	 */
	private static function from_national( string $digits, string $country ): string {
		$country = strtoupper( trim( Text::en_digits( $country ) ) );
		if ( preg_match( '/^(?:\+|00)?([1-9]\d{0,5})$/', $country, $m ) ) {
			$dial = $m[1];
			$iso2 = DialCodes::country( $dial );
		} else {
			$dial = DialCodes::dial( $country );
			$iso2 = $country;
		}
		if ( $dial === '' || $digits === '' ) {
			return '';
		}

		// The area code of a small country may be typed or left out.
		if ( strlen( $dial ) === 4 && $dial[0] === '1' ) {
			if ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
				return $digits;
			}
			return strlen( $digits ) === 10 ? '1' . $digits : $dial . $digits;
		}
		// Russia and Kazakhstan dial 8 inside the country.
		if ( $dial === '7' && preg_match( '/^8(\d{10})$/', $digits, $m ) ) {
			return '7' . $m[1];
		}
		// Already international, only the plus is missing. Brazilian area code 55 makes shorter numbers ambiguous.
		$least = $iso2 === 'BR' ? 12 : 11;
		if ( strlen( $digits ) >= $least && strncmp( $digits, $dial, strlen( $dial ) ) === 0 ) {
			return $digits;
		}
		if ( $digits[0] === '0' && ! in_array( $iso2, self::KEEP_ZERO, true ) ) {
			$digits = substr( $digits, 1 );
		}
		return $dial . $digits;
	}
}
