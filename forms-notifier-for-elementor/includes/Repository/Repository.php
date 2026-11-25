<?php
/**
 * Repository base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** Table access shared by the repositories. Dates are UTC, JSON columns hold readable JSON. */
abstract class Repository {

	/** Most rows a page may hold. */
	protected const MAX_PER_PAGE = 100;

	/** Full table name with prefix. */
	abstract public static function table(): string;

	/** Database handle. */
	protected static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Current UTC time. */
	protected static function now(): string {
		return gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) );
	}

	/**
	 * UTC time some seconds away from now.
	 *
	 * @param int $seconds Offset.
	 */
	protected static function now_plus( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) + $seconds );
	}

	/**
	 * JSON for a longtext column.
	 *
	 * @param mixed $data Data.
	 */
	protected static function encode( $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Array from a JSON column, [] on failure.
	 *
	 * @param mixed $json Column value.
	 */
	protected static function decode( $json ): array {
		if ( is_array( $json ) ) {
			return $json;
		}
		$data = is_string( $json ) && $json !== '' ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : [];
	}

	/**
	 * One page of rows.
	 *
	 * @param string $where_sql Conditions with placeholders, '' = all rows. Built from literals only.
	 * @param array  $params    Values of the placeholders.
	 * @param string $order_sql Body of ORDER BY. Built from literals only.
	 * @param int    $page      Page number, from 1.
	 * @param int    $per_page  Rows per page.
	 * @return array{items:array,total:int,page:int,per_page:int}
	 */
	protected static function paginate( string $where_sql, array $params, string $order_sql, int $page, int $per_page ): array {
		$db       = static::db();
		$page     = max( 1, $page );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$where    = $where_sql === '' ? '1 = 1' : $where_sql;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $where and $order_sql are assembled from literals by the repositories, values are placeholders.
		$total = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( [ static::table() ], $params ) ) );
		$items = $total
			? $db->get_results(
				$db->prepare(
					"SELECT * FROM %i WHERE $where ORDER BY $order_sql LIMIT %d OFFSET %d",
					array_merge( [ static::table() ], $params, [ $per_page, ( $page - 1 ) * $per_page ] )
				),
				ARRAY_A
			)
			: [];
		// phpcs:enable

		return [
			'items'    => is_array( $items ) ? $items : [],
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Row id.
	 */
	protected static function row( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$db  = static::db();
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE id = %d', static::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Column => value.
	 * @return int row id, 0 on failure
	 */
	protected static function insert_row( array $data ): int {
		$db = static::db();
		return $db->insert( static::table(), self::storable( $data ) ) ? (int) $db->insert_id : 0;
	}

	/**
	 * Update a row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value.
	 */
	protected static function update_row( int $id, array $data ): bool {
		return $id > 0 && $data && static::db()->update( static::table(), self::storable( $data ), [ 'id' => $id ] ) !== false;
	}

	/**
	 * Values the database accepts. wpdb refuses a whole row when one text is not valid UTF-8, or holds four-byte
	 * characters (emoji) and the column is not utf8mb4.
	 *
	 * @param array $data Column => value.
	 */
	private static function storable( array $data ): array {
		foreach ( $data as $column => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = Text::scrub( $value );
			if ( preg_match( '/[\xF0-\xF4]/', $value ) && in_array( static::db()->get_col_charset( static::table(), $column ), [ 'utf8', 'utf8mb3' ], true ) ) {
				$value = self::three_bytes( $value );
			}
			$data[ $column ] = $value;
		}
		return $data;
	}

	/**
	 * Text without four-byte characters: escaped in JSON, where nothing is lost, replaced elsewhere.
	 *
	 * @param string $value Valid UTF-8.
	 */
	private static function three_bytes( string $value ): string {
		$json = $value !== '' && strpos( '{["', $value[0] ) !== false && json_decode( $value ) !== null;
		return (string) preg_replace_callback(
			'/[\x{10000}-\x{10FFFF}]/u',
			static function ( array $m ) use ( $json ): string {
				return $json ? substr( (string) wp_json_encode( $m[0] ), 1, -1 ) : "\u{FFFD}";
			},
			$value
		);
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 */
	protected static function delete_row( int $id ): bool {
		return $id > 0 && (bool) static::db()->delete( static::table(), [ 'id' => $id ] );
	}

	/**
	 * "column IN ( ... )" for strings, appending the values to $params. '' for an empty list.
	 *
	 * @param string $column Column name literal.
	 * @param mixed  $values Value or list.
	 * @param array  $params Parameters, by reference.
	 */
	protected static function in_sql( string $column, $values, array &$params ): string {
		$values = array_values( array_filter( array_map( 'strval', array_filter( (array) $values, 'is_scalar' ) ), 'strlen' ) );
		if ( ! $values ) {
			return '';
		}
		$params = array_merge( $params, $values );
		return $column . ' IN ( ' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ' )';
	}

	/**
	 * Conditions on created_gmt for from/to days given in the site timezone.
	 *
	 * @param array $args   Query args with from, to (Y-m-d).
	 * @param array $params Parameters, by reference.
	 * @return string[]
	 */
	protected static function date_sql( array $args, array &$params ): array {
		$out = [];
		$day = self::day( $args['from'] ?? '' );
		if ( $day ) {
			$out[]    = 'created_gmt >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', $day->getTimestamp() );
		}
		$day = self::day( $args['to'] ?? '' );
		if ( $day ) {
			$out[]    = 'created_gmt < %s';
			$params[] = gmdate( 'Y-m-d H:i:s', $day->modify( '+1 day' )->getTimestamp() );
		}
		return $out;
	}

	/**
	 * Cut a string to a column length.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Characters.
	 */
	protected static function clip( $value, int $length ): string {
		return mb_substr( is_scalar( $value ) ? Text::scrub( (string) $value ) : '', 0, $length );
	}

	/**
	 * Search words for LIKE.
	 *
	 * @param mixed $search Untrusted search text.
	 */
	protected static function like( $search ): string {
		$search = is_scalar( $search ) ? trim( (string) $search ) : '';
		return $search === '' ? '' : '%' . static::db()->esc_like( $search ) . '%';
	}

	/**
	 * Midnight of a day in the site timezone.
	 *
	 * @param mixed $value Y-m-d.
	 */
	private static function day( $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		return $day ?: null;
	}
}
