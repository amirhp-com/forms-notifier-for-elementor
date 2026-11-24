<?php
/**
 * Quiet hours.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\FormConfig;

defined( 'ABSPATH' ) || exit;

/** Delay window for messages to the site's own receivers. */
final class QuietHours {

	/**
	 * Seconds a new admin message of a channel has to wait, 0 = send now.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 */
	public static function delay( string $channel, FormConfig $form ): int {
		$quiet = Settings::arr( 'general.quiet_hours' );
		if ( empty( $quiet['enabled'] ) || $form->get( 'quiet_hours' ) === 'off' || ! in_array( $channel, (array) ( $quiet['channels'] ?? [] ), true ) ) {
			return 0;
		}
		$now = (int) apply_filters( 'fnfe_now', time() );
		$end = self::ends_at( $now, (string) ( $quiet['from'] ?? '' ), (string) ( $quiet['to'] ?? '' ), wp_timezone() );
		return $end > $now ? $end - $now : 0;
	}

	/**
	 * Timestamp at which the running window ends, 0 outside of the window.
	 *
	 * @param int           $now  Timestamp.
	 * @param string        $from HH:MM.
	 * @param string        $to   HH:MM; earlier than $from = the window crosses midnight.
	 * @param \DateTimeZone $zone Timezone of both times.
	 */
	public static function ends_at( int $now, string $from, string $to, \DateTimeZone $zone ): int {
		$start = self::minutes( $from );
		$stop  = self::minutes( $to );
		if ( $start === null || $stop === null || $start === $stop ) {
			return 0;
		}
		$local   = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		$minute  = 60 * (int) $local->format( 'G' ) + (int) $local->format( 'i' );
		$crosses = $start > $stop;
		$inside  = $crosses ? ( $minute >= $start || $minute < $stop ) : ( $minute >= $start && $minute < $stop );
		if ( ! $inside ) {
			return 0;
		}
		$day = $crosses && $minute >= $start ? $local->modify( '+1 day' ) : $local;
		$end = $day->setTime( intdiv( $stop, 60 ), $stop % 60 )->getTimestamp();
		// Clock changes can put the end behind the present.
		return $end > $now ? $end : 0;
	}

	/**
	 * Minutes since midnight, null when malformed.
	 *
	 * @param string $time HH:MM.
	 */
	private static function minutes( string $time ): ?int {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
			return null;
		}
		return 60 * (int) $m[1] + (int) $m[2];
	}
}
