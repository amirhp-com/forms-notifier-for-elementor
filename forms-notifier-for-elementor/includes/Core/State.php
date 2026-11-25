<?php
/**
 * Runtime state.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Small runtime values that are not settings (option fnfe_state, not autoloaded). Keys may be dot paths. */
final class State {

	public const OPTION = 'fnfe_state';

	/** Seconds after which a lock of a request that died is taken over. */
	private const LOCK_TTL = 5;

	/** Seconds to wait for the lock. */
	private const WAIT = 2.0;

	/** @var bool this request is inside a step */
	private static $locked = false;

	/**
	 * Read a value.
	 *
	 * @param string $key     Key or dot path.
	 * @param mixed  $default Returned when unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$node = self::read();
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Write a value.
	 *
	 * @param string $key   Key or dot path.
	 * @param mixed  $value JSON-safe value.
	 */
	public static function set( string $key, $value ): void {
		self::update(
			$key,
			static function () use ( $value ) {
				return $value;
			}
		);
	}

	/**
	 * Change a value that depends on the stored one, without losing what other requests write meanwhile.
	 *
	 * @param string   $key     Key or dot path.
	 * @param callable $change  Gets the stored value, returns the new one. Keep it short: a lock is held.
	 * @param mixed    $default Given to the callback when the key is unknown.
	 * @return mixed the new value
	 */
	public static function update( string $key, callable $change, $default = null ) {
		return self::locked(
			static function () use ( $key, $change, $default ) {
				$state = self::fresh();
				$node  = &$state;
				$known = true;
				foreach ( explode( '.', $key ) as $part ) {
					if ( ! is_array( $node ) ) {
						$node = [];
					}
					if ( ! array_key_exists( $part, $node ) ) {
						$node[ $part ] = [];
						$known         = false;
					}
					$node = &$node[ $part ];
				}
				$value = $change( $known ? $node : $default );
				$node  = $value;
				unset( $node );
				update_option( self::OPTION, $state, false );
				return $value;
			}
		);
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Key or dot path.
	 */
	public static function delete( string $key ): void {
		self::locked(
			static function () use ( $key ): void {
				$state = self::fresh();
				$parts = explode( '.', $key );
				$last  = array_pop( $parts );
				$node  = &$state;
				foreach ( $parts as $part ) {
					if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
						return;
					}
					$node = &$node[ $part ];
				}
				if ( ! array_key_exists( $last, $node ) ) {
					return;
				}
				unset( $node[ $last ] );
				unset( $node );
				update_option( self::OPTION, $state, false );
			}
		);
	}

	/**
	 * Run a read-modify-write step alone. After two seconds without the lock it runs anyway.
	 *
	 * @param callable $step Step.
	 * @return mixed result of the step
	 */
	private static function locked( callable $step ) {
		if ( self::$locked ) {
			return $step();
		}
		$until = microtime( true ) + self::WAIT;
		do {
			self::$locked = Lock::acquire( 'state', self::LOCK_TTL );
			if ( self::$locked ) {
				break;
			}
			usleep( random_int( 5000, 25000 ) );
		} while ( microtime( true ) < $until );

		$owned        = self::$locked;
		self::$locked = true;
		try {
			return $step();
		} finally {
			self::$locked = false;
			if ( $owned ) {
				Lock::release( 'state' );
			}
		}
	}

	/** Stored array, past the cache of this request: another request may have written since. */
	private static function fresh(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return self::read();
	}

	/** Stored array. */
	private static function read(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? $state : [];
	}
}
