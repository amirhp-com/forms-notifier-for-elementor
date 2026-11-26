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
	$words   = [ 'verbose', '-v', 'skew' ];
	$verbose = (bool) array_intersect( [ 'verbose', '-v' ], $args );
	$filter  = (string) current( array_diff( $args, $words ) );

	// Tests come from this tree, the code under test from the active plugin: both must be the same code.
	$tree = dirname( __DIR__ ) . '/forms-notifier-for-elementor/';
	if ( ! in_array( 'skew', $args, true ) && realpath( $tree ) !== realpath( FNFE_DIR ) ) {
		$fingerprint = static function ( string $dir ): string {
			$out = [];
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . 'includes', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( substr( $file->getFilename(), -4 ) === '.php' ) {
					$out[ substr( $file->getPathname(), strlen( $dir ) ) ] = md5_file( $file->getPathname() );
				}
			}
			ksort( $out );
			return md5( serialize( $out ) );
		};
		if ( is_dir( $tree . 'includes' ) && $fingerprint( $tree ) !== $fingerprint( FNFE_DIR ) ) {
			WP_CLI::error( 'The active plugin differs from this working tree: sync the plugin first (or add the word "skew" to run anyway).' );
		}
	}

	AmirhpCom\FormsNotifier\Tests\Db::isolate();
	AmirhpCom\FormsNotifier\Tests\HttpMock::install();
	$code = ( new AmirhpCom\FormsNotifier\Tests\Runner( $filter, $verbose, false ) )->run();
	AmirhpCom\FormsNotifier\Tests\Db::restore();
	if ( $code ) {
		WP_CLI::halt( $code );
	}
} )( isset( $args ) ? (array) $args : [] );
