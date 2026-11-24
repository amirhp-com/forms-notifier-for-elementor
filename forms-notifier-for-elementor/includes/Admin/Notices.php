<?php
/**
 * Admin notices.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Admin;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Rest\MetaController;

defined( 'ABSPATH' ) || exit;

/** Missing dependency and welcome notices, on the Plugins screen only. */
final class Notices {

	private const DISMISS = 'fnfe_dismiss_welcome';

	/** Attach hooks. */
	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::DISMISS, [ self::class, 'dismiss' ] );
	}

	/** Print what applies. */
	public static function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}
		if ( current_user_can( 'activate_plugins' ) ) {
			$missing = self::missing_dependency();
			if ( $missing !== '' ) {
				printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $missing ) );
			}
		}
		if ( self::shows_welcome() ) {
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
				esc_html__( 'Forms Notifier is ready. Connect a channel to receive your form submissions.', 'forms-notifier-for-elementor' ),
				esc_url( Urls::admin( 'channels' ) ),
				esc_html__( 'Choose a channel', 'forms-notifier-for-elementor' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS ), self::DISMISS ) ),
				esc_html__( 'Dismiss', 'forms-notifier-for-elementor' )
			);
		}
	}

	/** Remember that the welcome notice was closed. */
	public static function dismiss(): void {
		check_admin_referer( self::DISMISS );
		if ( ! Capabilities::can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this.', 'forms-notifier-for-elementor' ), '', [ 'response' => 403 ] );
		}
		$ui              = MetaController::ui();
		$ui['dismissed'] = array_values( array_unique( array_merge( $ui['dismissed'], [ 'welcome' ] ) ) );
		update_user_meta( get_current_user_id(), 'fnfe_ui', $ui );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/** What is missing, '' when forms can be captured. */
	private static function missing_dependency(): string {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return __( 'Forms Notifier needs the Elementor plugin. Install and activate Elementor to receive form submissions.', 'forms-notifier-for-elementor' );
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return '';
		}
		foreach ( Registry::form_sources() as $source ) {
			if ( $source->is_available() ) {
				return '';
			}
		}
		return __( 'Forms Notifier did not find a form builder. Activate Elementor Pro (Forms widget) to receive form submissions.', 'forms-notifier-for-elementor' );
	}

	/** Until a channel is on or the notice is closed. */
	private static function shows_welcome(): bool {
		if ( ! Capabilities::can( Capabilities::MANAGE ) || in_array( 'welcome', MetaController::ui()['dismissed'], true ) ) {
			return false;
		}
		foreach ( Registry::channels() as $channel ) {
			if ( $channel->is_enabled() ) {
				return false;
			}
		}
		return true;
	}
}
