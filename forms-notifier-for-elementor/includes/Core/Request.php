<?php
/**
 * Request helpers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Sanitised facts about the current request. */
final class Request {

	/** Client IP: REMOTE_ADDR unless a site filters it. '' when not valid. */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'fnfe_client_ip', $ip );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Zero the last IPv4 octet or the last 80 bits of an IPv6 address.
	 *
	 * @param string $ip IP address.
	 */
	public static function anonymize_ip( string $ip ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		$packed = strlen( $packed ) === 4
			? substr( $packed, 0, 3 ) . "\0"
			: substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
		return (string) inet_ntop( $packed );
	}

	/** Sanitised user agent, at most 400 characters. */
	public static function user_agent(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return mb_substr( $agent, 0, 400 );
	}
}
