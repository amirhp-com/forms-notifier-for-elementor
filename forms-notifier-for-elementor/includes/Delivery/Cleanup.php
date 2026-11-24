<?php
/**
 * Cleanup.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** Retention of messages and the system log; files of submissions that are not saved. */
final class Cleanup {

	/** Seconds of one daily run. */
	private const BUDGET = 20;

	/** Rows per batch. */
	private const BATCH = 500;

	/** Daily hook fnfe_cleanup. */
	public static function daily(): void {
		try {
			$result = self::run();
			if ( $result['messages'] || $result['log'] ) {
				Logger::info( 'cleanup', 'Old entries were deleted.', $result );
			}
			self::check_secrets();
		} catch ( \Throwable $e ) {
			Logger::error( 'cleanup', $e->getMessage() );
		}
	}

	/**
	 * Apply the retention settings in batches.
	 *
	 * @param int $budget Seconds.
	 * @return array{messages:int,bodies:int,log:int}
	 */
	public static function run( int $budget = self::BUDGET ): array {
		$deadline = microtime( true ) + max( 1, $budget );
		$out      = [
			'messages' => 0,
			'bodies'   => 0,
			'log'      => 0,
		];

		MessageRepository::release_stale();
		$days = Settings::int( 'privacy.retention_messages_days', 90 );
		if ( $days > 0 ) {
			do {
				$done             = MessageRepository::purge_older_than( $days, self::BATCH );
				$out['messages'] += $done;
			} while ( $done >= self::BATCH && microtime( true ) < $deadline );
		}
		if ( ! Settings::bool( 'privacy.log_bodies', true ) ) {
			$out['bodies'] = self::clear_bodies();
		}
		$out['log'] = LogRepository::purge_older_than( Settings::int( 'privacy.retention_log_days', 30 ) );
		return $out;
	}

	/**
	 * Rows of a deleted submission: they hold its data and the addresses it was sent to.
	 *
	 * @param int $submission_id Submission id.
	 */
	public static function on_submission_deleted( $submission_id ): void {
		foreach ( MessageRepository::for_submission( (int) $submission_id ) as $row ) {
			MessageRepository::delete( (int) $row['id'] );
		}
	}

	/**
	 * Delete stored copies of files of an unsaved submission once no waiting message needs them.
	 *
	 * @param Attachment[] $files Files.
	 */
	public static function release_files( array $files ): void {
		foreach ( $files as $file ) {
			if ( ! $file instanceof Attachment || ! $file->stored || $file->id || $file->path === '' ) {
				continue;
			}
			if ( ! self::is_needed( $file->path ) ) {
				Storage::delete( $file );
			}
		}
	}

	/** Remove bodies and payloads of finished rows (log_bodies is off). */
	private static function clear_bodies(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET body = NULL, payload = NULL WHERE ( body IS NOT NULL OR payload IS NOT NULL ) AND status IN ('sent','failed','skipped','cancelled')",
				MessageRepository::table()
			)
		);
	}

	/**
	 * A waiting message refers to a file.
	 *
	 * @param string $path File path.
	 */
	private static function is_needed( string $path ): bool {
		global $wpdb;
		// Stored names are random, so the file name alone identifies the file.
		$like = '%' . $wpdb->esc_like( wp_basename( $path ) ) . '%';
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM %i WHERE status IN ('queued','retry','sending') AND payload LIKE %s LIMIT 1", MessageRepository::table(), $like )
		);
	}

	/** Secrets that became unreadable after the salts changed. */
	private static function check_secrets(): void {
		$broken = array_filter( Settings::secret_paths(), [ Settings::class, 'secret_is_broken' ] );
		if ( $broken ) {
			Alerts::send(
				__( 'Some saved credentials cannot be read', 'forms-notifier-for-elementor' ),
				sprintf(
					/* translators: %1$s: comma separated list of setting names */
					__( 'The security keys of this site have changed. Enter these values again: %1$s', 'forms-notifier-for-elementor' ),
					implode( ', ', $broken )
				)
			);
		}
	}
}
