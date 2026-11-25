<?php
/**
 * Webhook payloads.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Webhook;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** Standard, flat, IFTTT and template bodies from the structured data of a message. */
final class PayloadBuilder {

	/**
	 * Filter fnfe_message: structured data for channels of format 'json', built while the submission is at hand.
	 *
	 * @param Message    $message  Message.
	 * @param Delivery   $delivery Delivery.
	 * @param Submission $s        Submission.
	 */
	public static function attach( $message, $delivery, $s ) {
		if ( ! $message instanceof Message || $message->format !== 'json' || ! $s instanceof Submission ) {
			return $message;
		}
		if ( empty( $message->data['submission'] ) ) {
			$policy        = $delivery instanceof Delivery ? $delivery->attachment_policy : [];
			$message->data = array_merge( self::from_submission( $s, $message, $policy ), $message->data );
		}
		// Receivers use it to drop repeated deliveries, so it is made once and kept for every attempt.
		if ( empty( $message->data['delivery'] ) ) {
			$message->data['delivery'] = Crypto::token( 16 );
		}
		return $message;
	}

	/**
	 * Standard payload of a message, ready to send. Filter: fnfe_webhook_payload.
	 *
	 * @param Message $message  Message.
	 * @param array   $endpoint Endpoint.
	 */
	public static function build( Message $message, array $endpoint ): array {
		$data = $message->data;
		if ( empty( $data['submission'] ) && ! empty( $message->meta['submission_id'] ) && ! in_array( $message->purpose, [ 'alert', 'digest' ], true ) ) {
			$s = SubmissionRepository::find( (int) $message->meta['submission_id'] );
			if ( $s ) {
				$data = array_merge( self::from_submission( $s, $message ), $data );
			}
		}
		if ( empty( $data['submission'] ) && $message->purpose === 'test' ) {
			$data = array_merge( self::from_submission( Engine::sample_submission( 0, $message->lang ), $message ), $data );
		}

		$events  = [
			'test'   => 'test',
			'alert'  => 'alert',
			'digest' => 'digest',
		];
		$payload = [
			'event'    => (string) ( $data['event'] ?? $events[ $message->purpose ] ?? 'submission.created' ),
			'delivery' => self::delivery_id( $message ),
			'sent_at'  => gmdate( 'Y-m-d\TH:i:s\Z', (int) apply_filters( 'fnfe_now', time() ) ),
			'site'     => [
				'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url(),
			],
		];
		if ( isset( $data['submission'] ) ) {
			$payload['form']        = (array) ( $data['form'] ?? [] );
			$payload['submission']  = (array) $data['submission'];
			$payload['fields']      = (object) (array) ( $data['fields'] ?? [] );
			$payload['fields_meta'] = array_values( (array) ( $data['fields_meta'] ?? [] ) );
			$payload['files']       = array_values( (array) ( $data['files'] ?? [] ) );
		}
		foreach ( [ 'alert', 'digest' ] as $event ) {
			if ( isset( $data[ $event ] ) ) {
				$payload[ $event ] = $data[ $event ];
			}
		}
		$payload['text'] = (string) ( $message->plain !== '' ? $message->plain : ( $data['text'] ?? '' ) );

		$filtered = apply_filters( 'fnfe_webhook_payload', $payload, $message, $endpoint );
		return is_array( $filtered ) ? $filtered : $payload;
	}

	/**
	 * Structured data of a submission.
	 *
	 * @param Submission $s       Submission.
	 * @param Message    $message Message.
	 * @param array      $policy  Attachment policy, [] = the one of the channel.
	 */
	public static function from_submission( Submission $s, Message $message, array $policy = [] ): array {
		$fields = [];
		$meta   = [];
		foreach ( $s->fields as $id => $field ) {
			$value         = (string) ( $field['value'] ?? '' );
			$values        = array_values( (array) ( $field['values'] ?? [] ) );
			$fields[ $id ] = count( $values ) > 1 ? $values : $value;
			$meta[]        = [
				'id'    => (string) $id,
				'label' => (string) ( $field['label'] ?? $id ),
				'type'  => (string) ( $field['type'] ?? '' ),
				'value' => $value,
			];
		}

		return [
			'event'       => $message->purpose === 'test' || ( $s->meta['via'] ?? '' ) === 'test' ? 'test' : 'submission.created',
			'form'        => [
				'id'     => $s->form_id,
				'key'    => $s->form_key,
				'name'   => $s->form_name,
				'source' => $s->source,
			],
			'submission'  => [
				'id'         => $s->id,
				'uuid'       => $s->uuid,
				'status'     => $s->status,
				'lang'       => $s->lang,
				'created_at' => $s->created_gmt === '' ? '' : str_replace( ' ', 'T', $s->created_gmt ) . 'Z',
				'page_url'   => (string) ( $s->meta['page_url'] ?? '' ),
				'page_title' => (string) ( $s->meta['page_title'] ?? '' ),
				'user_id'    => (int) ( $s->meta['user_id'] ?? 0 ),
				'user_ip'    => (string) ( $s->meta['user_ip'] ?? '' ),
				'admin_url'  => $s->id ? Urls::submission_admin( $s->id ) : '',
			],
			'fields'      => $fields,
			'fields_meta' => $meta,
			'files'       => self::files( $s, $message, $policy ),
			'text'        => $message->plain,
		];
	}

	/**
	 * Flat map of a payload.
	 *
	 * @param array $payload Standard payload.
	 * @return array<string,scalar>
	 */
	public static function flat( array $payload ): array {
		$flat = [ 'event' => (string) ( $payload['event'] ?? '' ) ];
		if ( isset( $payload['submission'] ) ) {
			$form       = (array) ( $payload['form'] ?? [] );
			$submission = (array) $payload['submission'];
			$flat      += [
				'form_name'     => (string) ( $form['name'] ?? '' ),
				'form_key'      => (string) ( $form['key'] ?? '' ),
				'submission_id' => (int) ( $submission['id'] ?? 0 ),
				'page_url'      => (string) ( $submission['page_url'] ?? '' ),
				'created_at'    => (string) ( $submission['created_at'] ?? '' ),
			];
		}
		$flat['text'] = (string) ( $payload['text'] ?? '' );
		foreach ( (array) ( $payload['fields'] ?? [] ) as $id => $value ) {
			$flat[ 'field_' . $id ] = is_array( $value ) ? implode( ', ', array_filter( $value, 'is_scalar' ) ) : (string) $value;
		}
		foreach ( array_values( (array) ( $payload['files'] ?? [] ) ) as $n => $file ) {
			$flat[ 'file_' . ( $n + 1 ) . '_url' ] = (string) ( $file['url'] ?? '' );
		}
		foreach ( [ 'alert', 'digest' ] as $event ) {
			foreach ( (array) ( $payload[ $event ] ?? [] ) as $key => $value ) {
				$flat[ $event . '_' . $key ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		return $flat;
	}

	/**
	 * Body of IFTTT.
	 *
	 * @param array $payload Standard payload.
	 */
	public static function ifttt( array $payload ): array {
		$lines = [];
		foreach ( (array) ( $payload['fields_meta'] ?? [] ) as $field ) {
			if ( (string) ( $field['value'] ?? '' ) !== '' ) {
				$lines[] = $field['label'] . ': ' . $field['value'];
			}
		}
		return [
			'value1' => (string) ( $payload['form']['name'] ?? $payload['event'] ?? '' ),
			'value2' => $lines ? implode( "\n", $lines ) : (string) ( $payload['text'] ?? '' ),
			'value3' => (string) ( $payload['submission']['page_url'] ?? '' ),
		];
	}

	/**
	 * Body from a template. Values are JSON-escaped when the template is JSON.
	 *
	 * @param string  $template Template.
	 * @param array   $payload  Standard payload.
	 * @param Message $message  Message.
	 * @return array{0:string,1:string} body, content type
	 */
	public static function template( string $template, array $payload, Message $message ): array {
		$context            = Context::for_submission( self::submission( $payload ), $message->lang, 'webhook', $message->purpose );
		$context->recipient = $message->recipient;
		foreach ( [ 'alert', 'digest' ] as $event ) {
			foreach ( (array) ( $payload[ $event ] ?? [] ) as $key => $value ) {
				$context->extra[ $event . '.' . $key ] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}
		}

		$json   = preg_match( '/^\s*[{\[]/', $template ) === 1;
		$escape = static function ( string $value ) use ( $json ): string {
			if ( ! $json ) {
				return $value;
			}
			return substr( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ), 1, -1 );
		};
		return [ Engine::render_raw( $template, $context, $escape ), $json ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8' ];
	}

	/**
	 * Id of a delivery: stable across attempts.
	 *
	 * @param Message $message Message.
	 */
	public static function delivery_id( Message $message ): string {
		$id = $message->data['delivery'] ?? '';
		if ( is_string( $id ) && preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return $id;
		}
		$seed = (int) ( $message->meta['message_id'] ?? 0 );
		return $seed ? md5( home_url() . '|' . $seed ) : Crypto::token( 16 );
	}

	/**
	 * Submission rebuilt from a payload, so a template renders the same on every attempt.
	 *
	 * @param array $payload Standard payload.
	 */
	private static function submission( array $payload ): Submission {
		$form   = (array) ( $payload['form'] ?? [] );
		$meta   = (array) ( $payload['submission'] ?? [] );
		$values = (array) ( $payload['fields'] ?? [] );
		$fields = [];
		foreach ( (array) ( $payload['fields_meta'] ?? [] ) as $field ) {
			$id            = (string) ( $field['id'] ?? '' );
			$value         = $values[ $id ] ?? '';
			$fields[ $id ] = [
				'id'     => $id,
				'type'   => (string) ( $field['type'] ?? '' ),
				'label'  => (string) ( $field['label'] ?? $id ),
				'value'  => (string) ( $field['value'] ?? '' ),
				'values' => is_array( $value ) ? array_values( $value ) : ( (string) $value === '' ? [] : [ (string) $value ] ),
				'raw'    => $value,
			];
		}
		return Submission::from_array(
			[
				'id'          => (int) ( $meta['id'] ?? 0 ),
				'uuid'        => (string) ( $meta['uuid'] ?? '' ),
				'form_id'     => (int) ( $form['id'] ?? 0 ),
				'form_key'    => (string) ( $form['key'] ?? '' ),
				'form_name'   => (string) ( $form['name'] ?? '' ),
				'source'      => (string) ( $form['source'] ?? 'pro' ),
				'lang'        => (string) ( $meta['lang'] ?? '' ),
				'status'      => (string) ( $meta['status'] ?? 'new' ),
				'created_gmt' => str_replace( [ 'T', 'Z' ], [ ' ', '' ], (string) ( $meta['created_at'] ?? '' ) ),
				'fields'      => $fields,
				'files'       => array_map(
					static function ( $file ): array {
						return [
							'field_id' => (string) ( $file['field'] ?? '' ),
							'name'     => (string) ( $file['name'] ?? '' ),
							'size'     => (int) ( $file['size'] ?? 0 ),
							'mime'     => (string) ( $file['mime'] ?? '' ),
						];
					},
					array_filter( (array) ( $payload['files'] ?? [] ), 'is_array' )
				),
				'meta'        => [
					'page_url'   => (string) ( $meta['page_url'] ?? '' ),
					'page_title' => (string) ( $meta['page_title'] ?? '' ),
					'user_id'    => (int) ( $meta['user_id'] ?? 0 ),
					'user_ip'    => (string) ( $meta['user_ip'] ?? '' ),
				],
			]
		);
	}

	/**
	 * Files of a submission with download links where the policy allows them.
	 *
	 * @param Submission $s       Submission.
	 * @param Message    $message Message.
	 * @param array      $policy  Policy.
	 */
	private static function files( Submission $s, Message $message, array $policy ): array {
		$links = $message->links;
		if ( ! $links && $s->files ) {
			$channel = Registry::channel( $message->channel );
			$policy  = $policy ? $policy : Policy::resolve( $message->channel );
			if ( $channel && ( $policy['mode'] ?? 'off' ) !== 'off' ) {
				$policy['mode'] = 'link';
				$links          = (array) ( Policy::apply( $policy, $s, $channel )['links'] ?? [] );
			}
		}
		$out = [];
		foreach ( $s->files as $file ) {
			$url = '';
			foreach ( $links as $i => $link ) {
				if ( ( $link['name'] ?? '' ) === $file->name ) {
					$url = (string) ( $link['url'] ?? '' );
					unset( $links[ $i ] );
					break;
				}
			}
			$out[] = [
				'field' => $file->field_id,
				'name'  => $file->name,
				'size'  => $file->size,
				'mime'  => $file->mime,
				'url'   => $url,
			];
		}
		return $out;
	}
}
