<?php
/**
 * Assertions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

/** Thrown by a failed assertion. */
final class AssertionFailed extends \Exception {}

/** Assertion methods; every call is counted. */
trait Assert {

	/** @var int */
	public static $assertions = 0;

	/** Fail. */
	protected function fail( string $message ): void {
		throw new AssertionFailed( $message );
	}

	protected function assertTrue( $actual, string $message = '' ): void {
		$this->check( $actual === true, 'expected true', $actual, $message );
	}

	protected function assertFalse( $actual, string $message = '' ): void {
		$this->check( $actual === false, 'expected false', $actual, $message );
	}

	protected function assertNull( $actual, string $message = '' ): void {
		$this->check( $actual === null, 'expected null', $actual, $message );
	}

	protected function assertNotNull( $actual, string $message = '' ): void {
		$this->check( $actual !== null, 'expected a value', $actual, $message );
	}

	protected function assertSame( $expected, $actual, string $message = '' ): void {
		$this->check( $expected === $actual, 'expected ' . self::export( $expected ), $actual, $message );
	}

	protected function assertNotSame( $unexpected, $actual, string $message = '' ): void {
		$this->check( $unexpected !== $actual, 'expected something else than ' . self::export( $unexpected ), $actual, $message );
	}

	protected function assertEquals( $expected, $actual, string $message = '' ): void {
		$this->check( $expected == $actual, 'expected ' . self::export( $expected ), $actual, $message ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	protected function assertContains( $needle, $haystack, string $message = '' ): void {
		$found = is_array( $haystack ) ? in_array( $needle, $haystack, true ) : ( (string) $needle !== '' && strpos( (string) $haystack, (string) $needle ) !== false );
		$this->check( $found, 'expected to contain ' . self::export( $needle ), $haystack, $message );
	}

	protected function assertNotContains( $needle, $haystack, string $message = '' ): void {
		$found = is_array( $haystack ) ? in_array( $needle, $haystack, true ) : ( (string) $needle !== '' && strpos( (string) $haystack, (string) $needle ) !== false );
		$this->check( ! $found, 'expected not to contain ' . self::export( $needle ), $haystack, $message );
	}

	protected function assertCount( int $expected, $actual, string $message = '' ): void {
		$this->check( is_countable( $actual ) && count( $actual ) === $expected, 'expected ' . $expected . ' items', $actual, $message );
	}

	protected function assertArrayHasKey( $key, $actual, string $message = '' ): void {
		$this->check( is_array( $actual ) && array_key_exists( $key, $actual ), 'expected key ' . self::export( $key ), is_array( $actual ) ? array_keys( $actual ) : $actual, $message );
	}

	/** The array has exactly these keys, in any order. */
	protected function assertKeys( array $expected, $actual, string $message = '' ): void {
		$keys = is_array( $actual ) ? array_keys( $actual ) : [];
		sort( $expected );
		sort( $keys );
		$this->check( $expected === $keys, 'expected keys ' . self::export( $expected ), $keys, $message );
	}

	protected function assertMatches( string $pattern, $actual, string $message = '' ): void {
		$this->check( is_scalar( $actual ) && preg_match( $pattern, (string) $actual ) === 1, 'expected to match ' . $pattern, $actual, $message );
	}

	protected function assertInstanceOf( string $class_name, $actual, string $message = '' ): void {
		$this->check( $actual instanceof $class_name, 'expected instance of ' . $class_name, is_object( $actual ) ? get_class( $actual ) : $actual, $message );
	}

	/** The callback throws the given class. */
	protected function assertThrows( string $class_name, callable $callback, string $message = '' ): void {
		$thrown = null;
		try {
			$callback();
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}
		$this->check( $thrown instanceof $class_name, 'expected exception ' . $class_name, $thrown ? get_class( $thrown ) . ': ' . $thrown->getMessage() : 'none', $message );
	}

	private function check( bool $ok, string $expectation, $actual, string $message ): void {
		++self::$assertions;
		if ( ! $ok ) {
			throw new AssertionFailed( ( $message === '' ? '' : $message . ': ' ) . $expectation . ', actual ' . self::export( $actual ) );
		}
	}

	private static function export( $value ): string {
		$text = is_string( $value ) || is_array( $value ) || is_object( $value )
			? (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) // phpcs:ignore WordPress.WP.AlternativeFunctions
			: var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return mb_strlen( $text ) > 600 ? mb_substr( $text, 0, 600 ) . '...' : $text;
	}
}
