<?php
/**
 * URLs of the plugin.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Admin routes, REST urls, mini app urls. */
final class Urls {

	/**
	 * Admin panel URL, optionally at a hash route.
	 *
	 * @param string $route Hash route, e.g. 'channels/telegram'.
	 */
	public static function admin( string $route = '' ): string {
		return admin_url( 'admin.php?page=fnfe' ) . ( $route === '' ? '' : '#/' . ltrim( $route, '/' ) );
	}

	/**
	 * REST URL inside fnfe/v1.
	 *
	 * @param string $path Path.
	 */
	public static function rest( string $path = '' ): string {
		return rest_url( 'fnfe/v1/' . ltrim( $path, '/' ) );
	}

	/**
	 * Admin screen of a submission.
	 *
	 * @param int $id Submission id.
	 */
	public static function submission_admin( int $id ): string {
		return self::admin( 'submissions/' . $id );
	}

	/**
	 * Admin screen of a form.
	 *
	 * @param int $form_id Form row id.
	 */
	public static function form_admin( int $form_id ): string {
		return self::admin( 'forms/' . $form_id );
	}

	/**
	 * Mini app page of a platform, pretty or query-var form.
	 *
	 * @param string $platform telegram | bale.
	 * @param string $path     Path inside the app (hash route), '' = home.
	 */
	public static function miniapp( string $platform, string $path = '' ): string {
		$platform = sanitize_key( $platform );
		$url      = get_option( 'permalink_structure' )
			? user_trailingslashit( home_url( 'fnfe-app/' . $platform ) )
			: add_query_arg( 'fnfe_app', $platform, home_url( '/' ) );
		return $path === '' ? $url : $url . '#/' . ltrim( $path, '/' );
	}

	/**
	 * Webhook URL of a bot platform. The secret is created on first use.
	 *
	 * @param string $platform Platform id.
	 */
	public static function bot_webhook( string $platform ): string {
		$platform = sanitize_key( $platform );
		$secret   = State::get( "bot.$platform.webhook_secret", '' );
		if ( ! is_string( $secret ) || strlen( $secret ) !== 32 ) {
			$secret = Crypto::token( 16 );
			State::set( "bot.$platform.webhook_secret", $secret );
		}
		return self::rest( "bot/$platform/hook/$secret" );
	}
}
