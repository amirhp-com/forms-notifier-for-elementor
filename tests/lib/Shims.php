<?php
/**
 * Minimal WordPress functions for unit.php.
 *
 * @package FormsNotifier
 */

// phpcs:disable

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1048576 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['fnfe_shim_filters'] = [];
$GLOBALS['fnfe_shim_options'] = [];

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fnfe_shim_filters'][ $hook ][ $priority ][] = [ $callback, $args ];
	ksort( $GLOBALS['fnfe_shim_filters'][ $hook ] );
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return add_filter( $hook, $callback, $priority, $args ); }
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['fnfe_shim_filters'][ $hook ][ $priority ] ?? [] as $i => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['fnfe_shim_filters'][ $hook ][ $priority ][ $i ] );
		}
	}
	return true;
}
function remove_action( $hook, $callback, $priority = 10 ) { return remove_filter( $hook, $callback, $priority ); }
function has_filter( $hook ) { return ! empty( $GLOBALS['fnfe_shim_filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$more ) {
	foreach ( $GLOBALS['fnfe_shim_filters'][ $hook ] ?? [] as $entries ) {
		foreach ( $entries as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( [ $value ], $more ), 0, $entry[1] ) );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) { apply_filters( $hook, $args[0] ?? null, ...array_slice( $args, 1 ) ); }
function did_action( $hook ) { return 0; }

function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return (int) $number === 1 ? $single : $plural; }
function _nx( $single, $plural, $number, $context, $domain = 'default' ) { return (int) $number === 1 ? $single : $plural; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	$allowed = $protocols ?: [ 'http', 'https', 'mailto', 'tel', 'tg' ];
	if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $m ) && ! in_array( strtolower( $m[1] ), $allowed, true ) ) {
		return '';
	}
	return preg_replace( '/[^a-z0-9-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i', '', $url );
}
function esc_url( $url, $protocols = null ) { return str_replace( '&', '&#038;', esc_url_raw( $url, $protocols ) ); }

function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
function wp_check_invalid_utf8( $text ) { return mb_check_encoding( (string) $text, 'UTF-8' ) ? (string) $text : ''; }
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) );
	return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
}
function sanitize_text_field( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	return trim( preg_replace( [ '/[\r\n\t ]+/', '/%[a-f0-9]{2}/i' ], [ ' ', '' ], $text ) );
}
function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_email( $email ) { return preg_replace( '/[^a-z0-9+_.@-]/i', '', (string) $email ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $name ); }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value ); }
function wp_specialchars_decode( $text, $flags = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $text, $flags ); }
function wp_kses_post( $text ) { return (string) $text; }
function wp_kses( $text, $allowed, $protocols = [] ) {
	return strip_tags( (string) $text, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
}
function wp_salt( $scheme = 'auth' ) { return 'unit-test-salt-unit-test-salt-unit-test-salt-' . $scheme; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, (array) $args ); }
function wp_list_pluck( $list, $field ) { return array_column( $list, $field ); }
function trailingslashit( $text ) { return rtrim( $text, '/\\' ) . '/'; }
function untrailingslashit( $text ) { return rtrim( $text, '/\\' ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_timezone_string() { return 'UTC'; }
function wp_date( $format, $timestamp = null, $timezone = null ) {
	return ( new DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( $timezone ?: wp_timezone() )->format( $format );
}
function date_i18n( $format, $timestamp = false ) { return wp_date( $format, $timestamp ?: time() ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
function get_option( $name, $default = false ) { return $GLOBALS['fnfe_shim_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['fnfe_shim_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['fnfe_shim_options'][ $name ] ); return true; }
function get_site_transient( $name ) { return false; }
function get_transient( $name ) { return false; }
function set_transient( $name, $value, $ttl = 0 ) { return true; }
function get_bloginfo( $what = 'name' ) { return $what === 'name' ? 'Unit Site' : ''; }
function home_url( $path = '' ) { return 'https://example.com' . ( $path === '' ? '' : '/' . ltrim( $path, '/' ) ); }
function site_url( $path = '' ) { return home_url( $path ); }
function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.com/wp-json/' . ltrim( $path, '/' ); }
function user_trailingslashit( $url ) { return trailingslashit( $url ); }
function add_query_arg( $key, $value = '', $url = '' ) {
	$args = is_array( $key ) ? $key : [ $key => $value ];
	$url  = is_array( $key ) ? $value : $url;
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}
function get_current_blog_id() { return 1; }
function is_multisite() { return false; }
function get_available_languages() { return [ 'fa_IR' ]; }
function get_locale() { return 'en_US'; }
function get_user_locale() { return 'en_US'; }
function determine_locale() { return 'en_US'; }
function switch_to_locale( $locale ) { return true; }
function restore_previous_locale() { return true; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
