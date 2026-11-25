<?php
/**
 * Multilingual texts and other content hooks.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\I18n;

use AmirhpCom\FormsNotifier\Channels\Email\EmailChannel;
use AmirhpCom\FormsNotifier\Channels\Webhook\PayloadBuilder;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Template\HelpTopics;

defined( 'ABSPATH' ) || exit;

/** Hooks for settings saves and language list changes; data that channels of this group need at render time. */
final class Module implements ModuleInterface {

	/** @var bool texts are registered again at the end of the request */
	private static $dirty = false;

	/** Attach hooks. */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_for_polylang' ], 20 );

		foreach ( [ 'fnfe_settings_saved', 'fnfe_setting_updated', 'fnfe_form_config_saved' ] as $hook ) {
			add_action( $hook, [ self::class, 'changed' ], 10, 0 );
		}
		add_action( 'activated_plugin', [ Strings::class, 'forget' ], 10, 0 );
		add_action( 'wpml_update_active_languages', [ self::class, 'changed' ], 10, 0 );

		add_filter( 'fnfe_message', [ PayloadBuilder::class, 'attach' ], 5, 3 );
		add_filter( 'fnfe_message', [ EmailChannel::class, 'attach_reply_to' ], 5, 3 );
		add_filter( 'fnfe_help_topics', [ HelpTopics::class, 'add' ], 20 );
	}

	/** Polylang only lists strings that are registered in the running admin request. */
	public static function register_for_polylang(): void {
		if ( is_admin() && ! wp_doing_ajax() && I18n::provider() === 'polylang' ) {
			Strings::register_all();
		}
	}

	/** A text may have changed. */
	public static function changed(): void {
		Strings::forget();
		if ( ! self::$dirty && I18n::provider() === 'wpml' ) {
			self::$dirty = true;
			add_action( 'shutdown', [ self::class, 'flush' ], 5 );
		}
	}

	/** Register once per request, after everything is saved. */
	public static function flush(): void {
		if ( self::$dirty ) {
			self::$dirty = false;
			if ( I18n::provider() === 'wpml' ) {
				Strings::sync_all();
			}
		}
	}
}
