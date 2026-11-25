<?php
/**
 * WhatsApp marks.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** WhatsApp marks: *bold*, _italic_, ~strike~, `code`, "> quote". No escape character exists. */
final class WhatsAppFormatter extends AbstractFormatter {

	private const MARKS = [
		'b' => '*',
		'i' => '_',
		's' => '~',
	];

	/** Format id. */
	public function id(): string {
		return 'whatsapp';
	}

	/**
	 * Escape a raw value: marks are separated from their neighbours by an invisible character, backticks replaced.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return self::with_urls(
			$value,
			static function ( string $url ): string {
				return self::encode_marks( $url, [ '*', '_', '~' ] );
			},
			static function ( string $text ): string {
				$text = str_replace( '`', 'ˋ', $text );
				// Lists and quotes at the start of a line.
				$text = (string) preg_replace( '/^(?=[>*-]\s|>|\d+\.\s)/mu', self::ZWSP, $text );
				return self::neutralise(
					$text,
					'*_~',
					static function ( string $mark ): string {
						return self::ZWSP . $mark . self::ZWSP;
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
				'marks' => [ '*', '_', '~' ],
				'code'  => true,
				'quote' => '> ',
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
		return isset( $open['code'] ) || isset( $open['pre'] ) ? str_replace( '`', 'ˋ', $value ) : $this->escape( $value );
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
	 * A visible URL.
	 *
	 * @param string $url URL.
	 */
	protected function url( string $url ): string {
		return self::encode_marks( $url, [ '*', '_', '~' ] );
	}
}
