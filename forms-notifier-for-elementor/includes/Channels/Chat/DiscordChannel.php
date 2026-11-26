<?php
/**
 * Discord channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Discord webhook.
 *
 * @see https://docs.discord.com/developers/resources/webhook
 */
final class DiscordChannel extends AbstractWebhookChat {

	/** Characters of one message. */
	protected const LIMIT = 2000;

	/** Bytes of one file and of all files of one request. */
	private const MAX_FILE  = 20 * MB_IN_BYTES;
	private const MAX_TOTAL = 24 * MB_IN_BYTES;
	private const MAX_FILES = 10;

	/** Channel id. */
	public function id(): string {
		return 'discord';
	}

	/** Display name. */
	public function label(): string {
		return 'Discord';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post to Discord channels and threads through webhooks, with files.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'discord';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge( parent::capabilities(), [ 'attachments' => true ] );
	}

	/** Upload limit per file. */
	public function max_upload_bytes(): int {
		return self::MAX_FILE;
	}

	/** Sender. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'discord_options',
				'title'  => __( 'Options', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field(
						'username',
						'text',
						__( 'Sender name', 'forms-notifier-for-elementor' ),
						[
							'width' => 'half',
							'attrs' => [ 'maxlength' => 80 ],
						]
					),
					$this->field(
						'disable_preview',
						'toggle',
						__( 'Hide link previews', 'forms-notifier-for-elementor' ),
						[
							'default' => true,
							'width'   => 'half',
						]
					),
				],
			],
		];
	}

	/**
	 * URL check.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	protected function accepts( string $scheme, string $host, string $path ): bool {
		return $scheme === 'https'
			&& preg_match( '/^((canary|ptb)\.)?(discord|discordapp)\.com$/', $host )
			&& preg_match( '~^/api(/v\d+)?/webhooks/\d+/[\w-]+/?$~', $path );
	}

	/**
	 * Files Discord does not take become links of the text; files without a link are reported.
	 *
	 * @param string  $url     Webhook URL.
	 * @param Message $message Message.
	 */
	protected function deliver( string $url, Message $message ): SendResult {
		list( , $rest ) = $this->split_files( $message );
		$failed         = [];
		if ( $rest ) {
			$message = clone $message;
			foreach ( $rest as $file ) {
				$link = $file->id ? Storage::link( $file, Settings::int( 'attachments.link_ttl_days', 30 ) ) : $file->url;
				if ( $link === '' ) {
					$failed[] = $file->name;
					continue;
				}
				$message->links[] = [
					'name' => $file->name,
					'url'  => $link,
					'size' => $file->size,
				];
			}
		}
		$result = parent::deliver( $url, $message );
		return $failed ? $result->with_meta( [ 'files_failed' => $failed ] ) : $result;
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
		$body     = [
			'content'          => $text,
			// Form input must never notify a role or everyone.
			'allowed_mentions' => [ 'parse' => [] ],
		];
		$username = trim( (string) $this->setting( 'username' ) );
		if ( $username !== '' ) {
			$body['username'] = Text::truncate( $username, 80 );
		}
		$flags  = $this->bool( 'disable_preview', true ) ? 4 : 0;
		$flags |= empty( $message->options['silent'] ) ? 0 : 4096;
		if ( $flags ) {
			$body['flags'] = $flags;
		}

		$target = $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'wait=true';
		$files  = $last ? $this->files( $message ) : [];
		if ( $files ) {
			list( $raw, $type ) = Http::multipart( [ 'payload_json' => $body ], $files );

			$response = Http::request(
				'POST',
				$target,
				[
					'body'        => $raw,
					'headers'     => [ 'Content-Type' => $type ],
					'redirection' => 0,
					'timeout'     => 60,
					'scrub'       => [ $url ],
				]
			);
		} else {
			$response = $this->request( $target, $body );
		}

		$json   = is_array( $response['json'] ) ? $response['json'] : [];
		$result = $this->outcome( $response, is_scalar( $json['message'] ?? null ) ? (string) $json['message'] : '' );
		if ( $result->ok ) {
			$result->remote_id = is_scalar( $json['id'] ?? null ) ? (string) $json['id'] : '';
		} elseif ( is_numeric( $json['retry_after'] ?? null ) ) {
			$result->retry_after = max( 1, (int) ceil( (float) $json['retry_after'] ) );
		}
		return $result;
	}

	/**
	 * Multipart files within the limits.
	 *
	 * @param Message $message Message.
	 */
	private function files( Message $message ): array {
		$out = [];
		foreach ( $this->split_files( $message )[0] as $file ) {
			$out[ 'files[' . count( $out ) . ']' ] = [
				'path' => $file->path,
				'name' => $file->name,
				'mime' => $file->mime,
			];
		}
		return $out;
	}

	/**
	 * Files within the limits of one request, and the others.
	 *
	 * @param Message $message Message.
	 * @return array{0:Attachment[],1:Attachment[]}
	 */
	private function split_files( Message $message ): array {
		$fit   = [];
		$rest  = [];
		$total = 0;
		foreach ( $message->attachments as $file ) {
			$size = $file->exists() ? (int) filesize( $file->path ) : 0;
			if ( ! $size || ( class_exists( PathGuard::class ) && ! PathGuard::is_safe( $file->path ) ) ) {
				continue;
			}
			if ( $size > self::MAX_FILE || $total + $size > self::MAX_TOTAL || count( $fit ) >= self::MAX_FILES ) {
				$rest[] = $file;
				continue;
			}
			$total += $size;
			$fit[]  = $file;
		}
		return [ $fit, $rest ];
	}
}
