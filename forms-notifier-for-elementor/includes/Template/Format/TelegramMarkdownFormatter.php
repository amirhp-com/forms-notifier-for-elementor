<?php
/**
 * Telegram MarkdownV2 parse mode.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** Telegram MarkdownV2 parse mode. */
final class TelegramMarkdownFormatter extends AbstractFormatter {

	private const SPECIAL = '_*[]()~`>#+-=|{}.!\\';

	private const MARKS = [
		'b'       => '*',
		'i'       => '_',
		'u'       => '__',
		's'       => '~',
		'spoiler' => '||',
	];

	/** Format id. */
	public function id(): string {
		return 'telegram_md';
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return addcslashes( $value, self::SPECIAL );
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
				'marks'  => [ '||', '__', '*', '_', '~' ],
				'atoms'  => '\\\\.|\[(?:\\\\.|[^\]\\\\])*\]\((?:\\\\.|[^)\\\\])*\)',
				'code'   => true,
				'escape' => [ $this, 'escape' ],
				'strict' => true,
				'quote'  => '>',
			]
		);
	}

	/**
	 * UTF-16 code units after entity parsing.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int {
		$text = (string) preg_replace_callback(
			'~\\\\(.)|\[((?:\\\\.|[^\]\\\\])*)\]\((?:\\\\.|[^)\\\\])*\)|```\n?|[*_\~|`]|^>~mu',
			static function ( array $m ): string {
				if ( isset( $m[2] ) ) {
					return stripslashes( $m[2] );
				}
				return $m[1] ?? '';
			},
			$converted
		);
		return self::utf16( $text );
	}

	/**
	 * Escape a raw value where it stands.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements.
	 */
	protected function value( string $value, array $open ): string {
		return isset( $open['code'] ) || isset( $open['pre'] ) ? addcslashes( $value, '`\\' ) : $this->escape( $value );
	}

	/**
	 * Apply a mark.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		if ( $tag === 'pre' ) {
			return "```\n" . trim( $inner, "\n" ) . "\n```";
		}
		if ( $tag === 'code' ) {
			return '`' . $inner . '`';
		}
		if ( $tag === 'blockquote' ) {
			return self::quote( $inner, '>' );
		}
		$mark = self::MARKS[ $tag ];
		// "___" would be read as underline first: keep italic and underline apart.
		$before = $inner[0] === '_' && $mark[0] === '_' ? self::ZWSP : '';
		$after  = substr( $inner, -1 ) === '_' && substr( $inner, -2, 1 ) !== '\\' && $mark[0] === '_' ? self::ZWSP : '';
		return self::around( $before . $inner . $after, $mark, $mark );
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return '[' . $inner . '](' . addcslashes( $url, ')\\' ) . ')';
	}

	/**
	 * Join pieces: blocks on their own lines, equal marks apart.
	 *
	 * @param string $out   Output so far.
	 * @param string $piece Next piece.
	 * @param array  $node  Node.
	 */
	protected function append( string $out, string $piece, array $node ): string {
		if ( $piece === '' ) {
			return $out;
		}
		if ( $node['type'] === 'el' && ( strncmp( $piece, '>', 1 ) === 0 || strncmp( $piece, '```', 3 ) === 0 ) ) {
			return self::block( $out, $piece ) . "\n";
		}
		if ( $out !== '' && $node['type'] === 'el' && strpos( '_`|', $piece[0] ) !== false && substr( $out, -1 ) === $piece[0] && substr( $out, -2, 1 ) !== '\\' ) {
			return $out . self::ZWSP . $piece;
		}
		if ( $node['type'] !== 'el' && $piece[0] === "\n" && preg_match( '/(^|\n)(>[^\n]*|```)\n$/', $out ) ) {
			return $out . substr( $piece, 1 );
		}
		return $out . $piece;
	}
}
