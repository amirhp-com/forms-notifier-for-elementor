<?php
/**
 * Run by RequirementsTest in a process without mbstring.
 *
 * @package FormsNotifier
 */

// phpcs:disable

use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

// Plain PHP, not WP-CLI: WP-CLI brings its own replacement for mbstring.
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST'] = (string) parse_url( (string) $argv[2], PHP_URL_HOST ) . ( parse_url( (string) $argv[2], PHP_URL_PORT ) ? ':' . parse_url( (string) $argv[2], PHP_URL_PORT ) : '' );
if ( (string) getenv( 'FNFE_TEST_PRELOAD' ) !== '' ) {
	require getenv( 'FNFE_TEST_PRELOAD' ); // Lets a developer run this against other copies of classes.
}
require rtrim( (string) $argv[1], '/' ) . '/wp-load.php';

foreach ( [ 'Assert', 'TestCase', 'HttpMock', 'Db', 'Fixtures' ] as $file ) {
	require_once dirname( __DIR__, 3 ) . '/lib/' . $file . '.php';
}
AmirhpCom\FormsNotifier\Tests\Db::isolate();
AmirhpCom\FormsNotifier\Tests\HttpMock::install();
AmirhpCom\FormsNotifier\Tests\Db::reset();

$draft = AmirhpCom\FormsNotifier\Tests\Fixtures::submission( [ 'id' => 0 ] );
$draft->meta['via'] = 'api';
add_filter( 'fnfe_should_capture', '__return_true' );
$saved  = Capture::ingest( $draft );
$found  = SubmissionRepository::query( [ 'search' => 'SECOND' ] );
$stored = $saved && $saved->id > 0;
echo 'RESULT ' . wp_json_encode(
	[
		'mbstring' => Text::has_mbstring(),
		'stored'   => $stored,
		'found'    => $stored && $found['total'] === 1,
		'valid'    => Text::is_utf8( Text::scrub( "a\xE7" ) ),
	]
) . "\n";
AmirhpCom\FormsNotifier\Tests\Db::restore();
