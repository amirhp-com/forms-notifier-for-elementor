<?php
/**
 * Integration tests: wp eval-file tests/run.php [filter] [verbose]
 *
 * @package FormsNotifier
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	echo "Run with WP-CLI: wp eval-file tests/run.php\n";
	exit( 1 );
}
if ( ! defined( 'FNFE_VERSION' ) ) {
	WP_CLI::error( 'The plugin is not active.' );
}

( static function ( array $args ) {
	foreach ( [ 'Assert', 'TestCase', 'HttpMock', 'Db', 'Fixtures', 'FakeChannel', 'Runner' ] as $file ) {
		require_once __DIR__ . '/lib/' . $file . '.php';
	}
	$verbose = (bool) array_intersect( [ 'verbose', '-v' ], $args );
	$filter  = (string) current( array_diff( $args, [ 'verbose', '-v' ] ) );

	AmirhpCom\FormsNotifier\Tests\Db::isolate();
	AmirhpCom\FormsNotifier\Tests\HttpMock::install();
	$code = ( new AmirhpCom\FormsNotifier\Tests\Runner( $filter, $verbose, false ) )->run();
	AmirhpCom\FormsNotifier\Tests\Db::restore();
	if ( $code ) {
		WP_CLI::halt( $code );
	}
} )( isset( $args ) ? (array) $args : [] );
