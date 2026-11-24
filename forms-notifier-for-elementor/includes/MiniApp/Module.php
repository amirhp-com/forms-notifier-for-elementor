<?php
/**
 * Mini app module.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;

defined( 'ABSPATH' ) || exit;

/** Endpoint, settings sections, help topic and wiring of the mini app. */
final class Module implements ModuleInterface {

	public const QUERY_VAR = 'fnfe_app';

	private const RULE = '^fnfe-app/(telegram|bale)/?$';

	/** Hooks. */
	public function register(): void {
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_action( 'init', [ self::class, 'rewrite' ], 20 );
		add_action( 'template_redirect', [ self::class, 'serve' ], 0 );
		add_action( 'fnfe_settings_saved', [ self::class, 'on_settings_saved' ] );
		add_action( 'fnfe_setting_updated', [ self::class, 'on_setting_updated' ] );
		add_filter( 'fnfe_settings_sections', [ self::class, 'sections' ] );
		add_filter( 'fnfe_help_topics', [ self::class, 'help' ] );
	}

	/**
	 * Public query var.
	 *
	 * @param string[] $vars Query vars.
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Add the rewrite rule; flush once after a change. */
	public static function rewrite(): void {
		if ( Settings::bool( 'miniapp.enabled' ) ) {
			add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
		if ( State::get( 'flush_rewrite' ) ) {
			State::delete( 'flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/** Print the app, or let WordPress answer 404 while it is off. */
	public static function serve(): void {
		$platform = get_query_var( self::QUERY_VAR, null );
		if ( $platform === null || $platform === '' ) {
			return;
		}
		if ( self::is_available( $platform ) ) {
			Page::render( $platform );
			exit;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * The app is on and the platform is known.
	 *
	 * @param mixed $platform Platform from the request.
	 */
	public static function is_available( $platform ): bool {
		return is_string( $platform ) && in_array( $platform, InitData::PLATFORMS, true ) && Settings::bool( 'miniapp.enabled' );
	}

	/**
	 * Settings were saved.
	 *
	 * @param string[] $paths Changed paths.
	 */
	public static function on_settings_saved( $paths ): void {
		$paths = (array) $paths;
		if ( in_array( 'miniapp.enabled', $paths, true ) ) {
			State::set( 'flush_rewrite', true );
		}
		MenuButton::on_change( $paths );
	}

	/**
	 * One setting was written.
	 *
	 * @param string $path Path.
	 */
	public static function on_setting_updated( $path ): void {
		self::on_settings_saved( [ (string) $path ] );
	}

	/**
	 * Setup state for the panel: address, direct link and what is still missing, per platform.
	 *
	 * @return array[]
	 */
	public static function setup(): array {
		$labels = [
			'telegram' => __( 'Telegram', 'forms-notifier-for-elementor' ),
			'bale'     => __( 'Bale', 'forms-notifier-for-elementor' ),
		];
		$out    = [];
		foreach ( InitData::PLATFORMS as $platform ) {
			$url    = Urls::miniapp( $platform );
			$bot    = Page::bot_url( $platform );
			$short  = preg_replace( '/[^A-Za-z0-9_]/', '', Settings::str( "channels.$platform.miniapp_short_name" ) );
			$checks = [
				'enabled' => Settings::bool( 'miniapp.enabled' ),
				'https'   => strncmp( $url, 'https://', 8 ) === 0,
				'bot'     => Settings::has_secret( "channels.$platform.token" ),
				'admins'  => count( Access::admins( $platform ) ) > 0,
			];
			$out[]  = [
				'id'          => $platform,
				'label'       => $labels[ $platform ],
				'url'         => $url,
				'bot_url'     => $bot,
				'direct_link' => $bot === '' ? '' : $bot . ( $short === '' ? '' : '/' . $short ) . '?startapp=',
				'checks'      => $checks,
				'ready'       => ! in_array( false, $checks, true ),
			];
		}
		return $out;
	}

	/**
	 * Settings sections of the tab "miniapp".
	 *
	 * @param array $sections Sections.
	 */
	public static function sections( $sections ): array {
		$sections = (array) $sections;

		$main = [
			[
				'key'   => 'miniapp.enabled',
				'type'  => 'toggle',
				'label' => __( 'Enable the mini app', 'forms-notifier-for-elementor' ),
				'desc'  => __( 'Admins of the bots can open forms, submissions and delivery problems inside Telegram and Bale.', 'forms-notifier-for-elementor' ),
			],
			[
				'key'     => 'miniapp.allow_actions',
				'type'    => 'toggle',
				'label'   => __( 'Allow actions', 'forms-notifier-for-elementor' ),
				'desc'    => __( 'Admins with the matching permission can change the status of a submission and send failed messages again.', 'forms-notifier-for-elementor' ),
				'show_if' => self::when_enabled(),
			],
			[
				'key'     => 'miniapp.show_ip',
				'type'    => 'toggle',
				'label'   => __( 'Show IP addresses', 'forms-notifier-for-elementor' ),
				'desc'    => __( 'Shows the IP address of the visitor on the submission screen.', 'forms-notifier-for-elementor' ),
				'show_if' => self::when_enabled(),
			],
		];
		if ( strncmp( Urls::miniapp( 'telegram' ), 'https://', 8 ) !== 0 ) {
			array_unshift(
				$main,
				[
					'key'   => 'miniapp.https_notice',
					'type'  => 'notice',
					'level' => 'warning',
					'label' => __( 'HTTPS is required', 'forms-notifier-for-elementor' ),
					'desc'  => __( 'Telegram and Bale open mini apps only from addresses that start with https://. This site uses http://.', 'forms-notifier-for-elementor' ),
				]
			);
		}

		$sections[] = [
			'id'       => 'miniapp_main',
			'tab'      => 'miniapp',
			'title'    => __( 'Mini App', 'forms-notifier-for-elementor' ),
			'desc'     => __( 'A small panel inside the messenger. Only the admins listed on the screen of each bot can open it.', 'forms-notifier-for-elementor' ),
			'priority' => 10,
			'fields'   => $main,
		];
		$sections[] = [
			'id'       => 'miniapp_session',
			'tab'      => 'miniapp',
			'title'    => __( 'Sessions and lists', 'forms-notifier-for-elementor' ),
			'priority' => 20,
			'fields'   => [
				[
					'key'   => 'miniapp.session_ttl',
					'type'  => 'number',
					'label' => __( 'Session length (seconds)', 'forms-notifier-for-elementor' ),
					'desc'  => __( 'After this time the app signs in again on its own.', 'forms-notifier-for-elementor' ),
					'attrs' => [
						'min'  => 300,
						'max'  => 86400,
						'step' => 60,
					],
					'width' => 'third',
				],
				[
					'key'   => 'miniapp.max_age',
					'type'  => 'number',
					'label' => __( 'Launch data age (seconds)', 'forms-notifier-for-elementor' ),
					'desc'  => __( 'Older launch data is refused. The admin has to open the app again.', 'forms-notifier-for-elementor' ),
					'attrs' => [
						'min'  => 300,
						'max'  => 604800,
						'step' => 60,
					],
					'width' => 'third',
				],
				[
					'key'   => 'miniapp.page_size',
					'type'  => 'number',
					'label' => __( 'Items per page', 'forms-notifier-for-elementor' ),
					'attrs' => [
						'min'  => 5,
						'max'  => 50,
						'step' => 1,
					],
					'width' => 'third',
				],
			],
		];
		$sections[] = [
			'id'       => 'miniapp_setup',
			'tab'      => 'miniapp',
			'title'    => __( 'Connect the app to the bots', 'forms-notifier-for-elementor' ),
			'priority' => 30,
			'fields'   => [
				[
					'key'   => 'miniapp.guide_telegram',
					'type'  => 'guide',
					'label' => __( 'Telegram', 'forms-notifier-for-elementor' ),
					'steps' => self::telegram_steps(),
				],
				[
					'key'   => 'miniapp.guide_bale',
					'type'  => 'guide',
					'label' => __( 'Bale', 'forms-notifier-for-elementor' ),
					'steps' => self::bale_steps(),
				],
			],
		];
		return $sections;
	}

	/**
	 * Help topic.
	 *
	 * @param array $topics Topics.
	 */
	public static function help( $topics ): array {
		$topics = (array) $topics;

		$topics['miniapp'] = [
			'title' => __( 'Mini App in Telegram and Bale', 'forms-notifier-for-elementor' ),
			'group' => __( 'Bots and Mini App', 'forms-notifier-for-elementor' ),
			'body'  => implode(
				'',
				[
					'<p>' . esc_html__( 'The mini app shows your forms, saved submissions, delivery problems and a short digest of the site inside the messenger. It is served by your own site; no code is loaded from another server.', 'forms-notifier-for-elementor' ) . '</p>',
					'<ul>',
					'<li>' . esc_html__( 'Turn it on under Settings, Mini App. The site needs HTTPS.', 'forms-notifier-for-elementor' ) . '</li>',
					'<li>' . esc_html__( 'Add admins on the screen of each bot. Only they can open the app; the permission "act" lets them change a status or send a message again.', 'forms-notifier-for-elementor' ) . '</li>',
					'<li>' . esc_html__( 'Somebody who is not an admin sees a screen with their numeric id, so you can add them.', 'forms-notifier-for-elementor' ) . '</li>',
					'<li>' . esc_html__( 'Buttons of the type "Mini App" open the app at the submission of the message. In groups and channels they become a direct link to the bot.', 'forms-notifier-for-elementor' ) . '</li>',
					'<li>' . esc_html__( 'Removing an admin ends their session at once. Replacing the bot token or signing out all devices ends every session.', 'forms-notifier-for-elementor' ) . '</li>',
					'</ul>',
				]
			),
			'steps' => array_merge( self::telegram_steps(), self::bale_steps() ),
		];
		return $topics;
	}

	/** Steps for Telegram. */
	private static function telegram_steps(): array {
		return [
			[
				'title' => __( 'Telegram: set the app address', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Open @BotFather, choose your bot, then Bot Settings, Configure Mini App, Enable Mini App, and send this address.', 'forms-notifier-for-elementor' ),
				'code'  => Urls::miniapp( 'telegram' ),
				'lang'  => 'text',
				'copy'  => true,
			],
			[
				'title' => __( 'Telegram: optional short name for direct links', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Send /newapp to @BotFather, choose the bot and the same address, and pick a short name. Enter that short name on the Telegram screen. Buttons in groups and channels then use a link like this.', 'forms-notifier-for-elementor' ),
				'code'  => 'https://t.me/your_bot/short_name?startapp=sub_12',
				'lang'  => 'text',
				'copy'  => false,
			],
			[
				'title' => __( 'Telegram: menu button', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Turn on "Menu button" on the Telegram screen. The plugin then puts a button next to the message field of the private chat with the bot.', 'forms-notifier-for-elementor' ),
			],
		];
	}

	/** Steps for Bale. */
	private static function bale_steps(): array {
		return [
			[
				'title' => __( 'Bale: set the app address', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'Open @botfather in Bale, choose your bot, then the mini app settings, and paste this address. The menu button is set in the same place.', 'forms-notifier-for-elementor' ),
				'code'  => Urls::miniapp( 'bale' ),
				'lang'  => 'text',
				'copy'  => true,
			],
			[
				'title' => __( 'Bale: direct link', 'forms-notifier-for-elementor' ),
				'body'  => esc_html__( 'A link of this form opens the app at one submission.', 'forms-notifier-for-elementor' ),
				'code'  => 'https://ble.ir/your_bot?startapp=sub_12',
				'lang'  => 'text',
				'copy'  => false,
			],
		];
	}

	/** Shown while the app is on. */
	private static function when_enabled(): array {
		return [
			[
				'key' => 'miniapp.enabled',
				'op'  => 'truthy',
			],
		];
	}
}
