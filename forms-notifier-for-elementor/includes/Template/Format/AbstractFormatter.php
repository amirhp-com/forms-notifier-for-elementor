<?php
/**
 * Formatter base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Contracts\FormatterInterface;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Template\Markup;
use AmirhpCom\FormsNotifier\Template\Splitter;
use AmirhpCom\FormsNotifier\Template\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Walks parsed canonical markup. Subclasses say how text is escaped and how marks and links look. Values are escaped
 * where they stand (text, code, link) and written once; the output is never scanned again.
 */
abstract class AbstractFormatter implements FormatterInterface {

	/** Invisible character that keeps two marks apart. */
	protected const ZWSP = "\u{200B}";

	/**
	 * Convert canonical markup.
	 *
	 * @param string $canonical Canonical markup.
	 */
	public function convert( string $canonical ): string {
		return $this->convert_with( $canonical, [] );
	}

	/**
	 * Convert canonical markup with inserted values: "\x02{index}\x03" in the markup stands for $values[index], a raw
	 * string or rows of [ label, pieces, plain value ] (a table).
	 *
	 * @param string $canonical Canonical markup.
	 * @param array  $values    Values.
	 */
	public function convert_with( string $canonical, array $values ): string {
		return $this->nodes( Markup::parse( $canonical ), $values, [] );
	}

	/** Hard limit. */
	public function limit(): int {
		return 0;
	}

	/**
	 * Split converted text.
	 *
	 * @param string $converted Converted text.
	 * @param int    $limit     Limit, 0 = limit().
	 * @return string[]
	 */
	public function split( string $converted, int $limit = 0 ): array {
		return Splitter::split_plain( $converted, $limit > 0 ? $limit : $this->limit(), [ $this, 'length' ] );
	}

	/**
	 * Length as the remote API counts it.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int {
		return mb_strlen( $converted, 'UTF-8' );
	}

	/**
	 * Escape literal text.
	 *
	 * @param string $text Decoded text.
	 * @param array  $open Open elements (tag => true).
	 */
	protected function text( string $text, array $open ): string {
		return $this->value( $text, $open );
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements (tag => true).
	 */
	protected function value( string $value, array $open ): string {
		unset( $open );
		return $this->escape( $value );
	}

	/**
	 * Apply a mark to converted text.
	 *
	 * @param string $tag   b, i, u, s, code, pre, blockquote, spoiler.
	 * @param string $inner Converted, not empty.
	 * @param array  $open  Open elements around it.
	 */
	abstract protected function mark( string $tag, string $inner, array $open ): string;

	/**
	 * A link.
	 *
	 * @param string $inner Converted label.
	 * @param string $url   Valid absolute URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		$shown = $this->url( $url );
		return $plain === $url ? $shown : $inner . ': ' . $shown;
	}

	/**
	 * A visible URL.
	 *
	 * @param string $url Valid absolute URL.
	 */
	protected function url( string $url ): string {
		return $this->value( $url, [] );
	}

	/**
	 * Convert nodes.
	 *
	 * @param array $nodes  Nodes.
	 * @param array $values Values.
	 * @param array $open   Open elements.
	 */
	protected function nodes( array $nodes, array $values, array $open ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			$out = $this->append( $out, $this->node( $node, $values, $open ), $node );
		}
		return $out;
	}

	/**
	 * Join converted pieces.
	 *
	 * @param string $out   Output so far.
	 * @param string $piece Next piece.
	 * @param array  $node  Node of the piece.
	 */
	protected function append( string $out, string $piece, array $node ): string {
		unset( $node );
		return $out . $piece;
	}

	/**
	 * Convert one node.
	 *
	 * @param array $node   Node.
	 * @param array $values Values.
	 * @param array $open   Open elements.
	 */
	protected function node( array $node, array $values, array $open ): string {
		if ( $node['type'] === 'text' ) {
			return $this->text( $node['text'], $open );
		}
		if ( $node['type'] === 'value' ) {
			$value = $values[ $node['index'] ] ?? '';
			return is_array( $value ) ? $this->table( $value, $open ) : $this->value( (string) $value, $open );
		}

		$tag = $node['tag'];
		// No marks inside code, and none inside itself.
		if ( isset( $open[ $tag ] ) || isset( $open['code'] ) || isset( $open['pre'] ) ) {
			return $this->nodes( $node['children'], $values, $open );
		}
		$inner = $this->nodes( $node['children'], $values, $open + [ $tag => true ] );
		if ( $tag === 'a' ) {
			$url = self::href( $node['href'], $values );
			if ( $url === '' ) {
				return $this->nodes( $node['children'], $values, $open );
			}
			$plain = trim( Markup::plain_nodes( $node['children'], $values ) );
			return $plain === '' ? $this->link( $this->value( $url, [ 'a' => true ] ), $url, $url ) : $this->link( trim( $inner ), $url, $plain );
		}
		if ( trim( $inner ) === '' ) {
			return $inner;
		}
		return $this->mark( $tag, $inner, $open );
	}

	/**
	 * Rows of a fields table as lines.
	 *
	 * @param array $rows [ label, pieces, plain value ].
	 * @param array $open Open elements.
	 */
	protected function table( array $rows, array $open ): string {
		unset( $open );
		$lines = [];
		foreach ( $rows as $row ) {
			$lines[] = $this->pieces( Tags::line( $row[0], $row[1], $row[2] ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Convert pieces of a markup tag.
	 *
	 * @param array $pieces Pieces: [ 'c', canonical ] or [ 'v', raw value ].
	 */
	protected function pieces( array $pieces ): string {
		$canonical = '';
		$values    = [];
		foreach ( $pieces as $piece ) {
			if ( $piece[0] === 'c' ) {
				$canonical .= $piece[1];
			} else {
				$canonical .= "\x02" . count( $values ) . "\x03";
				$values[]   = $piece[1];
			}
		}
		return $this->convert_with( $canonical, $values );
	}

	/**
	 * Put marks around text, spaces at the edges stay outside.
	 *
	 * @param string $inner Converted text.
	 * @param string $before Opening mark.
	 * @param string $after  Closing mark.
	 */
	protected static function around( string $inner, string $before, string $after ): string {
		preg_match( '/^(\s*)(.*?)(\s*)$/su', $inner, $m );
		return $m[2] === '' ? $inner : $m[1] . $before . $m[2] . $after . $m[3];
	}

	/**
	 * Put marks around every line that has text: for services whose marks end with the line.
	 *
	 * @param string $inner  Converted text.
	 * @param string $before Opening mark.
	 * @param string $after  Closing mark.
	 */
	protected static function around_lines( string $inner, string $before, string $after ): string {
		$lines = [];
		foreach ( explode( "\n", $inner ) as $line ) {
			$lines[] = trim( $line ) === '' ? $line : self::around( $line, $before, $after );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Prefix of quoted lines.
	 *
	 * @param string $inner  Converted text.
	 * @param string $prefix Prefix.
	 */
	protected static function quote( string $inner, string $prefix ): string {
		return $prefix . str_replace( "\n", "\n" . $prefix, trim( $inner, "\n" ) );
	}

	/**
	 * Append a piece that must stand on its own lines.
	 *
	 * @param string $out   Output so far.
	 * @param string $piece Piece.
	 */
	protected static function block( string $out, string $piece ): string {
		return ( $out === '' || substr( $out, -1 ) === "\n" ? $out : $out . "\n" ) . $piece;
	}

	/**
	 * Change the marks of a value that could start or end formatting: those with a word character on at most one side.
	 * Marks between two word characters (john_doe) do nothing and stay.
	 *
	 * @param string   $value   Raw value.
	 * @param string   $marks   Mark characters.
	 * @param callable $replace Receives the mark.
	 */
	protected static function neutralise( string $value, string $marks, callable $replace ): string {
		$class = '[' . preg_quote( $marks, '/' ) . ']';
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}])' . $class . '|' . $class . '(?![\p{L}\p{N}])/u',
			static function ( array $m ) use ( $replace ): string {
				return $replace( $m[0] );
			},
			$value
		);
	}

	/**
	 * Split a value into URLs and other text and convert each part.
	 *
	 * @param string   $value Raw value.
	 * @param callable $url   Receives a URL.
	 * @param callable $text  Receives other text.
	 */
	protected static function with_urls( string $value, callable $url, callable $text ): string {
		$out = '';
		foreach ( preg_split( '~(https?://[^\s<>"\'`|\\\\^{}\[\]()]+)~iu', $value, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [ $value ] as $i => $part ) {
			if ( $part !== '' ) {
				$out .= $i % 2 ? $url( $part ) : $text( $part );
			}
		}
		return $out;
	}

	/**
	 * Percent-encode the characters that are marks in a visible URL; the address stays the same.
	 *
	 * @param string   $url   URL.
	 * @param string[] $marks Characters.
	 */
	protected static function encode_marks( string $url, array $marks ): string {
		$map = [];
		foreach ( $marks as $mark ) {
			$map[ $mark ] = '%' . strtoupper( bin2hex( $mark ) );
		}
		return strtr( $url, $map );
	}

	/**
	 * Valid link target with values put in, '' otherwise.
	 *
	 * @param string $href   Attribute.
	 * @param array  $values Values.
	 */
	private static function href( string $href, array $values ): string {
		$url = preg_replace_callback(
			'/\x02(\d+)\x03/',
			static function ( array $m ) use ( $values ): string {
				$value = $values[ (int) $m[1] ] ?? '';
				return is_array( $value ) ? '' : (string) $value;
			},
			$href
		);
		return Markup::url( (string) $url );
	}

	/**
	 * Length in UTF-16 code units.
	 *
	 * @param string $text Text.
	 */
	protected static function utf16( string $text ): int {
		return Text::utf16_length( $text );
	}
}
