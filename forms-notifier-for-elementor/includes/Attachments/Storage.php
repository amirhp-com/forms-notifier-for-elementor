<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Attachments;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\FileRepository;

defined( 'ABSPATH' ) || exit;

/** Protected copy of uploaded files: nothing is copied yet. */
final class Storage {

	/** Absolute, trailing slash. */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'fnfe-files/';
	}

	/**
	 * Copy a file into storage under an unguessable name.
	 *
	 * @param Attachment $file File.
	 * @return Attachment|null stored copy
	 */
	public static function keep( Attachment $file ): ?Attachment {
		unset( $file );
		return null;
	}

	/**
	 * Delete a stored file; only files inside dir().
	 *
	 * @param Attachment $file File.
	 */
	public static function delete( Attachment $file ): bool {
		$dir  = realpath( self::dir() );
		$path = $file->path === '' ? false : realpath( $file->path );
		if ( ! $dir || ! $path || strncmp( $path, $dir . '/', strlen( $dir ) + 1 ) !== 0 || ! is_file( $path ) ) {
			return false;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/**
	 * Signed, optionally expiring download URL for a stored file.
	 *
	 * @param Attachment $file     File.
	 * @param int        $ttl_days Days, 0 = never expires.
	 */
	public static function link( Attachment $file, int $ttl_days = 0 ): string {
		if ( ! $file->id ) {
			return '';
		}
		$expires = $ttl_days > 0 ? (int) apply_filters( 'fnfe_now', time() ) + $ttl_days * DAY_IN_SECONDS : 0;
		return add_query_arg(
			[
				't' => self::token( $file->id, $expires ),
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
		if ( $expires > 0 && $expires < (int) apply_filters( 'fnfe_now', time() ) ) {
			return false;
		}
		return $token !== '' && hash_equals( self::token( $file_id, $expires ), $token );
	}

	/**
	 * Signature of a link.
	 *
	 * @param int $file_id File id.
	 * @param int $expires Expiry.
	 */
	private static function token( int $file_id, int $expires ): string {
		return Crypto::sign( $file_id . '|' . $expires . '|' . FileRepository::token( $file_id ), 'file' );
	}
}
