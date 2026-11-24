<?php
/**
 * Messages: queue and log.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Logger;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_messages. */
final class MessageRepository extends Repository {

	/** Characters of a body kept for the log. */
	private const MAX_BODY = 10000;

	/** Column lengths of the varchar columns. */
	private const LENGTHS = [
		'channel'         => 40,
		'provider'        => 60,
		'purpose'         => 20,
		'recipient'       => 191,
		'recipient_label' => 191,
		'lang'            => 20,
		'status'          => 20,
		'lock_token'      => 32,
		'subject'         => 191,
		'remote_id'       => 191,
	];

	private const INTEGERS = [ 'id', 'submission_id', 'form_id', 'attempts', 'max_attempts', 'http_code', 'parent_id' ];

	private const DATES = [ 'next_attempt_gmt', 'locked_until_gmt', 'created_gmt', 'updated_gmt', 'sent_gmt' ];

	private const FINAL_STATUSES = [ 'sent', 'failed', 'skipped', 'cancelled' ];

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'messages' );
	}

	/**
	 * Insert a row. Unknown keys are ignored; a queued row without a due date is due now.
	 *
	 * @param array $row Column => value; payload may be an array.
	 * @return int id
	 */
	public static function insert( array $row ): int {
		$row += [ 'status' => 'queued' ];
		if ( in_array( $row['status'], [ 'queued', 'retry' ], true ) && empty( $row['next_attempt_gmt'] ) ) {
			$row['next_attempt_gmt'] = self::now();
		}
		$row += [ 'created_gmt' => self::now() ];
		$row += [ 'updated_gmt' => self::now() ];
		return self::insert_row( self::columns( $row ) );
	}

	/**
	 * Row with the payload decoded under 'payload'.
	 *
	 * @param int $id Row id.
	 */
	public static function find( int $id ): ?array {
		return self::hydrate( self::row( $id ) );
	}

	/**
	 * Change columns of a row.
	 *
	 * @param int   $id    Row id.
	 * @param array $patch Column => value; null is stored as NULL.
	 */
	public static function update( int $id, array $patch ): bool {
		unset( $patch['id'] );
		$patch += [ 'updated_gmt' => self::now() ];
		return self::update_row( $id, self::columns( $patch ) );
	}

	/**
	 * List envelope.
	 *
	 * @param array $args status (string|string[]), channel, purpose, form_id, submission_id,
	 *                    search (recipient/error/body LIKE), from, to, page, per_page, order.
	 */
	public static function query( array $args = [] ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'status', 'channel', 'purpose' ] as $column ) {
			$sql = self::in_sql( $column, $args[ $column ] ?? '', $params );
			if ( $sql !== '' ) {
				$where[] = $sql;
			}
		}
		foreach ( [ 'form_id', 'submission_id' ] as $column ) {
			if ( ! empty( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = absint( $args[ $column ] );
			}
		}
		$like = self::like( $args['search'] ?? '' );
		if ( $like !== '' ) {
			$where[] = '( recipient LIKE %s OR recipient_label LIKE %s OR error LIKE %s OR body LIKE %s )';
			array_push( $params, $like, $like, $like, $like );
		}
		$where = array_merge( $where, self::date_sql( $args, $params ) );
		$dir   = strtolower( (string) ( $args['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';
		$page  = self::paginate( implode( ' AND ', $where ), $params, "id $dir", (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 20 ) );

		$page['items'] = array_map( [ self::class, 'hydrate' ], $page['items'] );
		return $page;
	}

	/**
	 * Atomically claim up to $limit due rows.
	 *
	 * Candidates are read first, then each one is taken with a compare-and-set update, so two processes never get the
	 * same row.
	 *
	 * @param int $limit        Most rows.
	 * @param int $lock_seconds How long the claim is valid.
	 * @return array[] claimed rows
	 */
	public static function claim( int $limit, int $lock_seconds = 300 ): array {
		$db  = self::db();
		$now = self::now();
		$ids = $db->get_col(
			$db->prepare(
				"SELECT id FROM %i WHERE status IN ('queued','retry') AND next_attempt_gmt <= %s AND ( locked_until_gmt IS NULL OR locked_until_gmt < %s ) ORDER BY next_attempt_gmt ASC, id ASC LIMIT %d",
				self::table(),
				$now,
				$now,
				max( 1, $limit )
			)
		);

		$claimed = [];
		foreach ( $ids as $id ) {
			$row = self::take( (int) $id, $lock_seconds );
			if ( $row ) {
				$claimed[] = $row;
			}
		}
		return $claimed;
	}

	/**
	 * Claim one row regardless of its due date; null when it is not waiting any more.
	 *
	 * @param int $id           Row id.
	 * @param int $lock_seconds How long the claim is valid.
	 */
	public static function take( int $id, int $lock_seconds = 300 ): ?array {
		$db    = self::db();
		$token = bin2hex( random_bytes( 16 ) );
		$taken = $db->query(
			$db->prepare(
				"UPDATE %i SET status = 'sending', lock_token = %s, locked_until_gmt = %s, updated_gmt = %s WHERE id = %d AND status IN ('queued','retry')",
				self::table(),
				$token,
				self::now_plus( max( 1, $lock_seconds ) ),
				self::now(),
				$id
			)
		);
		if ( (int) $taken !== 1 ) {
			return null;
		}
		$row = self::find( $id );
		return $row && $row['lock_token'] === $token ? $row : null;
	}

	/** 'sending' rows with an expired lock -> 'retry'. */
	public static function release_stale(): int {
		$db  = self::db();
		$now = self::now();
		return (int) $db->query(
			$db->prepare(
				"UPDATE %i SET status = 'retry', lock_token = '', locked_until_gmt = NULL, next_attempt_gmt = %s, updated_gmt = %s WHERE status = 'sending' AND ( locked_until_gmt IS NULL OR locked_until_gmt < %s )",
				self::table(),
				$now,
				$now,
				$now
			)
		);
	}

	/**
	 * Rows per status.
	 *
	 * @param string $since_gmt Only rows created since, '' = all.
	 * @return array<string,int>
	 */
	public static function counts( string $since_gmt = '' ): array {
		$db  = self::db();
		$out = [];
		foreach ( (array) $db->get_results(
			$db->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE created_gmt >= %s GROUP BY status', self::table(), self::since( $since_gmt ) ),
			ARRAY_A
		) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Rows per channel and status.
	 *
	 * @param string $since_gmt Only rows created since, '' = all.
	 * @return array<string,array<string,int>>
	 */
	public static function counts_by_channel( string $since_gmt = '' ): array {
		$db  = self::db();
		$out = [];
		foreach ( (array) $db->get_results(
			$db->prepare( 'SELECT channel, status, COUNT(*) AS n FROM %i WHERE created_gmt >= %s GROUP BY channel, status', self::table(), self::since( $since_gmt ) ),
			ARRAY_A
		) as $row ) {
			$out[ $row['channel'] ][ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Rows of a submission, oldest first.
	 *
	 * @param int $submission_id Submission id.
	 * @return array[]
	 */
	public static function for_submission( int $submission_id ): array {
		if ( $submission_id <= 0 ) {
			return [];
		}
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE submission_id = %d ORDER BY id ASC', self::table(), $submission_id ), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], (array) $rows );
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
	 * Delete finished rows older than some days.
	 *
	 * @param int $days  Days.
	 * @param int $limit Most rows.
	 */
	public static function purge_older_than( int $days, int $limit = 500 ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		$db   = self::db();
		$done = 0;
		$ids  = $db->get_col(
			$db->prepare(
				"SELECT id FROM %i WHERE status IN ('sent','failed','skipped','cancelled') AND created_gmt < %s ORDER BY id ASC LIMIT %d",
				self::table(),
				self::now_plus( -$days * DAY_IN_SECONDS ),
				$limit
			)
		);
		foreach ( $ids as $id ) {
			$done += self::delete_row( (int) $id ) ? 1 : 0;
		}
		return $done;
	}

	/**
	 * Remove the payload of finished rows older than some days (0 = all finished rows).
	 *
	 * @param int $days Days.
	 */
	public static function clear_payloads_older_than( int $days ): int {
		$db = self::db();
		return (int) $db->query(
			$db->prepare(
				"UPDATE %i SET payload = NULL WHERE payload IS NOT NULL AND status IN ('sent','failed','skipped','cancelled') AND created_gmt <= %s",
				self::table(),
				self::now_plus( -max( 0, $days ) * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * A status that is never processed again by itself.
	 *
	 * @param string $status Status.
	 */
	public static function is_final( string $status ): bool {
		return in_array( $status, self::FINAL_STATUSES, true );
	}

	/**
	 * Known columns in storable form.
	 *
	 * @param array $row Untrusted row.
	 */
	private static function columns( array $row ): array {
		$out = [];
		foreach ( $row as $column => $value ) {
			if ( isset( self::LENGTHS[ $column ] ) ) {
				$out[ $column ] = self::clip( $value, self::LENGTHS[ $column ] );
			} elseif ( $column !== 'id' && in_array( $column, self::INTEGERS, true ) ) {
				$out[ $column ] = max( 0, (int) $value );
			} elseif ( in_array( $column, self::DATES, true ) ) {
				$out[ $column ] = $value === null || $value === '' ? null : (string) $value;
			} elseif ( $column === 'payload' ) {
				$out[ $column ] = is_array( $value ) ? self::encode( $value ) : null;
			} elseif ( $column === 'body' ) {
				$out[ $column ] = $value === null ? null : self::clip( Logger::clean( (string) $value ), self::MAX_BODY );
			} elseif ( $column === 'response' || $column === 'error' ) {
				$out[ $column ] = $value === null ? null : self::clip( Logger::clean( (string) $value ), 2000 );
			}
		}
		return $out;
	}

	/**
	 * Types and decoded payload.
	 *
	 * @param array|null $row Row.
	 */
	private static function hydrate( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( self::INTEGERS as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		foreach ( [ 'body', 'response', 'error' ] as $column ) {
			$row[ $column ] = (string) $row[ $column ];
		}
		$row['payload'] = self::decode( $row['payload'] );
		return $row;
	}

	/**
	 * Lower date bound.
	 *
	 * @param string $since_gmt Date or ''.
	 */
	private static function since( string $since_gmt ): string {
		return $since_gmt === '' ? '1970-01-01 00:00:00' : $since_gmt;
	}
}
