<?php
/**
 * Template parser.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a template into nodes. Never throws; problems are collected.
 *
 *   [ 'type' => 'text', 'text' => string ]
 *   [ 'type' => 'tag', 'name' =>, 'arg' =>, 'filters' => [ [ name, arg ] ], 'line' => int ]
 *   [ 'type' => 'if', 'branches' => [ [ 'cond' => condition|null, 'nodes' => nodes ] ], 'line' => int ]   cond null = else
 *
 * A condition is a list of alternatives ("or"); each alternative is a list of terms ("and"):
 *   [ 'neg' => bool, 'name' =>, 'arg' =>, 'op' => '' or an operator of Conditions, 'value' => string ]
 */
final class Parser {

	public const MAX_DEPTH = 5;

	private const ARG = '(?:"[^"\r\n]*"|[^{}|"\r\n])*+';

	private const OPS = [
		'=='        => 'is',
		'!='        => 'is_not',
		'contains'  => 'contains',
		'!contains' => 'not_contains',
		'>'         => 'gt',
		'<'         => 'lt',
		'in'        => 'in',
		'matches'   => 'matches',
	];

	/** @var array<string,array> */
	private static $cache = [];

	/** @var string */
	private $source;

	/** @var array[] */
	private $errors = [];

	/** @var array[] open blocks; the first entry is the root */
	private $stack = [];

	/** @param string $source Template. */
	private function __construct( string $source ) {
		$this->source = $source;
		$this->stack  = [ self::block( null, 1 ) ];
	}

	/**
	 * Parse a template.
	 *
	 * @param string $source Template.
	 * @return array{nodes:array,errors:array}
	 */
	public static function parse( string $source ): array {
		$key = md5( $source );
		if ( ! isset( self::$cache[ $key ] ) ) {
			if ( count( self::$cache ) >= 50 ) {
				self::$cache = [];
			}
			self::$cache[ $key ] = ( new self( $source ) )->run();
		}
		return self::$cache[ $key ];
	}

	/**
	 * Parse a condition.
	 *
	 * @param string $text Text between "{if " and "}".
	 * @return array|null null when it is not valid
	 */
	public static function condition( string $text ): ?array {
		$text    = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$pattern = '~\s*(!?)\s*([a-z0-9_.]+)(?::("[^"]*"|\'[^\']*\'|[^\s"\'=!<>]+))?'
			. '(?:\s*(==|!=|>|<|!contains\b|contains\b|in\b|matches\b)\s*("[^"]*"|\'[^\']*\'|[^\s"\']+))?'
			. '\s*(and\b|or\b|$)~Au';
		$or      = [];
		$and     = [];
		$offset  = 0;
		$length  = strlen( $text );
		if ( $text === '' ) {
			return null;
		}
		do {
			if ( ! preg_match( $pattern, $text, $m, 0, $offset ) ) {
				return null;
			}
			$and[]   = [
				'neg'   => $m[1] === '!',
				'name'  => $m[2],
				'arg'   => self::unquote( $m[3] ?? '' ),
				'op'    => isset( $m[4] ) && $m[4] !== '' ? self::OPS[ $m[4] ] : '',
				'value' => self::unquote( $m[5] ?? '' ),
			];
			$offset += strlen( $m[0] );
			$joiner  = $m[6] ?? '';
			if ( $joiner !== 'and' ) {
				$or[] = $and;
				$and  = [];
			}
		} while ( $joiner !== '' && $offset <= $length );

		return $joiner === '' ? $or : null;
	}

	/** Scan the source. */
	private function run(): array {
		$length = strlen( $this->source );
		$pos    = 0;
		while ( $pos < $length ) {
			$next = $pos + strcspn( $this->source, '{[\\', $pos );
			if ( $next > $pos ) {
				$this->text( substr( $this->source, $pos, $next - $pos ) );
			}
			$pos = $next < $length ? $this->construct( $next ) : $length;
		}

		// A block that is never closed keeps its content; only the markers disappear.
		for ( $left = count( $this->stack ); $left > 1; $left-- ) {
			$block = array_pop( $this->stack );
			$this->error( $block['line'], __( 'This {if} has no {/if}.', 'forms-notifier-for-elementor' ) );
			foreach ( $block['branches'] as $branch ) {
				foreach ( $branch['nodes'] as $node ) {
					$this->node( $node );
				}
			}
		}
		return [
			'nodes'  => $this->stack[0]['branches'][0]['nodes'],
			'errors' => $this->errors,
		];
	}

	/**
	 * Handle one special character; returns the position after it.
	 *
	 * @param int $pos Position of {, [ or \.
	 */
	private function construct( int $pos ): int {
		$char = $this->source[ $pos ];

		if ( $char === '\\' ) {
			if ( substr( $this->source, $pos + 1, 1 ) === '{' ) {
				$this->text( '{' );
				return $pos + 2;
			}
			$this->text( '\\' );
			return $pos + 1;
		}

		if ( $char === '[' ) {
			if ( preg_match( '~\[field\s+id=(?:"([^"\]]*)"|\'([^\'\]]*)\'|([^\s\]"\']+))[^\]\r\n]*\]~A', $this->source, $m, 0, $pos ) ) {
				$this->tag( 'field', trim( end( $m ) ), [], $pos );
				return $pos + strlen( $m[0] );
			}
			if ( preg_match( '~\[all-fields\]~A', $this->source, $m, 0, $pos ) ) {
				$this->tag( 'all_fields', '', [], $pos );
				return $pos + strlen( $m[0] );
			}
			$this->text( '[' );
			return $pos + 1;
		}

		if ( preg_match( '~\{(if|elseif)[ \t]+((?:"[^"\r\n]*"|[^{}"\r\n])*+)\}|\{(else|/if)\s*\}~A', $this->source, $m, 0, $pos ) ) {
			return $this->control( $pos, strlen( $m[0] ), $m[1] === '' ? $m[3] : $m[1], $m[2] );
		}

		$pattern = '~\{([a-z0-9_.]+)(?::(' . self::ARG . '))?((?:\|[a-z0-9_]+(?::' . self::ARG . ')?)*+)\}~A';
		if ( preg_match( $pattern, $this->source, $m, 0, $pos ) && ! in_array( $m[1], [ 'if', 'elseif', 'else' ], true ) ) {
			$this->tag( $m[1], self::unquote( $m[2] ?? '' ), self::filters( $m[3] ?? '' ), $pos );
			return $pos + strlen( $m[0] );
		}

		if ( preg_match( '~\{/?([a-z0-9_.]+)~A', $this->source, $m, 0, $pos ) && ( in_array( $m[1], [ 'if', 'elseif', 'else' ], true ) || Tags::known( $m[1] ) ) ) {
			/* translators: %1$s: beginning of a template tag, for example {field */
			$this->error( $this->line( $pos ), sprintf( __( 'This tag is not complete: %1$s', 'forms-notifier-for-elementor' ), $m[0] ) );
		}
		$this->text( '{' );
		return $pos + 1;
	}

	/**
	 * Handle if, elseif, else, /if; returns the position after it.
	 *
	 * @param int    $pos    Position.
	 * @param int    $length Length of the construct.
	 * @param string $name   if | elseif | else | /if.
	 * @param string $text   Condition.
	 */
	private function control( int $pos, int $length, string $name, string $text ): int {
		$line = $this->line( $pos );
		$end  = $pos + $length;
		$top  = count( $this->stack ) - 1;

		// A construct alone on its line leaves no empty line behind.
		$break  = strrpos( substr( $this->source, 0, $pos ), "\n" );
		$before = substr( $this->source, $break === false ? 0 : $break + 1, $pos - ( $break === false ? 0 : $break + 1 ) );
		if ( trim( $before, " \t" ) === '' && preg_match( '~[ \t]*(\n|$)~AD', $this->source, $m, 0, $end ) ) {
			$this->trim_indent( strlen( $before ) );
			$end += strlen( $m[0] );
		}

		$condition = null;
		if ( $name === 'if' || $name === 'elseif' ) {
			$condition = self::condition( $text );
			if ( $condition === null ) {
				/* translators: %1$s: condition text */
				$this->error( $line, sprintf( __( 'This condition is not valid: %1$s', 'forms-notifier-for-elementor' ), trim( $text ) ) );
				$condition = [];
			}
		}

		if ( $name === 'if' ) {
			if ( $top >= self::MAX_DEPTH ) {
				/* translators: %1$d: number of levels */
				$this->error( $line, sprintf( __( 'Conditions can be nested up to %1$d levels.', 'forms-notifier-for-elementor' ), self::MAX_DEPTH ) );
			}
			$this->stack[] = self::block( $condition, $line );
			return $end;
		}
		if ( $top === 0 ) {
			/* translators: %1$s: template tag, for example {/if} */
			$this->error( $line, sprintf( __( '%1$s without {if}.', 'forms-notifier-for-elementor' ), '{' . $name . '}' ) );
			return $end;
		}
		if ( $name === '/if' ) {
			$block = array_pop( $this->stack );
			if ( count( $this->stack ) <= self::MAX_DEPTH ) {
				$this->node(
					[
						'type'     => 'if',
						'branches' => $block['branches'],
						'line'     => $block['line'],
					]
				);
			}
			return $end;
		}
		if ( $this->stack[ $top ]['else'] ) {
			$this->error( $line, __( 'There is already an {else} in this block.', 'forms-notifier-for-elementor' ) );
			$condition = [];
		}
		$this->stack[ $top ]['else']       = $this->stack[ $top ]['else'] || $name === 'else';
		$this->stack[ $top ]['branches'][] = [
			'cond'  => $condition,
			'nodes' => [],
		];
		return $end;
	}

	/**
	 * Add a tag.
	 *
	 * @param string $name    Name.
	 * @param string $arg     Argument.
	 * @param array  $filters Filters.
	 * @param int    $pos     Position.
	 */
	private function tag( string $name, string $arg, array $filters, int $pos ): void {
		$this->node(
			[
				'type'    => 'tag',
				'name'    => $name,
				'arg'     => $arg,
				'filters' => $filters,
				'line'    => $this->line( $pos ),
			]
		);
	}

	/**
	 * Add literal text.
	 *
	 * @param string $text Text.
	 */
	private function text( string $text ): void {
		$nodes = &$this->current();
		$last  = count( $nodes ) - 1;
		if ( $last >= 0 && $nodes[ $last ]['type'] === 'text' ) {
			$nodes[ $last ]['text'] .= $text;
			return;
		}
		$nodes[] = [
			'type' => 'text',
			'text' => $text,
		];
	}

	/**
	 * Add a node.
	 *
	 * @param array $node Node.
	 */
	private function node( array $node ): void {
		if ( $node['type'] === 'text' ) {
			$this->text( $node['text'] );
			return;
		}
		$nodes   = &$this->current();
		$nodes[] = $node;
	}

	/** Node list that is being filled. */
	private function &current(): array {
		$top    = count( $this->stack ) - 1;
		$branch = count( $this->stack[ $top ]['branches'] ) - 1;
		return $this->stack[ $top ]['branches'][ $branch ]['nodes'];
	}

	/**
	 * Remove trailing spaces from the last literal.
	 *
	 * @param int $count Number of characters.
	 */
	private function trim_indent( int $count ): void {
		$nodes = &$this->current();
		$last  = count( $nodes ) - 1;
		if ( $count > 0 && $last >= 0 && $nodes[ $last ]['type'] === 'text' ) {
			$nodes[ $last ]['text'] = substr( $nodes[ $last ]['text'], 0, -$count );
		}
	}

	/**
	 * Line of a position.
	 *
	 * @param int $pos Position.
	 */
	private function line( int $pos ): int {
		return substr_count( $this->source, "\n", 0, $pos ) + 1;
	}

	/**
	 * Remember a problem.
	 *
	 * @param int    $line    Line.
	 * @param string $message Message.
	 */
	private function error( int $line, string $message ): void {
		$this->errors[] = [
			'line'    => $line,
			'message' => $message,
		];
	}

	/**
	 * Filter chain "|name:arg|name".
	 *
	 * @param string $chain Chain.
	 * @return array[] [ name, arg ]
	 */
	private static function filters( string $chain ): array {
		$out = [];
		if ( $chain !== '' && preg_match_all( '~\|([a-z0-9_]+)(?::(' . self::ARG . '))?~', $chain, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $filter ) {
				$out[] = [ $filter[1], self::unquote( $filter[2] ?? '' ) ];
			}
		}
		return $out;
	}

	/**
	 * Remove the quotes around an argument.
	 *
	 * @param string $text Argument.
	 */
	private static function unquote( string $text ): string {
		return preg_match( '~^\s*("([^"]*)"|\'([^\']*)\')\s*$~', $text, $m ) ? end( $m ) : $text;
	}

	/**
	 * New block.
	 *
	 * @param array|null $condition Condition of the first branch.
	 * @param int        $line      Line.
	 */
	private static function block( ?array $condition, int $line ): array {
		return [
			'line'     => $line,
			'else'     => false,
			'branches' => [
				[
					'cond'  => $condition,
					'nodes' => [],
				],
			],
		];
	}
}
