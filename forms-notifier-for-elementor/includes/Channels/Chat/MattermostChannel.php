<?php
/**
 * Mattermost channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Mattermost incoming webhook.
 *
 * @see https://docs.mattermost.com/developers/integrate/webhooks/incoming
 */
final class MattermostChannel extends AbstractWebhookChat {

	/** Characters of one post. */
	protected const LIMIT = 16383;

	/** Channel id. */
	public function id(): string {
		return 'mattermost';
	}

	/** Display name. */
	public function label(): string {
		return 'Mattermost';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post to your Mattermost server through incoming webhooks.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'mattermost';
	}

	/** Sender. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'mattermost_options',
				'title'  => __( 'Options', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field( 'username', 'text', __( 'Sender name', 'forms-notifier-for-elementor' ), [ 'width' => 'half' ] ),
					$this->field(
						'icon_url',
						'url',
						__( 'Sender picture', 'forms-notifier-for-elementor' ),
						[
							'width' => 'half',
							'attrs' => [ 'dir' => 'ltr' ],
						]
					),
				],
			],
		];
	}

	/**
	 * URL check: the server is self-hosted.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	protected function accepts( string $scheme, string $host, string $path ): bool {
		return (bool) preg_match( '~/hooks/[A-Za-z0-9]+/?$~', $path );
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
		$body = [ 'text' => $text ];
		foreach ( [ 'username', 'icon_url' ] as $key ) {
			$value = trim( (string) $this->setting( $key ) );
			if ( $value !== '' ) {
				$body[ $key ] = $value;
			}
		}
		$response = $this->request( $url, $body );
		$json     = is_array( $response['json'] ) ? $response['json'] : [];
		return $this->outcome( $response, is_scalar( $json['message'] ?? null ) ? (string) $json['message'] : '' );
	}
}
