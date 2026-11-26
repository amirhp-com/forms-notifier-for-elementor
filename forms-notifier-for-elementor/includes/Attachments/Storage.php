<?php
/**
 * Stored files.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Attachments;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\FileRepository;

defined( 'ABSPATH' ) || exit;

/** Protected copies of uploaded files. Downloads always go through FilesController. */
final class Storage {

	/** Folder inside the uploads directory. */
	public const FOLDER = 'fnfe-files';

	/** Transient with the cached answer of the probe. */
	public const PROBE_CACHE = 'fnfe_files_exposed';

	/** Name and content of the probe file. */
	private const PROBE_FILE = 'probe.txt';
	private const PROBE_TEXT = 'fnfe';

	/** Storage root without trailing slash: constant FNFE_FILES_DIR, else filter fnfe_files_dir, else uploads/fnfe-files. */
	public static function base(): string {
		return self::resolve( defined( 'FNFE_FILES_DIR' ) ? constant( 'FNFE_FILES_DIR' ) : null );
	}

	/**
	 * The storage root for a given value of the constant FNFE_FILES_DIR (null = not defined).
	 *
	 * @internal Used by base() and the tests.
	 * @param mixed $constant Value of the constant.
	 */
	public static function resolve( $constant ): string {
		$default = self::default_base();
		$dir     = $default;
		if ( is_string( $constant ) && $constant !== '' ) {
			// Like the uploads folder: every site of a network but the main one gets its own subfolder.
			$dir = is_multisite() && ! is_main_site() ? untrailingslashit( $constant ) . '/sites/' . get_current_blog_id() : $constant;
		}
		/**
		 * Folder of stored files. The constant FNFE_FILES_DIR wins over the default; this filter wins over both.
		 * Stream wrappers (scheme://) are refused.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = apply_filters( 'fnfe_files_dir', $dir );
		$dir = is_string( $dir ) && $dir !== '' && ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $dir ) && strpos( $dir, "\0" ) === false ? $dir : $default;
		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	/** The default folder uploads/fnfe-files, without trailing slash. Files stored there stay valid after a move. */
	public static function default_base(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( wp_normalize_path( trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER ) );
	}

	/** Absolute, trailing slash; created with index.php and .htaccess. */
	public static function dir(): string {
		$dir = trailingslashit( self::base() );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::write( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		self::write(
			$dir . '.htaccess',
			"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
		);
		return $dir;
	}

	/**
	 * Address of the storage root on the web, '' when the folder is outside every public folder of WordPress.
	 * Checked: uploads, wp-content, the WordPress folder.
	 */
	public static function public_url(): string {
		$base    = self::base();
		$uploads = wp_upload_dir( null, false );
		$places  = [
			[ (string) $uploads['basedir'], (string) $uploads['baseurl'] ],
			[ WP_CONTENT_DIR, content_url() ],
			[ ABSPATH, site_url() ],
		];
		foreach ( $places as $place ) {
			$root = untrailingslashit( wp_normalize_path( $place[0] ) );
			if ( $root !== '' && ( $base === $root || PathGuard::is_inside( $base, $root ) ) ) {
				$rest = ltrim( substr( $base, strlen( $root ) ), '/' );
				return untrailingslashit( trailingslashit( $place[1] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $rest ) ) ) );
			}
		}
		return '';
	}

	/**
	 * Ask the web server whether a file of the folder can be opened by its direct address. The answer is cached for a
	 * day (an hour when unclear); saving the settings forgets it.
	 *
	 * @return bool|null true = open, false = closed, null = outside the public folders or not testable
	 */
	public static function is_exposed(): ?bool {
		$url = self::public_url();
		if ( $url === '' ) {
			return null;
		}
		$key    = md5( self::base() );
		$cached = get_transient( self::PROBE_CACHE );
		if ( is_array( $cached ) && ( $cached['dir'] ?? '' ) === $key && array_key_exists( 'open', $cached ) ) {
			return is_bool( $cached['open'] ) ? $cached['open'] : null;
		}

		$open = null;
		$dir  = self::dir();
		self::write( $dir . self::PROBE_FILE, self::PROBE_TEXT );
		if ( is_file( $dir . self::PROBE_FILE ) ) {
			$response = wp_remote_get(
				$url . '/' . self::PROBE_FILE,
				[
					'timeout'     => 3,
					'redirection' => 0,
					// Same as the loopback requests of WordPress: a local certificate is often self-signed.
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter of loopback requests.
				]
			);
			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code === 200 ) {
					$open = trim( (string) wp_remote_retrieve_body( $response ) ) === self::PROBE_TEXT ? true : null;
				} elseif ( in_array( $code, [ 401, 403, 404 ], true ) ) {
					$open = false;
				}
			}
		}
		set_transient(
			self::PROBE_CACHE,
			[
				'dir'  => $key,
				'open' => $open,
			],
			$open === null ? HOUR_IN_SECONDS : DAY_IN_SECONDS
		);
		return $open;
	}

	/** Forget the answer of the probe (settings saved, folder changed). */
	public static function forget_probe(): void {
		delete_transient( self::PROBE_CACHE );
	}

	/**
	 * Folders that can hold stored files: base() and the default folder, without trailing slash.
	 *
	 * @return string[]
	 */
	public static function roots(): array {
		return array_values( array_unique( [ self::base(), self::default_base() ] ) );
	}

	/**
	 * Copy a file into storage under an unguessable name.
	 *
	 * @param Attachment $file File.
	 * @return Attachment|null stored copy, null when the file may not be stored
	 */
	public static function keep( Attachment $file ): ?Attachment {
		if ( ! PathGuard::is_safe( $file->path ) ) {
			return null;
		}
		$ext = self::extension( $file );
		if ( PathGuard::is_denied_ext( $ext ) ) {
			return null;
		}

		$dir = self::dir() . gmdate( 'Y/m', (int) apply_filters( 'fnfe_now', time() ) ) . '/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			Logger::warning( 'files', __( 'The storage folder could not be created.', 'forms-notifier-for-elementor' ) );
			return null;
		}
		self::write( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		self::write( dirname( $dir ) . '/index.php', "<?php\n// Silence is golden.\n" );

		$target = $dir . Crypto::token( 16 ) . ( $ext === '' ? '' : '.' . $ext );
		if ( ! copy( $file->path, $target ) ) {
			Logger::warning( 'files', __( 'An uploaded file could not be copied to the storage folder.', 'forms-notifier-for-elementor' ) );
			return null;
		}

		$copy         = Attachment::from_array( $file->to_array() );
		$copy->id     = 0;
		$copy->path   = $target;
		$copy->ext    = $ext;
		$copy->size   = (int) filesize( $target );
		$copy->mime   = self::mime( $target, $file->name );
		$copy->stored = true;
		return $copy;
	}

	/**
	 * Delete a stored file; only files inside roots().
	 *
	 * @param Attachment $file File.
	 */
	public static function delete( Attachment $file ): bool {
		return self::owns( $file->path ) && self::delete_path( $file->path );
	}

	/**
	 * The path is a file of the storage folder, or of the default folder when the storage was moved.
	 *
	 * @param string $path Path.
	 */
	public static function owns( string $path ): bool {
		if ( $path === '' || preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $path ) ) {
			return false;
		}
		$real = realpath( $path );
		if ( ! is_string( $real ) || ! is_file( $real ) ) {
			return false;
		}
		foreach ( self::roots() as $root ) {
			$dir = realpath( $root );
			if ( is_string( $dir ) && PathGuard::is_inside( wp_normalize_path( $real ), untrailingslashit( wp_normalize_path( $dir ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Signed, optionally expiring download URL for a stored file.
	 *
	 * @param Attachment $file        File.
	 * @param int        $ttl_days    Days, 0 = never expires.
	 * @param int        $ttl_seconds Seconds instead of days when greater than 0.
	 */
	public static function link( Attachment $file, int $ttl_days = 0, int $ttl_seconds = 0 ): string {
		if ( ! $file->id ) {
			return '';
		}
		$token = FileRepository::token( $file->id );
		if ( $token === '' ) {
			return '';
		}
		$ttl     = $ttl_seconds > 0 ? $ttl_seconds : max( 0, $ttl_days ) * DAY_IN_SECONDS;
		$expires = $ttl > 0 ? (int) apply_filters( 'fnfe_now', time() ) + $ttl : 0;
		return add_query_arg(
			[
				't' => self::sign( $file->id, $expires, $token ),
				'e' => $expires,
			],
			Urls::rest( 'file/' . $file->id )
		);
	}

	/**
	 * Check a download link.
	 *
	 * @param int    $file_id File id.
	 * @param string $token   Token of the link.
	 * @param int    $expires Expiry of the link, 0 = none.
	 */
	public static function verify( int $file_id, string $token, int $expires ): bool {
		if ( $file_id <= 0 || $expires < 0 || $token === '' || ( $expires > 0 && $expires < (int) apply_filters( 'fnfe_now', time() ) ) ) {
			return false;
		}
		$secret = FileRepository::token( $file_id );
		return $secret !== '' && hash_equals( self::sign( $file_id, $expires, $secret ), $token );
	}

	/**
	 * Mime type from content and name, 'application/octet-stream' when they disagree or are unknown.
	 *
	 * @param string $path File on disk.
	 * @param string $name Original name.
	 */
	public static function mime( string $path, string $name ): string {
		$type = wp_check_filetype_and_ext( $path, $name === '' ? basename( $path ) : $name );
		return ! empty( $type['type'] ) && is_string( $type['type'] ) ? $type['type'] : 'application/octet-stream';
	}

	/**
	 * Files of the storage folders (roots()) older than some seconds.
	 *
	 * @param int $seconds Age.
	 * @param int $limit   Most paths.
	 * @return string[]
	 */
	public static function older_files( int $seconds, int $limit = 500 ): array {
		$before = time() - $seconds;
		$out    = [];
		foreach ( self::roots() as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( $file->isFile() && ! $file->isLink() && self::is_stored_name( $file->getFilename() ) && $file->getMTime() < $before ) {
					$out[] = $file->getPathname();
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * A file name the storage creates: a stored copy, or one of the protection and probe files.
	 *
	 * @param string $name     File name.
	 * @param bool   $own_too  Also accept index.php, .htaccess and the probe file.
	 */
	public static function is_stored_name( string $name, bool $own_too = false ): bool {
		return preg_match( '/^[a-f0-9]{32}(\.[a-z0-9]+)?$/', $name ) === 1
			|| ( $own_too && in_array( $name, [ 'index.php', '.htaccess', self::PROBE_FILE ], true ) );
	}

	/**
	 * Remove a file of the storage folder.
	 *
	 * @param string $path Path that owns() accepted.
	 */
	private static function delete_path( string $path ): bool {
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! file_exists( $path );
	}

	/**
	 * Signature of a link.
	 *
	 * @param int    $file_id File id.
	 * @param int    $expires Expiry.
	 * @param string $secret  Random token of the file row.
	 */
	private static function sign( int $file_id, int $expires, string $secret ): string {
		return Crypto::sign( $file_id . '|' . $expires . '|' . $secret, 'file' );
	}

	/**
	 * Lowercase extension that is safe in a file name.
	 *
	 * @param Attachment $file File.
	 */
	private static function extension( Attachment $file ): string {
		$ext = $file->ext === '' ? pathinfo( $file->path, PATHINFO_EXTENSION ) : $file->ext;
		return substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) ), 0, 20 );
	}

	/**
	 * Create a protection file once.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 */
	private static function write( string $path, string $content ): void {
		if ( ! is_file( $path ) && is_dir( dirname( $path ) ) && wp_is_writable( dirname( $path ) ) ) {
			file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A constant text in the plugin's own folder; the filesystem API may ask for credentials on the front end.
		}
	}
}
