<?php
/**
 * Slack mrkdwn.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** Slack mrkdwn. Limit = text of one section block. */
final class SlackFormatter extends AbstractFormatter {

	private const MARKS = [
		'b' => '*',
		'i' => '_',
		's' => '~',
	];

	/** Format id. */
	public function id(): string {
		return 'slack';
	}

	/**
	 * Escape a raw value: entities for & < >, marks separated by an invisible character.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return self::with_urls(
			$value,
			static function ( string $url ): string {
				return self::entities( self::encode_marks( $url, [ '*', '_', '~' ] ) );
			},
			static function ( string $text ): string {
				$text = str_replace( '`', 'ˋ', $text );
				$text = (string) preg_replace( '/^(?=>)/mu', self::ZWSP, $text );
				$text = self::neutralise(
					$text,
					'*_~',
					static function ( string $mark ): string {
						return self::ZWSP . $mark . self::ZWSP;
					}
				);
				return self::entities( $text );
			}
		);
	}

	/** Text of one section block. */
	public function limit(): int {
		return 3000;
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
				'marks' => [ '*', '_', '~' ],
				'atoms' => '<[^<>\n]*>|&(?:amp|lt|gt);',
				'code'  => true,
				'quote' => '> ',
			]
		);
	}

	/**
	 * Escape a raw value where it stands.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements.
	 */
	protected function value( string $value, array $open ): string {
		if ( isset( $open['code'] ) || isset( $open['pre'] ) ) {
			return self::entities( str_replace( '`', 'ˋ', $value ) );
		}
		return isset( $open['a'] ) ? $this->escape( str_replace( [ '|', "\n" ], [ '∣', ' ' ], $value ) ) : $this->escape( $value );
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
		if ( $tag === 'pre' ) {
			return '```' . trim( $inner, "\n" ) . '```';
		}
		if ( $tag === 'code' ) {
			return strpos( $inner, "\n" ) === false ? '`' . $inner . '`' : '```' . $inner . '```';
		}
		return $tag === 'blockquote' ? self::quote( $inner, '> ' ) : $inner;
	}

	/**
	 * Join pieces: quotes on their own lines.
	 *
	 * @param string $out   Output so far.
	 * @param string $piece Next piece.
	 * @param array  $node  Node.
	 */
	protected function append( string $out, string $piece, array $node ): string {
		if ( $node['type'] === 'el' && $node['tag'] === 'blockquote' && strncmp( $piece, '> ', 2 ) === 0 ) {
			return self::block( $out, $piece ) . "\n";
		}
		if ( $piece !== '' && $node['type'] !== 'el' && $piece[0] === "\n" && preg_match( '/(^|\n)> [^\n]*\n$/', $out ) ) {
			return $out . substr( $piece, 1 );
		}
		return $out . $piece;
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		$target = self::entities( str_replace( [ '|', '<', '>' ], [ '%7C', '%3C', '%3E' ], $url ) );
		return $plain === $url ? '<' . $target . '>' : '<' . $target . '|' . $inner . '>';
	}

	/**
	 * Entities of the three control characters.
	 *
	 * @param string $text Text.
	 */
	private static function entities( string $text ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $text );
	}
}
