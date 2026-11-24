<?php
/**
 * Rubika.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Rubika bot API: text, files in three steps. Errors come with HTTP 200; the field "status" decides. */
final class RubikaChannel extends AbstractSimpleMessenger {

	private const BASE = 'https://botapi.rubika.ir/v3/';

	private const MAX_FILE = 20971520;

	/** Channel id. */
	public function id(): string {
		return 'rubika';
	}

	/** Label. */
	public function label(): string {
		return __( 'Rubika', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Text and files through a Rubika bot.', 'forms-notifier-for-elementor' );
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge( parent::capabilities(), [ 'attachments' => true ] );
	}

	/** Largest file in bytes; used by the attachment policy. */
	public function max_upload_bytes(): int {
		return self::MAX_FILE;
	}

	/**
	 * Chat id.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		unset( $context );
		$raw = trim( Text::en_digits( $raw ) );
		return preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $raw ) ? $raw : '';
	}

	/** Where to get the token. */
	protected function token_help(): string {
		return __( 'Create a bot with @BotFather in Rubika and copy its token.', 'forms-notifier-for-elementor' );
	}

	/** What a receiver address looks like. */
	protected function address_help(): string {
		return __( 'Receivers: the chat id (chat_id) of a person, group or channel in which the bot is active.', 'forms-notifier-for-elementor' );
	}

	/**
	 * Send text and files.
	 *
	 * @param Message $message Message.
	 * @param string  $token   Token.
	 */
	protected function deliver( Message $message, string $token ): SendResult {
		$chat = $this->normalize_address( $message->recipient->address );
		$text = $this->text( $message );
		if ( $text === '' ) {
			return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
		}
		$payload = [
			'chat_id' => $chat,
			'text'    => $text,
		];
		if ( ! empty( $message->options['silent'] ) && Text::bool( $message->options['silent'] ) ) {
			$payload['disable_notification'] = true;
		}
		$result = $this->result( $this->call( $token, 'sendMessage', $payload ) );
		if ( ! $result->ok ) {
			return $result;
		}

		$failed = [];
		foreach ( $this->files( $message, self::MAX_FILE ) as $file ) {
			$id = $this->upload( $token, $file->is_image() ? 'Image' : 'File', $file->path, $file->name, $file->mime );
			if ( $id === '' || ! $this->result(
				$this->call(
					$token,
					'sendFile',
					[
						'chat_id' => $chat,
						'file_id' => $id,
						'text'    => $file->name,
					]
				)
			)->ok ) {
				$failed[] = $file->name;
			}
		}
		return $this->with_files( $result, $failed );
	}

	/**
	 * Ask for an upload address and upload the file.
	 *
	 * @param string $token Token.
	 * @param string $type  File | Image.
	 * @param string $path  Path.
	 * @param string $name  Name.
	 * @param string $mime  Type.
	 * @return string file id, '' on failure
	 */
	private function upload( string $token, string $type, string $path, string $name, string $mime ): string {
		$request = $this->call( $token, 'requestSendFile', [ 'type' => $type ] );
		$url     = $this->result( $request )->ok ? $request['json']['data']['upload_url'] ?? '' : '';
		// The address comes from the service: only public https hosts are used.
		if ( ! is_string( $url ) || strncmp( $url, 'https://', 8 ) !== 0 || ! Keyboard::is_public_url( $url ) ) {
			return '';
		}
		list( $body, $content_type ) = Http::multipart(
			[],
			[
				'file' => [
					'path' => $path,
					'name' => $name,
					'mime' => $mime,
				],
			]
		);

		$uploaded = Http::post(
			$url,
			[
				'body'        => $body,
				'headers'     => [ 'Content-Type' => $content_type ],
				'timeout'     => 90,
				'redirection' => 0,
				'scrub'       => [ $token ],
			]
		);
		$id       = $this->result( $uploaded )->ok ? $uploaded['json']['data']['file_id'] ?? '' : '';
		return is_string( $id ) ? $id : '';
	}

	/**
	 * One API call.
	 *
	 * @param string $token  Token.
	 * @param string $method Method.
	 * @param array  $params Parameters.
	 */
	private function call( string $token, string $method, array $params ): array {
		return Http::post(
			self::BASE . $token . '/' . $method,
			[
				'json'        => $params,
				'redirection' => 0,
				'scrub'       => [ $token ],
			]
		);
	}

	/**
	 * Result of an answer.
	 *
	 * @param array $response Output of Http.
	 */
	private function result( array $response ): SendResult {
		$json   = is_array( $response['json'] ) ? $response['json'] : [];
		$status = is_string( $json['status'] ?? null ) ? strtoupper( sanitize_text_field( $json['status'] ) ) : '';
		if ( $response['ok'] && $status === 'OK' ) {
			$id                = $json['data']['message_id'] ?? '';
			$result            = $this->ok( $response['body'], is_scalar( $id ) ? (string) $id : '' );
			$result->http_code = $response['code'];
			return $result;
		}
		if ( $status === '' || $status === 'OK' ) {
			return $response['ok']
				? $this->fail( __( 'The service sent an unreadable answer.', 'forms-notifier-for-elementor' ), false, $response['body'], $response['code'] )
				: $this->from_http( $response );
		}

		$temporary = (bool) preg_match( '/TOO_?|RATE|LIMIT|SERVER|TIMEOUT|UNAVAILABLE/', $status );
		/* translators: %1$s: status word of the service */
		$error  = sprintf( __( 'Rubika answered: %1$s', 'forms-notifier-for-elementor' ), $status );
		$result = $this->fail( $error, ! $temporary, $response['body'], $response['code'] );
		if ( $temporary && preg_match( '/TOO_?|RATE|LIMIT/', $status ) ) {
			$result->retry_after = 60;
		}
		return $result;
	}
}
