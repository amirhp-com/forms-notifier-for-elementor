<?php
/**
 * Markdown.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** CommonMark-like text for chat services. Underline and spoiler use the marks of Discord. */
final class MarkdownFormatter extends AbstractFormatter {

	private const MARKS = [
		'b'       => '**',
		'i'       => '*',
		'u'       => '__',
		's'       => '~~',
		'spoiler' => '||',
	];

	/** Format id. */
	public function id(): string {
		return 'markdown';
	}

	/**
	 * Escape a raw value. Group mentions (@all and the like) are broken up: a visitor must not notify a whole room.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return $this->guard( (string) preg_replace( '/@(?=(?:all|channel|here|everyone)\b)/i', '@' . self::ZWSP, $value ) );
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
				'marks'  => [ '||', '**', '__', '~~', '*' ],
				'atoms'  => '\\\\.|\[(?:\\\\.|[^\]\\\\])*\]\([^)\s]*\)|https?://\S+',
				'code'   => true,
				'strict' => true,
				'quote'  => '> ',
			]
		);
	}

	/**
	 * Escape literal text.
	 *
	 * @param string $text Text.
	 * @param array  $open Open elements.
	 */
	protected function text( string $text, array $open ): string {
		return isset( $open['code'] ) || isset( $open['pre'] ) ? $this->value( $text, $open ) : $this->guard( $text );
	}

	/**
	 * Escape a raw value where it stands.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements.
	 */
	protected function value( string $value, array $open ): string {
		if ( isset( $open['code'] ) || isset( $open['pre'] ) ) {
			return str_replace( '`', 'ˋ', $value );
		}
		return isset( $open['a'] ) ? $this->escape( str_replace( "\n", ' ', $value ) ) : $this->escape( $value );
	}

	/**
	 * Apply a mark.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		if ( isset( self::MARKS[ $tag ] ) ) {
			return self::around_lines( $inner, self::MARKS[ $tag ], self::MARKS[ $tag ] );
		}
		if ( $tag === 'blockquote' ) {
			return self::quote( $inner, '> ' );
		}
		return $tag === 'code' && strpos( $inner, "\n" ) === false ? '`' . $inner . '`' : "```\n" . trim( $inner, "\n" ) . "\n```";
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return $plain === $url ? self::encode_marks( $url, [ '*', '_', '~', '|' ] ) : '[' . $inner . '](' . self::encode_marks( $url, [ '(', ')', '\\' ] ) . ')';
	}

	/**
	 * Join pieces: blocks on their own lines.
	 *
	 * @param string $out   Output so far.
	 * @param string $piece Next piece.
	 * @param array  $node  Node.
	 */
	protected function append( string $out, string $piece, array $node ): string {
		if ( $piece !== '' && $node['type'] === 'el' && ( strncmp( $piece, '> ', 2 ) === 0 || strncmp( $piece, "```\n", 4 ) === 0 ) ) {
			return self::block( $out, $piece ) . "\n";
		}
		if ( $piece !== '' && $node['type'] !== 'el' && $piece[0] === "\n" && preg_match( '/(^|\n)(> [^\n]*|```)\n$/', $out ) ) {
			return $out . substr( $piece, 1 );
		}
		return $out . $piece;
	}

	/**
	 * Backslash before everything that could become markup, URLs percent-encoded.
	 *
	 * @param string $text Text.
	 */
	private function guard( string $text ): string {
		return self::with_urls(
			$text,
			static function ( string $url ): string {
				return self::encode_marks( $url, [ '*', '_', '~' ] );
			},
			static function ( string $part ): string {
				$part = addcslashes( $part, '\\`*_~|[]<' );
				// Headings, quotes and lists only matter at the start of a line; no service reads a tab there as text.
				$part = (string) preg_replace( '/^([ \t]*)([#>+-])/m', '$1\\\\$2', $part );
				return (string) preg_replace( '/^([ \t]*\d+)([.)])(?=\s)/m', '$1\\\\$2', $part );
			}
		);
	}
}
