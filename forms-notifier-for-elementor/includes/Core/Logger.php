<?php
/**
 * System log.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Repository\LogRepository;

defined( 'ABSPATH' ) || exit;

/** Writes to fnfe_log, with secret scrubbing and an optional debug.log mirror. */
final class Logger {

	private const LEVELS = [ 'debug', 'info', 'warning', 'error' ];

	/** Shorter strings would mangle unrelated text. */
	private const MIN_SECRET = 4;

	/** @var array<string,bool> */
	private static $secrets = [];

	/**
	 * Write one entry.
	 *
	 * @param string $level   debug (only when advanced.debug_log), info, warning, error.
	 * @param string $context Short slug (bot, sms, queue, capture...).
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function log( string $level, string $context, string $message, array $data = [] ): void {
		$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';
		$debug = Settings::bool( 'advanced.debug_log' );
		if ( $level === 'debug' && ! $debug ) {
			return;
		}

		$message = self::clean( $message );
		$data    = self::clean_data( $data );
		$context = substr( sanitize_key( $context ), 0, 40 );

		try {
			LogRepository::insert( $level, $context, $message, $data );
		} catch ( \Throwable $e ) {
			unset( $e ); // Logging must never break the caller.
		}

		if ( $debug && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$line = sprintf( '[forms-notifier] %s %s: %s', strtoupper( $level ), $context, $message );
			if ( $data ) {
				$line .= ' ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only with the debug setting on.
		}
	}

	/**
	 * @param string $context Short slug.
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function debug( string $context, string $message, array $data = [] ): void {
		self::log( 'debug', $context, $message, $data );
	}

	/**
	 * @param string $context Short slug.
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function info( string $context, string $message, array $data = [] ): void {
		self::log( 'info', $context, $message, $data );
	}

	/**
	 * @param string $context Short slug.
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function warning( string $context, string $message, array $data = [] ): void {
		self::log( 'warning', $context, $message, $data );
	}

	/**
	 * @param string $context Short slug.
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function error( string $context, string $message, array $data = [] ): void {
		self::log( 'error', $context, $message, $data );
	}

	/**
	 * Register a secret so it is replaced by '***' in every logged string and stored response.
	 *
	 * @param string $secret Secret.
	 */
	public static function scrub( string $secret ): void {
		if ( strlen( $secret ) >= self::MIN_SECRET ) {
			self::$secrets[ $secret ]                 = true;
			self::$secrets[ rawurlencode( $secret ) ] = true;
		}
	}

	/**
	 * Apply the scrub list.
	 *
	 * @param string $text Text.
	 */
	public static function clean( string $text ): string {
		if ( $text === '' || ! self::$secrets ) {
			return $text;
		}
		$secrets = array_map( 'strval', array_keys( self::$secrets ) );
		// Longest first, so a secret that contains another one is removed whole.
		usort(
			$secrets,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return str_replace( $secrets, '***', $text );
	}

	/**
	 * Clean every string inside an array.
	 *
	 * @param array $data Data.
	 */
	private static function clean_data( array $data ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$value = self::clean( $value );
			} elseif ( is_array( $value ) ) {
				$value = self::clean_data( $value );
			} elseif ( is_object( $value ) ) {
				$value = get_class( $value );
			}
			$out[ is_string( $key ) ? self::clean( $key ) : $key ] = $value;
		}
		return $out;
	}
}
