<?php
/**
 * Google Chat channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;

defined( 'ABSPATH' ) || exit;

/**
 * Google Chat incoming webhook. Its text marks (*bold*, _italic_, ~strike~, code) are the ones of the whatsapp format.
 *
 * @see https://developers.google.com/workspace/chat/quickstart/webhooks
 */
final class GoogleChatChannel extends AbstractWebhookChat {

	/** Characters of one message. */
	protected const LIMIT = 4096;

	/** Channel id. */
	public function id(): string {
		return 'googlechat';
	}

	/** Display name. */
	public function label(): string {
		return 'Google Chat';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post to Google Chat spaces through incoming webhooks.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'chat';
	}

	/** Canonical format. */
	public function format(): string {
		return 'whatsapp';
	}

	/** Buttons are a card. */
	protected function buttons_as_text(): bool {
		return false;
	}

	/**
	 * URL check.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	protected function accepts( string $scheme, string $host, string $path ): bool {
		return $scheme === 'https' && $host === 'chat.googleapis.com' && preg_match( '~^/v1/spaces/[^/]+/messages$~', $path );
	}

	/**
	 * Post one part.
	 *
	 * @param string  $url     Webhook URL.
	 * @param string  $text    Text.
	 * @param Message $message Message.
	 * @param bool    $last    Last part.
	 */
	protected function post( string $url, string $text, Message $message, bool $last ): SendResult {
		// "<users/all>" would notify the whole space, "<https://...|text>" would hide an address.
		$body    = [ 'text' => (string) preg_replace( '~<(?=[a-z]+[:/])~i', "<\u{200B}", $text ) ];
		$buttons = [];
		foreach ( $last ? Extras::url_buttons( $message, 20 ) : [] as $button ) {
			$buttons[] = [
				'text'    => $button['text'],
				'onClick' => [ 'openLink' => [ 'url' => $button['value'] ] ],
			];
		}
		if ( $buttons ) {
			$body['cardsV2'] = [
				[
					'cardId' => 'fnfe',
					'card'   => [
						'sections' => [
							[
								'widgets' => [
									[ 'buttonList' => [ 'buttons' => $buttons ] ],
								],
							],
						],
					],
				],
			];
		}

		$response = $this->request( $url, $body );
		$json     = is_array( $response['json'] ) ? $response['json'] : [];
		$result   = $this->outcome( $response, is_scalar( $json['error']['message'] ?? null ) ? (string) $json['error']['message'] : '' );
		if ( $result->ok && is_scalar( $json['name'] ?? null ) ) {
			$result->remote_id = (string) $json['name'];
		}
		return $result;
	}
}
