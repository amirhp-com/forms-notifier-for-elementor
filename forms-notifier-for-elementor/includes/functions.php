<?php
/**
 * Public procedural API.
 *
 * @package FormsNotifier
 */

use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Plugin version; also a presence check for third parties. */
function fnfe(): string {
	return FNFE_VERSION;
}

/**
 * Read one setting by dot path.
 *
 * @param string $path    Dot path.
 * @param mixed  $default Returned when the path is unknown.
 * @return mixed
 */
function fnfe_get_setting( string $path, $default = null ) {
	return Settings::get( $path, $default );
}

/**
 * Register a delivery channel.
 *
 * @param string        $id                Channel id, [a-z0-9_]+.
 * @param string|object $class_or_instance Class name or ChannelInterface instance.
 */
function fnfe_register_channel( string $id, $class_or_instance ): void {
	add_filter(
		'fnfe_channels',
		static function ( array $channels ) use ( $id, $class_or_instance ) {
			$channels[ $id ] = $class_or_instance;
			return $channels;
		}
	);
	Registry::reset();
}

/**
 * Register an SMS gateway.
 *
 * @param string        $id                Provider id.
 * @param string|object $class_or_instance Class name or SmsProviderInterface instance.
 */
function fnfe_register_sms_provider( string $id, $class_or_instance ): void {
	add_filter(
		'fnfe_sms_providers',
		static function ( array $providers ) use ( $id, $class_or_instance ) {
			$providers[ $id ] = $class_or_instance;
			return $providers;
		}
	);
	Registry::reset();
}

/**
 * Register a WhatsApp gateway.
 *
 * @param string        $id                Provider id.
 * @param string|object $class_or_instance Class name or WhatsAppProviderInterface instance.
 */
function fnfe_register_whatsapp_provider( string $id, $class_or_instance ): void {
	add_filter(
		'fnfe_whatsapp_providers',
		static function ( array $providers ) use ( $id, $class_or_instance ) {
			$providers[ $id ] = $class_or_instance;
			return $providers;
		}
	);
	Registry::reset();
}

/**
 * Send a notification for arbitrary data (not from Elementor).
 *
 * @param array $args {
 *     @type string   $form_name Display name of the form.
 *     @type array    $fields    Field id => value, or field id => [ label, value, type ].
 *     @type string[] $channels  Channel ids, [] = all enabled.
 *     @type string   $form_key  Default 'api:custom'.
 *     @type array    $meta      Extra submission meta.
 * }
 * @return int Submission id, 0 on failure.
 */
function fnfe_send( array $args ): int {
	$args = array_merge(
		[
			'form_name' => '',
			'fields'    => [],
			'channels'  => [],
			'form_key'  => 'api:custom',
			'meta'      => [],
		],
		$args
	);

	$draft             = new Submission();
	$draft->source     = 'api';
	$draft->form_key   = mb_substr( sanitize_text_field( (string) $args['form_key'] ), 0, 64 );
	$draft->form_name  = sanitize_text_field( (string) $args['form_name'] );
	$draft->element_id = (string) substr( (string) strrchr( ':' . $draft->form_key, ':' ), 1 );

	foreach ( (array) $args['fields'] as $id => $field ) {
		$id    = sanitize_text_field( (string) $id );
		$field = is_array( $field ) && array_key_exists( 'value', $field ) ? $field : [ 'value' => $field ];
		$list  = is_array( $field['value'] ) ? $field['value'] : [ $field['value'] ];
		$list  = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return is_scalar( $item ) ? sanitize_textarea_field( (string) $item ) : '';
					},
					$list
				),
				'strlen'
			)
		);

		$draft->fields[ $id ] = [
			'id'       => $id,
			'type'     => sanitize_key( $field['type'] ?? 'text' ),
			'label'    => sanitize_text_field( (string) ( $field['label'] ?? $id ) ),
			'value'    => implode( ', ', $list ),
			'values'   => $list,
			'raw'      => is_array( $field['value'] ) ? $list : (string) ( $list[0] ?? '' ),
			'is_file'  => false,
			'redacted' => false,
			'hidden'   => false,
		];
	}

	$draft->meta                            = (array) $args['meta'];
	$draft->meta['via']                     = 'api';
	$draft->meta['editor']                  = (array) ( $draft->meta['editor'] ?? [] );
	$draft->meta['editor']['fnfe_enabled']  = 'yes';
	$draft->meta['editor']['fnfe_channels'] = array_values( array_map( 'sanitize_key', (array) $args['channels'] ) );

	$saved = Capture::ingest( $draft );
	return $saved ? $saved->id : 0;
}

/**
 * Send one text to one channel and address immediately.
 *
 * @param string $channel Channel id.
 * @param string $address Raw destination.
 * @param string $text    Plain text.
 * @param array  $args    Optional: name, subject, lang, options, buttons.
 */
function fnfe_send_message( string $channel, string $address, string $text, array $args = [] ): SendResult {
	$target = Registry::channel( $channel );
	if ( ! $target ) {
		return SendResult::failure( $channel, __( 'Unknown channel.', 'forms-notifier-for-elementor' ), true );
	}
	$normalized = $target->normalize_address( $address );
	if ( $normalized === '' ) {
		return SendResult::failure( $channel, __( 'The destination address is not valid.', 'forms-notifier-for-elementor' ), true );
	}

	$message          = new Message( $channel, new Recipient( $channel, $normalized, sanitize_text_field( (string) ( $args['name'] ?? '' ) ) ) );
	$message->lang    = (string) ( $args['lang'] ?? '' );
	$message->format  = $target->format();
	$message->subject = sanitize_text_field( (string) ( $args['subject'] ?? '' ) );
	$message->body    = Registry::formatter( $message->format )->escape( $text );
	$message->plain   = $text;
	$message->options = (array) ( $args['options'] ?? [] );
	$message->buttons = (array) ( $args['buttons'] ?? [] );

	return Dispatcher::send_now( $message );
}
