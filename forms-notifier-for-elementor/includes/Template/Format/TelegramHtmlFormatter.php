<?php
/**
 * Telegram HTML parse mode.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** Telegram HTML parse mode. */
final class TelegramHtmlFormatter extends AbstractFormatter {

	/** Format id. */
	public function id(): string {
		return 'telegram_html';
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $value );
	}

	/** Hard limit. */
	public function limit(): int {
		return 4096;
	}

	/**
	 * Split converted text.
	 *
	 * @param string $converted Converted text.
	 * @param int    $limit     Limit, 0 = limit().
	 * @return string[]
	 */
	public function split( string $converted, int $limit = 0 ): array {
		return Splitter::split_html( $converted, $limit > 0 ? $limit : $this->limit(), [ $this, 'length' ] );
	}

	/**
	 * UTF-16 code units after entity parsing.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int {
		// Only real tags: wp_strip_all_tags() would trim spaces that count.
		$text = (string) preg_replace( '~</?[a-z][a-z-]*(?:\s[^<>]*)?>~i', '', $converted );
		return self::utf16( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Apply a mark.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		$tag = $tag === 'spoiler' ? 'tg-spoiler' : $tag;
		return '<' . $tag . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return '<a href="' . str_replace( '"', '&quot;', $this->escape( $url ) ) . '">' . $inner . '</a>';
	}
}
