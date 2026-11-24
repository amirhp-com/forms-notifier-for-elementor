<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Phone;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** E.164 normalisation: a short dial code table. */
final class Phone {

	private const DIAL = [
		'AE' => '971',
		'AF' => '93',
		'CA' => '1',
		'DE' => '49',
		'FR' => '33',
		'GB' => '44',
		'IQ' => '964',
		'IR' => '98',
		'TR' => '90',
		'US' => '1',
	];

	/**
	 * Digits (ASCII) with an optional leading +.
	 *
	 * @param string $raw Raw number.
	 */
	public static function clean( string $raw ): string {
		$raw  = trim( Text::en_digits( $raw ) );
		$plus = strncmp( $raw, '+', 1 ) === 0 ? '+' : '';
		return $plus . preg_replace( '/\D/', '', $raw );
	}

	/**
	 * E.164 with leading +, null when not a plausible number.
	 *
	 * @param string $raw     Raw number.
	 * @param string $country ISO2 or dial code hint ('' = general.default_country).
	 */
	public static function normalize( string $raw, string $country = '' ): ?string {
		$number = self::clean( $raw );
		if ( strncmp( $number, '00', 2 ) === 0 ) {
			$number = '+' . substr( $number, 2 );
		}
		if ( strncmp( $number, '+', 1 ) !== 0 ) {
			$country = $country === '' ? Settings::str( 'general.default_country' ) : $country;
			$dial    = ctype_digit( $country ) ? $country : self::dial_for( $country );
			if ( $dial === '' ) {
				return null;
			}
			$number = strncmp( $number, $dial, strlen( $dial ) ) === 0 && strlen( $number ) > 10
				? '+' . $number
				: '+' . $dial . ltrim( $number, '0' );
		}
		return preg_match( '/^\+[1-9]\d{6,14}$/', $number ) ? $number : null;
	}

	/**
	 * ISO2 of an E.164 number ('' unknown).
	 *
	 * @param string $e164 Number.
	 */
	public static function country( string $e164 ): string {
		$digits = self::digits( $e164 );
		foreach ( [ 'IR', 'AE', 'AF', 'IQ', 'TR', 'DE', 'FR', 'GB', 'US' ] as $iso2 ) {
			if ( strncmp( $digits, self::DIAL[ $iso2 ], strlen( self::DIAL[ $iso2 ] ) ) === 0 ) {
				return $iso2;
			}
		}
		return '';
	}

	/**
	 * 'IR' -> '98'
	 *
	 * @param string $country ISO2.
	 */
	public static function dial_for( string $country ): string {
		return self::DIAL[ strtoupper( $country ) ] ?? '';
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
}
