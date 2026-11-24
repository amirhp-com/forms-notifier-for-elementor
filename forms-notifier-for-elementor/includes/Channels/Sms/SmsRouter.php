<?php
/**
 * SMS routing.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Country -> ordered gateways. */
final class SmsRouter {

	/**
	 * Ordered provider ids to try for a number: matching route's provider then its fallbacks. Filter: fnfe_sms_route.
	 *
	 * @param string       $e164    Number.
	 * @param Message|null $message Message, for the filter.
	 * @return string[]
	 */
	public static function chain( string $e164, ?Message $message = null ): array {
		$country = Phone::country( $e164 );
		$ids     = null;
		foreach ( Settings::arr( 'channels.sms.routes' ) as $route ) {
			if ( is_array( $route ) && $country !== '' && in_array( $country, (array) ( $route['countries'] ?? [] ), true ) ) {
				$ids = array_merge( [ $route['provider'] ?? '' ], (array) ( $route['fallbacks'] ?? [] ) );
				break;
			}
		}
		if ( $ids === null ) {
			$ids = array_merge( [ Settings::str( 'channels.sms.default_provider' ) ], Settings::arr( 'channels.sms.default_fallbacks' ) );
		}

		$message = $message ?? new Message( 'sms', new Recipient( 'sms', $e164 ) );
		$known   = Registry::providers( 'sms' );
		$out     = [];
		foreach ( (array) apply_filters( 'fnfe_sms_route', self::known( $ids, $known ), $e164, $country, $message ) as $id ) {
			$out[] = $id;
		}
		return self::known( $out, $known );
	}

	/**
	 * Every provider id that any route may use.
	 *
	 * @return string[]
	 */
	public static function used(): array {
		$ids = array_merge( [ Settings::str( 'channels.sms.default_provider' ) ], Settings::arr( 'channels.sms.default_fallbacks' ) );
		foreach ( Settings::arr( 'channels.sms.routes' ) as $route ) {
			if ( is_array( $route ) ) {
				$ids = array_merge( $ids, [ $route['provider'] ?? '' ], (array) ( $route['fallbacks'] ?? [] ) );
			}
		}
		return self::known( $ids, Registry::providers( 'sms' ) );
	}

	/**
	 * Without empty, repeated and unknown ids.
	 *
	 * @param array $ids   Provider ids.
	 * @param array $known Registered providers.
	 * @return string[]
	 */
	private static function known( array $ids, array $known ): array {
		return array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( $id ) use ( $known ): bool {
						return is_string( $id ) && isset( $known[ $id ] );
					}
				)
			)
		);
	}
}
