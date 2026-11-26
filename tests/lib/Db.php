<?php
/**
 * Database isolation.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;

/**
 * A run works on its own copies: plugin tables with a run prefix, a copy of the options table and a temporary uploads
 * directory. Nothing of the site changes and several runs may happen at the same time.
 */
final class Db {

	/** @var string */
	private static $options_table = '';

	/** @var string */
	private static $site_options_table = '';

	/** @var string */
	private static $uploads = '';

	/** Switch to the private copies. */
	public static function isolate(): void {
		global $wpdb;

		$run = 'fnfe_t' . getmypid() . '_';
		add_filter(
			'fnfe_table_prefix',
			static function () use ( $run ) {
				return $run;
			}
		);

		self::$site_options_table = $wpdb->options;
		self::$options_table      = $wpdb->prefix . $run . 'options';
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$options_table ); // phpcs:ignore WordPress.DB
		$wpdb->query( // phpcs:ignore WordPress.DB
			'CREATE TABLE ' . self::$options_table . " (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);
		$wpdb->query( 'INSERT INTO ' . self::$options_table . ' ( option_name, option_value, autoload ) SELECT option_name, option_value, autoload FROM ' . self::$site_options_table ); // phpcs:ignore WordPress.DB
		$wpdb->options = self::$options_table;

		self::$uploads = sys_get_temp_dir() . '/fnfe-tests-' . getmypid();
		wp_mkdir_p( self::$uploads );
		add_filter(
			'upload_dir',
			static function ( array $dirs ): array {
				return array_merge(
					$dirs,
					[
						'basedir' => self::$uploads,
						'path'    => self::$uploads . $dirs['subdir'],
						'error'   => false,
					]
				);
			}
		);

		self::closed_storage( true );
		register_shutdown_function( [ self::class, 'restore' ] );
		DbSchema::install();
	}

	/**
	 * The storage check asks the web server whether the files folder is closed, and Stats::health() runs it for the
	 * dashboard and the digest. During a run the cached answer is "closed"; tests of the probe switch this off.
	 *
	 * @param bool $on Answer from the cache.
	 */
	public static function closed_storage( bool $on ): void {
		$hook = 'pre_transient_' . \AmirhpCom\FormsNotifier\Attachments\Storage::PROBE_CACHE;
		remove_filter( $hook, [ self::class, 'closed_answer' ] );
		if ( $on ) {
			add_filter( $hook, [ self::class, 'closed_answer' ] );
		}
	}

	/** Filter pre_transient_fnfe_files_exposed. */
	public static function closed_answer(): array {
		return [
			'dir'  => md5( \AmirhpCom\FormsNotifier\Attachments\Storage::base() ),
			'open' => false,
		];
	}

	/** Drop the copies. */
	public static function restore(): void {
		global $wpdb;
		if ( self::$options_table === '' ) {
			return;
		}
		DbSchema::drop();
		$wpdb->options = self::$site_options_table;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$options_table ); // phpcs:ignore WordPress.DB
		self::$options_table = '';
		self::remove( self::$uploads );
	}

	/** Temporary uploads directory of the run. */
	public static function uploads(): string {
		return self::$uploads;
	}

	/** Empty the plugin tables, remove fnfe_* options and transients, clear caches. Runs before every class. */
	public static function reset(): void {
		global $wpdb;
		if ( self::$options_table === '' ) {
			throw new \RuntimeException( 'Db::reset() outside of an isolated run.' );
		}
		foreach ( DbSchema::tables() as $table ) {
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
		}
		self::delete_options();
		update_option( 'fnfe_db_version', FNFE_DB_VERSION );
		update_option( 'fnfe_version', FNFE_VERSION );
		self::flush();
	}

	/** Remove fnfe_* options and transients. */
	public static function delete_options(): void {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'fnfe\\_%' OR option_name LIKE '\\_transient\\_fnfe\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fnfe\\_%'" ); // phpcs:ignore WordPress.DB
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Clear every static cache of the plugin. */
	public static function flush(): void {
		Settings::clear_overlay();
		Registry::reset();
		Schema::flush();
		Settings::flush_cache();
	}

	/** Store options as they are; the value null deletes. */
	public static function options( array $options ): void {
		foreach ( $options as $name => $value ) {
			if ( $value === null ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		self::flush();
	}

	private static function remove( string $dir ): void {
		if ( strpos( $dir, '/fnfe-tests-' ) === false || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry !== '.' && $entry !== '..' ) {
				$path = $dir . '/' . $entry;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::remove( $path );
				} else {
					unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
