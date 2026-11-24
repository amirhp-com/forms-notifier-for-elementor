<?php
/**
 * Routing rules.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Template\Conditions;
use AmirhpCom\FormsNotifier\Template\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Conditional routing rules of a form. They change the routing set:
 *   channel id => [ 'on' => bool, 'implicit' => bool (no receiver list), 'receivers' => Receiver[] ]
 */
final class Rules {

	/**
	 * Apply rules in order.
	 *
	 * @param array   $set     Routing set.
	 * @param array   $rules   Rules of the form.
	 * @param Context $context Context the conditions are tested against.
	 * @return array|null changed set, null = nothing may be sent
	 */
	public static function apply( array $set, array $rules, Context $context ): ?array {
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! self::matches( $rule, $context ) ) {
				continue;
			}
			switch ( $rule['effect'] ?? '' ) {
				case 'skip':
					return null;

				case 'only':
					$set = self::only( $set, $rule );
					break;

				case 'add':
					$set = self::add( $set, $rule );
					break;

				case 'replace':
					$set = self::replace( $set, $rule );
					break;
			}
			if ( ! empty( $rule['stop'] ) ) {
				break;
			}
		}
		return $set;
	}

	/**
	 * The rule is on and its conditions hold.
	 *
	 * @param array   $rule    Rule.
	 * @param Context $context Context.
	 */
	public static function matches( array $rule, Context $context ): bool {
		if ( empty( $rule['enabled'] ) ) {
			return false;
		}
		try {
			return Conditions::test_all( (array) ( $rule['conditions'] ?? [] ), (string) ( $rule['match'] ?? 'all' ), $context );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Keep only the listed channels.
	 *
	 * @param array $set  Routing set.
	 * @param array $rule Rule.
	 */
	private static function only( array $set, array $rule ): array {
		$keep = (array) ( $rule['channels'] ?? [] );
		foreach ( $set as $channel => $entry ) {
			if ( ! in_array( (string) $channel, $keep, true ) ) {
				$set[ $channel ]['on'] = false;
			}
		}
		return $set;
	}

	/**
	 * Add receivers and switch their channels on. A channel that was off gets only the receivers of the rule, or its
	 * usual ones when the rule names none.
	 *
	 * @param array $set  Routing set.
	 * @param array $rule Rule.
	 */
	private static function add( array $set, array $rule ): array {
		foreach ( self::channels( $rule ) as $channel ) {
			if ( ! isset( $set[ $channel ] ) ) {
				continue;
			}
			$added = empty( $set[ $channel ]['implicit'] ) ? self::receivers( $rule, $channel ) : [];
			if ( $added ) {
				$set[ $channel ]['receivers'] = array_merge( $set[ $channel ]['on'] ? $set[ $channel ]['receivers'] : [], $added );
			}
			$set[ $channel ]['on'] = true;
		}
		return $set;
	}

	/**
	 * Replace the receivers of the listed channels.
	 *
	 * @param array $set  Routing set.
	 * @param array $rule Rule.
	 */
	private static function replace( array $set, array $rule ): array {
		foreach ( self::channels( $rule ) as $channel ) {
			if ( isset( $set[ $channel ] ) && empty( $set[ $channel ]['implicit'] ) ) {
				$set[ $channel ]['receivers'] = self::receivers( $rule, $channel );
			}
		}
		return $set;
	}

	/**
	 * Channels a rule names, in its list or by its receivers.
	 *
	 * @param array $rule Rule.
	 * @return string[]
	 */
	private static function channels( array $rule ): array {
		$listed = (array) ( $rule['channels'] ?? [] );
		return array_map( 'strval', $listed ? $listed : array_keys( (array) ( $rule['receivers'] ?? [] ) ) );
	}

	/**
	 * Enabled receivers of a rule for one channel, marked with the rule id.
	 *
	 * @param array  $rule    Rule.
	 * @param string $channel Channel id.
	 */
	private static function receivers( array $rule, string $channel ): array {
		$out = [];
		foreach ( (array) ( $rule['receivers'][ $channel ] ?? [] ) as $receiver ) {
			if ( is_array( $receiver ) && ! empty( $receiver['enabled'] ) ) {
				$receiver['rule_id'] = (string) ( $rule['id'] ?? '' );
				$out[]               = $receiver;
			}
		}
		return $out;
	}
}
