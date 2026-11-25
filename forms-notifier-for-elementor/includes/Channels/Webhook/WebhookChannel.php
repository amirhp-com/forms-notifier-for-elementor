<?php
/**
 * Webhook channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Webhook;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Webhooks and automation tools. A receiver is the id of an endpoint. */
final class WebhookChannel extends AbstractChannel {

	/** Headers that custom headers cannot replace. */
	private const RESERVED = [ 'host', 'content-length', 'transfer-encoding', 'connection' ];

	/** Channel id. */
	public function id(): string {
		return 'webhook';
	}

	/** Display name. */
	public function label(): string {
		return __( 'Webhook', 'forms-notifier-for-elementor' );
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Send submissions as JSON to n8n, Make, Zapier, Pabbly, IFTTT or your own address.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'automation';
	}

	/** Icon. */
	public function icon(): string {
		return 'webhook';
	}

	/** Structured data instead of text. */
	public function format(): string {
		return 'json';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'endpoint',
			]
		);
	}

	/** Endpoints. */
	protected function own_sections(): array {
		$presets = Presets::all();
		$fields  = [
			$this->field( 'name', 'text', __( 'Name', 'forms-notifier-for-elementor' ), [ 'width' => 'half' ] ),
			$this->field(
				'enabled',
				'toggle',
				__( 'Active', 'forms-notifier-for-elementor' ),
				[
					'default' => true,
					'width'   => 'half',
				]
			),
			$this->field(
				'preset',
				'select',
				__( 'Service', 'forms-notifier-for-elementor' ),
				[
					'options' => Presets::labels(),
					'default' => 'custom',
				]
			),
		];
		foreach ( $presets as $id => $preset ) {
			$fields[] = $this->field(
				'help_' . $id,
				'notice',
				$preset['help'],
				[
					'desc'    => esc_html( $preset['placeholder'] ),
					'show_if' => [
						[
							'key'   => 'preset',
							'op'    => 'is',
							'value' => $id,
						],
					],
				]
			);
		}
		$not_plain = [
			[
				'key'   => 'auth',
				'op'    => 'is_not',
				'value' => 'none',
			],
		];
		$fields    = array_merge(
			$fields,
			[
				$this->field(
					'url',
					'url',
					__( 'Address', 'forms-notifier-for-elementor' ),
					[
						'required'    => true,
						'attrs'       => [ 'dir' => 'ltr' ],
						'placeholder' => 'https://',
					]
				),
				$this->field(
					'method',
					'select',
					__( 'Method', 'forms-notifier-for-elementor' ),
					[
						'options' => [
							'POST'  => 'POST',
							'PUT'   => 'PUT',
							'PATCH' => 'PATCH',
							'GET'   => 'GET',
						],
						'default' => 'POST',
						'width'   => 'half',
					]
				),
				$this->field(
					'format',
					'select',
					__( 'Body', 'forms-notifier-for-elementor' ),
					[
						'options' => [
							'json'     => __( 'JSON in the shape of the service', 'forms-notifier-for-elementor' ),
							'form'     => __( 'Form fields', 'forms-notifier-for-elementor' ),
							'template' => __( 'Own template', 'forms-notifier-for-elementor' ),
						],
						'default' => 'json',
						'width'   => 'half',
					]
				),
				$this->field(
					'body_template',
					'code',
					__( 'Body template', 'forms-notifier-for-elementor' ),
					[
						'desc'        => __( 'Template tags work here. When the text starts with { or [ it is sent as JSON and values are escaped for JSON.', 'forms-notifier-for-elementor' ),
						'placeholder' => '{ "name": "{field:name}", "message": "{field:message}" }',
						'attrs'       => [
							'rows' => 8,
							'dir'  => 'ltr',
						],
						'show_if'     => [
							[
								'key'   => 'format',
								'op'    => 'is',
								'value' => 'template',
							],
						],
					]
				),
				$this->field( 'headers', 'keyvalue', __( 'Extra headers', 'forms-notifier-for-elementor' ) ),
				$this->field(
					'auth',
					'select',
					__( 'Authentication', 'forms-notifier-for-elementor' ),
					[
						'options' => [
							'none'   => __( 'None', 'forms-notifier-for-elementor' ),
							'basic'  => __( 'User name and password', 'forms-notifier-for-elementor' ),
							'bearer' => __( 'Bearer token', 'forms-notifier-for-elementor' ),
							'header' => __( 'Own header', 'forms-notifier-for-elementor' ),
						],
						'default' => 'none',
						'width'   => 'third',
					]
				),
				$this->field(
					'auth_user',
					'text',
					__( 'User name or header name', 'forms-notifier-for-elementor' ),
					[
						'width'   => 'third',
						'attrs'   => [ 'dir' => 'ltr' ],
						'show_if' => [
							[
								'key'   => 'auth',
								'op'    => 'in',
								'value' => [ 'basic', 'header' ],
							],
						],
					]
				),
				$this->field(
					'auth_secret',
					'secret',
					__( 'Password, token or header value', 'forms-notifier-for-elementor' ),
					[
						'width'   => 'third',
						'show_if' => $not_plain,
					]
				),
				$this->field( 'sign_secret', 'secret', __( 'Signing secret', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'When set, every request carries a signature your server can check. See the help screen.', 'forms-notifier-for-elementor' ) ] ),
				$this->field(
					'timeout',
					'number',
					__( 'Timeout in seconds', 'forms-notifier-for-elementor' ),
					[
						'default' => 20,
						'attrs'   => [
							'min' => 1,
							'max' => 60,
						],
						'width'   => 'half',
					]
				),
			]
		);

		return [
			[
				'id'     => 'webhook_endpoints',
				'title'  => __( 'Endpoints', 'forms-notifier-for-elementor' ),
				'desc'   => __( 'Add an endpoint for every address, then choose below which of them receive every form.', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field(
						'endpoints',
						'repeater',
						__( 'Endpoints', 'forms-notifier-for-elementor' ),
						[
							'fields'     => $fields,
							'item_label' => 'name',
						]
					),
				],
			],
		];
	}

	/** Problems. */
	public function problems(): array {
		foreach ( $this->endpoints() as $endpoint ) {
			if ( ! empty( $endpoint['enabled'] ) && (string) ( $endpoint['url'] ?? '' ) !== '' ) {
				return [];
			}
		}
		return [ __( 'Add at least one active endpoint.', 'forms-notifier-for-elementor' ) ];
	}

	/**
	 * Endpoint id. Endpoints and receivers may be saved in one request, so only the shape is checked here.
	 *
	 * @param string $raw     Raw id.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$raw = trim( $raw );
		return preg_match( '/^[A-Za-z0-9_]{1,40}$/', $raw ) ? $raw : '';
	}

	/** Picker rows. */
	public function actions(): array {
		return [ 'receiver_options' => [ $this, 'receiver_options' ] ];
	}

	/**
	 * Endpoints for the receivers picker.
	 *
	 * @param array $params search.
	 */
	public function receiver_options( array $params ): array {
		$search = mb_strtolower( trim( is_scalar( $params['search'] ?? '' ) ? (string) ( $params['search'] ?? '' ) : '' ) );
		$labels = Presets::labels();
		$rows   = [];
		foreach ( $this->endpoints() as $endpoint ) {
			$id    = (string) ( $endpoint['id'] ?? '' );
			$name  = (string) ( $endpoint['name'] ?? '' );
			$host  = (string) wp_parse_url( (string) ( $endpoint['url'] ?? '' ), PHP_URL_HOST );
			$label = $name === '' ? ( $host === '' ? $id : $host ) : $name;
			if ( $id === '' || ( $search !== '' && strpos( mb_strtolower( $label . ' ' . $host ), $search ) === false ) ) {
				continue;
			}
			$preset = (string) ( $endpoint['preset'] ?? 'custom' );
			$rows[] = [
				'address' => $id,
				'label'   => $label,
				'group'   => $labels[ $preset ] ?? $preset,
				'hint'    => $host,
				'meta'    => [ 'preset' => $preset ],
			];
		}
		return [
			'ok'      => true,
			'message' => $rows ? '' : __( 'Add an endpoint first.', 'forms-notifier-for-elementor' ),
			'level'   => 'info',
			'rows'    => $rows,
		];
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			return $this->deliver( $message );
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
	}

	/**
	 * Request of a message to its endpoint.
	 *
	 * @param Message $message Message.
	 */
	private function deliver( Message $message ): SendResult {
		$endpoint = $this->endpoint( $message->recipient->address );
		if ( ! $endpoint ) {
			return $this->fail( __( 'This endpoint does not exist any more.', 'forms-notifier-for-elementor' ), true );
		}
		if ( empty( $endpoint['enabled'] ) ) {
			return $this->fail( __( 'This endpoint is switched off.', 'forms-notifier-for-elementor' ), true );
		}
		$url = (string) ( $endpoint['url'] ?? '' );
		if ( ! preg_match( '~^https?://[^/\s]+~i', $url ) ) {
			return $this->fail( __( 'The address of this endpoint is not valid.', 'forms-notifier-for-elementor' ), true );
		}
		if ( self::is_private( $url ) && ! Settings::bool( 'advanced.allow_private_hosts' ) ) {
			return $this->fail( __( 'This address is on a private network. Allow private hosts in the advanced settings if that is intended.', 'forms-notifier-for-elementor' ), true );
		}

		$method              = in_array( $endpoint['method'] ?? '', [ 'POST', 'PUT', 'PATCH', 'GET' ], true ) ? $endpoint['method'] : 'POST';
		$payload             = PayloadBuilder::build( $message, $endpoint );
		list( $body, $type ) = $this->body( $payload, $endpoint, $message );
		if ( $method === 'GET' ) {
			$url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( PayloadBuilder::flat( $payload ), '', '&', PHP_QUERY_RFC3986 );
			$body = '';
		}

		$args = [
			'headers'     => $this->headers( $endpoint, $message, $payload, $body, $method === 'GET' ? '' : $type ),
			'timeout'     => max( 1, min( 60, (int) ( $endpoint['timeout'] ?? 20 ) ) ),
			'redirection' => 0,
		];
		if ( $method !== 'GET' ) {
			$args['body'] = $body;
		}
		return $this->result( Http::request( $method, $url, $args ) );
	}

	/**
	 * Raw body and content type.
	 *
	 * @param array   $payload  Standard payload.
	 * @param array   $endpoint Endpoint.
	 * @param Message $message  Message.
	 * @return array{0:string,1:string}
	 */
	private function body( array $payload, array $endpoint, Message $message ): array {
		$format = (string) ( $endpoint['format'] ?? 'json' );
		if ( $format === 'template' && trim( (string) ( $endpoint['body_template'] ?? '' ) ) !== '' ) {
			return PayloadBuilder::template( (string) $endpoint['body_template'], $payload, $message );
		}
		if ( $format === 'form' ) {
			return [ http_build_query( PayloadBuilder::flat( $payload ), '', '&' ), 'application/x-www-form-urlencoded; charset=utf-8' ];
		}
		$shape = Presets::shape( (string) ( $endpoint['preset'] ?? 'custom' ) );
		if ( $shape === 'flat' ) {
			$payload = PayloadBuilder::flat( $payload );
		} elseif ( $shape === 'ifttt' ) {
			$payload = PayloadBuilder::ifttt( $payload );
		}
		return [ (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ), 'application/json; charset=utf-8' ];
	}

	/**
	 * Request headers. Filter: fnfe_webhook_headers.
	 *
	 * @param array   $endpoint Endpoint.
	 * @param Message $message  Message.
	 * @param array   $payload  Standard payload.
	 * @param string  $body     Raw body.
	 * @param string  $type     Content type.
	 */
	private function headers( array $endpoint, Message $message, array $payload, string $body, string $type ): array {
		$headers = [ 'User-Agent' => 'FormsNotifier/' . FNFE_VERSION ];
		if ( $type !== '' ) {
			$headers['Content-Type'] = $type;
		}
		$headers = self::add_headers( $headers, (array) ( $endpoint['headers'] ?? [] ) );

		$secret = $this->secret( 'endpoints.' . $endpoint['id'] . '.auth_secret' );
		$user   = (string) ( $endpoint['auth_user'] ?? '' );
		switch ( $endpoint['auth'] ?? 'none' ) {
			case 'basic':
				$headers = self::add_headers( $headers, [ 'Authorization' => Http::basic_auth( $user, $secret ) ] );
				break;
			case 'bearer':
				$headers = self::add_headers( $headers, [ 'Authorization' => 'Bearer ' . $secret ] );
				break;
			case 'header':
				$headers = self::add_headers( $headers, [ $user => $secret ] );
				break;
		}

		$filtered = apply_filters( 'fnfe_webhook_headers', $headers, $message, $endpoint );
		$headers  = self::add_headers( [], is_array( $filtered ) ? $filtered : $headers );

		$timestamp = (int) apply_filters( 'fnfe_now', time() );

		$headers['X-FNFE-Event']     = self::clean( (string) ( $payload['event'] ?? '' ) );
		$headers['X-FNFE-Delivery']  = PayloadBuilder::delivery_id( $message );
		$headers['X-FNFE-Timestamp'] = (string) $timestamp;
		if ( isset( $payload['delivery'] ) && is_string( $payload['delivery'] ) && preg_match( '/^[a-f0-9]{32}$/', $payload['delivery'] ) ) {
			$headers['X-FNFE-Delivery'] = $payload['delivery'];
		}
		$sign = $this->secret( 'endpoints.' . $endpoint['id'] . '.sign_secret' );
		if ( $sign !== '' ) {
			$headers['X-FNFE-Signature'] = Signer::sign( $sign, $timestamp, $body );
		}
		return $headers;
	}

	/**
	 * Map the answer.
	 *
	 * @param array $response Output of Http::request().
	 */
	private function result( array $response ): SendResult {
		$response['body'] = mb_substr( (string) $response['body'], 0, 500 );
		$result           = $this->from_http( $response );
		$code             = (int) $response['code'];
		if ( $result->ok ) {
			return $result;
		}
		if ( $code === 410 ) {
			$result->error = __( 'This endpoint is gone (410). In Make this means that no scenario is connected to the webhook.', 'forms-notifier-for-elementor' );
		} elseif ( $code === 425 ) {
			$result->permanent = false;
		} elseif ( $code >= 300 && $code < 400 ) {
			$result->permanent = true;
			$result->error     = __( 'The endpoint redirects to another address. Enter the final address.', 'forms-notifier-for-elementor' );
		}
		return $result;
	}

	/**
	 * Stored endpoints.
	 *
	 * @return array[]
	 */
	private function endpoints(): array {
		return array_values( array_filter( Settings::arr( $this->key( 'endpoints' ) ), 'is_array' ) );
	}

	/**
	 * One endpoint.
	 *
	 * @param string $id Endpoint id.
	 */
	private function endpoint( string $id ): ?array {
		foreach ( $this->endpoints() as $endpoint ) {
			if ( $id !== '' && (string) ( $endpoint['id'] ?? '' ) === $id ) {
				return $endpoint;
			}
		}
		return null;
	}

	/**
	 * Add headers; names are compared without case, reserved and broken ones are dropped.
	 *
	 * @param array $headers Headers.
	 * @param array $more    Name => value.
	 */
	private static function add_headers( array $headers, array $more ): array {
		foreach ( $more as $name => $value ) {
			$name = trim( (string) $name );
			$low  = strtolower( $name );
			if ( ! is_scalar( $value ) || ! preg_match( '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name ) || in_array( $low, self::RESERVED, true ) || strncmp( $low, 'x-fnfe-', 7 ) === 0 ) {
				continue;
			}
			foreach ( array_keys( $headers ) as $existing ) {
				if ( strtolower( (string) $existing ) === $low ) {
					unset( $headers[ $existing ] );
				}
			}
			$headers[ $name ] = self::clean( (string) $value );
		}
		return $headers;
	}

	/**
	 * Header value on one line.
	 *
	 * @param string $value Value.
	 */
	private static function clean( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}

	/**
	 * The host is plainly local. Hosts that resolve to a private address are refused by the WordPress HTTP API.
	 *
	 * @param string $url URL.
	 */
	private static function is_private( string $url ): bool {
		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[].' ) );
		if ( $host === '' || $host === 'localhost' || preg_match( '/\.(localhost|local|internal|lan|home|test)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
		}
		return false;
	}
}
