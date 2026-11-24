<?php
/**
 * Forms.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\FormConfig;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_forms. */
final class FormRepository extends Repository {

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'forms' );
	}

	/**
	 * Row with fields, config and editor decoded.
	 *
	 * @param int $id Row id.
	 */
	public static function find( int $id ): ?array {
		return self::hydrate( self::row( $id ) );
	}

	/**
	 * Row by form key.
	 *
	 * @param string $form_key "{post_id}:{element_id}".
	 */
	public static function find_by_key( string $form_key ): ?array {
		$db = self::db();
		return self::hydrate( $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE form_key = %s', self::table(), $form_key ), ARRAY_A ) );
	}

	/**
	 * Insert or update by form_key.
	 *
	 * @param string $form_key Form key.
	 * @param array  $data     post_id, element_id, source, name, post_title, post_type, fields, editor, status.
	 * @return int id
	 */
	public static function upsert( string $form_key, array $data ): int {
		$db       = self::db();
		$form_key = self::clip( $form_key, 64 );
		if ( $form_key === '' ) {
			return 0;
		}

		$row     = [];
		$lengths = [
			'element_id' => 32,
			'source'     => 20,
			'name'       => 191,
			'post_title' => 191,
			'post_type'  => 40,
			'status'     => 20,
		];
		foreach ( $lengths as $column => $length ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = self::clip( $data[ $column ], $length );
			}
		}
		if ( array_key_exists( 'post_id', $data ) ) {
			$row['post_id'] = absint( $data['post_id'] );
		}
		foreach ( [ 'fields', 'editor' ] as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = self::encode( is_array( $data[ $column ] ) ? $data[ $column ] : [] );
			}
		}
		$row['last_seen_gmt'] = self::now();
		$row['updated_gmt']   = self::now();

		$id = (int) $db->get_var( $db->prepare( 'SELECT id FROM %i WHERE form_key = %s', self::table(), $form_key ) );
		if ( $id ) {
			self::update_row( $id, $row );
			return $id;
		}

		$suppress = $db->suppress_errors( true );
		$id       = self::insert_row(
			array_merge(
				$row,
				[
					'form_key'    => $form_key,
					'created_gmt' => self::now(),
				]
			)
		);
		$db->suppress_errors( $suppress );
		if ( ! $id ) {
			// Another request inserted the same key in between.
			$id = (int) $db->get_var( $db->prepare( 'SELECT id FROM %i WHERE form_key = %s', self::table(), $form_key ) );
			self::update_row( $id, $row );
		}
		return $id;
	}

	/**
	 * Effective config (follows a link once); defaults when the row has no config.
	 *
	 * @param int $id Row id.
	 */
	public static function config( int $id ): FormConfig {
		return self::config_of( self::row( $id ), $id, '' );
	}

	/**
	 * Effective config by form key.
	 *
	 * @param string $form_key Form key.
	 */
	public static function config_by_key( string $form_key ): FormConfig {
		$db = self::db();
		return self::config_of( $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE form_key = %s', self::table(), $form_key ), ARRAY_A ), 0, $form_key );
	}

	/**
	 * Normalise and save a config. Raw addresses of secret receivers are moved to the secret store.
	 *
	 * @param int   $id     Row id.
	 * @param array $config Untrusted config.
	 */
	public static function save_config( int $id, array $config ): bool {
		if ( ! self::row( $id ) ) {
			return false;
		}
		$config = FormConfig::normalize( $config );
		if ( $config['link'] === $id ) {
			$config['link'] = 0;
		}
		foreach ( $config['channels'] as $channel => $route ) {
			$config['channels'][ $channel ]['receivers'] = Settings::store_receiver_secrets( $channel, $route['receivers'] );
		}
		foreach ( $config['rules'] as $i => $rule ) {
			foreach ( $rule['receivers'] as $channel => $receivers ) {
				$config['rules'][ $i ]['receivers'][ $channel ] = Settings::store_receiver_secrets( $channel, $receivers );
			}
		}

		$saved = self::update_row(
			$id,
			[
				'config'      => self::encode( $config ),
				'updated_gmt' => self::now(),
			]
		);
		if ( $saved ) {
			do_action( 'fnfe_form_config_saved', $id, $config );
		}
		return $saved;
	}

	/**
	 * List envelope.
	 *
	 * @param array $args search, status, source, page, per_page (max 100), orderby (name|last_submission_gmt|submissions_count), order.
	 */
	public static function query( array $args = [] ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'status', 'source' ] as $column ) {
			$sql = self::in_sql( $column, $args[ $column ] ?? '', $params );
			if ( $sql !== '' ) {
				$where[] = $sql;
			}
		}
		$like = self::like( $args['search'] ?? '' );
		if ( $like !== '' ) {
			$where[] = '( name LIKE %s OR post_title LIKE %s OR form_key LIKE %s )';
			array_push( $params, $like, $like, $like );
		}
		$orders = [
			'name'                => 'name',
			'last_submission_gmt' => 'last_submission_gmt',
			'submissions_count'   => 'submissions_count',
		];
		$order  = $orders[ $args['orderby'] ?? '' ] ?? 'name';
		$dir    = strtolower( (string) ( $args['order'] ?? 'asc' ) ) === 'desc' ? 'DESC' : 'ASC';
		$page   = self::paginate( implode( ' AND ', $where ), $params, "$order $dir, id $dir", (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 20 ) );

		$page['items'] = array_map( [ self::class, 'hydrate' ], $page['items'] );
		return $page;
	}

	/**
	 * Count a submission.
	 *
	 * @param int $id Row id.
	 */
	public static function touch_submission( int $id ): void {
		$db  = self::db();
		$now = self::now();
		$db->query(
			$db->prepare(
				'UPDATE %i SET submissions_count = submissions_count + 1, last_submission_gmt = %s, last_seen_gmt = %s WHERE id = %d',
				self::table(),
				$now,
				$now,
				$id
			)
		);
	}

	/**
	 * Mark active forms that were not seen as missing.
	 *
	 * @param string[] $seen_keys Form keys of the last scan.
	 * @return int rows changed
	 */
	public static function mark_missing_except( array $seen_keys ): int {
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT id, form_key FROM %i WHERE status = %s AND post_id > 0', self::table(), 'active' ), ARRAY_A );
		$seen = array_flip( array_map( 'strval', array_filter( $seen_keys, 'is_scalar' ) ) );
		$done = 0;
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $seen[ $row['form_key'] ] ) && self::update_row(
				(int) $row['id'],
				[
					'status'      => 'missing',
					'updated_gmt' => self::now(),
				]
			) ) {
				++$done;
			}
		}
		return $done;
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
	 * Raw config JSON of every form that contains a text; used to find references.
	 *
	 * @param string $needle Text.
	 */
	public static function raw_configs_containing( string $needle ): string {
		$db = self::db();
		return implode( "\n", $db->get_col( $db->prepare( 'SELECT config FROM %i WHERE config LIKE %s', self::table(), '%' . $db->esc_like( $needle ) . '%' ) ) );
	}

	/**
	 * Every form key with its stored config (export).
	 *
	 * @return array[] form_key, name, config
	 */
	public static function export_rows(): array {
		$db  = self::db();
		$out = [];
		foreach ( (array) $db->get_results( $db->prepare( "SELECT form_key, name, config FROM %i WHERE config IS NOT NULL AND config <> '' ORDER BY id", self::table() ), ARRAY_A ) as $row ) {
			$out[] = [
				'form_key' => $row['form_key'],
				'name'     => $row['name'],
				'config'   => FormConfig::normalize( self::decode( $row['config'] ) ),
			];
		}
		return $out;
	}

	/**
	 * Build the config object, following one link.
	 *
	 * @param array|null $row      Row.
	 * @param int        $id       Requested id.
	 * @param string     $form_key Requested key.
	 */
	private static function config_of( $row, int $id, string $form_key ): FormConfig {
		if ( ! is_array( $row ) ) {
			return new FormConfig( $id, $form_key, [] );
		}
		$data = self::decode( $row['config'] );
		$link = absint( $data['link'] ?? 0 );
		if ( $link && $link !== (int) $row['id'] ) {
			$linked = self::row( $link );
			if ( $linked ) {
				$data         = self::decode( $linked['config'] );
				$data['link'] = $link;
			}
		}
		return new FormConfig( (int) $row['id'], (string) $row['form_key'], $data );
	}

	/**
	 * Types and decoded JSON.
	 *
	 * @param array|null $row Row.
	 */
	private static function hydrate( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( [ 'id', 'post_id', 'submissions_count' ] as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		$row['fields'] = self::decode( $row['fields'] );
		$row['config'] = FormConfig::normalize( self::decode( $row['config'] ) );
		$row['editor'] = self::decode( $row['editor'] );
		return $row;
	}
}
