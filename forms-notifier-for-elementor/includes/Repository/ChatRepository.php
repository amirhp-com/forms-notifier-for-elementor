<?php
/**
 * Discovered chats.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Repository;

use AmirhpCom\FormsNotifier\Core\DbSchema;

defined( 'ABSPATH' ) || exit;

/** Table fnfe_chats: one row per chat (thread_id 0) plus one row per forum topic. */
final class ChatRepository extends Repository {

	private const LENGTHS = [
		'type'       => 20,
		'title'      => 191,
		'username'   => 64,
		'topic_name' => 191,
		'status'     => 20,
		'bot_role'   => 20,
		'user_id'    => 40,
		'lang'       => 20,
	];

	/** Full table name. */
	public static function table(): string {
		return DbSchema::table( 'chats' );
	}

	/**
	 * Insert/update by (platform, chat_id, thread_id).
	 *
	 * @param string $platform  Platform.
	 * @param string $chat_id   Chat id.
	 * @param int    $thread_id Topic id, 0 = the chat itself.
	 * @param array  $data      type,title,username,topic_name,is_forum,status,bot_role,user_id,lang,meta.
	 * @return int id
	 */
	public static function upsert( string $platform, string $chat_id, int $thread_id, array $data ): int {
		if ( $platform === '' || $chat_id === '' ) {
			return 0;
		}
		$row = [ 'last_seen_gmt' => self::now() ];
		foreach ( self::LENGTHS as $column => $length ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = self::clip( $data[ $column ], $length );
			}
		}
		if ( array_key_exists( 'is_forum', $data ) ) {
			$row['is_forum'] = $data['is_forum'] ? 1 : 0;
		}
		if ( array_key_exists( 'meta', $data ) ) {
			$row['meta'] = self::encode( is_array( $data['meta'] ) ? $data['meta'] : [] );
		}

		$existing = self::find_chat( $platform, $chat_id, $thread_id );
		if ( $existing ) {
			self::update_row( $existing['id'], $row );
			return $existing['id'];
		}

		$db       = self::db();
		$suppress = $db->suppress_errors( true );
		$id       = self::insert_row(
			array_merge(
				$row,
				[
					'platform'       => self::clip( $platform, 20 ),
					'chat_id'        => self::clip( $chat_id, 40 ),
					'thread_id'      => $thread_id,
					'first_seen_gmt' => self::now(),
				]
			)
		);
		$db->suppress_errors( $suppress );
		if ( ! $id ) {
			$existing = self::find_chat( $platform, $chat_id, $thread_id );
			$id       = $existing ? $existing['id'] : 0;
			self::update_row( $id, $row );
		}
		return $id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 */
	public static function find( int $id ): ?array {
		return self::hydrate( self::row( $id ) );
	}

	/**
	 * One chat or topic.
	 *
	 * @param string $platform  Platform.
	 * @param string $chat_id   Chat id.
	 * @param int    $thread_id Topic id.
	 */
	public static function find_chat( string $platform, string $chat_id, int $thread_id = 0 ): ?array {
		$db = self::db();
		return self::hydrate(
			$db->get_row(
				$db->prepare( 'SELECT * FROM %i WHERE platform = %s AND chat_id = %s AND thread_id = %d', self::table(), $platform, $chat_id, $thread_id ),
				ARRAY_A
			)
		);
	}

	/**
	 * List envelope.
	 *
	 * @param array $args platform, type (string|string[]), status, search, with_topics (bool), page, per_page.
	 */
	public static function query( array $args = [] ): array {
		$where  = [];
		$params = [];
		foreach ( [ 'platform', 'type', 'status' ] as $column ) {
			$sql = self::in_sql( $column, $args[ $column ] ?? '', $params );
			if ( $sql !== '' ) {
				$where[] = $sql;
			}
		}
		if ( empty( $args['with_topics'] ) ) {
			$where[] = 'thread_id = 0';
		}
		$like = self::like( $args['search'] ?? '' );
		if ( $like !== '' ) {
			$where[] = '( title LIKE %s OR username LIKE %s OR topic_name LIKE %s OR chat_id LIKE %s )';
			array_push( $params, $like, $like, $like, $like );
		}
		$page = self::paginate( implode( ' AND ', $where ), $params, 'last_seen_gmt DESC, id DESC', (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 20 ) );

		$page['items'] = array_map( [ self::class, 'hydrate' ], $page['items'] );
		return $page;
	}

	/**
	 * Topics of a forum.
	 *
	 * @param string $platform Platform.
	 * @param string $chat_id  Chat id.
	 * @return array[]
	 */
	public static function topics( string $platform, string $chat_id ): array {
		$db   = self::db();
		$rows = $db->get_results(
			$db->prepare( 'SELECT * FROM %i WHERE platform = %s AND chat_id = %s AND thread_id > 0 ORDER BY thread_id ASC', self::table(), $platform, $chat_id ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], (array) $rows );
	}

	/**
	 * A group became a supergroup: copy its rows to the new id and mark the old ones migrated.
	 *
	 * @param string $platform    Platform.
	 * @param string $old_chat_id Old id.
	 * @param string $new_chat_id New id.
	 */
	public static function migrate( string $platform, string $old_chat_id, string $new_chat_id ): void {
		if ( $old_chat_id === $new_chat_id || $new_chat_id === '' ) {
			return;
		}
		$db   = self::db();
		$rows = $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE platform = %s AND chat_id = %s', self::table(), $platform, $old_chat_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$row = self::hydrate( $row );
			if ( $row['status'] === 'migrated' ) {
				continue;
			}
			self::upsert(
				$platform,
				$new_chat_id,
				$row['thread_id'],
				[
					'type'       => 'supergroup',
					'title'      => $row['title'],
					'username'   => $row['username'],
					'topic_name' => $row['topic_name'],
					'is_forum'   => $row['is_forum'],
					'status'     => 'active',
					'bot_role'   => $row['bot_role'],
					'lang'       => $row['lang'],
					'meta'       => array_merge( $row['meta'], [ 'migrated_from' => $old_chat_id ] ),
				]
			);
			self::update_row(
				$row['id'],
				[
					'status' => 'migrated',
					'meta'   => self::encode( array_merge( $row['meta'], [ 'migrated_to' => $new_chat_id ] ) ),
				]
			);
		}
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
	 * Types and decoded meta.
	 *
	 * @param array|null $row Row.
	 */
	private static function hydrate( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']        = (int) $row['id'];
		$row['thread_id'] = (int) $row['thread_id'];
		$row['is_forum']  = (bool) (int) $row['is_forum'];
		$row['meta']      = self::decode( $row['meta'] );
		return $row;
	}
}
