<?php
/**
 * Database schema.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Table names and CREATE TABLE statements. */
final class DbSchema {

	/** Short table names. */
	public const TABLES = [ 'forms', 'submissions', 'files', 'messages', 'chats', 'log' ];

	/**
	 * Full name of a plugin table.
	 *
	 * @param string $name Short name.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . (string) apply_filters( 'fnfe_table_prefix', 'fnfe_' ) . $name;
	}

	/** Full names keyed by short name. */
	public static function tables(): array {
		$out = [];
		foreach ( self::TABLES as $name ) {
			$out[ $name ] = self::table( $name );
		}
		return $out;
	}

	/** Create or update the tables. Returns what dbDelta changed. */
	public static function install(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		return dbDelta( self::statements() );
	}

	/** Drop the tables. */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
		}
	}

	/** Every table exists. */
	public static function exists(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * CREATE TABLE statements in the format dbDelta expects.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$out     = [];
		foreach ( self::definitions() as $name => $body ) {
			$out[] = 'CREATE TABLE ' . self::table( $name ) . " (\n" . trim( $body ) . "\n) $charset;";
		}
		return $out;
	}

	/** Columns and keys by short table name. */
	private static function definitions(): array {
		return [
			'forms'       => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_key varchar(64) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  element_id varchar(32) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'pro',
  name varchar(191) NOT NULL DEFAULT '',
  post_title varchar(191) NOT NULL DEFAULT '',
  post_type varchar(40) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  fields longtext NULL,
  config longtext NULL,
  editor longtext NULL,
  submissions_count bigint(20) unsigned NOT NULL DEFAULT 0,
  last_submission_gmt datetime NULL,
  last_seen_gmt datetime NULL,
  created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY form_key (form_key),
  KEY post_id (post_id),
  KEY status (status)
			",
			'submissions' => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(32) NOT NULL DEFAULT '',
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  form_key varchar(64) NOT NULL DEFAULT '',
  form_name varchar(191) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'pro',
  status varchar(20) NOT NULL DEFAULT 'new',
  lang varchar(20) NOT NULL DEFAULT '',
  fields longtext NULL,
  meta longtext NULL,
  search_text longtext NULL,
  content_hash char(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_ip varchar(46) NOT NULL DEFAULT '',
  files_count smallint(5) unsigned NOT NULL DEFAULT 0,
  elementor_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  delivery_status varchar(20) NOT NULL DEFAULT 'none',
  created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY form_id (form_id),
  KEY status (status),
  KEY created_gmt (created_gmt),
  KEY content_hash (content_hash),
  KEY user_id (user_id)
			",
			'files'       => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  field_id varchar(64) NOT NULL DEFAULT '',
  name varchar(191) NOT NULL DEFAULT '',
  path text NULL,
  url text NULL,
  mime varchar(100) NOT NULL DEFAULT '',
  ext varchar(20) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  stored tinyint(1) NOT NULL DEFAULT 0,
  token char(32) NOT NULL DEFAULT '',
  created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY submission_id (submission_id)
			",
			'messages'    => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(40) NOT NULL DEFAULT '',
  provider varchar(60) NOT NULL DEFAULT '',
  purpose varchar(20) NOT NULL DEFAULT 'admin',
  recipient varchar(191) NOT NULL DEFAULT '',
  recipient_label varchar(191) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
  next_attempt_gmt datetime NULL,
  locked_until_gmt datetime NULL,
  lock_token varchar(32) NOT NULL DEFAULT '',
  subject varchar(191) NOT NULL DEFAULT '',
  body longtext NULL,
  payload longtext NULL,
  response text NULL,
  error text NULL,
  remote_id varchar(191) NOT NULL DEFAULT '',
  http_code smallint(5) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  sent_gmt datetime NULL,
  PRIMARY KEY  (id),
  KEY status_next (status,next_attempt_gmt),
  KEY submission_id (submission_id),
  KEY form_id (form_id),
  KEY channel (channel),
  KEY created_gmt (created_gmt)
			",
			'chats'       => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  platform varchar(20) NOT NULL DEFAULT '',
  chat_id varchar(40) NOT NULL DEFAULT '',
  thread_id bigint(20) NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT '',
  title varchar(191) NOT NULL DEFAULT '',
  username varchar(64) NOT NULL DEFAULT '',
  topic_name varchar(191) NOT NULL DEFAULT '',
  is_forum tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  bot_role varchar(20) NOT NULL DEFAULT '',
  user_id varchar(40) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  meta longtext NULL,
  first_seen_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  last_seen_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY chat (platform,chat_id,thread_id),
  KEY type (type),
  KEY last_seen_gmt (last_seen_gmt)
			",
			'log'         => "
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL DEFAULT 'info',
  context varchar(40) NOT NULL DEFAULT '',
  message text NULL,
  data longtext NULL,
  created_gmt datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY level (level),
  KEY created_gmt (created_gmt)
			",
		];
	}
}
