<?php
/**
 * Boot sequence.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier;

use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Installer;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Upgrades;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together. Modules register their own hooks.
 */
final class Plugin {

	/** Module classes, in boot order. Missing classes are skipped. */
	const MODULES = [
		\AmirhpCom\FormsNotifier\I18n\Module::class,
		\AmirhpCom\FormsNotifier\Elementor\Module::class,
		\AmirhpCom\FormsNotifier\Privacy\Privacy::class,
		\AmirhpCom\FormsNotifier\Delivery\Module::class,
		\AmirhpCom\FormsNotifier\Health\SiteHealth::class,
		\AmirhpCom\FormsNotifier\Channels\Bot\BotModule::class,
		\AmirhpCom\FormsNotifier\Channels\Sms\SmsModule::class,
		\AmirhpCom\FormsNotifier\Channels\WhatsApp\WhatsAppModule::class,
		\AmirhpCom\FormsNotifier\MiniApp\Module::class,
		\AmirhpCom\FormsNotifier\Admin\HelpContent::class,
	];

	/** @var bool */
	private static $booted = false;

	/** Runs on plugins_loaded, priority 5. */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Upgrades::maybe_run();
		Cron::register();

		do_action( 'fnfe_before_modules' );

		foreach ( self::MODULES as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}
			$module = new $class_name();
			if ( $module instanceof ModuleInterface ) {
				$module->register();
			}
		}

		add_action( 'init', [ self::class, 'load_textdomain' ], 0 );
		add_action( 'init', [ self::class, 'on_init' ], 1 );
		add_action( 'rest_api_init', [ Rest\Routes::class, 'register' ] );
		add_action( 'wp_initialize_site', [ Installer::class, 'on_new_site' ], 20 );
		add_action( 'wp_uninitialize_site', [ Installer::class, 'on_delete_site' ], 5 );

		if ( is_admin() ) {
			if ( ! Core\Text::has_mbstring() ) {
				add_action( 'admin_notices', [ Health\SiteHealth::class, 'mbstring_notice' ] );
			}
			Admin\Admin::register();
			Admin\Notices::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( Cli\Commands::class ) ) {
			\WP_CLI::add_command( 'fnfe', Cli\Commands::class );
		}

		do_action( 'fnfe_loaded' );
	}

	/** Before 6.7 WordPress does not load bundled translations just in time. */
	public static function load_textdomain(): void {
		if ( version_compare( get_bloginfo( 'version' ), '6.7', '<' ) ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Needed on 6.5 and 6.6 only.
			load_plugin_textdomain( 'forms-notifier-for-elementor', false, dirname( FNFE_BASENAME ) . '/languages' );
		}
	}

	/** Registry boot, then the recurring events. */
	public static function on_init(): void {
		Registry::boot();
		// Anything cached before every channel was known is rebuilt on demand.
		Schema::flush();
		Settings::flush_cache();
		Cron::ensure_events();
	}
}
