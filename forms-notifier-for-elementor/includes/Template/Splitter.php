<?php
/**
 * Splitter.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Length-aware splitting. Text becomes a list of units (words, spaces, marks, links); a part ends at the best border
 * that fits: paragraph, line, sentence, word. Marks that are open at the border are closed and opened again in the
 * next part. A unit is never cut, so tags, entities and links stay whole.
 *
 * Unit: [ text, length, kind, reopen, close ]   kind: text | space | open | close
 */
final class Splitter {

	public const MAX_PARTS = 10;

	/** Room left under the limit. */
	private const MARGIN = 96;

	/** @var bool */
	private static $truncated = false;

	private const PARAGRAPH = 3;
	private const LINE      = 2;
	private const SENTENCE  = 1;
	private const WORD      = 0;

	/** Smallest share of a part a border of this class must leave. */
	private const SHARE = [
		self::PARAGRAPH => 0.5,
		self::LINE      => 0.4,
		self::SENTENCE  => 0.4,
		self::WORD      => 0.0,
	];

	/**
	 * Split HTML-like text on line boundaries, re-opening/closing tags in every part.
	 *
	 * @param string        $html   Text.
	 * @param int           $limit  Limit, 0 = none.
	 * @param callable|null $length Length function, default characters.
	 * @return string[]
	 */
	public static function split_html( string $html, int $limit, ?callable $length = null ): array {
		$html   = Text::scrub( $html );
		$length = $length ?? [ self::class, 'chars' ];
		$whole  = self::whole( $html, $limit, $length );
		if ( $whole !== null ) {
			return $whole;
		}
		$units = [];
		preg_match_all( '~<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^<>"\'])*+)>|(?:&[a-zA-Z0-9#]+;|[^\s<&])+|\s|[<&]~u', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$name = strtolower( $m[2] ?? '' );
			if ( $name === '' || in_array( $name, [ 'br', 'hr', 'img' ], true ) ) {
				self::add_text( $units, $m[0], $limit, $length, '~&[a-zA-Z0-9#]+;|\X~u' );
				continue;
			}
			$units[] = [ $m[0], $length( $m[0] ), $m[1] === '/' ? 'close' : 'open', $m[0], '</' . $name . '>' ];
		}
		return self::cut( $units, $limit, $length );
	}

	/**
	 * Split plain text.
	 *
	 * @param string        $text   Text.
	 * @param int           $limit  Limit, 0 = none.
	 * @param callable|null $length Length function, default characters.
	 * @return string[]
	 */
	public static function split_plain( string $text, int $limit, ?callable $length = null ): array {
		$text   = Text::scrub( $text );
		$length = $length ?? [ self::class, 'chars' ];
		$whole  = self::whole( $text, $limit, $length );
		if ( $whole !== null ) {
			return $whole;
		}
		$units = [];
		preg_match_all( '~\s|\S+~u', $text, $matches );
		foreach ( $matches[0] as $word ) {
			self::add_text( $units, $word, $limit, $length, '~\X~u' );
		}
		return self::cut( $units, $limit, $length );
	}

	/**
	 * Split text with inline marks.
	 *
	 * @param string   $text    Text.
	 * @param int      $limit   Limit, 0 = none.
	 * @param callable $length  Length function.
	 * @param array    $grammar marks (string[], longest first), atoms (regex of pieces that stay whole), code (bool: `x`
	 *                          is code), strict (bool: every mark counts; otherwise only marks at word borders),
	 *                          quote (prefix of quoted lines), escape (callable for the closing note).
	 * @return string[]
	 */
	public static function split_marks( string $text, int $limit, callable $length, array $grammar ): array {
		$text  = Text::scrub( $text );
		$whole = self::whole( $text, $limit, $length );
		if ( $whole !== null ) {
			return $whole;
		}
		$units  = [];
		$open   = [];
		$offset = 0;
		$total  = strlen( $text );
		$list   = $grammar['marks'] ?? [];
		$marks  = implode( '|', array_map( [ self::class, 'quoted' ], $list ) );
		$first  = preg_quote( count_chars( implode( '', $list ), 3 ), '~' );
		$atoms  = $grammar['atoms'] ?? '';
		$code   = ! empty( $grammar['code'] );
		$regex  = '~(?<fence>```)|' . ( $code ? '(?<tick>`)|' : '' ) . ( $atoms === '' ? '' : '(?<atom>' . $atoms . ')|' )
			. ( $marks === '' ? '' : '(?<mark>' . $marks . ')|' ) . '(?<space>\s)|(?<word>[^\s`\\\\\[<&' . $first . ']+)|(?<other>.)~Asu';

		while ( $offset < $total && preg_match( $regex, $text, $m, PREG_UNMATCHED_AS_NULL, $offset ) ) {
			$piece = $m[0];
			$end   = $offset + strlen( $piece );
			if ( $m['fence'] !== null || ( $m['tick'] ?? null ) !== null ) {
				$skip = isset( $grammar['escape'] ) ? '(?:\\\\.|(?!' . $piece . ').)*+' : '(?:(?!' . $piece . ').)*+';
				if ( preg_match( '~' . $skip . '(?=' . $piece . ')~As', $text, $found, 0, $end ) ) {
					$stop    = $end + strlen( $found[0] );
					$inner   = substr( $text, $end, $stop - $end );
					$break   = strncmp( $inner, "\n", 1 ) === 0 ? "\n" : '';
					$units[] = [ $piece . $break, $length( $piece . $break ), 'open', $piece . $break, $break . $piece ];
					$inner   = $break === '' ? $inner : substr( $inner, 1 );
					$tail    = $break !== '' && substr( $inner, -1 ) === "\n" ? "\n" : '';
					$inner   = $tail === '' ? $inner : substr( $inner, 0, -1 );
					preg_match_all( '~\\\\.|\s|[^\s\\\\]+|\\\\~su', $inner, $words );
					foreach ( $words[0] as $word ) {
						self::add_text( $units, $word, $limit, $length, '~\\\\.|\X~u' );
					}
					$units[] = [ $tail . $piece, $length( $tail . $piece ), 'close', '', '' ];
					$offset  = $stop + strlen( $piece );
					continue;
				}
			} elseif ( ( $m['mark'] ?? null ) !== null ) {
				$at     = array_search( $piece, $open, true );
				$before = $offset === 0 ? '' : (string) mb_substr( substr( $text, max( 0, $offset - 8 ), min( 8, $offset ) ), -1 );
				$after  = $end >= $total ? '' : (string) mb_substr( substr( $text, $end, 8 ), 0, 1 );
				$strict = ! empty( $grammar['strict'] );
				if ( $at === false && ( $strict || ( ! self::is_word( $before ) && $after !== '' && ! preg_match( '/^\s$/u', $after ) ) ) ) {
					$open[]  = $piece;
					$units[] = [ $piece, $length( $piece ), 'open', $piece, $piece ];
					$offset  = $end;
					continue;
				}
				if ( $at !== false && ( $strict || ( ! self::is_word( $after ) && ! preg_match( '/^\s?$/u', $before ) ) ) ) {
					array_splice( $open, (int) $at, 1 );
					$units[] = [ $piece, $length( $piece ), 'close', $piece, $piece ];
					$offset  = $end;
					continue;
				}
			}
			if ( ( $m['atom'] ?? null ) !== null ) {
				$units[] = [ $piece, $length( $piece ), 'text', '', '' ];
			} else {
				self::add_text( $units, $piece, $limit, $length, '~\X~u' );
			}
			$offset = $end;
		}
		return self::cut( $units, $limit, $length, $grammar['escape'] ?? null, (string) ( $grammar['quote'] ?? '' ) );
	}

	/** The last split left text out; its last part ends with a note. */
	public static function was_truncated(): bool {
		return self::$truncated;
	}

	/**
	 * Add a link under the note when the last split left text out.
	 *
	 * @param string[] $parts Parts of the last split.
	 * @param string   $link  Link in the format of the parts.
	 * @return string[]
	 */
	public static function append_link( array $parts, string $link ): array {
		if ( $link !== '' && $parts && self::$truncated ) {
			$parts[ count( $parts ) - 1 ] .= "\n" . $link;
		}
		return $parts;
	}

	/**
	 * Characters.
	 *
	 * @param string $text Text.
	 */
	public static function chars( string $text ): int {
		return mb_strlen( $text, 'UTF-8' );
	}

	/**
	 * Regex of a literal.
	 *
	 * @param string $text Literal.
	 */
	private static function quoted( string $text ): string {
		return preg_quote( $text, '~' );
	}

	/**
	 * Result without splitting, null when the text must be split.
	 *
	 * @param string   $text   Text.
	 * @param int      $limit  Limit.
	 * @param callable $length Length function.
	 * @return string[]|null
	 */
	private static function whole( string $text, int $limit, callable $length ): ?array {
		self::$truncated = false;
		if ( trim( $text ) === '' ) {
			return [];
		}
		return $limit <= 0 || $length( $text ) <= $limit ? [ $text ] : null;
	}

	/**
	 * Add a word or a space. Words that are long compared with the limit are added piece by piece.
	 *
	 * @param array    $units  Units.
	 * @param string   $text   Text.
	 * @param int      $limit  Limit.
	 * @param callable $length Length function.
	 * @param string   $pieces Regex of the smallest pieces.
	 */
	private static function add_text( array &$units, string $text, int $limit, callable $length, string $pieces ): void {
		$size = $length( $text );
		if ( $size > max( 1, intdiv( $limit, 4 ) ) && preg_match_all( $pieces, $text, $m ) ) {
			foreach ( $m[0] as $piece ) {
				$units[] = [ $piece, $length( $piece ), 'text', '', '' ];
			}
			return;
		}
		$units[] = [ $text, $size, preg_match( '/^\s$/u', $text ) ? 'space' : 'text', '', '' ];
	}

	/**
	 * Build the parts.
	 *
	 * @param array         $units  Units.
	 * @param int           $limit  Limit.
	 * @param callable      $length Length function.
	 * @param callable|null $escape Escapes the note.
	 * @param string        $quote  Prefix of quoted lines.
	 * @return string[]
	 */
	private static function cut( array $units, int $limit, callable $length, ?callable $escape = null, string $quote = '' ): array {
		$budget = max( 1, $limit - min( self::MARGIN, intdiv( $limit, 10 ) ) );
		$count  = count( $units );
		$parts  = [];
		$stack  = [];
		$prefix = '';
		$start  = 0;

		while ( $start < $count ) {
			$last = count( $parts ) === self::MAX_PARTS - 1;
			$stop = self::border( $units, $start, $stack, $prefix, $budget, $length );
			$note = '';
			if ( $last && $stop < $count ) {
				$note = self::note( $units, $start, $stop, $escape );
				$stop = self::border( $units, $start, $stack, $prefix, max( 1, $budget - $length( "\n" . $note ) ), $length );
				$note = self::note( $units, $start, $stop, $escape );
			}

			$text = $prefix . implode( '', array_column( $stack, 3 ) );
			$body = '';
			for ( $i = $start; $i < $stop; $i++ ) {
				$body .= $units[ $i ][0];
				self::track( $stack, $units[ $i ] );
			}
			$part = $text . rtrim( $body ) . implode( '', array_reverse( array_column( $stack, 4 ) ) );
			if ( $note !== '' ) {
				$part .= "\n" . $note;
			}
			if ( trim( $body ) !== '' ) {
				$parts[] = $part;
			}
			if ( $note !== '' ) {
				self::$truncated = true;
				break;
			}

			$prefix = self::quote_prefix( $prefix . $body, $quote );
			$start  = $stop;
			while ( $start < $count && $units[ $start ][2] === 'space' ) {
				$prefix = $units[ $start ][0] === "\n" ? '' : $prefix;
				++$start;
			}
		}
		return $parts;
	}

	/**
	 * Index after the last unit of the part that starts at $start.
	 *
	 * @param array    $units  Units.
	 * @param int      $start  First unit.
	 * @param array    $stack  Open marks.
	 * @param string   $prefix Text before the first unit.
	 * @param int      $budget Room.
	 * @param callable $length Length function.
	 */
	private static function border( array $units, int $start, array $stack, string $prefix, int $budget, callable $length ): int {
		$count   = count( $units );
		$used    = $prefix === '' ? 0 : $length( $prefix );
		$closing = 0;
		foreach ( $stack as $mark ) {
			$used    += $length( $mark[3] );
			$closing += $length( $mark[4] );
		}
		$best    = [];
		$visible = false;
		for ( $i = $start; $i < $count; $i++ ) {
			$unit  = $units[ $i ];
			$after = $closing;
			if ( $unit[2] === 'open' ) {
				$after += $length( $unit[4] );
			} elseif ( $unit[2] === 'close' ) {
				$after = max( 0, $after - $unit[1] );
			}
			if ( $visible && $used + $unit[1] + $after > $budget ) {
				break;
			}
			$used   += $unit[1];
			$closing = $after;
			$visible = $visible || $unit[2] === 'text';
			if ( $unit[2] === 'space' && $visible ) {
				$class          = self::border_class( $units, $i );
				$best[ $class ] = [ $i + 1, $used ];
			}
		}
		if ( $i >= $count ) {
			return $count;
		}

		$stop = $i;
		foreach ( [ self::PARAGRAPH, self::LINE, self::SENTENCE, self::WORD ] as $class ) {
			if ( isset( $best[ $class ] ) && $best[ $class ][1] >= $budget * self::SHARE[ $class ] ) {
				$stop = $best[ $class ][0];
				break;
			}
		}
		// A mark opens in the part of its text and closes there too.
		while ( $stop - 1 > $start && $units[ $stop - 1 ][2] === 'open' ) {
			--$stop;
		}
		while ( $stop < $count && $units[ $stop ][2] === 'close' ) {
			++$stop;
		}
		return $stop;
	}

	/**
	 * Class of the border after a space.
	 *
	 * @param array $units Units.
	 * @param int   $i     Index of the space.
	 */
	private static function border_class( array $units, int $i ): int {
		if ( $units[ $i ][0] === "\n" ) {
			return $i > 0 && $units[ $i - 1 ][0] === "\n" ? self::PARAGRAPH : self::LINE;
		}
		$j = $i - 1;
		while ( $j >= 0 && $units[ $j ][2] === 'close' ) {
			--$j;
		}
		return $j >= 0 && preg_match( '/[.!?؟؛…。][)"»”\']*$/u', $units[ $j ][0] ) ? self::SENTENCE : self::WORD;
	}

	/**
	 * Open marks after a unit.
	 *
	 * @param array $stack Open marks.
	 * @param array $unit  Unit.
	 */
	private static function track( array &$stack, array $unit ): void {
		if ( $unit[2] === 'open' ) {
			$stack[] = $unit;
		} elseif ( $unit[2] === 'close' && $stack ) {
			array_pop( $stack );
		}
	}

	/**
	 * Note about the text that is left out.
	 *
	 * @param array         $units  Units.
	 * @param int           $start  First unit of the last part.
	 * @param int           $stop   First unit that is left out.
	 * @param callable|null $escape Escapes the note.
	 */
	private static function note( array $units, int $start, int $stop, ?callable $escape ): string {
		unset( $start );
		$left = 0;
		for ( $i = $stop, $count = count( $units ); $i < $count; $i++ ) {
			$left += in_array( $units[ $i ][2], [ 'text', 'space' ], true ) ? $units[ $i ][1] : 0;
		}
		if ( $left <= 0 ) {
			return '';
		}
		/* translators: %1$d: number of characters */
		$note = sprintf( _n( '… (%1$d more character)', '… (%1$d more characters)', $left, 'forms-notifier-for-elementor' ), $left );
		return $escape ? (string) $escape( $note ) : $note;
	}

	/**
	 * Quote prefix of the next part when a quoted line was cut.
	 *
	 * @param string $body  Text of the part.
	 * @param string $quote Prefix.
	 */
	private static function quote_prefix( string $body, string $quote ): string {
		if ( $quote === '' || substr( $body, -1 ) === "\n" ) {
			return '';
		}
		$break = strrpos( $body, "\n" );
		$line  = $break === false ? $body : substr( $body, $break + 1 );
		return strncmp( $line, $quote, strlen( $quote ) ) === 0 ? $quote : '';
	}

	/**
	 * Letter or digit.
	 *
	 * @param string $char Character.
	 */
	private static function is_word( string $char ): bool {
		return $char !== '' && preg_match( '/^[\p{L}\p{N}]$/u', $char ) === 1;
	}
}
