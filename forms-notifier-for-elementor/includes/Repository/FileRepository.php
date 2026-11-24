<?php
/**
 * Files.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Model\Attachment;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_files. */
final class FileRepository extends Repository {

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'files' );
	}

	/**
	 * Insert a file of a submission and set its id.
	 *
	 * @param int        $submission_id Submission id.
	 * @param Attachment $file          File.
	 * @return int id
	 */
	public static function insert( int $submission_id, Attachment $file ): int {
		$file->id = self::insert_row(
			[
				'submission_id' => $submission_id,
				'field_id'      => self::clip( $file->field_id, 64 ),
				'name'          => self::clip( $file->name, 191 ),
				'path'          => $file->path,
				'url'           => $file->url,
				'mime'          => self::clip( $file->mime, 100 ),
				'ext'           => self::clip( $file->ext, 20 ),
				'size'          => $file->size,
				'is_stored'     => $file->stored ? 1 : 0,
				'token'         => Crypto::token( 16 ),
				'created_gmt'   => self::now(),
			]
		);
		return $file->id;
	}

	/**
	 * One file.
	 *
	 * @param int $id Row id.
	 */
	public static function find( int $id ): ?Attachment {
		$row = self::row( $id );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Files of a submission.
	 *
	 * @param int $submission_id Submission id.
	 * @return Attachment[]
	 */
	public static function for_submission( int $submission_id ): array {
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE submission_id = %d ORDER BY id ASC', self::table(), $submission_id ), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], (array) $rows );
	}

	/**
	 * Submission a file belongs to, 0 when unknown.
	 *
	 * @param int $id Row id.
	 */
	public static function submission_id( int $id ): int {
		$row = self::row( $id );
		return $row ? (int) $row['submission_id'] : 0;
	}

	/**
	 * Per-file random token (signing input).
	 *
	 * @param int $id Row id.
	 */
	public static function token( int $id ): string {
		$row = self::row( $id );
		return $row ? (string) $row['token'] : '';
	}

	/**
	 * Forget the path of a file that was removed from disk; the row stays.
	 *
	 * @param int $id Row id.
	 */
	public static function clear_path( int $id ): bool {
		return self::update_row(
			$id,
			[
				'path'      => '',
				'is_stored' => 0,
			]
		);
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 */
	public static function delete( int $id ): bool {
		return self::delete_row( $id );
	}

	/**
	 * Stored files older than some days.
	 *
	 * @param int $days  Days.
	 * @param int $limit Most files.
	 * @return Attachment[]
	 */
	public static function older_than( int $days, int $limit = 200 ): array {
		if ( $days <= 0 ) {
			return [];
		}
		$db   = self::db();
		$rows = $db->get_results(
			$db->prepare(
				'SELECT * FROM %i WHERE is_stored = 1 AND created_gmt < %s ORDER BY id ASC LIMIT %d',
				self::table(),
				self::now_plus( -$days * DAY_IN_SECONDS ),
				$limit
			),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], (array) $rows );
	}

	/**
	 * Row to value object. The column is not called "stored" because MySQL reserves that word.
	 *
	 * @param array $row Row.
	 */
	private static function hydrate( array $row ): Attachment {
		$row['stored'] = (bool) (int) $row['is_stored'];
		return Attachment::from_array( $row );
	}
}
