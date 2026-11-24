<?php
/**
 * Bot channel that also receives updates.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Settings and actions shared by Telegram and Bale. */
abstract class AbstractUpdatesChannel extends AbstractBotChannel {

	/** Admin actions. */
	public function actions(): array {
		return [
			'receiver_options' => [ $this, 'action_receiver_options' ],
			'test_connection'  => [ $this, 'action_test_connection' ],
			'connect'          => [ $this, 'action_connect' ],
			'find_chats'       => [ $this, 'action_find_chats' ],
			'status'           => [ $this, 'action_status' ],
		];
	}

	/** Where to get a token. */
	abstract protected function token_help(): string;

	/** Platform options of the sending section. */
	abstract protected function delivery_fields(): array;

	/** Platform options of the mini app section. */
	abstract protected function app_fields(): array;

	/** How chats of this platform are found. */
	abstract protected function discovery_help(): string;

	/** Connection, sending, receiving, admins, mini app. */
	protected function own_sections(): array {
		$id = $this->id();
		return [
			[
				'id'       => $id . '_connection',
				'title'    => __( 'Connection', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => array_merge(
					$this->connection_fields( $this->token_help() ),
					[
						$this->base_field(),
						$this->field(
							'worker_guide',
							'guide',
							__( 'This server cannot reach the messenger?', 'forms-notifier-for-elementor' ),
							[
								'desc'  => __( 'Put a small forwarder between the site and the messenger. It takes about five minutes.', 'forms-notifier-for-elementor' ),
								'steps' => WorkerGuide::steps( $id ),
							]
						),
					]
				),
			],
			[
				'id'       => $id . '_receiving',
				'title'    => __( 'Receiving messages', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'Needed to find chats and topics, for commands and for buttons that change a submission.', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => [
					$this->field(
						'connection_status',
						'status',
						__( 'Status', 'forms-notifier-for-elementor' ),
						[
							'action' => [
								'channel' => $id,
								'id'      => 'status',
							],
						]
					),
					$this->field(
						'update_mode',
						'radio',
						__( 'How the bot receives messages', 'forms-notifier-for-elementor' ),
						[
							'default' => 'auto',
							'options' => [
								[
									'value' => 'auto',
									'label' => __( 'Automatic', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'Webhook when the site has a public HTTPS address, otherwise regular checks.', 'forms-notifier-for-elementor' ),
								],
								[
									'value' => 'webhook',
									'label' => __( 'Webhook', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'The messenger calls this site. Immediate; the site must be reachable from the internet.', 'forms-notifier-for-elementor' ),
								],
								[
									'value' => 'polling',
									'label' => __( 'Regular checks', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'This site asks the messenger every minute. Works behind a firewall.', 'forms-notifier-for-elementor' ),
								],
								[
									'value' => 'off',
									'label' => __( 'Off', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'The bot only sends.', 'forms-notifier-for-elementor' ),
								],
							],
						]
					),
					$this->field(
						'connect',
						'action',
						__( 'Connect the bot', 'forms-notifier-for-elementor' ),
						[
							'desc'   => __( 'Applies the choice above. Run it again after you change the token or the site address.', 'forms-notifier-for-elementor' ),
							'action' => [
								'channel' => $id,
								'id'      => 'connect',
								'label'   => __( 'Connect', 'forms-notifier-for-elementor' ),
								'send'    => [ 'token', 'api_base', 'update_mode' ],
							],
						]
					),
					$this->field(
						'find_chats',
						'action',
						__( 'Find chats', 'forms-notifier-for-elementor' ),
						[
							'desc'   => $this->discovery_help(),
							'action' => [
								'channel' => $id,
								'id'      => 'find_chats',
								'label'   => __( 'Find chats', 'forms-notifier-for-elementor' ),
							],
						]
					),
					$this->field(
						'commands',
						'toggle',
						__( 'Answer commands', 'forms-notifier-for-elementor' ),
						[
							'default' => true,
							'desc'    => __( 'Commands such as /id, /last and /stats. /start always works.', 'forms-notifier-for-elementor' ),
						]
					),
				],
			],
			[
				'id'       => $id . '_delivery',
				'title'    => __( 'Sending', 'forms-notifier-for-elementor' ),
				'priority' => 30,
				'fields'   => array_merge( $this->delivery_fields(), $this->file_fields() ),
			],
			[
				'id'       => $id . '_admins',
				'title'    => __( 'Bot admins', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'People who may use the admin commands, the buttons and the Mini App. They can also be linked to a WordPress user from the bot screen.', 'forms-notifier-for-elementor' ),
				'priority' => 40,
				'fields'   => array_merge(
					[
						$this->field(
							'admins',
							'repeater',
							__( 'Admins', 'forms-notifier-for-elementor' ),
							[
								'item_label' => 'name',
								'fields'     => [
									$this->field(
										'uid',
										'text',
										__( 'Numeric user id', 'forms-notifier-for-elementor' ),
										[
											'required' => true,
											'attrs'    => [
												'dir' => 'ltr',
												'inputmode' => 'numeric',
											],
											'desc'     => __( 'The person sends /id to the bot in a private chat to see it.', 'forms-notifier-for-elementor' ),
										]
									),
									$this->field( 'name', 'text', __( 'Name', 'forms-notifier-for-elementor' ) ),
									$this->field( 'username', 'text', __( 'Messenger username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
									$this->field( 'wp_user', 'number', __( 'WordPress user id', 'forms-notifier-for-elementor' ), [ 'default' => 0 ] ),
									$this->field(
										'perms',
										'checkboxes',
										__( 'Permissions', 'forms-notifier-for-elementor' ),
										[
											'default' => [ 'view', 'act' ],
											'options' => [
												'view' => __( 'See forms and submissions', 'forms-notifier-for-elementor' ),
												'act'  => __( 'Change submissions and send messages again', 'forms-notifier-for-elementor' ),
											],
										]
									),
								],
							]
						),
						$this->field(
							'callback_access',
							'select',
							__( 'Who may press the action buttons', 'forms-notifier-for-elementor' ),
							[
								'default' => 'admins',
								'options' => [
									'admins' => __( 'Bot admins', 'forms-notifier-for-elementor' ),
									'chat'   => __( 'Everybody in a chat that is a receiver', 'forms-notifier-for-elementor' ),
								],
							]
						),
					],
					$this->app_fields()
				),
			],
		];
	}

	/**
	 * Action receiver_options.
	 *
	 * @param array $params search, type, page.
	 */
	public function action_receiver_options( array $params ): array {
		$options = ChatDiscovery::options( $this->id(), $params );
		return [
			'ok'      => true,
			'level'   => 'info',
			'message' => $options['rows'] ? '' : $this->discovery_help(),
			'rows'    => $options['rows'],
			'data'    => [
				'total' => $options['total'],
				'page'  => $options['page'],
			],
		];
	}

	/**
	 * Action connect: checks the token and applies the mode; token, API base and mode are saved when it worked.
	 *
	 * @param array $params mode.
	 */
	public function action_connect( array $params ): array {
		$id    = $this->id();
		$mode  = is_string( $params['mode'] ?? null ) && in_array( $params['mode'], Updates::MODES, true ) ? $params['mode'] : Settings::str( "channels.$id.update_mode", 'auto' );
		$error = self::connect( $id, $mode );
		if ( $error !== '' ) {
			return [
				'ok'      => false,
				'message' => $error,
			];
		}
		return array_merge( $this->action_status( [] ), [ 'level' => 'success' ] );
	}

	/**
	 * Connect with the values in use (unsaved ones included) and save what the connection depends on.
	 *
	 * @param string $platform Platform.
	 * @param string $mode     auto | webhook | polling | off.
	 * @return string error, '' on success
	 */
	public static function connect( string $platform, string $mode ): string {
		$error = Updates::connect( $platform, $mode );
		if ( $error !== '' ) {
			return $error;
		}
		// A webhook set with a token that is not stored would point at a bot the plugin does not know.
		$token = Settings::secret( "channels.$platform.token" );
		$base  = Settings::str( "channels.$platform.api_base" );
		Settings::clear_overlay();
		BotModule::without_reconnect(
			static function () use ( $platform, $token, $base, $mode ): void {
				if ( ! Settings::secret_is_constant( "channels.$platform.token" ) && Settings::secret( "channels.$platform.token" ) !== $token ) {
					Settings::set_secret( "channels.$platform.token", $token );
				}
				Settings::set_many(
					[
						"channels.$platform.api_base"    => $base,
						"channels.$platform.update_mode" => $mode,
					]
				);
			}
		);
		return '';
	}

	/**
	 * Action find_chats.
	 *
	 * @param array $params Parameters.
	 */
	public function action_find_chats( array $params ): array {
		unset( $params );
		$result = Updates::poll( $this->id() );
		if ( ! $result['ok'] ) {
			return [
				'ok'      => false,
				'message' => $result['message'],
			];
		}
		if ( $result['message'] === '' ) {
			$result['message'] = $result['updates'] > 0
				? sprintf(
					/* translators: %1$s: number of new chats and topics, %2$s: number of updates */
					__( 'New chats and topics: %1$s. Messages read: %2$s.', 'forms-notifier-for-elementor' ),
					number_format_i18n( $result['chats'] ),
					number_format_i18n( $result['updates'] )
				)
				: __( 'Nothing new.', 'forms-notifier-for-elementor' ) . ' ' . $this->discovery_help();
		}
		return [
			'ok'      => true,
			'level'   => $result['chats'] > 0 ? 'success' : 'info',
			'message' => $result['message'],
			'data'    => [
				'updates' => $result['updates'],
				'chats'   => $result['chats'],
			],
		];
	}

	/**
	 * Action status.
	 *
	 * @param array $params Parameters.
	 */
	public function action_status( array $params ): array {
		unset( $params );
		$status = Updates::status( $this->id() );
		if ( ! $status['configured'] ) {
			return [
				'ok'      => false,
				'level'   => 'warning',
				'message' => __( 'Enter the bot token.', 'forms-notifier-for-elementor' ),
			];
		}
		$modes   = [
			'webhook' => __( 'The bot receives messages through a webhook.', 'forms-notifier-for-elementor' ),
			'polling' => __( 'The site checks for new messages every minute.', 'forms-notifier-for-elementor' ),
			'off'     => __( 'The bot does not receive messages. Press Connect to change that.', 'forms-notifier-for-elementor' ),
		];
		$message = $modes[ $status['mode'] ];
		$problem = $status['error'];
		if ( $problem === '' && $status['mode'] === 'webhook' && ! $status['webhook']['set'] ) {
			$problem = __( 'The messenger does not know the webhook. Press Connect.', 'forms-notifier-for-elementor' );
		} elseif ( $problem === '' && $status['webhook']['last_error'] !== '' && $status['webhook']['pending'] > 0 ) {
			$problem = $status['webhook']['last_error'];
		}
		if ( $status['me']['username'] !== '' ) {
			$message = '@' . $status['me']['username'] . ': ' . $message;
		}
		return [
			'ok'      => $problem === '',
			'level'   => $problem === '' ? ( $status['mode'] === 'off' ? 'info' : 'success' ) : 'warning',
			'message' => trim( $message . ' ' . $problem ),
			'data'    => $status,
		];
	}

	/** Per-form options: '' = as in the channel settings. */
	public function form_schema(): array {
		$choices = [
			''    => __( 'As in the channel settings', 'forms-notifier-for-elementor' ),
			'yes' => __( 'Yes', 'forms-notifier-for-elementor' ),
			'no'  => __( 'No', 'forms-notifier-for-elementor' ),
		];
		$fields  = [];
		foreach ( $this->form_options() as $key => $label ) {
			$fields[] = $this->field(
				$key,
				'select',
				$label,
				[
					'default' => '',
					'options' => $choices,
					'width'   => 'third',
				]
			);
		}
		return $fields;
	}

	/** Options a form can override: key => label. */
	protected function form_options(): array {
		return [];
	}
}
