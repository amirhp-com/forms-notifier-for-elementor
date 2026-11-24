<?php
/**
 * Channel base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;

defined( 'ABSPATH' ) || exit;

/** Shared channel behaviour. Subclasses implement id(), label(), send(); everything else has a default. */
abstract class AbstractChannel implements ChannelInterface {

	/** One translated sentence for the channel tile. */
	public function description(): string {
		return '';
	}

	/** Group for the Channels screen. */
	public function group(): string {
		return 'chat';
	}

	/** Icon key. */
	public function icon(): string {
		return 'send';
	}

	/** Canonical format of the body. */
	public function format(): string {
		return 'text';
	}

	/** Per-form option fields. */
	public function form_schema(): array {
		return [];
	}

	/** Extra admin actions. */
	public function actions(): array {
		return [];
	}

	/** Receivers list/text, no submitter, no buttons, no attachments, links, no subject, no pattern, no limit. */
	public function capabilities(): array {
		return [
			'receivers'     => 'list',
			'receiver_kind' => 'text',
			'submitter'     => false,
			'buttons'       => [],
			'attachments'   => false,
			'links'         => true,
			'subject'       => false,
			'pattern'       => false,
			'max_length'    => 0,
			'discover'      => false,
		];
	}

	/** Channel-specific sections only; override this, NOT settings_schema(). */
	protected function own_sections(): array {
		return [];
	}

	/** Main section, own sections, receivers, template, buttons, fallback. Filter: fnfe_channel_schema. */
	final public function settings_schema(): array {
		$id           = $this->id();
		$capabilities = array_merge( self::capabilities(), $this->capabilities() );
		$sections     = [
			[
				'id'       => $id . '_main',
				'title'    => $this->label(),
				'priority' => 0,
				'fields'   => [
					$this->field( 'enabled', 'toggle', __( 'Use this channel', 'forms-notifier-for-elementor' ), [ 'default' => false ] ),
				],
			],
		];

		foreach ( $this->own_sections() as $section ) {
			$sections[] = array_merge( [ 'priority' => 10 ], $section );
		}

		if ( $capabilities['receivers'] === 'list' ) {
			$sections[] = [
				'id'       => $id . '_receivers',
				'title'    => __( 'Receivers', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'They get the submissions of every form. Each form can add its own receivers or replace these.', 'forms-notifier-for-elementor' ),
				'priority' => 50,
				'fields'   => [
					$this->field( 'receivers', 'receivers', __( 'Receivers', 'forms-notifier-for-elementor' ), [ 'channel' => $id ] ),
				],
			];
		}

		$sections[] = [
			'id'       => $id . '_template',
			'title'    => __( 'Message', 'forms-notifier-for-elementor' ),
			'desc'     => __( 'Leave empty to use the built-in text.', 'forms-notifier-for-elementor' ),
			'priority' => 60,
			'fields'   => $this->template_fields( $capabilities ),
		];

		if ( $capabilities['buttons'] ) {
			$sections[] = [
				'id'       => $id . '_buttons',
				'title'    => __( 'Buttons', 'forms-notifier-for-elementor' ),
				'priority' => 70,
				'fields'   => [
					$this->field( 'buttons', 'buttons', __( 'Buttons under the message', 'forms-notifier-for-elementor' ), [ 'channel' => $id ] ),
				],
			];
		}

		$sections[] = [
			'id'        => $id . '_fallback',
			'title'     => __( 'If sending fails', 'forms-notifier-for-elementor' ),
			'priority'  => 90,
			'collapsed' => true,
			'fields'    => [
				$this->field(
					'fallback',
					'select',
					__( 'Send through another channel instead', 'forms-notifier-for-elementor' ),
					[
						'options'        => [ '' => __( 'No other channel', 'forms-notifier-for-elementor' ) ],
						'options_source' => 'channels',
						'desc'           => __( 'Used once, after the last attempt failed.', 'forms-notifier-for-elementor' ),
					]
				),
			],
		];

		$out = [];
		foreach ( $sections as $section ) {
			$fields = [];
			foreach ( (array) ( $section['fields'] ?? [] ) as $field ) {
				$fields[] = Schema::normalize_field( $field, $this->key( '' ) );
			}
			$section['fields'] = $fields;
			$out[]             = array_merge( [ 'tab' => $id ], $section );
		}

		return (array) apply_filters( 'fnfe_channel_schema', $out, $this );
	}

	/** Switched on in settings. */
	public function is_enabled(): bool {
		return Settings::bool( $this->key( 'enabled' ) );
	}

	/** Enabled AND has everything needed to send. */
	public function is_ready(): bool {
		return $this->is_enabled() && ! $this->problems();
	}

	/** Translated problems. */
	public function problems(): array {
		return [];
	}

	/**
	 * Normalise a raw destination.
	 *
	 * @param string $raw     Raw destination.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		return trim( $raw );
	}

	/**
	 * Built-in template for a purpose.
	 *
	 * @param string $purpose Purpose.
	 */
	public function default_template( string $purpose ): string {
		return DefaultTemplates::for_format( $this->format(), $purpose );
	}

	/**
	 * Send a short test message.
	 *
	 * @param Recipient $to Recipient.
	 */
	public function test( Recipient $to ): SendResult {
		$text = sprintf(
			/* translators: %1$s: site name */
			__( 'Test message from %1$s. This channel works.', 'forms-notifier-for-elementor' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$message          = new Message( $this->id(), $to );
		$message->purpose = 'test';
		$message->lang    = $to->lang;
		$message->format  = $this->format();
		$message->subject = __( 'Test message', 'forms-notifier-for-elementor' );
		$message->body    = Registry::formatter( $message->format )->escape( $text );
		$message->plain   = $text;
		$message->meta    = [ 'test' => true ];
		$message->data    = [
			'event' => 'test',
			'text'  => $text,
		];

		try {
			return $this->send( $message );
		} catch ( \Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/**
	 * Absolute settings path: "channels.{id}.{relative}".
	 *
	 * @param string $relative Relative key.
	 */
	protected function key( string $relative ): string {
		return 'channels.' . $this->id() . '.' . $relative;
	}

	/**
	 * Channel setting.
	 *
	 * @param string $relative Relative key.
	 * @param mixed  $default  Returned when unknown.
	 * @return mixed
	 */
	protected function setting( string $relative, $default = '' ) {
		return Settings::get( $this->key( $relative ), $default );
	}

	/**
	 * Channel setting as boolean.
	 *
	 * @param string $relative Relative key.
	 * @param bool   $default  Returned when unknown.
	 */
	protected function bool( string $relative, bool $default = false ): bool {
		return Settings::bool( $this->key( $relative ), $default );
	}

	/**
	 * Channel secret.
	 *
	 * @param string $relative Relative key.
	 */
	protected function secret( string $relative ): string {
		return Settings::secret( $this->key( $relative ) );
	}

	/**
	 * Success result.
	 *
	 * @param string $response  Provider response.
	 * @param string $remote_id Remote message id.
	 */
	protected function ok( string $response = '', string $remote_id = '' ): SendResult {
		return SendResult::success( $this->id(), $response, $remote_id );
	}

	/**
	 * Failure result.
	 *
	 * @param string $error     Error.
	 * @param bool   $permanent Retrying is pointless.
	 * @param string $response  Provider response.
	 * @param int    $http_code HTTP status.
	 */
	protected function fail( string $error, bool $permanent = false, string $response = '', int $http_code = 0 ): SendResult {
		return SendResult::failure( $this->id(), $error, $permanent, $response, $http_code );
	}

	/**
	 * Standard mapping of an Http response: 2xx ok; 408/429/5xx/0 temporary; other 4xx permanent.
	 *
	 * @param array  $response Output of Http::request().
	 * @param string $ok_note  Logged instead of the body on success.
	 */
	protected function from_http( array $response, string $ok_note = '' ): SendResult {
		return self::map_http( $this->id(), $response, $ok_note );
	}

	/**
	 * Build a field array. Relative keys are made absolute by settings_schema().
	 *
	 * @param string $key   Relative key.
	 * @param string $type  Field type.
	 * @param string $label Translated label.
	 * @param array  $extra Other field keys.
	 */
	protected function field( string $key, string $type, string $label, array $extra = [] ): array {
		return array_merge(
			[
				'key'   => $key,
				'type'  => $type,
				'label' => $label,
			],
			$extra
		);
	}

	/**
	 * Real destination of a recipient: resolves 'secret:{rid}' placeholders; other addresses are returned as is.
	 *
	 * @param string $address Address.
	 */
	protected function resolve_address( string $address ): string {
		if ( ! Settings::is_placeholder( $address ) ) {
			return $address;
		}
		return $this->secret( 'receiver_secrets.' . substr( $address, strlen( Settings::PLACEHOLDER ) ) );
	}

	/**
	 * Result of an HTTP exchange; shared with AbstractProvider.
	 *
	 * @param string $provider Channel or provider id.
	 * @param array  $response Output of Http::request().
	 * @param string $ok_note  Logged instead of the body on success.
	 */
	final public static function map_http( string $provider, array $response, string $ok_note = '' ): SendResult {
		$code = (int) ( $response['code'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );

		if ( ! empty( $response['ok'] ) ) {
			$result            = SendResult::success( $provider, $ok_note === '' ? $body : $ok_note );
			$result->http_code = $code;
			return $result;
		}

		$error = (string) ( $response['error'] ?? '' );
		if ( $error === '' ) {
			$error = $code
				/* translators: %1$d: HTTP status code */
				? sprintf( __( 'The service answered with status %1$d.', 'forms-notifier-for-elementor' ), $code )
				: __( 'The service could not be reached.', 'forms-notifier-for-elementor' );
		}
		$temporary = $code === 0 || $code === 408 || $code === 429 || $code >= 500 || $code < 400;
		$result    = SendResult::failure( $provider, $error, ! $temporary, $body, $code );
		$retry     = $response['headers']['retry-after'] ?? '';
		if ( is_numeric( $retry ) ) {
			$result->retry_after = max( 0, (int) $retry );
		}
		return $result;
	}

	/**
	 * Template and subject fields by purpose.
	 *
	 * @param array $capabilities Capabilities.
	 */
	private function template_fields( array $capabilities ): array {
		$fields   = [];
		$purposes = [ 'admin' => __( 'Message to your receivers', 'forms-notifier-for-elementor' ) ];
		$subjects = [ 'admin' => __( 'Subject', 'forms-notifier-for-elementor' ) ];
		if ( $capabilities['submitter'] ) {
			$purposes['submitter'] = __( 'Reply to the person who sent the form', 'forms-notifier-for-elementor' );
			$subjects['submitter'] = __( 'Subject of the reply', 'forms-notifier-for-elementor' );
		}
		foreach ( $purposes as $purpose => $label ) {
			$extra = [
				'localized' => true,
				'channel'   => $this->id(),
				'purpose'   => $purpose,
			];
			if ( $capabilities['subject'] ) {
				$fields[] = $this->field( 'subject.' . $purpose, 'text', $subjects[ $purpose ], $extra );
			}
			$fields[] = $this->field( 'template.' . $purpose, 'template', $label, $extra );
		}
		return $fields;
	}
}
