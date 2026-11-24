<?php
/**
 * Retry delays.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Seconds to wait before the next attempt. */
final class Backoff {

	/** Longest wait a remote service can ask for. */
	private const MAX_RETRY_AFTER = HOUR_IN_SECONDS;

	/**
	 * Delay after a failed attempt. Filter: fnfe_retry_delay.
	 *
	 * @param int        $attempt Attempts made so far, from 1.
	 * @param array      $row     Message row.
	 * @param SendResult $result  Result of the failed attempt.
	 */
	public static function delay( int $attempt, array $row, SendResult $result ): int {
		if ( $result->retry_after > 0 ) {
			$delay = min( self::MAX_RETRY_AFTER, $result->retry_after );
		} else {
			$base  = self::base( Settings::arr( 'queue.backoff' ), $attempt );
			$delay = $base + random_int( 0, (int) floor( $base / 10 ) );
		}
		return max( 0, (int) apply_filters( 'fnfe_retry_delay', $delay, $attempt, $row, $result ) );
	}

	/**
	 * Configured delay without jitter; the last value repeats.
	 *
	 * @param array $steps   Seconds per attempt.
	 * @param int   $attempt Attempts made so far, from 1.
	 */
	public static function base( array $steps, int $attempt ): int {
		$steps = array_values( array_filter( array_map( 'intval', array_filter( $steps, 'is_numeric' ) ) ) );
		if ( ! $steps ) {
			return MINUTE_IN_SECONDS;
		}
		return max( 1, $steps[ max( 0, min( count( $steps ), $attempt ) - 1 ) ] );
	}
}
