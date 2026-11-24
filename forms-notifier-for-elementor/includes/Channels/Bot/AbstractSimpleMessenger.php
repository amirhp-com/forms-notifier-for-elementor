<?php
/**
 * Simple messenger base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Messengers with a token and plain text: no keyboards, no updates, receivers typed by hand. */
abstract class AbstractSimpleMessenger extends AbstractChannel {

	/** Group of the Channels screen. */
	public function group(): string {
		return 'messenger';
	}

	/** Icon key. */
	public function icon(): string {
		return 'chat';
	}

	/** Token. */
	protected function own_sections(): array {
		return [
			[
				'id'       => $this->id() . '_connection',
				'title'    => __( 'Connection', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					$this->field(
						'token',
						'secret',
						__( 'Token', 'forms-notifier-for-elementor' ),
						[
							'desc'     => $this->token_help(),
							'required' => true,
							'attrs'    => [ 'dir' => 'ltr' ],
						]
					),
					$this->field(
						'address_help',
						'notice',
						'',
						[
							'desc'  => $this->address_help(),
							'level' => 'info',
						]
					),
				],
			],
		];
	}

	/** Where to get the token. */
	abstract protected function token_help(): string;

	/** What a receiver address looks like. */
	abstract protected function address_help(): string;

	/**
	 * Send text and files.
	 *
	 * @param Message $message Message.
	 * @param string  $token   Token, safe inside a URL path.
	 */
	abstract protected function deliver( Message $message, string $token ): SendResult;

	/** Translated problems. */
	public function problems(): array {
		if ( Settings::secret_is_broken( $this->key( 'token' ) ) ) {
			return [ __( 'The saved token cannot be read any more because the security keys of the site changed. Enter it again.', 'forms-notifier-for-elementor' ) ];
		}
		return trim( $this->secret( 'token' ) ) === '' ? [ __( 'Enter the token.', 'forms-notifier-for-elementor' ) ] : [];
	}

	/**
	 * Send one message. Never throws.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		$token = trim( $this->secret( 'token' ) );
		if ( $token === '' ) {
			return $this->fail( __( 'The token is not set.', 'forms-notifier-for-elementor' ), true );
		}
		if ( ! preg_match( '/^[A-Za-z0-9:._-]+$/', $token ) ) {
			return $this->fail( __( 'The token is not valid.', 'forms-notifier-for-elementor' ), true );
		}
		if ( $this->normalize_address( $message->recipient->address ) === '' ) {
			return $this->fail( __( 'The destination address is not valid.', 'forms-notifier-for-elementor' ), true );
		}
		try {
			return $this->deliver( $message, $token );
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/**
	 * Body, link buttons as lines, file links.
	 *
	 * @param Message $message Message.
	 */
	protected function text( Message $message ): string {
		$text  = trim( $message->body );
		$lines = [];
		foreach ( $message->buttons as $row ) {
			foreach ( is_array( $row ) ? $row : [] as $button ) {
				if ( is_array( $button ) && ( $button['type'] ?? '' ) === 'url' && Keyboard::is_public_url( (string) ( $button['value'] ?? '' ) ) ) {
					$lines[] = trim( (string) ( $button['text'] ?? '' ) ) . ': ' . $button['value'];
				}
			}
		}
		$files = [];
		foreach ( $message->links as $link ) {
			$url = is_array( $link ) ? esc_url_raw( (string) ( $link['url'] ?? '' ), [ 'http', 'https' ] ) : '';
			if ( $url !== '' ) {
				$files[] = trim( (string) ( $link['name'] ?? '' ) ) . ': ' . $url;
			}
		}
		if ( $lines ) {
			$text .= "\n\n" . implode( "\n", $lines );
		}
		if ( $files ) {
			$title = I18n::with_language(
				$message->lang,
				static function (): string {
					return __( 'Files:', 'forms-notifier-for-elementor' );
				}
			);
			$text .= "\n\n" . $title . "\n" . implode( "\n", $files );
		}
		return trim( $text );
	}

	/**
	 * Files that are still there and not too large.
	 *
	 * @param Message $message Message.
	 * @param int     $max     Largest size in bytes.
	 * @return Attachment[]
	 */
	protected function files( Message $message, int $max ): array {
		return array_values(
			array_filter(
				$message->attachments,
				static function ( $file ) use ( $max ): bool {
					return $file instanceof Attachment && $file->exists() && (int) filesize( $file->path ) <= $max
						&& ( ! class_exists( PathGuard::class ) || PathGuard::is_safe( $file->path ) );
				}
			)
		);
	}

	/**
	 * Result of the text with the outcome of the files; a failed file never repeats the text.
	 *
	 * @param SendResult $result Result of the text.
	 * @param string[]   $failed Names of failed files.
	 */
	protected function with_files( SendResult $result, array $failed ): SendResult {
		if ( $failed ) {
			$result->response = trim( $result->response . '; files failed: ' . implode( ', ', $failed ), '; ' );
			$result->with_meta( [ 'files_failed' => $failed ] );
		}
		return $result;
	}
}
