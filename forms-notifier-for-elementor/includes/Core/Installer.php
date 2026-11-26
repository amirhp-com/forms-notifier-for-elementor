<?php
/**
 * Activation, deactivation, uninstall, multisite.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;

defined( 'ABSPATH' ) || exit;

/** Creates and removes everything the plugin owns. */
final class Installer {

	/** Sites handled per query on network activation. */
	private const BATCH = 100;

	/** Options removed with the data. */
	private const OPTIONS = [ 'fnfe_settings', 'fnfe_secrets', 'fnfe_state', 'fnfe_db_version', 'fnfe_version' ];

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::each_site( [ self::class, 'install_site' ] );
			return;
		}
		self::install_site();
		if ( ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides about a redirect.
			set_transient( 'fnfe_activated', 1, 30 );
		}
	}

	/**
	 * Deactivation hook: clears scheduled events only.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			self::each_site( [ Cron::class, 'clear_all' ] );
			return;
		}
		Cron::clear_all();
	}

	/** Tables, default options and events of the current site. */
	public static function install_site(): void {
		DbSchema::install();

		if ( get_option( 'fnfe_db_version', '' ) === '' ) {
			self::seed();
		}
		// A failed install is tried again later (see Upgrades).
		if ( DbSchema::exists() ) {
			update_option( 'fnfe_db_version', FNFE_DB_VERSION, true );
		}
		update_option( 'fnfe_version', FNFE_VERSION, true );

		// Rewrite rules of the mini app are flushed on the next init.
		State::set( 'flush_rewrite', true );

		Cron::register();
		if ( did_action( 'init' ) ) {
			Cron::ensure_events();
		}
	}

	/**
	 * Tables for a new site of the network.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( FNFE_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Remove the tables of a deleted site.
	 *
	 * @param \WP_Site $site Deleted site.
	 */
	public static function on_delete_site( $site ): void {
		switch_to_blog( (int) $site->blog_id );
		DbSchema::drop();
		restore_current_blog();
	}

	/** Uninstall on every site. */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			self::each_site( [ self::class, 'uninstall_site' ] );
			return;
		}
		self::uninstall_site();
	}

	/** Uninstall the current site: data stays unless privacy.delete_on_uninstall is on. */
	public static function uninstall_site(): void {
		$delete = Settings::bool( 'privacy.delete_on_uninstall' );

		if ( $delete ) {
			self::delete_bot_webhooks();
		}

		Cron::clear_all();
		self::delete_temporary_options();

		if ( ! $delete ) {
			return;
		}

		DbSchema::drop();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( [ 'fnfe_ui', 'fnfe_bot_ids' ] as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		self::delete_storage();
		Settings::flush_cache();
	}

	/**
	 * Stored files of the current site. The default folder belongs to the plugin and goes completely. A folder moved
	 * with FNFE_FILES_DIR may be shared: only the files the plugin creates are deleted there, then empty folders.
	 * During uninstall the plugin is not loaded, so a fnfe_files_dir filter in a theme or plugin is not applied.
	 */
	private static function delete_storage(): void {
		$default = Storage::default_base();
		foreach ( Storage::roots() as $dir ) {
			if ( $dir === $default ) {
				self::delete_directory( $dir );
			} else {
				self::delete_own_files( $dir );
			}
		}
	}

	/**
	 * Delete the files the storage creates inside a folder and its year/month folders, then the folders left empty.
	 *
	 * @param string $dir   Directory.
	 * @param int    $depth 0 = root, 1 = year, 2 = month.
	 */
	private static function delete_own_files( string $dir, int $depth = 0 ): void {
		if ( is_link( $dir ) || ! is_dir( $dir ) || in_array( untrailingslashit( wp_normalize_path( $dir ) ), self::protected_dirs(), true ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				if ( $depth < 2 && preg_match( $depth === 0 ? '/^\d{4}$/' : '/^\d{2}$/', (string) $entry ) ) {
					self::delete_own_files( $path, $depth + 1 );
				}
			} elseif ( Storage::is_stored_name( (string) $entry, true ) ) {
				wp_delete_file( $path );
			}
		}
		$left = array_diff( (array) scandir( $dir ), [ '.', '..' ] );
		if ( ! $left ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty storage folder; WP_Filesystem may need credentials here.
		}
	}

	/**
	 * Folders that are never removed, even when FNFE_FILES_DIR points at one of them.
	 *
	 * @return string[]
	 */
	private static function protected_dirs(): array {
		$uploads = wp_upload_dir( null, false );
		$list    = [ ABSPATH, WP_CONTENT_DIR, (string) $uploads['basedir'], dirname( ABSPATH ) ];
		return array_map(
			static function ( $dir ) {
				return untrailingslashit( wp_normalize_path( (string) $dir ) );
			},
			$list
		);
	}

	/** Values that depend on the site at install time. */
	private static function seed(): void {
		$locale = I18n::site_locale();
		$parts  = explode( '_', $locale );
		if ( Settings::str( 'general.default_country' ) === '' && isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ) {
			Settings::set( 'general.default_country', $parts[1] );
		}
	}

	/** Transients and locks. */
	private static function delete_temporary_options(): void {
		global $wpdb;
		foreach ( [ '_transient_fnfe_', '_transient_timeout_fnfe_', 'fnfe_lock_' ] as $prefix ) {
			$names = $wpdb->get_col(
				$wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' )
			);
			foreach ( $names as $name ) {
				delete_option( $name );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			}
		}
	}

	/** Best effort: tell the messengers not to call this site any more. */
	private static function delete_bot_webhooks(): void {
		foreach ( [ 'telegram', 'bale' ] as $platform ) {
			$token = Settings::secret( "channels.$platform.token" );
			if ( $token === '' ) {
				continue;
			}
			$base = Settings::str( "channels.$platform.api_base" );
			if ( $base === '' ) {
				$base = BotClient::default_base( $platform );
			}
			Http::post( untrailingslashit( $base ) . '/bot' . $token . '/deleteWebhook', [ 'timeout' => 5 ] );
		}
	}

	/**
	 * Delete a directory inside the uploads folder.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function delete_directory( string $dir ): void {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Own directory inside uploads; WP_Filesystem may need credentials here.
	}

	/**
	 * Run a callback on every site of the network.
	 *
	 * @param callable $callback Callback.
	 */
	private static function each_site( callable $callback ): void {
		$offset = 0;
		do {
			$ids = get_sites(
				[
					'fields' => 'ids',
					'number' => self::BATCH,
					'offset' => $offset,
				]
			);
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				$callback();
				restore_current_blog();
			}
			$count   = count( $ids );
			$offset += self::BATCH;
		} while ( $count === self::BATCH );
	}
}
