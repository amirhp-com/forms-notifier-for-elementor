<?php
/**
 * SMS parts.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** Counts and limits the parts of an SMS: GSM-7 160/153 characters, anything else UCS-2 70/67. */
final class Segments {

	private const GSM_BASIC    = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	private const GSM_EXTENDED = '^{}\\[~]|€';

	/**
	 * The text fits the GSM-7 alphabet.
	 *
	 * @param string $text Text.
	 */
	public static function is_gsm( string $text ): bool {
		return preg_match( '/^[' . preg_quote( self::GSM_BASIC . self::GSM_EXTENDED, '/' ) . ']*$/u', $text ) === 1;
	}

	/**
	 * Number of parts.
	 *
	 * @param string $text Text.
	 */
	public static function parts( string $text ): int {
		if ( $text === '' ) {
			return 0;
		}
		if ( self::is_gsm( $text ) ) {
			$length = mb_strlen( $text ) + preg_match_all( '/[' . preg_quote( self::GSM_EXTENDED, '/' ) . ']/u', $text );
			return $length <= 160 ? 1 : (int) ceil( $length / 153 );
		}
		$length = Text::utf16_length( $text );
		return $length <= 70 ? 1 : (int) ceil( $length / 67 );
	}

	/**
	 * Shorten a text to a number of parts. GSM-7 texts end with "..." because the ellipsis character would turn the
	 * whole message into the shorter Unicode form.
	 *
	 * @param string $text  Text.
	 * @param int    $parts Largest number of parts, 0 = no limit.
	 */
	public static function limit( string $text, int $parts ): string {
		if ( $parts < 1 || self::parts( $text ) <= $parts ) {
			return $text;
		}
		$gsm      = self::is_gsm( $text );
		$ellipsis = $gsm ? '...' : '…';
		$length   = min( mb_strlen( $text ), $gsm ? ( $parts === 1 ? 160 : 153 * $parts ) : ( $parts === 1 ? 70 : 67 * $parts ) );
		do {
			$short = rtrim( mb_substr( $text, 0, $length ) ) . $ellipsis;
			--$length;
		} while ( $length > 0 && self::parts( $short ) > $parts );
		return $short;
	}
}
