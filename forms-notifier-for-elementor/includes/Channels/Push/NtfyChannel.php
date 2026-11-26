<?php
/**
 * Channel of ntfy.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Push;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Channels\PartsResume;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;

defined( 'ABSPATH' ) || exit;

/**
 * Push messages through ntfy. JSON is used because header values cannot hold other alphabets.
 *
 * @see https://docs.ntfy.sh/publish/
 */
final class NtfyChannel extends AbstractChannel {

	private const DEFAULT_SERVER = 'https://ntfy.sh';

	/** Bytes of one message; a longer one becomes an attachment. */
	private const LIMIT = 4096;

	/** Channel id. */
	public function id(): string {
		return 'ntfy';
	}

	/** Display name. */
	public function label(): string {
		return 'ntfy';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Push messages to phones and desktops through ntfy.sh or your own server.', 'forms-notifier-for-elementor' );
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
				'receiver_kind' => 'secret',
				'buttons'       => [ 'url' ],
				'subject'       => true,
				'max_length'    => self::LIMIT,
			]
		);
	}

	/** Server and token. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'ntfy_connection',
				'title'  => __( 'Connection', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field(
						'server',
						'url',
						__( 'Server', 'forms-notifier-for-elementor' ),
						[
							'default' => self::DEFAULT_SERVER,
							'attrs'   => [ 'dir' => 'ltr' ],
						]
					),
					$this->field( 'token', 'secret', __( 'Access token', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Only for topics that need a login.', 'forms-notifier-for-elementor' ) ] ),
					$this->field( 'topics_note', 'notice', __( 'Anyone who knows the name of a topic on a public server can read it. Use a long random name or a server with access control.', 'forms-notifier-for-elementor' ), [ 'level' => 'warning' ] ),
					$this->priority_field(),
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

	/**
	 * Topic name; the address of a topic on the configured server works too.
	 *
	 * @param string $raw     Raw topic.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$raw    = trim( $raw );
		$server = $this->server() . '/';
		if ( strncasecmp( $raw, $server, strlen( $server ) ) === 0 ) {
			$raw = substr( $raw, strlen( $server ) );
		}
		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $raw ) ? $raw : '';
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			$topic = $this->normalize_address( $this->resolve_address( $message->recipient->address ) );
			if ( $topic === '' ) {
				return $this->fail( __( 'The topic is missing or not valid.', 'forms-notifier-for-elementor' ), true );
			}
			Logger::scrub( $topic );
			$parts = Registry::formatter( 'markdown' )->split( Extras::compose( $message ), 0 );
			$parts = $parts && strlen( $parts[0] ) > self::LIMIT ? self::bytes( $message ) : $parts;
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
				$result = $this->post( $topic, $part, $message, $i === $last );
				if ( ! $result->ok ) {
					return $result->with_meta( [ 'parts_sent' => $resume->count() ] );
				}
				$resume->add( $i, $result->remote_id );
			}
			$resume->done();
			$result->remote_id = $resume->remote_ids();
			return $result;
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/**
	 * Post one part.
	 *
	 * @param string  $topic   Topic.
	 * @param string  $text    Text.
	 * @param Message $message Message.
	 * @param bool    $last    Last part.
	 */
	private function post( string $topic, string $text, Message $message, bool $last ): SendResult {
		$priority = (string) ( $message->options['priority'] ?? '' );
		$priority = (int) ( $priority === '' ? $this->setting( 'priority', '3' ) : $priority );
		$body     = [
			'topic'    => $topic,
			'message'  => $text,
			'markdown' => true,
		];
		if ( $message->subject !== '' ) {
			$body['title'] = Text::cut_bytes( $message->subject, 250 );
		}
		if ( $priority >= 1 && $priority <= 5 && $priority !== 3 ) {
			$body['priority'] = $priority;
		}
		$buttons = $last ? Extras::url_buttons( $message, 3 ) : [];
		if ( $buttons ) {
			$body['click'] = $buttons[0]['value'];
			foreach ( $buttons as $button ) {
				$body['actions'][] = [
					'action' => 'view',
					'label'  => $button['text'],
					'url'    => $button['value'],
				];
			}
		}
		$headers = [];
		$token   = $this->secret( 'token' );
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response         = Http::request(
			'POST',
			$this->server() . '/',
			[
				'json'        => $body,
				'headers'     => $headers,
				'redirection' => 0,
				'scrub'       => [ $topic ],
			]
		);
		$json             = is_array( $response['json'] ) ? $response['json'] : [];
		$response['body'] = mb_substr( (string) $response['body'], 0, 500 );
		$result           = $this->from_http( $response );
		if ( $result->ok ) {
			$result->remote_id = is_scalar( $json['id'] ?? null ) ? (string) $json['id'] : '';
		} elseif ( is_scalar( $json['error'] ?? null ) ) {
			$result->error .= ' ' . mb_substr( Logger::clean( (string) $json['error'] ), 0, 200 );
		}
		return $result;
	}

	/**
	 * Parts measured in bytes.
	 *
	 * @param Message $message Message.
	 * @return string[]
	 */
	private static function bytes( Message $message ): array {
		$text = Extras::compose( $message );
		// A Persian letter takes two bytes: the average decides how many characters fit.
		$ratio = strlen( $text ) / max( 1, mb_strlen( $text ) );
		$limit = (int) floor( self::LIMIT / $ratio * 0.9 );
		$out   = [];
		foreach ( Registry::formatter( 'markdown' )->split( $text, max( 500, $limit ) ) as $part ) {
			$out[] = Text::cut_bytes( $part, self::LIMIT );
		}
		return $out;
	}

	/** Server address without a trailing slash. */
	private function server(): string {
		$server = untrailingslashit( (string) $this->setting( 'server', self::DEFAULT_SERVER ) );
		return $server === '' ? self::DEFAULT_SERVER : $server;
	}

	/** Priority of the channel. */
	private function priority_field(): array {
		return $this->field(
			'priority',
			'select',
			__( 'Priority', 'forms-notifier-for-elementor' ),
			[
				'options' => self::priorities(),
				'default' => '3',
				'width'   => 'half',
			]
		);
	}

	/** Priorities. */
	private static function priorities(): array {
		return [
			'1' => _x( 'Lowest', 'priority', 'forms-notifier-for-elementor' ),
			'2' => _x( 'Low', 'priority', 'forms-notifier-for-elementor' ),
			'3' => _x( 'Normal', 'priority', 'forms-notifier-for-elementor' ),
			'4' => _x( 'High', 'priority', 'forms-notifier-for-elementor' ),
			'5' => _x( 'Highest', 'priority', 'forms-notifier-for-elementor' ),
		];
	}
}
