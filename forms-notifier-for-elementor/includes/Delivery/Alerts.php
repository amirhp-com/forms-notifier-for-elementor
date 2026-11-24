<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Failure alerts: nothing is sent yet. */
final class Alerts {

	/**
	 * Called when a row fails.
	 *
	 * @param array      $message_row Row.
	 * @param SendResult $result      Result.
	 * @param bool       $final       No more attempts.
	 */
	public static function on_failure( array $message_row, SendResult $result, bool $final ): void {
		unset( $message_row, $result, $final );
	}

	/**
	 * Send a custom alert text through the alert channels.
	 *
	 * @param string $title   Title.
	 * @param string $text    Text.
	 * @param array  $context Extra data.
	 */
	public static function send( string $title, string $text, array $context = [] ): void {
		unset( $title, $text, $context );
	}
}
