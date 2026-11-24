<?php
/**
 * Runs when the plugin is deleted.
 *
 * @package FormsNotifier
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'FNFE_VERSION' ) ) {
	define( 'FNFE_VERSION', '1.0.0' );
}
if ( ! defined( 'FNFE_DIR' ) ) {
	define( 'FNFE_DIR', plugin_dir_path( __FILE__ ) );
}

require_once FNFE_DIR . 'includes/Autoloader.php';

AmirhpCom\FormsNotifier\Autoloader::register();
AmirhpCom\FormsNotifier\Core\Installer::uninstall();
