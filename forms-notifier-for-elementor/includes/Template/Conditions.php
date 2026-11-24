<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Condition evaluation: 'is', 'is_not', 'empty', 'not_empty', 'contains' on fields only. */
final class Conditions {

	public const OPS = [ 'is', 'is_not', 'contains', 'not_contains', 'starts', 'ends', 'empty', 'not_empty', 'gt', 'lt', 'in', 'not_in', 'matches' ];

	/**
	 * One condition [ field, op, value ] against a submission.
	 *
	 * @param array   $condition Condition.
	 * @param Context $context   Context.
	 */
	public static function test( array $condition, Context $context ): bool {
		$actual   = mb_strtolower( trim( $context->submission->value( (string) ( $condition['field'] ?? '' ) ) ) );
		$expected = mb_strtolower( trim( (string) ( $condition['value'] ?? '' ) ) );
		switch ( $condition['op'] ?? 'is' ) {
			case 'is':
				return $actual === $expected;
			case 'is_not':
				return $actual !== $expected;
			case 'contains':
				return $expected !== '' && strpos( $actual, $expected ) !== false;
			case 'empty':
				return $actual === '';
			case 'not_empty':
				return $actual !== '';
		}
		return false;
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
}
