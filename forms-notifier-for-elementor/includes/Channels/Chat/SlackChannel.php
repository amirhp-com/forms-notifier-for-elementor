<?php
/**
 * Slack channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;

defined( 'ABSPATH' ) || exit;

/**
 * Slack incoming webhook.
 *
 * @see https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/
 */
final class SlackChannel extends AbstractWebhookChat {

	/** Text of a whole message. */
	protected const LIMIT = 12000;

	/** Characters of one section block. */
	private const SECTION = 3000;

	/** Channel id. */
	public function id(): string {
		return 'slack';
	}

	/** Display name. */
	public function label(): string {
		return 'Slack';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post to Slack channels through incoming webhooks.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'slack';
	}

	/** Canonical format. */
	public function format(): string {
		return 'slack';
	}

	/** Buttons are blocks. */
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
		return $scheme === 'https' && in_array( $host, [ 'hooks.slack.com', 'hooks.slack-gov.com' ], true ) && strlen( $path ) > 1;
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
		$blocks = [];
		foreach ( Registry::formatter( 'slack' )->split( $text, self::SECTION ) as $section ) {
			$blocks[] = [
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => $section,
				],
			];
		}
		$elements = [];
		foreach ( $last ? Extras::url_buttons( $message, 25 ) : [] as $i => $button ) {
			if ( strlen( $button['value'] ) <= 3000 ) {
				$elements[] = [
					'type'      => 'button',
					'text'      => [
						'type' => 'plain_text',
						'text' => Text::truncate( $button['text'], 75 ),
					],
					'url'       => $button['value'],
					'action_id' => 'fnfe_' . $i,
				];
			}
		}
		foreach ( array_chunk( $elements, 5 ) as $chunk ) {
			$blocks[] = [
				'type'     => 'actions',
				'elements' => $chunk,
			];
		}

		$response = $this->request(
			$url,
			[
				'text'         => $text,
				'blocks'       => $blocks,
				'unfurl_links' => false,
			]
		);
		// Errors are short plain texts such as no_service or invalid_payload.
		return $this->outcome( $response, $response['ok'] ? '' : (string) $response['body'] );
	}
}
