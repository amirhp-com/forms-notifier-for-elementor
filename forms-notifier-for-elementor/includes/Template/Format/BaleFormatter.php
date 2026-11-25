<?php
/**
 * Bale markdown.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/**
 * Bale markdown: single marks with a space before the first and after the last mark, [text](url), fenced code.
 * Bale has no escape character, so marks inside values are replaced by look-alikes.
 */
final class BaleFormatter extends AbstractFormatter {

	/** Elements Bale cannot show. */
	private const PLAIN = [ 'u', 's', 'blockquote', 'spoiler' ];

	/** Format id. */
	public function id(): string {
		return 'bale_md';
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return self::with_urls(
			$value,
			static function ( string $url ): string {
				return self::encode_marks( $url, [ '*', '_' ] );
			},
			static function ( string $text ): string {
				$text = str_replace( '](', '] (', str_replace( '```', 'ˋˋˋ', $text ) );
				return self::neutralise(
					$text,
					'*_',
					static function ( string $mark ): string {
						return $mark === '*' ? '∗' : '＿';
					}
				);
			}
		);
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
		return Splitter::split_marks(
			$converted,
			$limit > 0 ? $limit : $this->limit(),
			[ $this, 'length' ],
			[
				'marks' => [ '*', '_' ],
				'atoms' => '\[[^\]\n]*\]\([^)\s]*\)',
			]
		);
	}

	/**
	 * Length as the remote API counts it.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int {
		return self::utf16( $converted );
	}

	/**
	 * Escape a raw value where it stands.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements.
	 */
	protected function value( string $value, array $open ): string {
		if ( isset( $open['code'] ) || isset( $open['pre'] ) ) {
			return str_replace( '```', 'ˋˋˋ', $value );
		}
		if ( isset( $open['a'] ) ) {
			$value = str_replace( [ '[', ']', "\n" ], [ '(', ')', ' ' ], $value );
		}
		return $this->escape( $value );
	}

	/**
	 * Convert nodes; marks get the spaces Bale needs around them.
	 *
	 * @param array $nodes  Nodes.
	 * @param array $values Values.
	 * @param array $open   Open elements.
	 */
	protected function nodes( array $nodes, array $values, array $open ): string {
		$out    = '';
		$marked = false;
		foreach ( self::flatten( $nodes ) as $node ) {
			$piece = $this->node( $node, $values, $open );
			if ( $piece === '' ) {
				continue;
			}
			$is_mark = $node['type'] === 'el' && in_array( $node['tag'], [ 'b', 'i' ], true ) && ! isset( $open['b'] ) && ! isset( $open['i'] ) && ! isset( $open['code'] ) && ! isset( $open['pre'] );
			if ( $out !== '' && ( $marked || $is_mark ) && ! preg_match( '/\s$/u', $out ) && ! preg_match( '/^\s/u', $piece ) ) {
				$out .= ' ';
			}
			$out   .= $piece;
			$marked = $is_mark;
		}
		return $out;
	}

	/**
	 * Convert one node: marks are not nested.
	 *
	 * @param array $node   Node.
	 * @param array $values Values.
	 * @param array $open   Open elements.
	 */
	protected function node( array $node, array $values, array $open ): string {
		if ( $node['type'] === 'el' && in_array( $node['tag'], [ 'b', 'i' ], true ) && ( isset( $open['b'] ) || isset( $open['i'] ) ) ) {
			return $this->nodes( $node['children'], $values, $open );
		}
		return parent::node( $node, $values, $open );
	}

	/**
	 * Apply a mark.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		if ( $tag === 'b' || $tag === 'i' ) {
			$mark = $tag === 'b' ? '*' : '_';
			return self::around_lines( $inner, $mark, $mark );
		}
		return '```' . trim( $inner, "\n" ) . '```';
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return $plain === $url ? self::encode_marks( $url, [ '*', '_' ] ) : '[' . $inner . '](' . self::encode_marks( $url, [ '(', ')' ] ) . ')';
	}

	/**
	 * Elements without a counterpart are replaced by their content.
	 *
	 * @param array $nodes Nodes.
	 */
	private static function flatten( array $nodes ): array {
		$out = [];
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'el' && in_array( $node['tag'], self::PLAIN, true ) ) {
				$out = array_merge( $out, self::flatten( $node['children'] ) );
			} else {
				$out[] = $node;
			}
		}
		return $out;
	}
}
