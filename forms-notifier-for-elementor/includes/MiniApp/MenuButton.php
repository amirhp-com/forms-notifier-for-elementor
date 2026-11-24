<?php
/**
 * Menu button of the Telegram bot.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;

defined( 'ABSPATH' ) || exit;

/** Points the menu button of the Telegram bot to the mini app. Bale has no API for it. */
final class MenuButton {

	/** Settings that decide what the button does. */
	private const PATHS = [ 'miniapp.enabled', 'channels.telegram.menu_button', 'channels.telegram.enabled' ];

	/** @var bool */
	private static $planned = false;

	/**
	 * A setting changed: sync after the response when it matters.
	 *
	 * @param string[] $paths Changed paths.
	 */
	public static function on_change( array $paths ): void {
		if ( ! self::$planned && array_intersect( self::PATHS, $paths ) ) {
			self::$planned = true;
			add_action( 'shutdown', [ self::class, 'sync' ] );
		}
	}

	/** Set or reset the button. Nothing is sent when it is already right or was never set by the plugin. */
	public static function sync(): void {
		self::$planned = false;

		$want = self::wanted_url();
		$have = State::get( 'miniapp_menu', '' );
		if ( $want === ( is_string( $have ) ? $have : '' ) ) {
			return;
		}
		$client = BotClient::for_platform( 'telegram' );
		if ( ! $client->has_token() ) {
			return;
		}
		$response = $client->set_menu_button(
			$want === ''
				? [ 'type' => 'default' ]
				: [
					'type'    => 'web_app',
					'text'    => __( 'Open', 'forms-notifier-for-elementor' ),
					'web_app' => [ 'url' => $want ],
				]
		);
		if ( empty( $response['ok'] ) ) {
			Logger::warning( 'miniapp', __( 'The menu button of the Telegram bot could not be changed.', 'forms-notifier-for-elementor' ), [ 'error' => (string) ( $response['error'] ?? '' ) ] );
			return;
		}
		State::set( 'miniapp_menu', $want );
	}

	/** URL the button should open, '' = standard button. */
	private static function wanted_url(): string {
		if ( ! Settings::bool( 'miniapp.enabled' ) || ! Settings::bool( 'channels.telegram.enabled' ) || ! Settings::bool( 'channels.telegram.menu_button' ) ) {
			return '';
		}
		$url = Urls::miniapp( 'telegram' );
		return strncmp( $url, 'https://', 8 ) === 0 ? $url : '';
	}
}
