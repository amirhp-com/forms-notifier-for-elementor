<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Length-aware splitting: plain character cuts only. */
final class Splitter {

	/**
	 * Split HTML-like text.
	 *
	 * @param string        $html   Text.
	 * @param int           $limit  Limit.
	 * @param callable|null $length Length function.
	 * @return string[]
	 */
	public static function split_html( string $html, int $limit, ?callable $length = null ): array {
		return self::split_plain( $html, $limit, $length );
	}

	/**
	 * Split plain text.
	 *
	 * @param string        $text   Text.
	 * @param int           $limit  Limit.
	 * @param callable|null $length Length function.
	 * @return string[]
	 */
	public static function split_plain( string $text, int $limit, ?callable $length = null ): array {
		unset( $length );
		if ( $text === '' ) {
			return [];
		}
		return $limit > 0 ? mb_str_split( $text, $limit ) : [ $text ];
	}
}
