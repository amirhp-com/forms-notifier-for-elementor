<?php
/**
 * Eitaa.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Eitaa through the eitaayar API: text and files, no buttons, no formatting. */
final class EitaaChannel extends AbstractSimpleMessenger {

	private const BASE = 'https://eitaayar.ir/api/';

	private const MAX_FILE = 20971520;

	/** Channel id. */
	public function id(): string {
		return 'eitaa';
	}

	/** Label. */
	public function label(): string {
		return __( 'Eitaa', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Text and files in Eitaa channels and groups, through Eitaayar.', 'forms-notifier-for-elementor' );
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'attachments' => true,
				'subject'     => true,
			]
		);
	}

	/** Largest file in bytes; used by the attachment policy. */
	public function max_upload_bytes(): int {
		return self::MAX_FILE;
	}

	/**
	 * Numeric id or username without @.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		unset( $context );
		$raw = preg_replace( '~^(?:https?://)?(?:www\.)?eitaa\.com/~i', '', trim( Text::en_digits( $raw ) ) );
		$raw = trim( ltrim( (string) $raw, '@' ), '/' );
		return preg_match( '/^(-?\d{1,20}|[A-Za-z][A-Za-z0-9_]{2,63})$/', $raw ) ? $raw : '';
	}

	/** Where to get the token. */
	protected function token_help(): string {
		return __( 'Create a token in the panel of eitaayar.ir and add your channel or group there.', 'forms-notifier-for-elementor' );
	}

	/** What a receiver address looks like. */
	protected function address_help(): string {
		return __( 'Receivers: the numeric id or the username of a channel or group that was added in the Eitaayar panel.', 'forms-notifier-for-elementor' );
	}

	/**
	 * Send text and files.
	 *
	 * @param Message $message Message.
	 * @param string  $token   Token.
	 */
	protected function deliver( Message $message, string $token ): SendResult {
		$chat   = $this->normalize_address( $message->recipient->address );
		$fields = array_filter(
			[
				'chat_id' => $chat,
				'text'    => $this->text( $message ),
				'title'   => trim( $message->subject ),
			],
			'strlen'
		);
		if ( ! isset( $fields['text'] ) ) {
			return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
		}
		if ( ! empty( $message->options['silent'] ) && Text::bool( $message->options['silent'] ) ) {
			$fields['disable_notification'] = 1;
		}
		$result = $this->result(
			Http::post(
				self::BASE . $token . '/sendMessage',
				[
					'body'        => http_build_query( $fields, '', '&' ),
					'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ],
					'redirection' => 0,
					'scrub'       => [ $token ],
				]
			)
		);
		if ( ! $result->ok ) {
			return $result;
		}

		$failed = [];
		foreach ( $this->files( $message, self::MAX_FILE ) as $file ) {
			list( $body, $type ) = Http::multipart(
				[
					'chat_id' => $chat,
					'caption' => $file->name,
				],
				[
					'file' => [
						'path' => $file->path,
						'name' => $file->name,
						'mime' => $file->mime,
					],
				]
			);

			$sent = $this->result(
				Http::post(
					self::BASE . $token . '/sendFile',
					[
						'body'        => $body,
						'headers'     => [ 'Content-Type' => $type ],
						'timeout'     => 90,
						'redirection' => 0,
						'scrub'       => [ $token ],
					]
				)
			);
			if ( ! $sent->ok ) {
				$failed[] = $file->name;
			}
		}
		return $this->with_files( $result, $failed );
	}

	/**
	 * Result of an answer. The body decides, not the status.
	 *
	 * @param array $response Output of Http.
	 */
	private function result( array $response ): SendResult {
		$json = is_array( $response['json'] ) ? $response['json'] : null;
		if ( $json !== null && ! empty( $json['ok'] ) ) {
			$id                = $json['result']['message_id'] ?? '';
			$result            = $this->ok( $response['body'], is_scalar( $id ) ? (string) $id : '' );
			$result->http_code = $response['code'];
			return $result;
		}
		if ( $json !== null && array_key_exists( 'ok', $json ) ) {
			$code  = is_numeric( $json['error_code'] ?? null ) ? (int) $json['error_code'] : $response['code'];
			$error = is_scalar( $json['description'] ?? null ) ? sanitize_text_field( (string) $json['description'] ) : '';

			$response['code']  = $code >= 400 ? $code : 400;
			$response['error'] = $error === '' ? __( 'Eitaa refused the message.', 'forms-notifier-for-elementor' ) : $error;
		} elseif ( $response['ok'] ) {
			return $this->fail( __( 'The service sent an unreadable answer.', 'forms-notifier-for-elementor' ), false, $response['body'], $response['code'] );
		}
		$response['ok'] = false;
		return $this->from_http( $response );
	}
}
