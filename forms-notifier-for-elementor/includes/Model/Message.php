<?php
/**
 * Rendered message.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

defined( 'ABSPATH' ) || exit;

/**
 * A rendered message for exactly one channel and one recipient.
 * Serialisable: stored as JSON in fnfe_messages.payload.
 */
final class Message {

	/** @var string */
	public string $channel = '';

	/** @var Recipient */
	public Recipient $recipient;

	/** @var string */
	public string $purpose = 'admin';

	/** @var string */
	public string $lang = '';

	/** @var string format the body is rendered in */
	public string $format = 'text';

	/** @var string rendered, plain text */
	public string $subject = '';

	/** @var string rendered in $format, NOT yet split */
	public string $body = '';

	/** @var string plain-text rendering of the same template */
	public string $plain = '';

	/**
	 * Pattern/template sending. [] = normal text.
	 * Keys: code (string), vars (array of rendered values), lang (string), codes (provider id => code).
	 *
	 * @var array
	 */
	public array $pattern = [];

	/** @var array rendered buttons: rows of [ text, type, value, style ] */
	public array $buttons = [];

	/** @var Attachment[] files the channel should upload (already filtered by policy) */
	public array $attachments = [];

	/** @var array links to show: [ [ name, url, size ] ] */
	public array $links = [];

	/** @var array silent, disable_preview, protect, reply_to, priority, plus channel form options */
	public array $options = [];

	/** @var array submission_id, form_id, form_key, message_id, attempt, rule_id, fallback_of, test */
	public array $meta = [];

	/** @var array structured data for format 'json' channels */
	public array $data = [];

	/**
	 * @param string    $channel   Channel id.
	 * @param Recipient $recipient Recipient.
	 */
	public function __construct( string $channel, Recipient $recipient ) {
		$this->channel   = $channel;
		$this->recipient = $recipient;
	}

	/** JSON-safe array. */
	public function to_array(): array {
		return [
			'channel'     => $this->channel,
			'recipient'   => $this->recipient->to_array(),
			'purpose'     => $this->purpose,
			'lang'        => $this->lang,
			'format'      => $this->format,
			'subject'     => $this->subject,
			'body'        => $this->body,
			'plain'       => $this->plain,
			'pattern'     => $this->pattern,
			'buttons'     => $this->buttons,
			'attachments' => array_map(
				static function ( Attachment $file ): array {
					return $file->to_array();
				},
				array_values( $this->attachments )
			),
			'links'       => $this->links,
			'options'     => $this->options,
			'meta'        => $this->meta,
			'data'        => $this->data,
		];
	}

	/**
	 * @param array $a Output of to_array().
	 */
	public static function from_array( array $a ): self {
		$channel   = (string) ( $a['channel'] ?? '' );
		$recipient = $a['recipient'] ?? [];
		if ( ! $recipient instanceof Recipient ) {
			$recipient = Recipient::from_array( array_merge( [ 'channel' => $channel ], is_array( $recipient ) ? $recipient : [] ) );
		}

		$m = new self( $channel, $recipient );
		foreach ( [ 'purpose', 'lang', 'format', 'subject', 'body', 'plain' ] as $key ) {
			if ( isset( $a[ $key ] ) && is_scalar( $a[ $key ] ) ) {
				$m->$key = (string) $a[ $key ];
			}
		}
		foreach ( [ 'pattern', 'buttons', 'links', 'options', 'meta', 'data' ] as $key ) {
			if ( isset( $a[ $key ] ) && is_array( $a[ $key ] ) ) {
				$m->$key = $a[ $key ];
			}
		}
		foreach ( (array) ( $a['attachments'] ?? [] ) as $file ) {
			if ( $file instanceof Attachment ) {
				$m->attachments[] = $file;
			} elseif ( is_array( $file ) ) {
				$m->attachments[] = Attachment::from_array( $file );
			}
		}
		return $m;
	}
}
