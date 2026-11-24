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
		$state = self::read();
		$node  = &$state;
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $node ) ) {
				$node = [];
			}
			if ( ! array_key_exists( $part, $node ) ) {
				$node[ $part ] = [];
			}
			$node = &$node[ $part ];
		}
		$node = $value;
		unset( $node );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Key or dot path.
	 */
	public static function delete( string $key ): void {
		$state = self::read();
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

	/** Stored array. */
	private static function read(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? $state : [];
	}
}
