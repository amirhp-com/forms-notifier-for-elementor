<?php
/**
 * Bot module.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Wiring of the bots: polling, reconnecting after a settings change, help topics. */
final class BotModule implements ModuleInterface {

	/** @var array<string,bool> platforms to connect again at the end of the request */
	private static $reconnect = [];

	/** @var bool */
	private static $muted = false;

	/** Hooks. */
	public function register(): void {
		add_action( Cron::POLL, [ Updates::class, 'poll_all' ] );
		add_action( Cron::DIGEST, [ Updates::class, 'check_webhooks' ], 30 );
		add_action( 'admin_init', [ Updates::class, 'sync_schedule' ] );
		add_action( 'fnfe_settings_saved', [ self::class, 'on_settings_saved' ] );
		add_action( 'update_option_' . Settings::SECRETS, [ self::class, 'on_secrets_changed' ], 10, 2 );
		add_action( 'add_option_' . Settings::SECRETS, [ self::class, 'on_secrets_added' ], 10, 2 );
		add_action( 'deleted_user', [ Linking::class, 'on_user_deleted' ] );
		add_filter( 'fnfe_help_topics', [ self::class, 'help' ] );
	}

	/**
	 * Settings were saved.
	 *
	 * @param string[] $paths Changed paths.
	 */
	public static function on_settings_saved( $paths ): void {
		foreach ( Updates::PLATFORMS as $platform ) {
			if ( in_array( "channels.$platform.admins", (array) $paths, true ) ) {
				Linking::on_admins_changed();
			}
			if ( array_intersect( [ "channels.$platform.api_base", "channels.$platform.update_mode", "channels.$platform.commands" ], (array) $paths ) ) {
				self::plan_reconnect( $platform );
			}
		}
	}

	/**
	 * Secrets were written.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public static function on_secrets_changed( $old, $new ): void {
		foreach ( Updates::PLATFORMS as $platform ) {
			$path = "channels.$platform.token";
			if ( ( is_array( $old ) ? $old[ $path ] ?? '' : '' ) !== ( is_array( $new ) ? $new[ $path ] ?? '' : '' ) ) {
				self::plan_reconnect( $platform );
			}
		}
	}

	/**
	 * Secrets were written for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public static function on_secrets_added( $option, $value ): void {
		unset( $option );
		self::on_secrets_changed( [], $value );
	}

	/**
	 * Run something that writes connection settings without connecting again.
	 *
	 * @param callable $fn Callback.
	 */
	public static function without_reconnect( callable $fn ): void {
		$muted       = self::$muted;
		self::$muted = true;
		try {
			$fn();
		} finally {
			self::$muted = $muted;
		}
	}

	/** Connect the platforms whose settings changed. */
	public static function reconnect(): void {
		Updates::finish_response();
		$platforms       = array_keys( self::$reconnect );
		self::$reconnect = [];
		Settings::clear_overlay();
		foreach ( $platforms as $platform ) {
			if ( ! BotClient::for_platform( $platform )->has_token() ) {
				continue;
			}
			$error = Updates::connect( $platform, Settings::str( "channels.$platform.update_mode", 'auto' ) );
			if ( $error !== '' ) {
				Logger::warning( 'bot', $platform . ' could not be connected', [ 'error' => $error ] );
			}
		}
	}

	/** Group of the bot topics on the Help screen. */
	public static function help_group(): string {
		return __( 'Bots and Mini App', 'forms-notifier-for-elementor' );
	}

	/**
	 * Help topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function help( $topics ): array {
		$topics = is_array( $topics ) ? $topics : [];
		$group  = self::help_group();

		$topics['bots-connect']  = [
			'title' => __( 'Connect a bot', 'forms-notifier-for-elementor' ),
			'group' => $group,
			'body'  => esc_html__( 'The same steps work for Telegram and Bale.', 'forms-notifier-for-elementor' ),
			'steps' => [
				self::step( __( 'Create the bot', 'forms-notifier-for-elementor' ), esc_html__( 'Open @BotFather in Telegram (or @botfather in Bale), create a bot and copy its token. Keep the token secret: whoever has it controls the bot.', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'Enter the token', 'forms-notifier-for-elementor' ), esc_html__( 'Open the channel in the plugin, paste the token, press "Test connection" and save. The token is stored encrypted. It can also be set in wp-config.php:', 'forms-notifier-for-elementor' ), "define( 'FNFE_CHANNELS_TELEGRAM_TOKEN', '123456789:ABC-DEF' );", 'php' ),
				self::step( __( 'Connect', 'forms-notifier-for-elementor' ), esc_html__( 'Press "Connect". On a site with a public HTTPS address the messenger calls the site (webhook). Otherwise the site asks the messenger for news every minute, which needs a working WordPress cron.', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'Choose receivers', 'forms-notifier-for-elementor' ), esc_html__( 'Find the chats as described in the next topic and add them as receivers. Then send a test.', 'forms-notifier-for-elementor' ) ),
			],
		];
		$topics['bots-chats']    = [
			'title' => __( 'Find chats and topics', 'forms-notifier-for-elementor' ),
			'group' => $group,
			'body'  => esc_html__( 'A messenger does not list the chats of a bot. The plugin learns them from the messages the bot sees.', 'forms-notifier-for-elementor' ),
			'steps' => [
				self::step( __( 'A person', 'forms-notifier-for-elementor' ), esc_html__( 'The person opens the bot and presses Start. A bot cannot write to somebody who has not done that.', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'A group', 'forms-notifier-for-elementor' ), esc_html__( 'Add the bot to the group and send /id there. A bot that is not an admin only sees commands, so an ordinary message is not enough.', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'A topic in a Telegram group', 'forms-notifier-for-elementor' ), esc_html__( 'Send /id once inside each topic, or make the bot an admin. Messages to the group itself go to the topic "General".', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'A channel', 'forms-notifier-for-elementor' ), esc_html__( 'Telegram: add the bot as an admin and publish one post. Bale does not report channels: add the bot as an admin and type the @username or the id of the channel.', 'forms-notifier-for-elementor' ) ),
				self::step( __( 'By hand', 'forms-notifier-for-elementor' ), esc_html__( 'These forms are accepted as an address:', 'forms-notifier-for-elementor' ), "123456789\n-1001234567890\n-1001234567890/45\n@channel_name\nhttps://t.me/c/1234567890/45", 'text' ),
			],
		];
		$topics['bots-commands'] = [
			'title' => __( 'Bot commands and admins', 'forms-notifier-for-elementor' ),
			'group' => $group,
			'body'  => implode(
				'<br>',
				[
					'<code>/start</code> ' . esc_html__( 'Greeting. Also used by the link that connects an admin.', 'forms-notifier-for-elementor' ),
					'<code>/id</code> ' . esc_html__( 'Id of the chat and the address to enter as a receiver.', 'forms-notifier-for-elementor' ),
					'<code>/help</code> ' . esc_html__( 'List of commands.', 'forms-notifier-for-elementor' ),
					'<code>/forms</code> ' . esc_html__( 'Forms with their number of submissions (admins).', 'forms-notifier-for-elementor' ),
					'<code>/last 5</code> ' . esc_html__( 'Latest submissions, 10 at most (admins).', 'forms-notifier-for-elementor' ),
					'<code>/stats</code> ' . esc_html__( 'Submissions and messages of today and the last 7 days (admins).', 'forms-notifier-for-elementor' ),
					'<code>/app</code> ' . esc_html__( 'Opens the Mini App (admins).', 'forms-notifier-for-elementor' ),
					esc_html__( 'In a group, admin commands are answered only when the group is a receiver.', 'forms-notifier-for-elementor' ),
					esc_html__( 'To make somebody an admin, open the bot screen, choose a WordPress user and send that person the link. It works once and for 15 minutes.', 'forms-notifier-for-elementor' ),
				]
			),
			'steps' => [],
		];
		$topics['bots-worker']   = WorkerGuide::topic();
		return $topics;
	}

	/**
	 * Connect a platform when the request ends. Only for changes made in the panel: imports, the command line and code
	 * that writes settings do not contact the messenger.
	 *
	 * @param string $platform Platform.
	 */
	private static function plan_reconnect( string $platform ): void {
		if ( self::$muted || Updates::is_connecting() || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return;
		}
		if ( ! self::$reconnect ) {
			add_action( 'shutdown', [ self::class, 'reconnect' ], 5 );
		}
		self::$reconnect[ $platform ] = true;
	}

	/**
	 * One step of a topic.
	 *
	 * @param string $title Title.
	 * @param string $body  Limited HTML.
	 * @param string $code  Code.
	 * @param string $lang  Language of the code.
	 */
	private static function step( string $title, string $body, string $code = '', string $lang = '' ): array {
		return [
			'title' => $title,
			'body'  => $body,
			'code'  => $code,
			'lang'  => $lang,
			'copy'  => $code !== '',
		];
	}
}
