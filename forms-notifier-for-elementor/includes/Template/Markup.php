<?php
/**
 * Canonical markup.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Sanitiser, escaping and parser of the small HTML subset templates are written in. */
final class Markup {

	public const ALLOWED = [ 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'code', 'pre', 'a', 'blockquote', 'spoiler', 'br' ];

	public const PROTOCOLS = [ 'http', 'https', 'tg', 'mailto', 'tel' ];

	/** Template constructs: conditionals, tags, Elementor shortcodes. They are never touched. */
	public const CONSTRUCT = '\{/?(?:if|elseif|else)\b(?:"[^"\r\n]*"|[^{}"\r\n])*+\}|\{[a-z0-9_.]+(?::(?:"[^"\r\n]*"|[^{}|"\r\n])*+)?(?:\|[a-z0-9_]+(?::(?:"[^"\r\n]*"|[^{}|"\r\n])*+)?)*+\}|\[field\s[^\]\r\n]*\]|\[all-fields\]';

	/** Same element under another name. */
	private const ALIASES = [
		'strong' => 'b',
		'em'     => 'i',
		'del'    => 's',
	];

	/** One HTML tag; template constructs may appear inside attributes. */
	private const TAG = '<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^<>"\'])*+)>';

	/**
	 * Clean an admin-authored template: keep allowed tags (a: href only), keep template tags untouched. Idempotent.
	 *
	 * @param string $template Untrusted template.
	 */
	public static function sanitize( string $template ): string {
		$template = self::clean_text( wp_check_invalid_utf8( $template ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$before   = $template;
			$template = self::strip( $template );
			if ( $template === $before ) {
				break;
			}
		}
		return $template;
	}

	/**
	 * Escape a raw value for canonical markup.
	 *
	 * @param string $value Raw value.
	 */
	public static function escape( string $value ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $value );
	}

	/**
	 * Plain text of canonical markup, without link targets.
	 *
	 * @param string $canonical Canonical markup.
	 */
	public static function to_plain( string $canonical ): string {
		return self::plain_nodes( self::parse( $canonical ), [] );
	}

	/**
	 * Line endings to \n and no control characters (they mark inserted values while rendering).
	 *
	 * @param string $text Text.
	 */
	public static function clean_text( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	/**
	 * Parse canonical markup into nodes:
	 *   [ 'type' => 'text', 'text' => string ]
	 *   [ 'type' => 'value', 'index' => int ]              "\x02{index}\x03" in the input
	 *   [ 'type' => 'el', 'tag' => string, 'href' => string, 'children' => nodes ]
	 * Unknown tags are dropped, their content stays. Never fails.
	 *
	 * @param string $canonical Canonical markup.
	 */
	public static function parse( string $canonical ): array {
		$canonical = (string) preg_replace( '/<!--.*?-->/s', '', $canonical );
		$stack     = [ self::element( '' ) ];
		$offset    = 0;
		preg_match_all( '~' . self::TAG . '|\x02(\d+)\x03~', $canonical, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $match ) {
			self::push_text( $stack, substr( $canonical, $offset, $match[0][1] - $offset ) );
			$offset = $match[0][1] + strlen( $match[0][0] );

			if ( isset( $match[4] ) && $match[4][0] !== '' ) {
				self::push(
					$stack,
					[
						'type'  => 'value',
						'index' => (int) $match[4][0],
					]
				);
				continue;
			}
			$tag = strtolower( $match[2][0] );
			$tag = self::ALIASES[ $tag ] ?? $tag;
			if ( $tag === 'br' ) {
				self::push_text( $stack, "\n" );
			} elseif ( ! in_array( $tag, self::ALLOWED, true ) ) {
				continue;
			} elseif ( $match[1][0] === '/' ) {
				self::close( $stack, $tag );
			} else {
				$node         = self::element( $tag );
				$node['href'] = $tag === 'a' ? self::href( $match[3][0] ) : '';
				$stack[]      = $node;
			}
		}
		self::push_text( $stack, substr( $canonical, $offset ) );
		self::close_all( $stack );
		return $stack[0]['children'];
	}

	/**
	 * Text of nodes without any mark.
	 *
	 * @param array $nodes  Nodes.
	 * @param array $values Inserted values.
	 */
	public static function plain_nodes( array $nodes, array $values ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'text' ) {
				$out .= $node['text'];
			} elseif ( $node['type'] === 'value' ) {
				$value = $values[ $node['index'] ] ?? '';
				$out  .= is_array( $value ) ? '' : $value;
			} else {
				$out .= self::plain_nodes( $node['children'], $values );
			}
		}
		return $out;
	}

	/**
	 * Absolute URL with an accepted protocol, '' otherwise.
	 *
	 * @param string $url Raw URL.
	 */
	public static function url( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '~^(https?://[^\s/?#]|tg://|mailto:|tel:)~i', $url ) ) {
			return '';
		}
		return (string) esc_url_raw( $url, self::PROTOCOLS );
	}

	/**
	 * One pass of the sanitiser.
	 *
	 * @param string $template Template.
	 */
	private static function strip( string $template ): string {
		$template = (string) preg_replace( [ '/<!--.*?-->/s', '~<(script|style|iframe|object)\b[^>]*>.*?</\1\s*>~is', '/<[!?][^>]*>/' ], '', $template );

		$tag  = '<(?<close>/?)(?<name>[a-zA-Z][a-zA-Z0-9-]*)(?<attr>(?:' . self::CONSTRUCT . '|"(?:' . self::CONSTRUCT . '|[^"])*+"|\'[^\']*+\'|[^<>"\'])*+)>';
		$open = [];
		$out  = preg_replace_callback(
			'~(?<keep>' . self::CONSTRUCT . ')|' . $tag . '~',
			static function ( array $m ) use ( &$open ): string {
				if ( ( $m['keep'] ?? '' ) !== '' ) {
					return $m[0];
				}
				$name = strtolower( $m['name'] );
				if ( ! in_array( $name, self::ALLOWED, true ) ) {
					return '';
				}
				if ( $name === 'br' ) {
					return '<br>';
				}
				if ( $m['close'] !== '' ) {
					// A link that lost its target loses its end tag too.
					return $name !== 'a' || array_pop( $open ) ? '</' . $name . '>' : '';
				}
				if ( $name !== 'a' ) {
					return '<' . $name . '>';
				}
				$href   = self::template_href( self::href( $m['attr'] ) );
				$open[] = $href !== '';
				return $href === '' ? '' : '<a href="' . $href . '">';
			},
			$template
		);
		return $out === null ? wp_strip_all_tags( $template ) : $out;
	}

	/**
	 * Link target of a template: a template construct at the start is checked after rendering.
	 *
	 * @param string $href Decoded attribute.
	 */
	private static function template_href( string $href ): string {
		$href = trim( $href );
		if ( preg_match( '~' . self::CONSTRUCT . '~', $href ) ) {
			$prefix = (string) preg_replace( '~(?:' . self::CONSTRUCT . ').*$~s', '', $href );
			if ( $prefix !== '' && ! preg_match( '~^(https?://|tg://|mailto:|tel:)~i', $prefix ) ) {
				return '';
			}
			return str_replace(
				[ '<', '>', "\n" ],
				[ '%3C', '%3E', '' ],
				preg_replace_callback(
					'~(' . self::CONSTRUCT . ')|"~',
					static function ( array $m ): string {
						return isset( $m[1] ) && $m[1] !== '' ? str_replace( '"', '', $m[1] ) : '%22';
					},
					$href
				)
			);
		}
		return str_replace( '"', '%22', self::url( $href ) );
	}

	/**
	 * Decoded href of an attribute string.
	 *
	 * @param string $attributes Attribute string.
	 */
	private static function href( string $attributes ): string {
		if ( ! preg_match( '~(?:^|\s)href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attributes, $m ) ) {
			return '';
		}
		return html_entity_decode( end( $m ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Empty element.
	 *
	 * @param string $tag Tag name, '' = root.
	 */
	private static function element( string $tag ): array {
		return [
			'type'     => 'el',
			'tag'      => $tag,
			'href'     => '',
			'children' => [],
		];
	}

	/**
	 * Append a node to the innermost element.
	 *
	 * @param array $stack Open elements.
	 * @param array $node  Node.
	 */
	private static function push( array &$stack, array $node ): void {
		$stack[ count( $stack ) - 1 ]['children'][] = $node;
	}

	/**
	 * Append text, joined with a preceding text node.
	 *
	 * @param array  $stack Open elements.
	 * @param string $text  Canonical text.
	 */
	private static function push_text( array &$stack, string $text ): void {
		if ( $text === '' ) {
			return;
		}
		$text = str_replace( [ "\x02", "\x03" ], '', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$top  = count( $stack ) - 1;
		$last = count( $stack[ $top ]['children'] ) - 1;
		if ( $last >= 0 && $stack[ $top ]['children'][ $last ]['type'] === 'text' ) {
			$stack[ $top ]['children'][ $last ]['text'] .= $text;
			return;
		}
		self::push(
			$stack,
			[
				'type' => 'text',
				'text' => $text,
			]
		);
	}

	/**
	 * Close every open element.
	 *
	 * @param array $stack Open elements.
	 */
	private static function close_all( array &$stack ): void {
		for ( $i = count( $stack ) - 1; $i > 0; $i-- ) {
			$node = array_pop( $stack );
			self::push( $stack, $node );
		}
	}

	/**
	 * Close an element and everything opened inside it; a stray end tag is ignored.
	 *
	 * @param array  $stack Open elements.
	 * @param string $tag   Tag name.
	 */
	private static function close( array &$stack, string $tag ): void {
		$found = false;
		for ( $i = count( $stack ) - 1; $i > 0; $i-- ) {
			if ( $stack[ $i ]['tag'] === $tag ) {
				$found = true;
				break;
			}
		}
		while ( $found ) {
			$node = array_pop( $stack );
			self::push( $stack, $node );
			$found = $node['tag'] !== $tag;
		}
	}
}
