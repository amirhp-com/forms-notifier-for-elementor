<?php
/**
 * Channel routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface;
use AmirhpCom\FormsNotifier\Contracts\WhatsAppProviderInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** /channels and /providers. */
final class ChannelsController extends Controller {

	private const ID = '(?P<id>[a-z0-9_]+)';

	/** Register the routes. */
	public function register_routes(): void {
		$id_arg = [
			'id' => $this->string_arg( '', [], 'sanitize_key' ),
		];

		register_rest_route(
			self::NS,
			'/channels',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'index' ],
				'permission_callback' => $this->can( ...Capabilities::all() ),
				'args'                => [],
			]
		);
		register_rest_route(
			self::NS,
			'/channels/' . self::ID,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'show' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => $id_arg,
			]
		);
		register_rest_route(
			self::NS,
			'/channels/' . self::ID . '/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => $id_arg + [ 'to' => self::raw_arg() ] + $this->overlay_args(),
			]
		);
		register_rest_route(
			self::NS,
			'/channels/' . self::ID . '/action/(?P<action>[a-z0-9_]+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'action' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => $id_arg + [
					'action' => $this->string_arg( '', [], 'sanitize_key' ),
					'params' => $this->object_arg(),
				] + $this->overlay_args(),
			]
		);
		register_rest_route(
			self::NS,
			'/providers/(?P<channel>[a-z0-9_]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'providers' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [ 'channel' => $this->string_arg( '', [], 'sanitize_key' ) ],
			]
		);
		register_rest_route(
			self::NS,
			'/providers/(?P<channel>[a-z0-9_]+)/(?P<provider>[a-z0-9_]+)/(?P<action>test|status)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'provider_action' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [
					'channel'  => $this->string_arg( '', [], 'sanitize_key' ),
					'provider' => $this->string_arg( '', [], 'sanitize_key' ),
					'action'   => $this->string_arg( '', [ 'test', 'status' ], 'sanitize_key' ),
					'to'       => self::raw_arg(),
					'text'     => $this->string_arg( '', [], 'sanitize_textarea_field' ),
					'pattern'  => [
						'type'              => [ 'string', 'object' ],
						'default'           => '',
						'sanitize_callback' => static function ( $value ) {
							return is_array( $value ) ? $value : sanitize_textarea_field( (string) $value );
						},
						'validate_callback' => static function ( $value ): bool {
							return is_array( $value ) || is_scalar( $value );
						},
					],
				] + $this->overlay_args(),
			]
		);
	}

	/** All channels. */
	public function index(): \WP_REST_Response {
		return rest_ensure_response( [ 'items' => array_map( [ self::class, 'channel_meta' ], array_values( Registry::channels() ) ) ] );
	}

	/**
	 * One channel with its schema.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$channel = Registry::channel( (string) $request['id'] );
		if ( ! $channel ) {
			return $this->unknown_channel();
		}
		return rest_ensure_response(
			[
				'channel'     => self::channel_meta( $channel ),
				'sections'    => self::client_sections( Schema::sections_for( $channel->id() ) ),
				'form_fields' => self::client_fields( $channel->form_schema() ),
			]
		);
	}

	/**
	 * Send a test message, with unsaved values if given.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$channel = Registry::channel( (string) $request['id'] );
		if ( ! $channel ) {
			return $this->unknown_channel();
		}

		$this->apply_overlay( $request );
		try {
			$id       = $channel->id();
			$to       = trim( (string) $request['to'] );
			$receiver = [];
			if ( $to === '' ) {
				if ( ( $channel->capabilities()['receivers'] ?? 'list' ) === 'none' ) {
					$to = 'default';
				} else {
					$receivers = array_filter(
						Settings::arr( "channels.$id.receivers" ),
						static function ( $row ): bool {
							return is_array( $row ) && ! empty( $row['enabled'] );
						}
					);
					$receiver  = $receivers ? reset( $receivers ) : [];
					$to        = (string) ( $receiver['address'] ?? '' );
				}
			}
			if ( $to === '' ) {
				return $this->result( false, __( 'Add a receiver first, or type an address.', 'forms-notifier-for-elementor' ), 'warning' );
			}
			$address = $to === 'default' || Settings::is_placeholder( $to ) ? $to : $channel->normalize_address( $to );
			if ( $address === '' ) {
				return $this->result( false, __( 'The destination address is not valid.', 'forms-notifier-for-elementor' ) );
			}

			$recipient       = new Recipient( $id, $address, (string) ( $receiver['label'] ?? '' ), 'test' );
			$recipient->lang = (string) ( $receiver['lang'] ?? '' );
			$recipient->meta = array_merge( (array) ( $receiver['meta'] ?? [] ), isset( $receiver['id'] ) ? [ 'rid' => $receiver['id'] ] : [] );

			$result = $channel->test( $recipient );
			self::log_test( $id, $recipient, $result );
			return $this->send_result( $result );
		} catch ( \Throwable $e ) {
			Logger::error( 'test', $e->getMessage(), [ 'channel' => (string) $request['id'] ] );
			return $this->result( false, Logger::clean( $e->getMessage() ) );
		} finally {
			Settings::clear_overlay();
		}
	}

	/**
	 * Run an action of a channel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function action( \WP_REST_Request $request ) {
		$channel = Registry::channel( (string) $request['id'] );
		if ( ! $channel ) {
			return $this->unknown_channel();
		}
		$actions = $channel->actions();
		$action  = $actions[ (string) $request['action'] ] ?? null;
		if ( ! is_callable( $action ) ) {
			return $this->error( 'unknown_action', __( 'Unknown action.', 'forms-notifier-for-elementor' ), 404 );
		}

		$this->apply_overlay( $request );
		try {
			return rest_ensure_response( $this->action_result( $action( (array) $request['params'] ) ) );
		} catch ( \Throwable $e ) {
			Logger::error( 'action', $e->getMessage(), [ 'channel' => $channel->id() ] );
			return $this->result( false, Logger::clean( $e->getMessage() ) );
		} finally {
			Settings::clear_overlay();
		}
	}

	/**
	 * Providers behind a channel.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function providers( \WP_REST_Request $request ): \WP_REST_Response {
		$channel = (string) $request['channel'];
		$items   = [];
		foreach ( Registry::providers( $channel ) as $id => $provider ) {
			$items[] = [
				'id'         => $id,
				'label'      => $provider->label(),
				'region'     => $provider->region(),
				'configured' => $provider->is_configured(),
				'problems'   => array_values( $provider->problems() ),
				'supports'   => (object) self::supports( $provider ),
				'panel_url'  => $provider->panel_url(),
				'fields'     => self::client_fields( $provider->fields(), "channels.$channel.providers.$id." ),
			];
		}
		return rest_ensure_response( [ 'items' => $items ] );
	}

	/**
	 * Test a provider or read its status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function provider_action( \WP_REST_Request $request ) {
		$channel  = (string) $request['channel'];
		$provider = Registry::provider( $channel, (string) $request['provider'] );
		if ( ! $provider ) {
			return $this->error( 'unknown_provider', __( 'Unknown gateway.', 'forms-notifier-for-elementor' ), 404 );
		}

		$this->apply_overlay( $request );
		try {
			return $request['action'] === 'status'
				? $this->provider_status( $channel, $provider, (bool) ( $request['values'] || $request['secrets'] ) )
				: $this->provider_test( $channel, $provider, $request );
		} catch ( \Throwable $e ) {
			Logger::error( 'provider', $e->getMessage(), [ 'provider' => $provider->id() ] );
			return $this->result( false, Logger::clean( $e->getMessage() ) );
		} finally {
			Settings::clear_overlay();
		}
	}

	/**
	 * Cached status line.
	 *
	 * @param string            $channel  Channel id.
	 * @param ProviderInterface $provider Provider.
	 * @param bool              $fresh    Unsaved values are in use.
	 */
	private function provider_status( string $channel, ProviderInterface $provider, bool $fresh ): \WP_REST_Response {
		$key    = 'fnfe_status_' . $channel . '_' . $provider->id();
		$status = $fresh ? false : get_transient( $key );
		if ( ! is_string( $status ) ) {
			$status = $provider->status();
			if ( $status === null ) {
				return $this->result( true, __( 'This gateway does not report its status.', 'forms-notifier-for-elementor' ), 'info' );
			}
			if ( ! $fresh ) {
				set_transient( $key, $status, 5 * MINUTE_IN_SECONDS );
			}
		}
		return $this->result( true, Logger::clean( $status ), 'info' );
	}

	/**
	 * Send a test through one provider.
	 *
	 * @param string            $channel  Channel id.
	 * @param ProviderInterface $provider Provider.
	 * @param \WP_REST_Request  $request  Request.
	 */
	private function provider_test( string $channel, ProviderInterface $provider, \WP_REST_Request $request ): \WP_REST_Response {
		$target = Registry::channel( $channel );
		$raw    = (string) $request['to'];
		$to     = $target ? $target->normalize_address( $raw ) : (string) Phone::normalize( $raw );
		if ( $to === '' ) {
			return $this->result( false, __( 'The destination address is not valid.', 'forms-notifier-for-elementor' ) );
		}
		if ( ! $provider instanceof SmsProviderInterface && ! $provider instanceof WhatsAppProviderInterface ) {
			return $this->result( false, __( 'This gateway cannot send a test.', 'forms-notifier-for-elementor' ) );
		}

		$text = (string) $request['text'];
		if ( $text === '' ) {
			/* translators: %1$s: site name */
			$text = sprintf( __( 'Test message from %1$s.', 'forms-notifier-for-elementor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}

		$message          = new Message( $channel, new Recipient( $channel, $to, '', 'test' ) );
		$message->purpose = 'test';
		$message->format  = $target ? $target->format() : 'text';
		$message->body    = Registry::formatter( $message->format )->escape( $text );
		$message->plain   = $text;
		$message->pattern = self::pattern( $request['pattern'] );
		$message->meta    = [ 'test' => true ];

		$result = $provider->send( $to, $message );
		self::log_test( $channel, $message->recipient, $result );
		return $this->send_result( $result );
	}

	/**
	 * Pattern given as text or as [ code, vars ].
	 *
	 * @param mixed $raw Request param.
	 */
	private static function pattern( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = $raw === '' ? [] : PatternText::parse( $raw );
		}
		$code = is_array( $raw ) && is_scalar( $raw['code'] ?? null ) ? sanitize_text_field( (string) $raw['code'] ) : '';
		if ( $code === '' ) {
			return [];
		}
		$vars = [];
		foreach ( is_array( $raw['vars'] ?? null ) ? $raw['vars'] : [] as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$vars[ is_int( $name ) ? $name : sanitize_text_field( $name ) ] = sanitize_text_field( (string) $value );
			}
		}
		return [
			'code'  => $code,
			'vars'  => $vars,
			'lang'  => is_scalar( $raw['lang'] ?? null ) ? sanitize_text_field( (string) $raw['lang'] ) : '',
			'codes' => [],
		];
	}

	/**
	 * Optional features of a provider.
	 *
	 * @param ProviderInterface $provider Provider.
	 */
	private static function supports( ProviderInterface $provider ): array {
		if ( $provider instanceof SmsProviderInterface ) {
			return [
				'pattern'   => $provider->supports_pattern(),
				'countries' => array_values( $provider->countries() ),
			];
		}
		if ( $provider instanceof WhatsAppProviderInterface ) {
			return [
				'template' => $provider->supports_template(),
				'media'    => $provider->supports_media(),
			];
		}
		return [];
	}

	/**
	 * Log a test as a message row.
	 *
	 * @param string     $channel   Channel id.
	 * @param Recipient  $recipient Recipient.
	 * @param SendResult $result    Result.
	 */
	private static function log_test( string $channel, Recipient $recipient, SendResult $result ): void {
		MessageRepository::insert(
			[
				'channel'         => $channel,
				'provider'        => $result->provider,
				'purpose'         => 'test',
				'recipient'       => $recipient->address,
				'recipient_label' => $recipient->name,
				'lang'            => $recipient->lang,
				'status'          => $result->ok ? 'sent' : 'failed',
				'attempts'        => 1,
				'max_attempts'    => 1,
				'response'        => $result->response,
				'error'           => $result->error,
				'remote_id'       => $result->remote_id,
				'http_code'       => $result->http_code,
				'sent_gmt'        => $result->ok ? gmdate( 'Y-m-d H:i:s' ) : null,
			]
		);
	}

	/**
	 * ActionResult of a send.
	 *
	 * @param SendResult $result Result.
	 */
	private function send_result( SendResult $result ): \WP_REST_Response {
		if ( $result->ok ) {
			return $this->result( true, __( 'The test message was sent.', 'forms-notifier-for-elementor' ), 'success', [ 'remote_id' => $result->remote_id ] );
		}
		$message = $result->error === '' ? __( 'The test message could not be sent.', 'forms-notifier-for-elementor' ) : $result->error;
		return $this->result( false, $message, 'danger', [ 'http_code' => $result->http_code ] );
	}

	/**
	 * ActionResult response.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @param string $level   Level, '' = by $ok.
	 * @param array  $data    Data.
	 */
	private function result( bool $ok, string $message, string $level = '', array $data = [] ): \WP_REST_Response {
		return rest_ensure_response(
			$this->action_result(
				[
					'ok'      => $ok,
					'message' => $message,
					'level'   => $level,
					'data'    => $data,
				]
			)
		);
	}

	/** 404. */
	private function unknown_channel(): \WP_Error {
		return $this->error( 'unknown_channel', __( 'Unknown channel.', 'forms-notifier-for-elementor' ), 404 );
	}

	/** Arg schema of an address: checked by the channel, kept as typed (URLs may hold encoded characters). */
	private static function raw_arg(): array {
		return [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => [ FieldSanitizer::class, 'secret' ],
			'validate_callback' => 'rest_validate_request_arg',
		];
	}
}
