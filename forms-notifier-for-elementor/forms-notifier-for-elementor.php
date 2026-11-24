<?php
/**
 * Plugin Name:       Forms Notifier for Elementor
 * Plugin URI:        https://github.com/amirhp-com/forms-notifier-for-elementor
 * Description:       Send Elementor form submissions to Telegram, Bale, SMS, WhatsApp, email and webhooks, with templates, logs and retries.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            Amirhossein Hosseinpour
 * Author URI:        https://amirhp.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forms-notifier-for-elementor
 * Domain Path:       /languages
 *
 * @package FormsNotifier
 */

defined( 'ABSPATH' ) || exit;

define( 'FNFE_VERSION', '1.0.0' );
define( 'FNFE_DB_VERSION', '1' );
define( 'FNFE_FILE', __FILE__ );
define( 'FNFE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FNFE_URL', plugin_dir_url( __FILE__ ) );
define( 'FNFE_BASENAME', plugin_basename( __FILE__ ) );
define( 'FNFE_MIN_PHP', '7.4' );
define( 'FNFE_MIN_WP', '6.5' );

/**
 * Notice shown instead of booting on an unsupported PHP or WordPress version.
 */
function fnfe_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html(
		sprintf(
			/* translators: %1$s: minimum PHP version, %2$s: minimum WordPress version */
			__( 'Forms Notifier for Elementor needs PHP %1$s or newer and WordPress %2$s or newer.', 'forms-notifier-for-elementor' ),
			FNFE_MIN_PHP,
			FNFE_MIN_WP
		)
	);
	echo '</p></div>';
}

if ( version_compare( PHP_VERSION, FNFE_MIN_PHP, '<' ) || version_compare( $GLOBALS['wp_version'], FNFE_MIN_WP, '<' ) ) {
	add_action( 'admin_notices', 'fnfe_requirements_notice' );
	return;
}

require FNFE_DIR . 'includes/Autoloader.php';
AmirhpCom\FormsNotifier\Autoloader::register();
require FNFE_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'AmirhpCom\FormsNotifier\Core\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AmirhpCom\FormsNotifier\Core\Installer', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'AmirhpCom\FormsNotifier\Plugin', 'boot' ), 5 );
