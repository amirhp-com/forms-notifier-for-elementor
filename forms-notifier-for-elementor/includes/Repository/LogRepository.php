<?php
/**
 * System log.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\DbSchema;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_log. */
final class LogRepository extends Repository {

	/** Rows kept at most. */
	private const CAP = 5000;

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'log' );
	}

	/**
	 * Insert an entry; every 100th write trims the table.
	 *
	 * @param string $level   Level.
	 * @param string $context Context slug.
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 */
	public static function insert( string $level, string $context, string $message, array $data ): void {
		$db       = self::db();
		$suppress = $db->suppress_errors( true );
		$id       = self::insert_row(
			[
				'level'       => self::clip( $level, 10 ),
				'context'     => self::clip( $context, 40 ),
				'message'     => self::clip( $message, 5000 ),
				'data'        => $data ? self::encode( $data ) : null,
				'created_gmt' => self::now(),
			]
		);
		$db->suppress_errors( $suppress );
		if ( $id && $id % 100 === 0 ) {
			self::trim();
		}
	}

	/**
	 * List envelope.
	 *
	 * @param array $args level, context, search, page, per_page.
	 */
	public static function query( array $args = [] ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'level', 'context' ] as $column ) {
			$sql = self::in_sql( $column, $args[ $column ] ?? '', $params );
			if ( $sql !== '' ) {
				$where[] = $sql;
			}
		}
		$like = self::like( $args['search'] ?? '' );
		if ( $like !== '' ) {
			$where[] = '( message LIKE %s OR data LIKE %s )';
			array_push( $params, $like, $like );
		}
		$page = self::paginate( implode( ' AND ', $where ), $params, 'id DESC', (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 20 ) );

		foreach ( $page['items'] as $i => $row ) {
			$page['items'][ $i ]['id']   = (int) $row['id'];
			$page['items'][ $i ]['data'] = self::decode( $row['data'] );
		}
		return $page;
	}

	/**
	 * Delete entries older than some days.
	 *
	 * @param int $days Days.
	 */
	public static function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		$db = self::db();
		return (int) $db->query( $db->prepare( 'DELETE FROM %i WHERE created_gmt < %s', self::table(), self::now_plus( -$days * DAY_IN_SECONDS ) ) );
	}

	/** Delete every entry. */
	public static function truncate(): void {
		$db = self::db();
		$db->query( $db->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/** Keep the newest rows only. */
	private static function trim(): void {
		$db     = self::db();
		$oldest = $db->get_var( $db->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', self::table(), self::CAP - 1 ) );
		if ( $oldest ) {
			$db->query( $db->prepare( 'DELETE FROM %i WHERE id < %d', self::table(), (int) $oldest ) );
		}
	}
}
