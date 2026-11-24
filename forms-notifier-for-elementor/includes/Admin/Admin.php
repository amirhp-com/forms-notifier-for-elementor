<?php
/**
 * Admin panel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Admin;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Rest\MetaController;

defined( 'ABSPATH' ) || exit;

/** Menu, page shell, body classes, plugin row links. */
final class Admin {

	/** Menu slug. */
	public const SLUG = 'fnfe';

	/** @var string[] hook suffixes of the plugin screens */
	private static $hooks = [];

	/** Attach hooks. */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'welcome_redirect' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'in_admin_header', [ self::class, 'remove_notices' ], 1000 );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_filter( 'plugin_action_links_' . FNFE_BASENAME, [ self::class, 'action_links' ] );
	}

	/** Add the menu. */
	public static function menu(): void {
		$parent = self::lowest_capability();
		if ( $parent === '' ) {
			return;
		}

		self::$hooks[] = (string) add_menu_page(
			__( 'Forms Notifier', 'forms-notifier-for-elementor' ),
			__( 'Forms Notifier', 'forms-notifier-for-elementor' ),
			$parent,
			self::SLUG,
			[ self::class, 'render' ],
			self::menu_icon(),
			58.6
		);
		foreach ( self::screens( $parent ) as $slug => $screen ) {
			self::$hooks[] = (string) add_submenu_page(
				self::SLUG,
				$screen['title'],
				$screen['title'],
				$screen['cap'],
				$slug,
				[ self::class, 'render' ]
			);
		}
	}

	/** Page shell; the panel itself is built by JavaScript. */
	public static function render(): void {
		$slug    = self::current_slug();
		$screens = self::screens( self::lowest_capability() );
		$route   = $screens[ $slug ]['route'] ?? 'dashboard';
		$ui      = MetaController::ui();

		printf(
			'<div id="fnfe-app" class="fnfe-root" data-route="%1$s" data-theme="%2$s" data-font="%3$s" dir="%4$s"><noscript>%5$s</noscript></div>',
			esc_attr( $route ),
			esc_attr( $ui['theme'] ),
			esc_attr( self::is_persian() ? 'fa' : '' ),
			esc_attr( is_rtl() ? 'rtl' : 'ltr' ),
			esc_html__( 'This screen needs JavaScript.', 'forms-notifier-for-elementor' )
		);
	}

	/** The current admin screen belongs to the plugin. */
	public static function is_plugin_screen(): bool {
		$slug = self::current_slug();
		return $slug !== '' && ( $slug === self::SLUG || isset( self::screens( '' )[ $slug ] ) );
	}

	/** The panel of this user is Persian. */
	public static function is_persian(): bool {
		return strpos( get_user_locale(), 'fa' ) === 0;
	}

	/** Enqueue the panel on its own screens only. */
	public static function enqueue(): void {
		if ( self::is_plugin_screen() ) {
			Assets::enqueue_admin();
		}
	}

	/** Notices of other plugins have no place inside the panel. */
	public static function remove_notices(): void {
		if ( self::is_plugin_screen() ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	/**
	 * Body class of the plugin screens.
	 *
	 * @param string $classes Classes.
	 */
	public static function body_class( $classes ): string {
		return (string) $classes . ( self::is_plugin_screen() ? ' fnfe-screen ' : '' );
	}

	/**
	 * Links in the plugins list.
	 *
	 * @param array $links Links.
	 */
	public static function action_links( $links ): array {
		$links = (array) $links;
		$own   = [];
		if ( Capabilities::can( Capabilities::MANAGE ) ) {
			$own['fnfe_settings'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Urls::admin( 'settings' ) ), esc_html__( 'Settings', 'forms-notifier-for-elementor' ) );
		}
		$own['fnfe_docs'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Urls::admin( 'help' ) ), esc_html__( 'Docs', 'forms-notifier-for-elementor' ) );
		return $own + $links;
	}

	/** Open the panel once after a single activation. */
	public static function welcome_redirect(): void {
		if ( ! get_transient( 'fnfe_activated' ) ) {
			return;
		}
		delete_transient( 'fnfe_activated' );
		if ( wp_doing_ajax() || is_network_admin() || ! Capabilities::can( Capabilities::MANAGE ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides about a redirect.
			return;
		}
		wp_safe_redirect( Urls::admin() );
		exit;
	}

	/**
	 * Submenu screens: slug => title, route, capability.
	 *
	 * @param string $any Capability of the screens everybody with access sees.
	 */
	private static function screens( string $any ): array {
		return [
			'fnfe'             => [
				'title' => __( 'Dashboard', 'forms-notifier-for-elementor' ),
				'route' => 'dashboard',
				'cap'   => $any,
			],
			'fnfe-channels'    => [
				'title' => __( 'Channels', 'forms-notifier-for-elementor' ),
				'route' => 'channels',
				'cap'   => Capabilities::map( Capabilities::MANAGE ),
			],
			'fnfe-forms'       => [
				'title' => __( 'Forms', 'forms-notifier-for-elementor' ),
				'route' => 'forms',
				'cap'   => Capabilities::map( Capabilities::FORMS ),
			],
			'fnfe-submissions' => [
				'title' => __( 'Submissions', 'forms-notifier-for-elementor' ),
				'route' => 'submissions',
				'cap'   => Capabilities::map( Capabilities::SUBMISSIONS ),
			],
			'fnfe-log'         => [
				'title' => __( 'Log', 'forms-notifier-for-elementor' ),
				'route' => 'log',
				'cap'   => Capabilities::map( Capabilities::LOGS ),
			],
			'fnfe-settings'    => [
				'title' => __( 'Settings', 'forms-notifier-for-elementor' ),
				'route' => 'settings',
				'cap'   => Capabilities::map( Capabilities::MANAGE ),
			],
			'fnfe-help'        => [
				'title' => __( 'Help', 'forms-notifier-for-elementor' ),
				'route' => 'help',
				'cap'   => $any,
			],
		];
	}

	/** First mapped capability the user has, '' when there is none. */
	private static function lowest_capability(): string {
		foreach ( Capabilities::all() as $cap ) {
			if ( Capabilities::can( $cap ) ) {
				return Capabilities::map( $cap );
			}
		}
		return '';
	}

	/** Requested admin page. */
	private static function current_slug(): string {
		return isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads the screen name only.
	}

	/** Speech bubble crossed by an arrow. */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" stroke="black" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M17 10.5V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h1v3l3.5-3"/><path d="M8 11 17 14.5M17 14.5 14 12M17 14.5 13.5 16"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- add_menu_page() expects a base64 data URI.
	}
}
