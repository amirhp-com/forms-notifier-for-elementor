<?php
/**
 * Pure tests, no WordPress: php tests/unit.php [filter] [verbose]
 *
 * @package FormsNotifier
 */

// phpcs:disable

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
date_default_timezone_set( 'UTC' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'FNFE_VERSION', '1.0.0' );
define( 'FNFE_DB_VERSION', '1' );
define( 'FNFE_DIR', dirname( __DIR__ ) . '/forms-notifier-for-elementor/' );
define( 'FNFE_URL', 'https://example.com/wp-content/plugins/forms-notifier-for-elementor/' );
define( 'FNFE_BASENAME', 'forms-notifier-for-elementor/forms-notifier-for-elementor.php' );

require __DIR__ . '/lib/Shims.php';
require FNFE_DIR . 'includes/Autoloader.php';
AmirhpCom\FormsNotifier\Autoloader::register();
foreach ( [ 'Assert', 'TestCase', 'HttpMock', 'Fixtures', 'Runner' ] as $file ) {
	require_once __DIR__ . '/lib/' . $file . '.php';
}

$args    = array_slice( $argv, 1 );
$verbose = (bool) array_intersect( [ 'verbose', '--verbose', '-v' ], $args );
$filter  = (string) current( array_diff( $args, [ 'verbose', '--verbose', '-v' ] ) );
exit( ( new AmirhpCom\FormsNotifier\Tests\Runner( $filter, $verbose, true ) )->run() );
