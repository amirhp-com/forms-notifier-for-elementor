<?php
/**
 * Gotify channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Push;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;

defined( 'ABSPATH' ) || exit;

/**
 * Gotify: one server, one application, one implicit receiver.
 *
 * @see https://gotify.net/docs/pushmsg
 */
final class GotifyChannel extends AbstractChannel {

	/** Channel id. */
	public function id(): string {
		return 'gotify';
	}

	/** Display name. */
	public function label(): string {
		return 'Gotify';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Push messages through your own Gotify server.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'push';
	}

	/** Icon. */
	public function icon(): string {
		return 'bell';
	}

	/** Canonical format. */
	public function format(): string {
		return 'markdown';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receivers' => 'none',
				'buttons'   => [ 'url' ],
				'subject'   => true,
			]
		);
	}

	/** Server and token. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'gotify_connection',
				'title'  => __( 'Connection', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field(
						'server',
						'url',
						__( 'Server', 'forms-notifier-for-elementor' ),
						[
							'placeholder' => 'https://gotify.example.com',
							'attrs'       => [ 'dir' => 'ltr' ],
						]
					),
					$this->field( 'app_token', 'secret', __( 'Application token', 'forms-notifier-for-elementor' ) ),
					$this->field(
						'priority',
						'number',
						__( 'Priority', 'forms-notifier-for-elementor' ),
						[
							'default' => 5,
							'attrs'   => [
								'min' => 0,
								'max' => 10,
							],
							'width'   => 'half',
						]
					),
				],
			],
		];
	}

	/** Problems. */
	public function problems(): array {
		$problems = [];
		if ( $this->server() === '' ) {
			$problems[] = __( 'Enter the address of your server.', 'forms-notifier-for-elementor' );
		}
		if ( $this->secret( 'app_token' ) === '' ) {
			$problems[] = __( 'Enter the application token.', 'forms-notifier-for-elementor' );
		}
		return $problems;
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			$problems = $this->problems();
			if ( $problems ) {
				return $this->fail( implode( ' ', $problems ), true );
			}
			$text = Extras::compose( $message, true );
			if ( trim( $text ) === '' ) {
				return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
			}
			$extras  = [ 'client::display' => [ 'contentType' => 'text/markdown' ] ];
			$buttons = Extras::url_buttons( $message, 1 );
			if ( $buttons ) {
				$extras['client::notification'] = [ 'click' => [ 'url' => $buttons[0]['value'] ] ];
			}
			$token            = $this->secret( 'app_token' );
			$response         = Http::request(
				'POST',
				$this->server() . '/message',
				[
					'json'        => [
						'title'    => $message->subject === '' ? wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) : $message->subject,
						'message'  => $text,
						'priority' => max( 0, min( 10, (int) $this->setting( 'priority', 5 ) ) ),
						'extras'   => $extras,
					],
					'headers'     => [ 'X-Gotify-Key' => $token ],
					'redirection' => 0,
					'scrub'       => [ $token ],
				]
			);
			$json             = is_array( $response['json'] ) ? $response['json'] : [];
			$response['body'] = mb_substr( (string) $response['body'], 0, 500 );
			$result           = $this->from_http( $response );
			if ( $result->ok ) {
				$result->remote_id = is_scalar( $json['id'] ?? null ) ? (string) $json['id'] : '';
			} elseif ( is_scalar( $json['errorDescription'] ?? null ) ) {
				$result->error .= ' ' . mb_substr( Logger::clean( (string) $json['errorDescription'] ), 0, 200 );
			}
			return $result;
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/** Server address without a trailing slash. */
	private function server(): string {
		return untrailingslashit( (string) $this->setting( 'server' ) );
	}
}
