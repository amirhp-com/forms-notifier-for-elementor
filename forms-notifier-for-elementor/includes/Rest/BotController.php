<?php
/**
 * Bot routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Channels\Bot\AbstractUpdatesChannel;
use AmirhpCom\FormsNotifier\Channels\Bot\ChatDiscovery;
use AmirhpCom\FormsNotifier\Channels\Bot\Linking;
use AmirhpCom\FormsNotifier\Channels\Bot\Updates;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;

defined( 'ABSPATH' ) || exit;

/** /bot/{platform}/hook/{secret} (public, secret) and /bots/... */
final class BotController extends Controller {

	private const PLATFORM = '(?P<platform>telegram|bale)';

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/bot/' . self::PLATFORM . '/hook/(?P<secret>[A-Za-z0-9_-]{1,128})',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'hook' ],
				'permission_callback' => [ $this, 'can_hook' ],
				'args'                => $this->platform_args() + [ 'secret' => $this->string_arg() ],
			]
		);
		$this->route( '/status', \WP_REST_Server::READABLE, 'status' );
		$this->route(
			'/connect',
			\WP_REST_Server::CREATABLE,
			'connect',
			[ 'mode' => $this->string_arg( 'auto', Updates::MODES, 'sanitize_key' ) ] + $this->overlay_args()
		);
		$this->route( '/poll', \WP_REST_Server::CREATABLE, 'poll' );
		$this->route(
			'/chats',
			\WP_REST_Server::READABLE,
			'chats',
			[
				'type'        => $this->string_arg( '', [ '', 'private', 'group', 'supergroup', 'channel' ], 'sanitize_key' ),
				'status'      => $this->string_arg( '', [ '', 'active', 'left', 'kicked', 'migrated', 'closed' ], 'sanitize_key' ),
				'search'      => $this->string_arg(),
				'with_topics' => [
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				],
			] + $this->paging_args(),
			[ Capabilities::MANAGE, Capabilities::FORMS ]
		);
		$this->route(
			'/chats/(?P<id>\d+)',
			'PATCH',
			'rename_chat',
			[
				'id'         => $this->id_arg(),
				'title'      => $this->name_arg(),
				'topic_name' => $this->name_arg(),
			]
		);
		$this->route( '/chats/(?P<id>\d+)', \WP_REST_Server::DELETABLE, 'delete_chat', [ 'id' => $this->id_arg() ] );
		$this->route(
			'/link',
			\WP_REST_Server::CREATABLE,
			'link',
			[
				'user_id' => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				],
			]
		);
		$this->route( '/admins', \WP_REST_Server::READABLE, 'admins' );
		$this->route( '/admins/(?P<uid>\d{1,20})', \WP_REST_Server::DELETABLE, 'delete_admin', [ 'uid' => $this->string_arg() ] );
	}

	/**
	 * Path secret and, for Telegram, the header secret.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_hook( \WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'x_telegram_bot_api_secret_token' );
		$url    = $request->get_url_params();
		return Updates::verify( (string) ( $url['platform'] ?? '' ), (string) ( $url['secret'] ?? '' ), $header )
			? true
			: new \WP_Error( 'fnfe_forbidden', __( 'Forbidden.', 'forms-notifier-for-elementor' ), [ 'status' => 403 ] );
	}

	/**
	 * Accept an update; it is processed after the response.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function hook( \WP_REST_Request $request ): \WP_REST_Response {
		Updates::receive( (string) $request->get_url_params()['platform'], (string) $request->get_body() );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Status.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Updates::status( (string) $request['platform'] ) );
	}

	/**
	 * Connect with the given values; they are saved when it worked.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function connect( \WP_REST_Request $request ): \WP_REST_Response {
		$platform = (string) $request['platform'];
		$this->apply_overlay( $request );
		try {
			$error = AbstractUpdatesChannel::connect( $platform, (string) $request['mode'] );
			// After a failure the status of the stored connection is shown, with the reason of the failure.
			$status = Updates::status( $platform, $error === '' );
		} finally {
			Settings::clear_overlay();
		}
		if ( $error !== '' ) {
			$status['error'] = $error;
		}
		return rest_ensure_response( $status );
	}

	/**
	 * Fetch updates now.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function poll( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Updates::poll( (string) $request['platform'] ) );
	}

	/**
	 * Discovered chats.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function chats( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->list_response(
			ChatRepository::query(
				[
					'platform'    => (string) $request['platform'],
					'type'        => (string) $request['type'],
					'status'      => (string) $request['status'],
					'search'      => (string) $request['search'],
					'with_topics' => (bool) $request['with_topics'],
					'page'        => (int) $request['page'],
					'per_page'    => (int) $request['per_page'],
				]
			),
			[ ChatDiscovery::class, 'client_row' ]
		);
	}

	/**
	 * Rename a chat or topic. An empty name shows the messenger's name again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rename_chat( \WP_REST_Request $request ) {
		$row = $this->chat( $request );
		if ( ! $row ) {
			return $this->unknown_chat();
		}
		$meta = $row['meta'];
		foreach ( [
			'title'      => 'custom_title',
			'topic_name' => 'custom_topic',
		] as $param => $key ) {
			if ( $request->has_param( $param ) && $request[ $param ] !== null ) {
				$meta[ $key ] = mb_substr( (string) $request[ $param ], 0, 191 );
			}
		}
		ChatRepository::upsert( $row['platform'], $row['chat_id'], $row['thread_id'], [ 'meta' => array_filter( $meta, [ self::class, 'is_filled' ] ) ] );
		return rest_ensure_response(
			[
				'ok'   => true,
				'chat' => ChatDiscovery::client_row( (array) ChatRepository::find( $row['id'] ) ),
			]
		);
	}

	/**
	 * Forget a chat with its topics, or one topic.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_chat( \WP_REST_Request $request ) {
		$row = $this->chat( $request );
		if ( ! $row ) {
			return $this->unknown_chat();
		}
		if ( $row['thread_id'] === 0 ) {
			foreach ( ChatRepository::topics( $row['platform'], $row['chat_id'] ) as $topic ) {
				ChatRepository::delete( $topic['id'] );
			}
		}
		return rest_ensure_response( [ 'ok' => ChatRepository::delete( $row['id'] ) ] );
	}

	/**
	 * Deep link that links a messenger account to a WordPress user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link( \WP_REST_Request $request ) {
		$user_id = (int) $request['user_id'] > 0 ? (int) $request['user_id'] : get_current_user_id();
		if ( ! get_userdata( $user_id ) || ( is_multisite() && ! is_user_member_of_blog( $user_id ) && ! is_super_admin( $user_id ) ) ) {
			return $this->error( 'unknown_user', __( 'Unknown user.', 'forms-notifier-for-elementor' ), 404 );
		}
		$platform = (string) $request['platform'];
		$link     = Linking::create( $platform, $user_id );
		if ( $link['url'] === '' && Updates::identify( $platform ) === '' ) {
			$link['url'] = Linking::deep_link( $platform, $link['code'] );
		}
		return rest_ensure_response( $link );
	}

	/**
	 * Bot admins.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function admins( \WP_REST_Request $request ): \WP_REST_Response {
		$items = [];
		foreach ( Linking::admins( (string) $request['platform'] ) as $admin ) {
			$user    = $admin['wp_user'] > 0 ? get_userdata( $admin['wp_user'] ) : false;
			$items[] = [
				'uid'      => $admin['uid'],
				'name'     => $admin['name'],
				'username' => $admin['username'],
				'wp_user'  => $admin['wp_user'],
				'wp_name'  => $user ? $user->display_name : '',
				'perms'    => $admin['perms'],
			];
		}
		return rest_ensure_response( [ 'items' => $items ] );
	}

	/**
	 * Remove an admin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_admin( \WP_REST_Request $request ) {
		if ( ! Linking::remove( (string) $request['platform'], (string) $request['uid'] ) ) {
			return $this->error( 'unknown_admin', __( 'This admin does not exist.', 'forms-notifier-for-elementor' ), 404 );
		}
		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * Register one /bots/{platform} route.
	 *
	 * @param string   $path     Path below the platform.
	 * @param string   $method   HTTP method.
	 * @param string   $callback Method of this class.
	 * @param array    $args     Args.
	 * @param string[] $caps     Plugin capabilities, any of them.
	 */
	private function route( string $path, string $method, string $callback, array $args = [], array $caps = [ Capabilities::MANAGE ] ): void {
		register_rest_route(
			self::NS,
			'/bots/' . self::PLATFORM . $path,
			[
				'methods'             => $method,
				'callback'            => [ $this, $callback ],
				'permission_callback' => $this->can( ...$caps ),
				'args'                => $this->platform_args() + $args,
			]
		);
	}

	/** Arg schema of the platform. */
	private function platform_args(): array {
		return [ 'platform' => $this->string_arg( '', Updates::PLATFORMS, 'sanitize_key' ) ];
	}

	/** Arg schema of a row id. */
	private function id_arg(): array {
		return [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/** Arg schema of an optional name. */
	private function name_arg(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/**
	 * Chat row of a request, null when it belongs to another platform.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function chat( \WP_REST_Request $request ): ?array {
		$row = ChatRepository::find( (int) $request['id'] );
		return $row && $row['platform'] === (string) $request['platform'] ? $row : null;
	}

	/** 404. */
	private function unknown_chat(): \WP_Error {
		return $this->error( 'unknown_chat', __( 'This chat does not exist.', 'forms-notifier-for-elementor' ), 404 );
	}

	/**
	 * Keep a meta entry.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_filled( $value ): bool {
		return $value !== '' && $value !== null;
	}
}
