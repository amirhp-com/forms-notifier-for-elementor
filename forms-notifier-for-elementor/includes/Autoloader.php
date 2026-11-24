<?php
/**
 * Class autoloader.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 style loader: AmirhpCom\FormsNotifier\A\B -> includes/A/B.php.
 */
final class Autoloader {

	/** @var bool */
	private static $registered = false;

	/** Register the loader once. */
	public static function register(): void {
		if ( ! self::$registered ) {
			self::$registered = spl_autoload_register( [ self::class, 'load' ] );
		}
	}

	/**
	 * Load one class file. Unknown classes return quietly.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( $class_name ): void {
		$path = self::path( (string) $class_name );
		if ( $path !== '' && is_file( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * File a class name maps to, '' for names outside the namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function path( string $class_name ): string {
		$prefix = __NAMESPACE__ . '\\';
		$length = strlen( $prefix );
		if ( strncmp( $class_name, $prefix, $length ) !== 0 ) {
			return '';
		}
		$relative = substr( $class_name, $length );
		if ( ! preg_match( '/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$/', $relative ) ) {
			return '';
		}
		return __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
	}
}
