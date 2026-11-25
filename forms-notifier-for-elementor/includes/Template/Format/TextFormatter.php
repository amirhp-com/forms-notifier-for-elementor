<?php
/**
 * Plain text.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

defined( 'ABSPATH' ) || exit;

/** Plain text: marks dropped, links as "text (url)". */
final class TextFormatter extends AbstractFormatter {

	/** Format id. */
	public function id(): string {
		return 'text';
	}

	/**
	 * Escape a raw value: nothing is special in plain text.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return $value;
	}

	/**
	 * Marks are dropped.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		return $inner;
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return $plain === $url ? $url : $inner . ' (' . $url . ')';
	}
}
