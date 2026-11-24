<?php
/**
 * Mutex.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Short database-backed mutex: one row fnfe_lock_{name} in the options table. */
final class Lock {

	/**
	 * Try to take a lock. Locks older than $ttl are taken over.
	 *
	 * The row is written directly (like WP_Upgrader::create_lock) because add_option() is an upsert and therefore
	 * not atomic between two requests.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds.
	 */
	public static function acquire( string $name, int $ttl = 60 ): bool {
		global $wpdb;

		$option = self::option( $name );
		$now    = (int) apply_filters( 'fnfe_now', time() );

		$inserted = $wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )', $wpdb->options, $option, (string) $now, 'no' )
		);
		if ( $inserted ) {
			return true;
		}

		$since = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		if ( $since === null || (int) $since > $now - $ttl ) {
			return false;
		}

		// Compare-and-set so only one request takes an expired lock over.
		$taken = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) $now, $option, (string) $since )
		);
		return (int) $taken === 1;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		global $wpdb;
		$option = self::option( $name );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * Option name of a lock.
	 *
	 * @param string $name Lock name.
	 */
	private static function option( string $name ): string {
		return 'fnfe_lock_' . substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $name ) ), 0, 100 );
	}
}
