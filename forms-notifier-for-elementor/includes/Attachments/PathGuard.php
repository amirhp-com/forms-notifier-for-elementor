<?php
/**
 * File path checks.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Attachments;

defined( 'ABSPATH' ) || exit;

/** Decides which local files the plugin may touch. Every path goes through is_safe() right before it is used. */
final class PathGuard {

	/** Extensions that are never stored, linked or sent. */
	private const DENIED = [
		'php',
		'php2',
		'php3',
		'php4',
		'php5',
		'php6',
		'php7',
		'php8',
		'phps',
		'pht',
		'phtm',
		'phtml',
		'phpt',
		'phar',
		'hphp',
		'inc',
		'shtml',
		'shtm',
		'stm',
		'htaccess',
		'htpasswd',
		'htgroup',
		'ini',
		'conf',
		'config',
		'env',
		'js',
		'mjs',
		'jse',
		'html',
		'htm',
		'xhtml',
		'xht',
		'hta',
		'mht',
		'mhtml',
		'svg',
		'svgz',
		'swf',
		'asp',
		'aspx',
		'ascx',
		'ashx',
		'asmx',
		'cer',
		'jsp',
		'jspx',
		'cfm',
		'cgi',
		'fcgi',
		'pl',
		'py',
		'pyc',
		'rb',
		'sh',
		'bash',
		'zsh',
		'csh',
		'ksh',
		'exe',
		'com',
		'bat',
		'cmd',
		'msi',
		'scr',
		'pif',
		'cpl',
		'dll',
		'jar',
		'vbs',
		'vbe',
		'wsf',
		'wsh',
		'ps1',
		'ps2',
		'psm1',
		'lnk',
		'reg',
		'tmp',
	];

	/**
	 * Allowed roots as real paths without trailing slash. Filter: fnfe_allowed_file_roots.
	 *
	 * @return string[]
	 */
	public static function roots(): array {
		$uploads = wp_upload_dir( null, false );
		$base    = untrailingslashit( (string) $uploads['basedir'] );
		$forms   = apply_filters( 'elementor_pro/forms/upload_path', $base . '/elementor/forms' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor Pro filter.
		$roots   = apply_filters( 'fnfe_allowed_file_roots', [ $forms, $base . '/' . Storage::FOLDER, $base ] );

		$out = [];
		foreach ( is_array( $roots ) ? $roots : [] as $root ) {
			$real = is_string( $root ) && $root !== '' && ! self::is_stream( $root ) ? realpath( $root ) : false;
			// The file system root would allow everything.
			if ( is_string( $real ) && $real !== '' && is_dir( $real ) && dirname( $real ) !== $real ) {
				$out[] = untrailingslashit( wp_normalize_path( $real ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A regular, readable file inside an allowed root, links resolved, with an allowed extension.
	 *
	 * @param string $path Absolute path.
	 */
	public static function is_safe( string $path ): bool {
		if ( $path === '' || strpos( $path, "\0" ) !== false || self::is_stream( $path ) || self::has_dots( $path ) ) {
			return false;
		}
		$real = realpath( $path );
		if ( ! is_string( $real ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		if ( self::is_denied_ext( pathinfo( $real, PATHINFO_EXTENSION ) ) || self::is_denied_ext( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		foreach ( self::roots() as $root ) {
			if ( self::is_inside( $real, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extension deny-list. Filter: fnfe_denied_extensions.
	 *
	 * @param string $ext Extension, with or without a dot.
	 */
	public static function is_denied_ext( string $ext ): bool {
		$ext    = strtolower( trim( $ext, " \t\r\n\0\x0B." ) );
		$denied = apply_filters( 'fnfe_denied_extensions', self::DENIED );
		return $ext !== '' && in_array( $ext, is_array( $denied ) ? $denied : self::DENIED, true );
	}

	/**
	 * Map an uploads URL to a path, '' when it does not lead to a safe file inside the uploads directory.
	 *
	 * @param string $url URL.
	 */
	public static function url_to_path( string $url ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( set_url_scheme( (string) $uploads['baseurl'], 'https' ) );
		$url     = set_url_scheme( trim( $url ), 'https' );
		if ( strncmp( $url, $base, strlen( $base ) ) !== 0 ) {
			return '';
		}
		$relative = rawurldecode( (string) preg_replace( '/[?#].*$/', '', substr( $url, strlen( $base ) ) ) );
		if ( $relative === '' || strpos( $relative, "\0" ) !== false || strpos( $relative, '\\' ) !== false || self::has_dots( $relative ) ) {
			return '';
		}
		$path = trailingslashit( (string) $uploads['basedir'] ) . $relative;
		$root = realpath( (string) $uploads['basedir'] );
		$real = realpath( $path );
		if ( ! is_string( $root ) || ! is_string( $real ) || ! self::is_inside( wp_normalize_path( $real ), untrailingslashit( wp_normalize_path( $root ) ) ) ) {
			return '';
		}
		return self::is_safe( $path ) ? $path : '';
	}

	/**
	 * Path below a directory; the separator is part of the comparison.
	 *
	 * @param string $path Normalised real path.
	 * @param string $root Normalised real path without trailing slash.
	 */
	public static function is_inside( string $path, string $root ): bool {
		return $root !== '' && strncmp( $path, $root . '/', strlen( $root ) + 1 ) === 0;
	}

	/**
	 * A ".." segment.
	 *
	 * @param string $path Path.
	 */
	private static function has_dots( string $path ): bool {
		return in_array( '..', explode( '/', str_replace( '\\', '/', $path ) ), true );
	}

	/**
	 * A stream wrapper such as phar:// or http://.
	 *
	 * @param string $path Path.
	 */
	private static function is_stream( string $path ): bool {
		return (bool) preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $path );
	}
}
