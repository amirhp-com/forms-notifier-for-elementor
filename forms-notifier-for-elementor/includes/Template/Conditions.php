<?php
/**
 * Conditions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** Condition evaluation shared by templates and routing rules. */
final class Conditions {

	public const OPS = [ 'is', 'is_not', 'contains', 'not_contains', 'starts', 'ends', 'empty', 'not_empty', 'gt', 'lt', 'in', 'not_in', 'matches' ];

	/**
	 * One condition [ field, op, value ] against a submission. 'field' may be a field id or "meta:key" / "tag:name[:arg]".
	 *
	 * @param array   $condition Condition.
	 * @param Context $context   Context.
	 */
	public static function test( array $condition, Context $context ): bool {
		$field = $condition['field'] ?? '';
		$op    = $condition['op'] ?? 'is';
		$value = $condition['value'] ?? '';
		if ( ! is_string( $field ) || ! is_string( $op ) ) {
			return false;
		}
		list( $actual, $values ) = self::actual( $field, $context );
		return self::compare( $op, $actual, is_scalar( $value ) ? (string) $value : '', $values );
	}

	/**
	 * Several conditions; an empty list is true.
	 *
	 * @param array   $conditions Conditions.
	 * @param string  $match      all|any.
	 * @param Context $context    Context.
	 */
	public static function test_all( array $conditions, string $match, Context $context ): bool {
		if ( ! $conditions ) {
			return true;
		}
		foreach ( $conditions as $condition ) {
			$ok = is_array( $condition ) && self::test( $condition, $context );
			if ( $match === 'any' && $ok ) {
				return true;
			}
			if ( $match !== 'any' && ! $ok ) {
				return false;
			}
		}
		return $match !== 'any';
	}

	/**
	 * A parsed template condition (see Parser).
	 *
	 * @param array   $alternatives Alternatives of terms.
	 * @param Context $context      Context.
	 */
	public static function template( array $alternatives, Context $context ): bool {
		foreach ( $alternatives as $terms ) {
			$ok = true;
			foreach ( $terms as $term ) {
				$field = $term['name'] === 'field' ? $term['arg'] : 'tag:' . $term['name'] . ( $term['arg'] === '' ? '' : ':' . $term['arg'] );
				$op    = $term['op'] === '' ? 'not_empty' : $term['op'];

				list( $actual, $values ) = self::actual( $field, $context );
				if ( self::compare( $op, $actual, $term['value'], $values ) === $term['neg'] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Value to compare: the display value and its list form.
	 *
	 * @param string  $field   Field id, "meta:key" or "tag:name[:arg]".
	 * @param Context $context Context.
	 * @return array{0:string,1:string[]}
	 */
	private static function actual( string $field, Context $context ): array {
		$known = $context->submission->field( $field );
		if ( $known ) {
			$value  = (string) ( $known['value'] ?? '' );
			$values = array_map( 'strval', array_filter( (array) ( $known['values'] ?? [] ), 'is_scalar' ) );
			return [ $value, $values ? array_values( $values ) : ( $value === '' ? [] : [ $value ] ) ];
		}
		$value = '';
		if ( strncmp( $field, 'meta:', 5 ) === 0 ) {
			$value = self::meta( substr( $field, 5 ), $context );
		} elseif ( strncmp( $field, 'tag:', 4 ) === 0 ) {
			$parts = explode( ':', substr( $field, 4 ), 2 );
			$value = Engine::tag( $parts[0], $parts[1] ?? '', $context );
		}
		return [ $value, $value === '' ? [] : [ $value ] ];
	}

	/**
	 * Submission meta by dot path; lang, status and the form are part of it.
	 *
	 * @param string  $key     Key.
	 * @param Context $context Context.
	 */
	private static function meta( string $key, Context $context ): string {
		$s    = $context->submission;
		$core = [
			'id'        => $s->id,
			'lang'      => $s->lang,
			'status'    => $s->status,
			'source'    => $s->source,
			'form_id'   => $s->form_id,
			'form_key'  => $s->form_key,
			'form_name' => $s->form_name,
			'post_id'   => $s->post_id,
		];
		if ( array_key_exists( $key, $core ) ) {
			return (string) $core[ $key ];
		}
		$node = $s->meta;
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return '';
			}
			$node = $node[ $part ];
		}
		if ( is_bool( $node ) ) {
			return $node ? '1' : '';
		}
		return is_scalar( $node ) ? (string) $node : implode( ', ', array_filter( (array) $node, 'is_scalar' ) );
	}

	/**
	 * Apply an operator.
	 *
	 * @param string   $op       Operator.
	 * @param string   $actual   Display value.
	 * @param string   $expected Expected value.
	 * @param string[] $values   List form of the value.
	 */
	private static function compare( string $op, string $actual, string $expected, array $values ): bool {
		$custom = apply_filters( 'fnfe_condition_ops', [] );
		if ( is_array( $custom ) && isset( $custom[ $op ] ) && is_callable( $custom[ $op ] ) ) {
			try {
				return (bool) call_user_func( $custom[ $op ], $actual, $expected, $values );
			} catch ( \Throwable $e ) {
				Logger::error( 'template', sprintf( 'Condition operator "%s": %s', $op, $e->getMessage() ) );
				return false;
			}
		}

		$left  = self::fold( $actual );
		$right = self::fold( $expected );
		$items = array_map( [ self::class, 'fold' ], $values );
		switch ( $op ) {
			case 'is':
				return $left === $right || in_array( $right, $items, true );
			case 'is_not':
				return ! ( $left === $right || in_array( $right, $items, true ) );
			case 'contains':
				return $right !== '' && strpos( $left, $right ) !== false;
			case 'not_contains':
				return $right === '' || strpos( $left, $right ) === false;
			case 'starts':
				return $right !== '' && strncmp( $left, $right, strlen( $right ) ) === 0;
			case 'ends':
				return $right !== '' && substr( $left, -strlen( $right ) ) === $right;
			case 'empty':
				return $left === '';
			case 'not_empty':
				return $left !== '';
			case 'gt':
			case 'lt':
				$a = self::number( $actual );
				$b = self::number( $expected );
				return $a !== null && $b !== null && ( $op === 'gt' ? $a > $b : $a < $b );
			case 'in':
			case 'not_in':
				$list  = array_filter( array_map( [ self::class, 'fold' ], preg_split( '/[,،]/u', $expected ) ?: [] ), 'strlen' );
				$found = ( $left !== '' && in_array( $left, $list, true ) ) || array_intersect( $items, $list );
				return $op === 'in' ? (bool) $found : ! $found;
			case 'matches':
				return self::matches( $expected, array_merge( [ $actual ], $values ) );
		}
		return false;
	}

	/**
	 * Regular expression given without delimiters; an invalid one is false.
	 *
	 * @param string   $pattern  Pattern.
	 * @param string[] $subjects Values.
	 */
	private static function matches( string $pattern, array $subjects ): bool {
		if ( $pattern === '' || strlen( $pattern ) > 1000 ) {
			return false;
		}
		$regex = '~' . str_replace( '~', '\x7E', $pattern ) . '~u';
		foreach ( array_unique( $subjects ) as $subject ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The pattern is user input; an invalid one is simply false.
			if ( @preg_match( $regex, $subject ) === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Trimmed, lower case, ASCII digits.
	 *
	 * @param string $text Text.
	 */
	private static function fold( string $text ): string {
		return Text::lower( trim( Text::en_digits( $text ) ) );
	}

	/**
	 * Number, null when the text is not numeric.
	 *
	 * @param string $text Text.
	 */
	private static function number( string $text ): ?float {
		$text = str_replace( [ ',', '٬', ' ' ], '', str_replace( '٫', '.', trim( Text::en_digits( $text ) ) ) );
		return is_numeric( $text ) ? (float) $text : null;
	}
}
