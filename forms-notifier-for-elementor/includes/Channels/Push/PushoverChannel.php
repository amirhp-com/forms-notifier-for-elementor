<?php
/**
 * Pushover channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Push;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;
use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/**
 * Pushover. Messages count against a monthly quota, so a long text is cut and the link leads to the rest.
 *
 * @see https://pushover.net/api
 */
final class PushoverChannel extends AbstractChannel {

	private const URL = 'https://api.pushover.net/1/messages.json';

	/** Characters of one message. */
	private const LIMIT = 1024;

	/** Channel id. */
	public function id(): string {
		return 'pushover';
	}

	/** Display name. */
	public function label(): string {
		return 'Pushover';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Push messages to phones and desktops through Pushover.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'push';
	}

	/** Icon. */
	public function icon(): string {
		return 'bell';
	}

	/** HTML subset. */
	public function format(): string {
		return 'telegram_html';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'secret',
				'buttons'       => [ 'url' ],
				'subject'       => true,
				'max_length'    => self::LIMIT,
			]
		);
	}

	/** Application token. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'pushover_connection',
				'title'  => __( 'Connection', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field( 'app_token', 'secret', __( 'Application token', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Create an application in your Pushover account. Receivers are user or group keys.', 'forms-notifier-for-elementor' ) ] ),
					$this->field(
						'priority',
						'select',
						__( 'Priority', 'forms-notifier-for-elementor' ),
						[
							'options' => self::priorities(),
							'default' => '0',
							'width'   => 'half',
						]
					),
				],
			],
		];
	}

	/** Per-form options. */
	public function form_schema(): array {
		return [
			$this->field(
				'priority',
				'select',
				__( 'Priority', 'forms-notifier-for-elementor' ),
				[ 'options' => [ '' => __( 'Same as the channel', 'forms-notifier-for-elementor' ) ] + self::priorities() ]
			),
		];
	}

	/** Problems. */
	public function problems(): array {
		return $this->secret( 'app_token' ) === '' ? [ __( 'Enter the application token.', 'forms-notifier-for-elementor' ) ] : [];
	}

	/**
	 * User or group key.
	 *
	 * @param string $raw     Raw key.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$raw = trim( $raw );
		return preg_match( '/^[A-Za-z0-9]{30}$/', $raw ) ? $raw : '';
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			$token = $this->secret( 'app_token' );
			$user  = $this->normalize_address( $this->resolve_address( $message->recipient->address ) );
			if ( $token === '' || $user === '' ) {
				return $this->fail( __( 'The application token or the receiver key is missing.', 'forms-notifier-for-elementor' ), true );
			}
			Logger::scrub( $user );
			$text = self::text( Extras::compose( $message ) );
			if ( $text === '' ) {
				return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
			}

			$priority = (string) ( $message->options['priority'] ?? '' );
			$priority = (int) ( $priority === '' ? $this->setting( 'priority', '0' ) : $priority );
			$fields   = [
				'token'    => $token,
				'user'     => $user,
				'message'  => $text,
				'html'     => 1,
				'priority' => max( -2, min( 1, $priority ) ),
			];
			if ( $message->subject !== '' ) {
				$fields['title'] = Text::truncate( $message->subject, 250 );
			}
			$buttons = Extras::url_buttons( $message, 1 );
			$id      = (int) ( $message->meta['submission_id'] ?? 0 );
			if ( $buttons && strlen( $buttons[0]['value'] ) <= 512 ) {
				$fields['url']       = $buttons[0]['value'];
				$fields['url_title'] = Text::truncate( $buttons[0]['text'], 100 );
			} elseif ( $id && $message->purpose === 'admin' ) {
				$fields['url']       = Urls::submission_admin( $id );
				$fields['url_title'] = __( 'Open the submission', 'forms-notifier-for-elementor' );
			}
			return $this->result(
				Http::request(
					'POST',
					self::URL,
					[
						'body'    => http_build_query( $fields, '', '&' ),
						'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ],
						'scrub'   => [ $token, $user ],
					]
				)
			);
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/**
	 * Map the answer. 429 is the monthly quota: retrying is pointless.
	 *
	 * @param array $response Output of Http::request().
	 */
	private function result( array $response ): SendResult {
		$json             = is_array( $response['json'] ) ? $response['json'] : [];
		$response['body'] = mb_substr( (string) $response['body'], 0, 500 );
		if ( $response['ok'] && (int) ( $json['status'] ?? 1 ) !== 1 ) {
			$response['ok'] = false;
		}
		$result = $this->from_http( $response );
		if ( $result->ok ) {
			$result->remote_id = is_scalar( $json['request'] ?? null ) ? (string) $json['request'] : '';
			return $result;
		}
		$errors = array_filter( (array) ( $json['errors'] ?? [] ), 'is_scalar' );
		if ( $errors ) {
			$result->error = mb_substr( Logger::clean( implode( ', ', $errors ) ), 0, 300 );
		}
		if ( (int) $response['code'] === 429 ) {
			$result->permanent = true;
		}
		return $result;
	}

	/**
	 * Text with the tags Pushover knows, cut to the limit.
	 *
	 * @param string $html Telegram HTML.
	 */
	private static function text( string $html ): string {
		$html  = (string) preg_replace( '~</?(?!(?:b|i|u|a)\b)[a-z][a-z-]*(?:\s[^<>]*)?>~i', '', $html );
		$parts = Splitter::split_html( $html, self::LIMIT );
		if ( ! $parts ) {
			return '';
		}
		return count( $parts ) > 1 ? $parts[0] . ' …' : $parts[0];
	}

	/** Priorities. */
	private static function priorities(): array {
		return [
			'-2' => _x( 'Lowest, no alert', 'priority', 'forms-notifier-for-elementor' ),
			'-1' => _x( 'Low, no sound', 'priority', 'forms-notifier-for-elementor' ),
			'0'  => _x( 'Normal', 'priority', 'forms-notifier-for-elementor' ),
			'1'  => _x( 'High, ignores quiet hours', 'priority', 'forms-notifier-for-elementor' ),
		];
	}
}
