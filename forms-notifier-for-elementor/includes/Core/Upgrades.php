<?php
/**
 * Upgrade routines.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Versioned upgrade routines. */
final class Upgrades {

	/** Run what is due. Cheap when nothing is. */
	public static function maybe_run(): void {
		$installed = (string) get_option( 'fnfe_db_version', '' );
		if ( $installed !== FNFE_DB_VERSION && RateLimit::hit( 'install', 1, 5 * MINUTE_IN_SECONDS ) ) {
			Installer::install_site();
		}
		if ( get_option( 'fnfe_version' ) !== FNFE_VERSION ) {
			update_option( 'fnfe_version', FNFE_VERSION, true );
		}
	}
}
