<?php
/**
 * Incoming webhook chats.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Channels\PartsResume;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Extras;
use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** Chats with an incoming webhook. The receiver address is the webhook URL: a credential, kept in the secret store. */
abstract class AbstractWebhookChat extends AbstractChannel {

	/** Longest text of one post, 0 = limit of the format. */
	protected const LIMIT = 0;

	/** Canonical format. */
	public function format(): string {
		return 'markdown';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'secret',
				'buttons'       => [ 'url' ],
				'max_length'    => static::LIMIT,
			]
		);
	}

	/**
	 * Built-in template: chats get the complete text.
	 *
	 * @param string $purpose Purpose.
	 */
	public function default_template( string $purpose ): string {
		return DefaultTemplates::for_format( $this->format(), $purpose );
	}

	/**
	 * Webhook URL.
	 *
	 * @param string $raw     Raw URL.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$url   = esc_url_raw( trim( $raw ), [ 'http', 'https' ] );
		$parts = $url === '' ? false : wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) ) {
			return '';
		}
		return $this->accepts( strtolower( $parts['scheme'] ?? '' ), strtolower( $parts['host'] ), (string) ( $parts['path'] ?? '' ) ) ? $url : '';
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			$url = $this->normalize_address( $this->resolve_address( $message->recipient->address ) );
			if ( $url === '' ) {
				return $this->fail( __( 'The webhook address is missing or not valid.', 'forms-notifier-for-elementor' ), true );
			}
			Logger::scrub( $url );
			return $this->deliver( $url, $message );
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/**
	 * The URL belongs to this service.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	abstract protected function accepts( string $scheme, string $host, string $path ): bool;

	/**
	 * Post one part. Last part carries the buttons and files.
	 *
	 * @param string  $url     Webhook URL.
	 * @param string  $text    Text in the format of the channel.
	 * @param Message $message Message.
	 * @param bool    $last    Last part.
	 */
	abstract protected function post( string $url, string $text, Message $message, bool $last ): SendResult;

	/** Link buttons are shown as lines of the text. */
	protected function buttons_as_text(): bool {
		return true;
	}

	/**
	 * Send the parts in order.
	 *
	 * @param string  $url     Webhook URL.
	 * @param Message $message Message.
	 */
	protected function deliver( string $url, Message $message ): SendResult {
		$parts = $this->parts( $message );
		if ( ! $parts ) {
			return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
		}
		$resume = new PartsResume( $message, $parts );
		$result = $this->ok();
		$last   = count( $parts ) - 1;
		foreach ( $parts as $i => $part ) {
			if ( $resume->has( $i ) ) {
				continue;
			}
			$result = $this->post( $url, $part, $message, $i === $last );
			if ( ! $result->ok ) {
				return $result->with_meta( [ 'parts_sent' => $resume->count() ] );
			}
			$resume->add( $i, $result->remote_id );
		}
		$resume->done();
		$result->remote_id = $resume->remote_ids();
		return $result->with_meta( [ 'parts' => count( $parts ) ] );
	}

	/**
	 * Text in parts.
	 *
	 * @param Message $message Message.
	 * @return string[]
	 */
	protected function parts( Message $message ): array {
		$formatter = Registry::formatter( $this->format() );
		$parts     = $formatter->split( Extras::compose( $message, $this->buttons_as_text() ), static::LIMIT );
		$id        = (int) ( $message->meta['submission_id'] ?? 0 );
		return $id ? Splitter::append_link( $parts, Urls::submission_admin( $id ) ) : $parts;
	}

	/**
	 * POST JSON; the URL never appears in an error.
	 *
	 * @param string $url  URL.
	 * @param array  $body Body.
	 */
	protected function request( string $url, array $body ): array {
		return Http::request(
			'POST',
			$url,
			[
				'json'        => $body,
				'redirection' => 0,
				'scrub'       => [ $url ],
			]
		);
	}

	/**
	 * Result with the error text of the service.
	 *
	 * @param array  $response Output of Http::request().
	 * @param string $detail   Error text of the service.
	 */
	protected function outcome( array $response, string $detail = '' ): SendResult {
		$response['body'] = mb_substr( (string) $response['body'], 0, 500 );
		$result           = $this->from_http( $response );
		$detail           = trim( wp_strip_all_tags( $detail ) );
		if ( ! $result->ok && $detail !== '' ) {
			$result->error .= ' ' . mb_substr( Logger::clean( $detail ), 0, 200 );
		}
		return $result;
	}
}
