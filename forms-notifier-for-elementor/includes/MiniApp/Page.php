<?php
/**
 * Mini app document.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Admin\Assets;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/** Prints the page. It is public and the same for everyone: no nonce, no user data, no secrets. */
final class Page {

	/** @var bool */
	private static $registered = false;

	/**
	 * Send headers and print the document.
	 *
	 * @param string $platform telegram | bale.
	 */
	public static function render( string $platform ): void {
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared by caching plugins.

		if ( ! headers_sent() ) {
			status_header( 200 );
			foreach ( self::headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
			// The web clients of the messengers show the page in a frame.
			header_remove( 'X-Frame-Options' );
		}

		self::register_assets( $platform );
		// Only the assets of the plugin belong on this page.
		remove_all_actions( 'wp_print_scripts' );

		self::document( $platform );
	}

	/** Response headers. */
	public static function headers(): array {
		return [
			'Content-Type'           => 'text/html; charset=UTF-8',
			'Cache-Control'          => 'no-store, private',
			'X-Robots-Tag'           => 'noindex, nofollow',
			'Referrer-Policy'        => 'no-referrer',
			'X-Content-Type-Options' => 'nosniff',
		];
	}

	/**
	 * Public configuration of the page.
	 *
	 * @param string $platform telegram | bale.
	 */
	public static function config( string $platform ): array {
		$config = [
			'app'       => true,
			'platform'  => $platform,
			'rest'      => [ 'root' => Urls::rest() ],
			'site'      => [
				'name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'lang'     => I18n::default_lang(),
				'locale'   => determine_locale(),
				'rtl'      => is_rtl(),
				'calendar' => Controller::calendar(),
				'timezone' => wp_timezone_string(),
			],
			'bot'       => self::bot_url( $platform ),
			'version'   => FNFE_VERSION,
			'page_size' => Settings::int( 'miniapp.page_size', 20 ),
			'strings'   => (object) [],
		];
		return (array) apply_filters( 'fnfe_miniapp_config', $config, $platform );
	}

	/**
	 * Chat with the bot, '' while the bot is not connected.
	 *
	 * @param string $platform telegram | bale.
	 */
	public static function bot_url( string $platform ): string {
		$username = State::get( "bot.$platform.me.username", '' );
		if ( ! is_string( $username ) || ! preg_match( '/^[A-Za-z0-9_]{3,64}$/', $username ) ) {
			return '';
		}
		return ( $platform === 'bale' ? 'https://ble.ir/' : 'https://t.me/' ) . $username;
	}

	/**
	 * Register the assets of the page.
	 *
	 * @param string $platform telegram | bale.
	 */
	private static function register_assets( string $platform ): void {
		Assets::register();
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		wp_register_style( 'fnfe-miniapp-css', FNFE_URL . 'assets/css/miniapp.css', [ 'fnfe-tokens' ], self::version( 'assets/css/miniapp.css' ) );
		$scripts = [
			'fnfe-miniapp-bridge' => [ 'assets/js/miniapp/bridge.js', [ 'fnfe-core' ] ],
			'fnfe-miniapp'        => [ 'assets/js/miniapp/app.js', [ 'fnfe-core', 'fnfe-miniapp-bridge', 'wp-i18n' ] ],
		];
		foreach ( $scripts as $handle => $script ) {
			wp_register_script(
				$handle,
				FNFE_URL . $script[0],
				$script[1],
				self::version( $script[0] ),
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
			wp_set_script_translations( $handle, 'forms-notifier-for-elementor', FNFE_DIR . 'languages' );
		}
		wp_add_inline_script(
			'fnfe-core',
			'window.fnfeBoot = ' . wp_json_encode( self::config( $platform ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * Print the document.
	 *
	 * @param string $platform telegram | bale.
	 */
	private static function document( string $platform ): void {
		$persian = strncmp( determine_locale(), 'fa', 2 ) === 0;
		$styles  = [ 'fnfe-tokens', 'fnfe-miniapp-css' ];
		if ( $persian && wp_style_is( 'fnfe-fonts-fa', 'registered' ) ) {
			$styles[] = 'fnfe-fonts-fa';
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
	<meta name="robots" content="noindex, nofollow">
	<meta name="format-detection" content="telephone=no">
	<title><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></title>
		<?php wp_print_styles( $styles ); ?>
</head>
<body class="fnfe-root fnfe-miniapp" data-theme="auto" data-platform="<?php echo esc_attr( $platform ); ?>" data-font="<?php echo esc_attr( $persian ? 'fa' : '' ); ?>">
	<div id="fnfe-miniapp"></div>
	<noscript><p class="fnfe-mini-noscript"><?php esc_html_e( 'This page needs JavaScript.', 'forms-notifier-for-elementor' ); ?></p></noscript>
		<?php wp_print_scripts( [ 'fnfe-miniapp' ] ); ?>
</body>
</html>
		<?php
	}

	/**
	 * Cache-busting version.
	 *
	 * @param string $file Path inside the plugin.
	 */
	private static function version( string $file ): string {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && is_file( FNFE_DIR . $file ) ? FNFE_VERSION . '.' . filemtime( FNFE_DIR . $file ) : FNFE_VERSION;
	}
}
