<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class DbSchemaTest extends TestCase {

	public function test_idempotent(): void {
		$this->assertTrue( DbSchema::exists() );
		$this->assertSame( [], DbSchema::install() );
		$this->assertSame( [], DbSchema::install() );
	}

	public function test_all_indexes_exist(): void {
		global $wpdb;
		$expected = [
			'forms'       => [ 'PRIMARY' => [ 'id' ], 'form_key' => [ 'form_key' ], 'post_id' => [ 'post_id' ], 'status' => [ 'status' ] ],
			'submissions' => [ 'PRIMARY' => [ 'id' ], 'uuid' => [ 'uuid' ], 'form_id' => [ 'form_id' ], 'status' => [ 'status' ], 'created_gmt' => [ 'created_gmt' ], 'content_hash' => [ 'content_hash' ], 'user_id' => [ 'user_id' ] ],
			'files'       => [ 'PRIMARY' => [ 'id' ], 'submission_id' => [ 'submission_id' ] ],
			'messages'    => [ 'PRIMARY' => [ 'id' ], 'status_next' => [ 'status', 'next_attempt_gmt' ], 'submission_id' => [ 'submission_id' ], 'form_id' => [ 'form_id' ], 'channel' => [ 'channel' ], 'created_gmt' => [ 'created_gmt' ] ],
			'chats'       => [ 'PRIMARY' => [ 'id' ], 'chat' => [ 'platform', 'chat_id', 'thread_id' ], 'type' => [ 'type' ], 'last_seen_gmt' => [ 'last_seen_gmt' ] ],
			'log'         => [ 'PRIMARY' => [ 'id' ], 'level' => [ 'level' ], 'created_gmt' => [ 'created_gmt' ] ],
		];
		foreach ( $expected as $name => $indexes ) {
			$found = [];
			foreach ( $wpdb->get_results( 'SHOW INDEX FROM ' . DbSchema::table( $name ) ) as $row ) {
				$found[ $row->Key_name ][] = $row->Column_name;
			}
			ksort( $found );
			ksort( $indexes );
			$this->assertSame( $indexes, $found, $name );
		}
	}

	public function test_dbdelta_rules(): void {
		foreach ( DbSchema::statements() as $sql ) {
			$this->assertContains( 'PRIMARY KEY  (id)', $sql );
			$this->assertNotContains( '`', $sql );
			$this->assertNotContains( 'IF NOT EXISTS', $sql );
			$this->assertFalse( (bool) preg_match( '/\b(enum|json|fulltext|foreign)\b/i', $sql ) );
		}
	}

	public function test_no_reserved_words_as_columns(): void {
		$reserved = [ 'STORED', 'VIRTUAL', 'KEY', 'KEYS', 'INDEX', 'ORDER', 'GROUP', 'RANK', 'GROUPS', 'SYSTEM', 'WINDOW', 'ROWS', 'ROW_NUMBER', 'LATERAL', 'OF', 'OVER', 'READ', 'RECURSIVE', 'TRIGGER', 'USAGE', 'VALUES', 'CONDITION', 'RANGE', 'LOCK', 'MATCH', 'OPTION' ];
		foreach ( DbSchema::statements() as $sql ) {
			preg_match_all( '/^  ([a-z_]+) [a-z]/m', $sql, $m );
			$this->assertSame( [], array_intersect( array_map( 'strtoupper', $m[1] ), $reserved ) );
		}
	}
}
