<?php
/**
 * Submissions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_submissions. */
final class SubmissionRepository extends Repository {

	private const STATUSES = [ 'new', 'read', 'done', 'spam', 'trash' ];

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'submissions' );
	}

	/**
	 * Insert and set $s->id. Files are inserted with the row; a missing uuid or date is filled.
	 *
	 * @param Submission $s Submission.
	 * @return int id, 0 on failure
	 */
	public static function insert( Submission $s ): int {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $s->uuid ) ) {
			$s->uuid = Crypto::token( 16 );
		}
		if ( $s->created_gmt === '' ) {
			$s->created_gmt = self::now();
		}

		$s->id = self::insert_row(
			[
				'uuid'                    => $s->uuid,
				'form_id'                 => $s->form_id,
				'form_key'                => self::clip( $s->form_key, 64 ),
				'form_name'               => self::clip( $s->form_name, 191 ),
				'source'                  => self::clip( $s->source, 20 ),
				'status'                  => in_array( $s->status, self::STATUSES, true ) ? $s->status : 'new',
				'lang'                    => self::clip( $s->lang, 20 ),
				'fields'                  => self::encode( (object) $s->fields ),
				'meta'                    => self::encode( (object) $s->meta ),
				'search_text'             => self::search_text( $s ),
				'content_hash'            => self::content_hash( $s ),
				'user_id'                 => absint( $s->meta['user_id'] ?? 0 ),
				'user_ip'                 => self::clip( $s->meta['user_ip'] ?? '', 46 ),
				'files_count'             => count( $s->files ),
				'elementor_submission_id' => absint( $s->meta['elementor_submission_id'] ?? 0 ),
				'created_gmt'             => $s->created_gmt,
				'updated_gmt'             => self::now(),
			]
		);

		if ( $s->id ) {
			foreach ( $s->files as $file ) {
				if ( ! $file->id ) {
					$file->id = FileRepository::insert( $s->id, $file );
				}
			}
		}
		return $s->id;
	}

	/**
	 * Submission with files.
	 *
	 * @param int $id Row id.
	 */
	public static function find( int $id ): ?Submission {
		return self::hydrate( self::row( $id ) );
	}

	/**
	 * Submission by uuid, with files.
	 *
	 * @param string $uuid 32 hex.
	 */
	public static function find_by_uuid( string $uuid ): ?Submission {
		$db = self::db();
		return self::hydrate( $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE uuid = %s', self::table(), $uuid ), ARRAY_A ) );
	}

	/**
	 * List envelope; items are Submission objects.
	 *
	 * @param array $args form_id, status (string|string[]), search, from, to (Y-m-d, site tz), user_id, page, per_page, order.
	 */
	public static function query( array $args = [] ): array {
		list( $where, $params ) = self::conditions( $args );

		$dir  = strtolower( (string) ( $args['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';
		$page = self::paginate( $where, $params, "created_gmt $dir, id $dir", (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 20 ) );

		$page['items'] = array_map( [ self::class, 'hydrate' ], $page['items'] );
		return $page;
	}

	/**
	 * Change the status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status new|read|done|spam|trash.
	 */
	public static function set_status( int $id, string $status ): bool {
		return in_array( $status, self::STATUSES, true ) && self::row( $id ) && self::update_row(
			$id,
			[
				'status'      => $status,
				'updated_gmt' => self::now(),
			]
		);
	}

	/**
	 * Change the delivery status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status none|queued|sent|partial|failed.
	 */
	public static function set_delivery_status( int $id, string $status ): void {
		if ( in_array( $status, [ 'none', 'queued', 'sent', 'partial', 'failed' ], true ) ) {
			self::update_row( $id, [ 'delivery_status' => $status ] );
		}
	}

	/**
	 * Merge keys into the meta column.
	 *
	 * @param int   $id         Row id.
	 * @param array $meta_patch Keys to set; null removes a key.
	 */
	public static function update_meta( int $id, array $meta_patch ): void {
		$row = self::row( $id );
		if ( ! $row ) {
			return;
		}
		$meta = array_filter(
			array_merge( self::decode( $row['meta'] ), $meta_patch ),
			static function ( $value ): bool {
				return $value !== null;
			}
		);
		$data = [
			'meta'        => self::encode( (object) $meta ),
			'updated_gmt' => self::now(),
		];
		foreach ( [ 'user_id', 'elementor_submission_id' ] as $column ) {
			if ( array_key_exists( $column, $meta_patch ) ) {
				$data[ $column ] = absint( $meta_patch[ $column ] );
			}
		}
		if ( array_key_exists( 'user_ip', $meta_patch ) ) {
			$data['user_ip'] = self::clip( $meta_patch['user_ip'], 46 );
		}
		self::update_row( $id, $data );
	}

	/**
	 * The same content from the same address was saved inside the window.
	 *
	 * @param string $content_hash Output of content_hash().
	 * @param string $ip           Address as it is stored.
	 * @param int    $window       Seconds.
	 */
	public static function recent_duplicate( string $content_hash, string $ip, int $window ): bool {
		if ( $content_hash === '' || $window <= 0 ) {
			return false;
		}
		$db = self::db();
		return (bool) $db->get_var(
			$db->prepare(
				'SELECT id FROM %i WHERE content_hash = %s AND user_ip = %s AND created_gmt >= %s LIMIT 1',
				self::table(),
				$content_hash,
				$ip,
				self::now_plus( -$window )
			)
		);
	}

	/**
	 * Number of rows.
	 *
	 * @param array $args See query().
	 */
	public static function count( array $args = [] ): int {
		list( $where, $params ) = self::conditions( $args );

		$db    = self::db();
		$where = $where === '' ? '1 = 1' : $where;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $where holds literals and placeholders only.
		return (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( [ self::table() ], $params ) ) );
	}

	/**
	 * Delete a submission with its stored files and file rows.
	 *
	 * @param int $id Row id.
	 */
	public static function delete( int $id ): bool {
		foreach ( FileRepository::for_submission( $id ) as $file ) {
			if ( $file->stored ) {
				Storage::delete( $file );
			}
			FileRepository::delete( $file->id );
		}
		if ( ! self::delete_row( $id ) ) {
			return false;
		}
		do_action( 'fnfe_submission_deleted', $id );
		return true;
	}

	/**
	 * Ids older than some days.
	 *
	 * @param int $days  Days.
	 * @param int $limit Most ids.
	 * @return int[]
	 */
	public static function older_than( int $days, int $limit = 200 ): array {
		if ( $days <= 0 ) {
			return [];
		}
		$db = self::db();
		return array_map(
			'intval',
			$db->get_col(
				$db->prepare( 'SELECT id FROM %i WHERE created_gmt < %s ORDER BY id ASC LIMIT %d', self::table(), self::now_plus( -$days * DAY_IN_SECONDS ), $limit )
			)
		);
	}

	/**
	 * Rows sent by the owner of an email address (privacy tools): an email field or the field the form maps to the
	 * submitter's email holds it, or another one-line field holds nothing else. An address someone mentions inside a
	 * message does not make the submission theirs.
	 *
	 * @param string $email    Email address.
	 * @param int    $page     Page number, from 1.
	 * @param int    $per_page Ids per page.
	 * @return int[]
	 */
	public static function ids_by_email( string $email, int $page = 1, int $per_page = 100 ): array {
		$email = trim( $email );
		if ( $email === '' || ! is_email( $email ) ) {
			return [];
		}
		$db      = self::db();
		$mapped  = [];
		$like    = '%' . $db->esc_like( $email ) . '%';
		$pattern = '/(?<![A-Za-z0-9._%+\-])' . preg_quote( $email, '/' ) . '(?![A-Za-z0-9\-]|\.[A-Za-z0-9])/iu';
		$skip    = ( max( 1, $page ) - 1 ) * max( 1, $per_page );
		$last    = 0;
		$out     = [];

		// LIKE also finds longer addresses that end with this one; only whole addresses count.
		do {
			$rows = (array) $db->get_results(
				$db->prepare( 'SELECT id, form_id, fields FROM %i WHERE id > %d AND fields LIKE %s ORDER BY id ASC LIMIT 200', self::table(), $last, $like ),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				$form = (int) $row['form_id'];
				if ( ! isset( $mapped[ $form ] ) ) {
					$mapped[ $form ] = $form > 0 ? (string) FormRepository::config( $form )->get( 'fields.email', '' ) : '';
				}
				if ( ! self::owns_email( self::decode( $row['fields'] ), $email, $pattern, $mapped[ $form ] ) ) {
					continue;
				}
				if ( $skip > 0 ) {
					--$skip;
					continue;
				}
				$out[] = $last;
				if ( count( $out ) >= $per_page ) {
					return $out;
				}
			}
		} while ( isset( $rows[199] ) );

		return $out;
	}

	/**
	 * One of the fields names the address as the submitter's.
	 *
	 * @param array  $fields  Stored fields.
	 * @param string $email   Address.
	 * @param string $pattern Regex of the whole address.
	 * @param string $mapped  Field mapped to the submitter's email, '' = none.
	 */
	private static function owns_email( array $fields, string $email, string $pattern, string $mapped ): bool {
		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$value = is_scalar( $field['value'] ?? null ) ? (string) $field['value'] : '';
			$type  = (string) ( $field['type'] ?? '' );
			if ( $type === 'email' || ( $mapped !== '' && (string) ( $field['id'] ?? $id ) === $mapped ) ) {
				if ( preg_match( $pattern, $value ) ) {
					return true;
				}
			} elseif ( $type !== 'textarea' && strcasecmp( trim( $value ), $email ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hash of form key and values (duplicate guard).
	 *
	 * @param Submission $s Submission.
	 */
	public static function content_hash( Submission $s ): string {
		$values = [];
		foreach ( $s->fields as $id => $field ) {
			$values[ $id ] = (string) ( $field['value'] ?? '' );
		}
		return md5( $s->form_key . '|' . self::encode( $values ) );
	}

	/**
	 * Lowercased values for search.
	 *
	 * @param Submission $s Submission.
	 */
	private static function search_text( Submission $s ): string {
		$parts = [ $s->form_name ];
		foreach ( $s->fields as $field ) {
			if ( empty( $field['redacted'] ) ) {
				$parts[] = (string) ( $field['value'] ?? '' );
			}
		}
		return Text::lower( implode( "\n", array_filter( $parts, 'strlen' ) ) );
	}

	/**
	 * Query args to SQL.
	 *
	 * @param array $args See query().
	 * @return array{0:string,1:array}
	 */
	private static function conditions( array $args ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'form_id', 'user_id' ] as $column ) {
			if ( ! empty( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = absint( $args[ $column ] );
			}
		}
		$sql = self::in_sql( 'status', $args['status'] ?? '', $params );
		if ( $sql !== '' ) {
			$where[] = $sql;
		}
		$like = self::like( is_scalar( $args['search'] ?? '' ) ? Text::lower( (string) ( $args['search'] ?? '' ) ) : '' );
		if ( $like !== '' ) {
			$where[]  = 'search_text LIKE %s';
			$params[] = $like;
		}
		$where = array_merge( $where, self::date_sql( $args, $params ) );
		return [ implode( ' AND ', $where ), $params ];
	}

	/**
	 * Row to DTO.
	 *
	 * @param array|null $row Row.
	 */
	private static function hydrate( $row ): ?Submission {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['fields'] = self::decode( $row['fields'] );
		$row['meta']   = self::decode( $row['meta'] );
		$parts         = explode( ':', (string) $row['form_key'], 2 );

		$row['post_id']                 = absint( $parts[0] );
		$row['element_id']              = $parts[1] ?? '';
		$row['meta']['delivery_status'] = (string) $row['delivery_status'];

		$s = Submission::from_array( $row );
		if ( (int) $row['files_count'] > 0 ) {
			$s->files = FileRepository::for_submission( $s->id );
		}
		return $s;
	}
}
