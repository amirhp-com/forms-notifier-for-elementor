<?php
/**
 * Text helpers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Digits, truncation, ids. Free of WordPress calls. */
final class Text {

	private const FA_DIGITS = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
	private const AR_DIGITS = [ '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ];
	private const EN_DIGITS = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];

	/**
	 * Persian and Arabic-Indic digits to ASCII.
	 *
	 * @param string $s Text.
	 */
	public static function en_digits( string $s ): string {
		return str_replace( self::AR_DIGITS, self::EN_DIGITS, str_replace( self::FA_DIGITS, self::EN_DIGITS, $s ) );
	}

	/**
	 * ASCII and Arabic-Indic digits to Persian.
	 *
	 * @param string $s Text.
	 */
	public static function fa_digits( string $s ): string {
		return str_replace( self::EN_DIGITS, self::FA_DIGITS, self::en_digits( $s ) );
	}

	/**
	 * Cut to a number of characters, the ellipsis included.
	 *
	 * @param string $s        Text.
	 * @param int    $limit    Maximum characters of the result.
	 * @param string $ellipsis Appended when something was cut.
	 */
	public static function truncate( string $s, int $limit, string $ellipsis = '…' ): string {
		if ( $limit <= 0 ) {
			return '';
		}
		if ( mb_strlen( $s, 'UTF-8' ) <= $limit ) {
			return $s;
		}
		$room = $limit - mb_strlen( $ellipsis, 'UTF-8' );
		if ( $room <= 0 ) {
			return mb_substr( $s, 0, $limit, 'UTF-8' );
		}
		return rtrim( mb_substr( $s, 0, $room, 'UTF-8' ) ) . $ellipsis;
	}

	/**
	 * Length in UTF-16 code units (as Telegram counts).
	 *
	 * @param string $s Text.
	 */
	public static function utf16_length( string $s ): int {
		return (int) ( strlen( mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' ) ) / 2 );
	}

	/**
	 * Random id: prefix + 8 base36 characters.
	 *
	 * @param string $prefix Prefix.
	 */
	public static function uid( string $prefix = '' ): string {
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
		$id       = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$id .= $alphabet[ random_int( 0, 35 ) ];
		}
		return $prefix . $id;
	}

	/**
	 * Split on any line ending.
	 *
	 * @param string $s Text.
	 * @return string[]
	 */
	public static function lines( string $s ): array {
		return preg_split( '/\r\n|\r|\n/', $s );
	}

	/**
	 * Loose boolean: 'yes', '1', 'true', 'on' are true.
	 *
	 * @param mixed $v Value.
	 */
	public static function bool( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v !== 0.0;
		}
		return is_string( $v ) && in_array( strtolower( trim( $v ) ), [ 'yes', '1', 'true', 'on' ], true );
	}
}
