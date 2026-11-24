<?php
/**
 * Custom HTTP request.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * An HTTP endpoint described by the admin; used by the custom gateway of SMS and of WhatsApp.
 *
 * Placeholders are replaced in one pass, so a value that looks like a placeholder is never replaced again, and every
 * value is encoded for the place it goes to.
 */
trait CustomRequest {

	/**
	 * Settings fields.
	 *
	 * @param string[] $placeholders Placeholders to list.
	 */
	protected function request_fields( array $placeholders ): array {
		return [
			$this->field(
				'url',
				'text',
				__( 'Address', 'forms-notifier-for-elementor' ),
				[
					'desc'        => __( 'Starts with https:// and may hold placeholders.', 'forms-notifier-for-elementor' ),
					'placeholder' => 'https://api.example.com/send',
					'attrs'       => [ 'dir' => 'ltr' ],
				]
			),
			$this->field(
				'method',
				'select',
				__( 'Method', 'forms-notifier-for-elementor' ),
				[
					'default' => 'POST',
					'width'   => 'half',
					'options' => [
						'POST' => 'POST',
						'GET'  => 'GET',
						'PUT'  => 'PUT',
					],
				]
			),
			$this->field(
				'format',
				'select',
				__( 'Format', 'forms-notifier-for-elementor' ),
				[
					'default' => 'json',
					'width'   => 'half',
					'options' => [
						'json'  => __( 'JSON body', 'forms-notifier-for-elementor' ),
						'form'  => __( 'Form body', 'forms-notifier-for-elementor' ),
						'query' => __( 'Query string', 'forms-notifier-for-elementor' ),
					],
				]
			),
			$this->field( 'token', 'secret', __( 'API key', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Optional. Stored encrypted; use it as {token}.', 'forms-notifier-for-elementor' ) ] ),
			$this->field( 'headers', 'keyvalue', __( 'Headers', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'body',
				'code',
				__( 'Body', 'forms-notifier-for-elementor' ),
				[
					'desc'        => sprintf(
						/* translators: %1$s: list of placeholders */
						__( 'JSON text, or name=value pairs joined with & for the form and query formats. Placeholders: %1$s', 'forms-notifier-for-elementor' ),
						'<code>' . implode( '</code> <code>', $placeholders ) . '</code>'
					),
					'placeholder' => '{"to":"{to}","text":"{text}"}',
					'attrs'       => [
						'dir'  => 'ltr',
						'rows' => 6,
					],
				]
			),
			$this->field(
				'success_contains',
				'text',
				__( 'The answer must contain', 'forms-notifier-for-elementor' ),
				[
					'desc'  => __( 'Optional. Without any check, a status from 200 to 299 counts as sent.', 'forms-notifier-for-elementor' ),
					'attrs' => [ 'dir' => 'ltr' ],
				]
			),
			$this->field(
				'success_path',
				'text',
				__( 'JSON field to check', 'forms-notifier-for-elementor' ),
				[
					'placeholder' => 'data.status',
					'width'       => 'half',
					'attrs'       => [ 'dir' => 'ltr' ],
				]
			),
			$this->field(
				'success_value',
				'text',
				__( 'Expected value of that field', 'forms-notifier-for-elementor' ),
				[
					'width' => 'half',
					'attrs' => [ 'dir' => 'ltr' ],
				]
			),
		];
	}

	/** The address is set. */
	public function is_configured(): bool {
		return preg_match( '~^https?://[^/\s{}]+~i', $this->option( 'url' ) ) === 1;
	}

	/** Problems. */
	public function problems(): array {
		if ( $this->is_configured() ) {
			return [];
		}
		return [
			sprintf(
				/* translators: %1$s: gateway name */
				__( '%1$s: enter an address that starts with https://.', 'forms-notifier-for-elementor' ),
				$this->label()
			),
		];
	}

	/**
	 * Values of the number placeholders.
	 *
	 * @param string $to E.164.
	 * @return array<string,string>
	 */
	protected function number_values( string $to ): array {
		return [
			'to'        => $to,
			'to_local'  => Phone::is_iran( $to ) ? Phone::iran_local( $to ) : Phone::digits( $to ),
			'to_digits' => Phone::digits( $to ),
			'token'     => $this->secret( 'token' ),
		];
	}

	/**
	 * Send.
	 *
	 * @param string $body   Body template.
	 * @param array  $values Placeholder => value.
	 */
	protected function request( string $body, array $values ): SendResult {
		$method  = in_array( $this->option( 'method' ), [ 'GET', 'PUT' ], true ) ? $this->option( 'method' ) : 'POST';
		$format  = in_array( $this->option( 'format' ), [ 'form', 'query' ], true ) ? $this->option( 'format' ) : 'json';
		$url     = $this->fill( $this->option( 'url' ), $values, 'url' );
		$headers = [];
		foreach ( (array) $this->setting( 'headers', [] ) as $name => $value ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $name );
			if ( $name !== '' && is_scalar( $value ) ) {
				// Line breaks would start another header.
				$headers[ $name ] = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $this->fill( (string) $value, $values, 'header' ) ) );
			}
		}

		$args = [];
		if ( $format === 'query' || ( $method === 'GET' && $format === 'form' ) ) {
			$query = $this->fill( trim( (string) preg_replace( '/\s*\R\s*/', '&', trim( $body ) ), '&?' ), $values, 'url' );
			$url  .= $query === '' ? '' : ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
		} elseif ( $method !== 'GET' ) {
			if ( $format === 'json' ) {
				$text = $this->fill( $body, $values, 'json' );
				if ( json_decode( $text ) === null && trim( $text ) !== 'null' ) {
					return $this->fail( __( 'The body is not valid JSON. Write placeholders inside quotation marks.', 'forms-notifier-for-elementor' ), true );
				}
				$type = 'application/json; charset=utf-8';
			} else {
				$text = $this->fill( trim( (string) preg_replace( '/\s*\R\s*/', '&', trim( $body ) ), '&' ), $values, 'url' );
				$type = 'application/x-www-form-urlencoded; charset=utf-8';
			}
			$args['body'] = $text;
			if ( ! array_key_exists( 'content-type', array_change_key_case( $headers ) ) ) {
				$headers['Content-Type'] = $type;
			}
		}
		$args['headers'] = $headers;
		return $this->check( $this->http( $method, $url, $args ) );
	}

	/**
	 * Apply the success rule. A failed check is final: the endpoint may have sent the message already.
	 *
	 * @param array $r Response.
	 */
	private function check( array $r ): SendResult {
		$contains = $this->option( 'success_contains' );
		$path     = $this->option( 'success_path' );
		$error    = $this->first_text( $r['json'], [ 'error.message', 'error', 'message' ] );
		if ( ! $r['ok'] ) {
			return $this->outcome( $r, false, '', $error );
		}
		if ( $contains !== '' && strpos( (string) $r['body'], $contains ) === false ) {
			return $this->outcome( $r, false, '', __( 'The answer does not contain the expected text.', 'forms-notifier-for-elementor' ) );
		}
		if ( $path !== '' ) {
			$value = $this->dig( $r['json'], $path );
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			if ( ! is_scalar( $value ) || (string) $value !== $this->option( 'success_value' ) ) {
				return $this->outcome( $r, false, '', __( 'The answer does not have the expected value.', 'forms-notifier-for-elementor' ) );
			}
		}
		return $this->outcome( $r, true, $this->first_text( $r['json'], [ 'id', 'message_id', 'messageId', 'data.id' ] ) );
	}

	/**
	 * Replace placeholders once.
	 *
	 * @param string $template Template.
	 * @param array  $values   Placeholder => value.
	 * @param string $target   url | json | header.
	 */
	private function fill( string $template, array $values, string $target ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+(?::[A-Za-z0-9_.\-]+)?)\}/',
			static function ( array $m ) use ( $values, $target ): string {
				$name = $m[1];
				if ( ! array_key_exists( $name, $values ) ) {
					// An unknown variable is empty, any other braces belong to the template.
					return strncmp( $name, 'var:', 4 ) === 0 ? '' : $m[0];
				}
				$value = (string) $values[ $name ];
				if ( $target === 'url' ) {
					return rawurlencode( $value );
				}
				if ( $target === 'json' ) {
					return substr( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 1, -1 );
				}
				return $value;
			},
			$template
		);
	}
}
