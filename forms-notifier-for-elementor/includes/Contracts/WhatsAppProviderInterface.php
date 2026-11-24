<?php
/**
 * WhatsApp gateway contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

interface WhatsAppProviderInterface extends ProviderInterface {

	/** True when the provider can send approved templates (Message::$pattern). */
	public function supports_template(): bool;

	/** True when it can upload/send media. */
	public function supports_media(): bool;

	/**
	 * Send one message.
	 *
	 * @param string  $to      E.164.
	 * @param Message $message Body in $message->body (format 'whatsapp'), files in $message->attachments / links.
	 */
	public function send( string $to, Message $message ): SendResult;
}
