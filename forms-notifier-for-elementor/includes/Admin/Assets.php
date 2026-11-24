<?php
/**
 * Assets.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Admin;

use AmirhpCom\FormsNotifier\Rest\MetaController;

defined( 'ABSPATH' ) || exit;

/** Registers the fixed asset list. Missing files are skipped. */
final class Assets {

	private const WIDGETS = [ 'receivers', 'template', 'buttons', 'rules', 'guide' ];

	private const SCREENS = [ 'dashboard', 'channels', 'channel', 'forms', 'form', 'submissions', 'submission', 'log', 'bots', 'miniapp', 'alerts', 'digest', 'settings', 'tools', 'help' ];

	/** @var bool */
	private static $registered = false;

	/** Register every handle once. Safe on the front end (mini app page). */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		self::style( 'fnfe-tokens', 'assets/css/tokens.css' );
		self::style( 'fnfe-admin', 'assets/css/admin.css', [ 'fnfe-tokens' ] );
		self::style( 'fnfe-fonts-fa', 'assets/css/fonts-fa.css' );
		self::style( 'fnfe-editor-css', 'assets/css/editor.css' );

		self::script( 'fnfe-core', 'assets/js/core/fnfe.js', [ 'wp-i18n', 'wp-hooks' ] );
		foreach ( self::admin_scripts() as $handle => $script ) {
			self::script( $handle, $script[0], $script[1] );
		}
		self::script( 'fnfe-editor', 'assets/js/editor/editor.js', [ 'jquery', 'wp-i18n' ] );
	}

	/** Enqueue the admin panel. */
	public static function enqueue_admin(): void {
		self::register();

		wp_enqueue_style( 'fnfe-tokens' );
		if ( wp_style_is( 'fnfe-admin', 'registered' ) ) {
			wp_enqueue_style( 'fnfe-admin' );
		}
		if ( Admin::is_persian() && wp_style_is( 'fnfe-fonts-fa', 'registered' ) ) {
			wp_enqueue_style( 'fnfe-fonts-fa' );
		}

		wp_enqueue_script( 'fnfe-core' );
		wp_add_inline_script(
			'fnfe-core',
			'window.fnfeBoot = ' . wp_json_encode( MetaController::bootstrap(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
		foreach ( array_keys( self::admin_scripts() ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}
	}

	/** Scripts of the panel: handle => [ file, dependencies ]. Dependencies on missing files are dropped. */
	private static function admin_scripts(): array {
		$scripts = [
			'fnfe-icons'      => [ 'assets/js/admin/icons.js', [ 'fnfe-core' ] ],
			'fnfe-components' => [ 'assets/js/admin/components.js', [ 'fnfe-icons' ] ],
			'fnfe-fields'     => [ 'assets/js/admin/fields.js', [ 'fnfe-components' ] ],
		];
		foreach ( self::WIDGETS as $name ) {
			$scripts[ 'fnfe-widget-' . $name ] = [ 'assets/js/admin/widgets/' . $name . '.js', [ 'fnfe-fields' ] ];
		}
		foreach ( self::SCREENS as $name ) {
			$scripts[ 'fnfe-screen-' . $name ] = [ 'assets/js/admin/screens/' . $name . '.js', [ 'fnfe-fields' ] ];
		}

		$existing = array_filter(
			$scripts,
			static function ( array $script ): bool {
				return is_file( FNFE_DIR . $script[0] );
			}
		);
		foreach ( $existing as $handle => $script ) {
			$existing[ $handle ][1] = array_values(
				array_filter(
					$script[1],
					static function ( string $dep ) use ( $existing ): bool {
						return $dep === 'fnfe-core' || isset( $existing[ $dep ] );
					}
				)
			) ?: [ 'fnfe-core' ];
		}
		if ( is_file( FNFE_DIR . 'assets/js/admin/app.js' ) ) {
			$existing['fnfe-app'] = [ 'assets/js/admin/app.js', array_merge( [ 'fnfe-core' ], array_keys( $existing ) ) ];
		}
		return $existing;
	}

	/**
	 * Register a style if its file exists.
	 *
	 * @param string   $handle Handle.
	 * @param string   $file   Path inside the plugin.
	 * @param string[] $deps   Dependencies.
	 */
	private static function style( string $handle, string $file, array $deps = [] ): void {
		if ( is_file( FNFE_DIR . $file ) ) {
			wp_register_style( $handle, FNFE_URL . $file, $deps, self::version( $file ) );
		}
	}

	/**
	 * Register a script with translations if its file exists.
	 *
	 * @param string   $handle Handle.
	 * @param string   $file   Path inside the plugin.
	 * @param string[] $deps   Dependencies.
	 */
	private static function script( string $handle, string $file, array $deps = [] ): void {
		if ( ! is_file( FNFE_DIR . $file ) ) {
			return;
		}
		wp_register_script(
			$handle,
			FNFE_URL . $file,
			$deps,
			self::version( $file ),
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_set_script_translations( $handle, 'forms-notifier-for-elementor', FNFE_DIR . 'languages' );
	}

	/**
	 * Cache-busting version.
	 *
	 * @param string $file Path inside the plugin.
	 */
	private static function version( string $file ): string {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? FNFE_VERSION . '.' . filemtime( FNFE_DIR . $file ) : FNFE_VERSION;
	}
}
