<?php
/**
 * SMS gateway contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

interface SmsProviderInterface extends ProviderInterface {

	/** True when the gateway can send by pattern/template code. */
	public function supports_pattern(): bool;

	/** ISO2 country codes this gateway is known to deliver to, [] = worldwide/unknown. */
	public function countries(): array;

	/**
	 * Send one SMS.
	 *
	 * @param string  $to      E.164, e.g. +989121234567.
	 * @param Message $message $message->pattern is [] for normal text (use $message->plain), or [code, vars, lang] for a pattern.
	 */
	public function send( string $to, Message $message ): SendResult;
}
