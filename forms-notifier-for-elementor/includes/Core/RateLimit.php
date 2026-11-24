<?php
/**
 * Rate limiting.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Fixed-window counters in transients. */
final class RateLimit {

	/**
	 * Count one hit; false when more than $max hits happened in the current window.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Allowed hits per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $bucket, int $max, int $window ): bool {
		$now    = (int) apply_filters( 'fnfe_now', time() );
		$window = max( 1, $window );
		$entry  = self::read( $bucket );

		if ( ! $entry || $entry['until'] <= $now ) {
			$entry = [
				'count' => 0,
				'until' => $now + $window,
			];
		}
		++$entry['count'];
		set_transient( self::key( $bucket ), $entry, max( 1, $entry['until'] - $now ) );

		return $entry['count'] <= $max;
	}

	/**
	 * Hits in the current window.
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function count( string $bucket ): int {
		$entry = self::read( $bucket );
		return $entry && $entry['until'] > (int) apply_filters( 'fnfe_now', time() ) ? $entry['count'] : 0;
	}

	/**
	 * Stored counter.
	 *
	 * @param string $bucket Bucket name.
	 */
	private static function read( string $bucket ): ?array {
		$entry = get_transient( self::key( $bucket ) );
		return is_array( $entry ) && isset( $entry['count'], $entry['until'] ) ? $entry : null;
	}

	/**
	 * Transient name.
	 *
	 * @param string $bucket Bucket name.
	 */
	private static function key( string $bucket ): string {
		return 'fnfe_rl_' . ( preg_match( '/^[a-z0-9_]{1,60}$/', $bucket ) ? $bucket : md5( $bucket ) );
	}
}
