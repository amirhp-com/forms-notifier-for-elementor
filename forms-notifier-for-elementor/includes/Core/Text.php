<?php
/**
 * Text helpers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Digits, truncation, ids, and multibyte helpers that also work without the mbstring extension (WordPress replaces
 * mb_substr() and mb_strlen() only). Free of WordPress calls.
 */
final class Text {

	private const FA_DIGITS = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
	private const AR_DIGITS = [ '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ];
	private const EN_DIGITS = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];

	private const LOWER = 'abcdefghijklmnopqrstuvwxyz';
	private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/** One well-formed UTF-8 character, as bytes. */
	private const UTF8_CHAR = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

	/** The mbstring functions WordPress does not replace are there. */
	public static function has_mbstring(): bool {
		return function_exists( 'mb_strtolower' ) && function_exists( 'mb_convert_encoding' );
	}

	/**
	 * Lowercase. Without mbstring only ASCII letters change.
	 *
	 * @param string $s Text.
	 */
	public static function lower( string $s ): string {
		// Not strtolower(): before PHP 8 it follows the locale and can break UTF-8.
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtr( $s, self::UPPER, self::LOWER );
	}

	/**
	 * Uppercase. Without mbstring only ASCII letters change.
	 *
	 * @param string $s Text.
	 */
	public static function upper( string $s ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtr( $s, self::LOWER, self::UPPER );
	}

	/**
	 * Title case. Without mbstring only ASCII letters change.
	 *
	 * @param string $s Text.
	 */
	public static function title( string $s ): string {
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $s, MB_CASE_TITLE, 'UTF-8' );
		}
		return (string) preg_replace_callback(
			'/(?<![A-Za-z0-9\x80-\xFF\'])[a-z]/',
			static function ( array $m ): string {
				return self::upper( $m[0] );
			},
			self::lower( $s )
		);
	}

	/**
	 * The text is valid UTF-8.
	 *
	 * @param string $s Text.
	 */
	public static function is_utf8( string $s ): bool {
		return preg_match( '//u', $s ) === 1;
	}

	/**
	 * Valid UTF-8: every byte that is not part of a character becomes "?".
	 *
	 * @param string $s Text.
	 */
	public static function scrub( string $s ): string {
		if ( self::is_utf8( $s ) ) {
			return $s;
		}
		if ( function_exists( 'mb_scrub' ) ) {
			return mb_scrub( $s, 'UTF-8' );
		}
		$clean = preg_replace( '/(?:' . self::UTF8_CHAR . '){1,100}+(*SKIP)(*FAIL)|./s', '?', $s );
		return is_string( $clean ) ? $clean : strtr( $s, (string) implode( '', array_map( 'chr', range( 128, 255 ) ) ), str_repeat( '?', 128 ) );
	}

	/**
	 * Cut to a number of bytes without splitting a character.
	 *
	 * @param string $s     Text.
	 * @param int    $limit Maximum bytes.
	 */
	public static function cut_bytes( string $s, int $limit ): string {
		if ( $limit <= 0 ) {
			return '';
		}
		if ( strlen( $s ) <= $limit ) {
			return $s;
		}
		// Never further back than one character: the text may not be UTF-8 at all.
		for ( $end = $limit; $end > max( 0, $limit - 4 ); $end-- ) {
			if ( ( ord( $s[ $end ] ) & 0xC0 ) !== 0x80 ) {
				return substr( $s, 0, $end );
			}
		}
		return substr( $s, 0, $limit );
	}

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
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (int) ( strlen( mb_convert_encoding( $s, 'UTF-16LE', 'UTF-8' ) ) / 2 );
		}
		// Every character once, characters outside the basic plane (four bytes) twice.
		return (int) preg_match_all( '/[^\x80-\xBF]/', $s ) + (int) preg_match_all( '/[\xF0-\xF7]/', $s );
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
