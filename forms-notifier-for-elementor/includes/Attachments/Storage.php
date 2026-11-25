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

	/** Absolute, trailing slash; created with index.php and .htaccess. */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER . '/';
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
	 * Delete a stored file; only files inside dir().
	 *
	 * @param Attachment $file File.
	 */
	public static function delete( Attachment $file ): bool {
		return self::owns( $file->path ) && self::delete_path( $file->path );
	}

	/**
	 * The path is a file of the storage folder.
	 *
	 * @param string $path Path.
	 */
	public static function owns( string $path ): bool {
		if ( $path === '' || preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $path ) ) {
			return false;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = realpath( trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER );
		$real    = realpath( $path );
		return is_string( $dir ) && is_string( $real ) && is_file( $real )
			&& PathGuard::is_inside( wp_normalize_path( $real ), untrailingslashit( wp_normalize_path( $dir ) ) );
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
	 * Files of the storage folder older than some seconds.
	 *
	 * @param int $seconds Age.
	 * @param int $limit   Most paths.
	 * @return string[]
	 */
	public static function older_files( int $seconds, int $limit = 500 ): array {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::FOLDER;
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$before = time() - $seconds;
		$out    = [];
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->isFile() && ! $file->isLink() && preg_match( '/^[a-f0-9]{32}(\.[a-z0-9]+)?$/', $file->getFilename() ) && $file->getMTime() < $before ) {
				$out[] = $file->getPathname();
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
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
