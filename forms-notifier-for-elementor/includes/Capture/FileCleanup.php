<?php
/**
 * File cleanup.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Retention of submissions and files, and removal of copies that belong to no stored submission. */
final class FileCleanup {

	/** Seconds one cleanup run may take. */
	private const BUDGET = 20;

	/** A copy without a row is left alone this long: a delivery may still need it. */
	private const ORPHAN_AGE = 3 * DAY_IN_SECONDS;

	/** Attach hooks. */
	public static function register(): void {
		add_action( Cron::CLEANUP, [ self::class, 'run' ] );
	}

	/** Daily cleanup. */
	public static function run(): void {
		$deadline = microtime( true ) + self::BUDGET;

		$days = Settings::int( 'privacy.retention_submissions_days', 0 );
		do {
			$ids  = SubmissionRepository::older_than( $days, 50 );
			$done = 0;
			foreach ( $ids as $id ) {
				$done += self::delete_submission( $id ) ? 1 : 0;
				if ( microtime( true ) >= $deadline ) {
					return;
				}
			}
		} while ( $done );

		$days = Settings::int( 'privacy.retention_files_days', 0 );
		do {
			$files = FileRepository::older_than( $days, 50 );
			foreach ( $files as $file ) {
				Storage::delete( $file );
				// The path is forgotten even when the file is gone already, otherwise it would come back forever.
				FileRepository::clear_path( $file->id );
				if ( microtime( true ) >= $deadline ) {
					return;
				}
			}
		} while ( $files );

		foreach ( Storage::older_files( self::ORPHAN_AGE ) as $path ) {
			if ( ! self::is_known( $path ) && ! self::is_awaited( $path ) ) {
				self::delete_path( $path );
			}
			if ( microtime( true ) >= $deadline ) {
				return;
			}
		}
	}

	/**
	 * Delete a submission with its files and message rows.
	 *
	 * @param int $id Submission id.
	 */
	public static function delete_submission( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		foreach ( MessageRepository::for_submission( $id ) as $row ) {
			MessageRepository::delete( (int) $row['id'] );
		}
		return SubmissionRepository::delete( $id );
	}

	/**
	 * A file row points at the path.
	 *
	 * @param string $path Path.
	 */
	private static function is_known( string $path ): bool {
		global $wpdb;
		// The random part of the name is unique, and the folder may be spelled differently (symbolic links).
		$like = '%' . $wpdb->esc_like( '/' . wp_basename( $path ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE is_stored = 1 AND path LIKE %s LIMIT 1', FileRepository::table(), $like ) );
	}

	/**
	 * A message that is not finished carries the path.
	 *
	 * @param string $path Path.
	 */
	private static function is_awaited( string $path ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE status IN ( 'queued', 'retry', 'sending' ) AND payload LIKE %s LIMIT 1",
				MessageRepository::table(),
				'%' . $wpdb->esc_like( wp_basename( $path ) ) . '%'
			)
		);
	}

	/**
	 * Delete a path of the storage folder.
	 *
	 * @param string $path Path.
	 */
	private static function delete_path( string $path ): void {
		$file         = new Attachment();
		$file->path   = $path;
		$file->stored = true;
		Storage::delete( $file );
	}
}
