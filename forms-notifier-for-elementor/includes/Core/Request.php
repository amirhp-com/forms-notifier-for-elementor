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

	/** Headers the setting advanced.proxy_header may name. */
	public const PROXY_HEADERS = [ 'cf-connecting-ip', 'x-real-ip', 'x-forwarded-for' ];

	/**
	 * Client IP: REMOTE_ADDR, or the header of a trusted proxy (advanced.proxy_header). '' when not valid.
	 * Filter fnfe_client_ip( string $ip ) has the last word.
	 */
	public static function ip(): string {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$header = Settings::str( 'advanced.proxy_header' );
		if ( in_array( $header, self::PROXY_HEADERS, true ) ) {
			$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
			$found = self::from_header( $value );
			$ip    = $found === '' ? $ip : $found;
		}
		$ip = apply_filters( 'fnfe_client_ip', $ip );
		return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Visitor address in a proxy header. In a list the right-most public address counts: the entries to its left were
	 * sent by the visitor and can be anything.
	 *
	 * @param string $value Header value.
	 */
	public static function from_header( string $value ): string {
		$list = array_reverse( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		$last = '';
		foreach ( $list as $entry ) {
			if ( ! filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				return $last;
			}
			$last = $entry;
			if ( filter_var( $entry, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $entry;
			}
		}
		return $last;
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
