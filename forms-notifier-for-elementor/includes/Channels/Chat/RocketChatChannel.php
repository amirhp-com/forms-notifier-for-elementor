<?php
/**
 * Rocket.Chat channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Rocket.Chat incoming webhook.
 *
 * @see https://docs.rocket.chat/docs/integrations
 */
final class RocketChatChannel extends AbstractWebhookChat {

	/** Characters of one message (default of the server). */
	protected const LIMIT = 5000;

	/** Channel id. */
	public function id(): string {
		return 'rocketchat';
	}

	/** Display name. */
	public function label(): string {
		return 'Rocket.Chat';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post to your Rocket.Chat server through incoming webhooks.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'rocket';
	}

	/**
	 * URL check: the server is self-hosted.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	protected function accepts( string $scheme, string $host, string $path ): bool {
		return (bool) preg_match( '~/hooks/[A-Za-z0-9]+/[A-Za-z0-9]+/?$~', $path );
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
		$response = $this->request( $url, [ 'text' => $text ] );
		$json     = is_array( $response['json'] ) ? $response['json'] : [];
		$error    = is_scalar( $json['error'] ?? null ) ? (string) $json['error'] : '';
		if ( $response['ok'] && array_key_exists( 'success', $json ) && $json['success'] === false ) {
			return $this->fail( $error === '' ? __( 'The service refused the message.', 'forms-notifier-for-elementor' ) : $error, true, (string) $response['body'], (int) $response['code'] );
		}
		return $this->outcome( $response, $error );
	}
}
