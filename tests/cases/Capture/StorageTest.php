<?php
/**
 * Storage folder: location, probe of the web server, Site Health, uninstall.
 *
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Installer;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Health\SiteHealth;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class StorageTest extends TestCase {

	/** @var string */
	private $moved = '';

	public function setUp(): void {
		Db::reset();
		Db::closed_storage( false );
		Storage::forget_probe();
		$this->moved = Db::uploads() . '-moved-' . wp_generate_password( 6, false );
	}

	public function tearDown(): void {
		Storage::forget_probe();
		Db::closed_storage( true );
		if ( $this->moved !== '' && is_dir( $this->moved ) ) {
			$this->remove( $this->moved );
		}
	}

	private function remove( string $dir ): void {
		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $entry ) {
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/** Move the folder out of every public folder with the filter. */
	private function move(): void {
		$moved = $this->moved;
		$this->filter( 'fnfe_files_dir', static function () use ( $moved ) {
			return $moved;
		} );
	}

	private function probe_url(): string {
		return '~^' . preg_quote( Storage::public_url(), '~' ) . '/probe\.txt$~';
	}

	public function test_default_location(): void {
		$this->assertSame( wp_normalize_path( Db::uploads() ) . '/fnfe-files', Storage::base() );
		$this->assertSame( Storage::base() . '/', Storage::dir() );
		$this->assertSame( [ Storage::base() ], Storage::roots() );
		$uploads = wp_upload_dir( null, false );
		$this->assertSame( untrailingslashit( $uploads['baseurl'] ) . '/fnfe-files', Storage::public_url() );
	}

	public function test_constant_and_filter(): void {
		// The constant cannot be defined in a test run, so its value goes through resolve() as base() passes it.
		$this->assertSame( '/srv/private/fnfe-files', Storage::resolve( '/srv/private/fnfe-files/' ) );
		$this->assertSame( Storage::default_base(), Storage::resolve( null ) );
		$this->assertSame( Storage::default_base(), Storage::resolve( '' ) );
		$this->assertSame( Storage::default_base(), Storage::resolve( 42 ) );

		$this->filter( 'fnfe_files_dir', static function ( $dir ) {
			return $dir === '/srv/private/fnfe-files' ? '/srv/other' : $dir;
		} );
		$this->assertSame( '/srv/other', Storage::resolve( '/srv/private/fnfe-files' ), 'the filter wins over the constant' );

		$this->filter( 'fnfe_files_dir', static function () {
			return 'phar:///tmp/x.phar';
		}, 20 );
		$this->assertSame( Storage::default_base(), Storage::base(), 'stream wrappers are refused' );
	}

	public function test_index_in_every_folder(): void {
		$this->freeze( gmmktime( 10, 0, 0, 3, 5, 2026 ) );
		$copy = Storage::keep( Fixtures::file() );
		$this->assertNotNull( $copy );
		$this->assertTrue( is_file( Storage::base() . '/index.php' ) );
		$this->assertTrue( is_file( Storage::base() . '/2026/index.php' ), 'year folder' );
		$this->assertTrue( is_file( Storage::base() . '/2026/03/index.php' ), 'month folder' );
	}

	public function test_probe_answers(): void {
		HttpMock::expect( 'GET', $this->probe_url(), 'fnfe' );
		$this->assertTrue( Storage::is_exposed() );
		$this->assertSame( 'fnfe', file_get_contents( Storage::dir() . 'probe.txt' ) );
		$this->assertSame( 3, HttpMock::last()['args']['timeout'] );
		$this->assertSame( 0, HttpMock::last()['args']['redirection'] );

		$this->assertTrue( Storage::is_exposed() );
		$this->assertCount( 1, HttpMock::requests(), 'the answer is cached' );

		$cases = [
			[ 'denied', 403, false ],
			[ 'missing', 404, false ],
			[ 'login', 401, false ],
			[ '<html>a page</html>', 200, null ],
			[ 'oops', 500, null ],
			[ new \WP_Error( 'http_request_failed', 'timed out' ), 200, null ],
		];
		foreach ( $cases as $case ) {
			HttpMock::reset();
			Storage::forget_probe();
			HttpMock::expect( 'GET', $this->probe_url(), $case[0], $case[1] );
			$this->assertSame( $case[2], Storage::is_exposed(), is_string( $case[0] ) ? $case[0] . ' ' . $case[1] : 'error' );
		}
	}

	public function test_saving_settings_forgets_the_answer(): void {
		HttpMock::expect( 'GET', $this->probe_url(), 'fnfe' );
		Storage::is_exposed();
		$this->assertNotSame( false, get_transient( Storage::PROBE_CACHE ) );
		Settings::set_many( [ 'queue.batch' => 7 ] );
		$this->assertFalse( get_transient( Storage::PROBE_CACHE ) );
	}

	public function test_site_health_is_honest(): void {
		Settings::set( 'attachments.keep_copy', true );

		HttpMock::expect( 'GET', $this->probe_url(), 'fnfe' );
		$check = SiteHealth::check( 'storage' );
		$this->assertSame( 'warning', $check['status'] );
		$this->assertContains( 'FNFE_FILES_DIR', $check['message'] );
		$this->assertSame( 'help', $check['route'] );
		$tests  = SiteHealth::tests( [ 'direct' => [], 'async' => [] ] );
		$result = call_user_func( $tests['direct']['fnfe_storage']['test'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertContains( 'deny all;', $result['description'] );
		$this->assertContains( 'Require all denied', $result['description'] );
		$this->assertContains( 'OpenLiteSpeed', $result['description'] );

		HttpMock::reset();
		Storage::forget_probe();
		HttpMock::expect( 'GET', $this->probe_url(), 'Forbidden', 403 );
		$check = SiteHealth::check( 'storage' );
		$this->assertSame( 'good', $check['status'] );
		$this->assertContains( 'closed', $check['message'] );

		HttpMock::reset();
		Storage::forget_probe();
		HttpMock::expect( 'GET', $this->probe_url(), new \WP_Error( 'http_request_failed', 'timed out' ) );
		$check = SiteHealth::check( 'storage' );
		$this->assertSame( 'good', $check['status'] );
		$this->assertContains( 'could not be tested', $check['message'] );

		HttpMock::reset();
		Storage::forget_probe();
		$this->move();
		$check = SiteHealth::check( 'storage' );
		$this->assertSame( 'good', $check['status'] );
		$this->assertContains( 'outside the public folder', $check['message'] );
		HttpMock::assertNoRequests();
	}

	public function test_moved_folder_keeps_old_files_working(): void {
		$old = Storage::keep( Fixtures::file( 'old.pdf' ) );
		$this->move();
		$this->assertSame( wp_normalize_path( $this->moved ), Storage::base() );
		$this->assertSame( '', Storage::public_url() );
		$this->assertNull( Storage::is_exposed() );

		$new = Storage::keep( Fixtures::file( 'new.pdf' ) );
		$this->assertTrue( PathGuard::is_inside( wp_normalize_path( $new->path ), Storage::base() ), 'new files go to the moved folder' );
		$this->assertTrue( is_file( Storage::base() . '/.htaccess' ) );
		$this->assertTrue( PathGuard::is_safe( $new->path ), 'the moved folder is an allowed root' );
		$this->assertTrue( PathGuard::is_safe( $old->path ), 'old files are still served' );

		touch( $old->path, time() - 3 * DAY_IN_SECONDS );
		touch( $new->path, time() - 3 * DAY_IN_SECONDS );
		$this->assertCount( 2, Storage::older_files( DAY_IN_SECONDS ), 'cleanup sees both folders' );

		$this->assertTrue( Storage::delete( $old ), 'a file of the old location can still be deleted' );
		$this->assertTrue( Storage::delete( $new ) );
	}

	public function test_uninstall_with_a_moved_folder(): void {
		$old = Storage::keep( Fixtures::file( 'old.pdf' ) );
		$this->move();
		$new = Storage::keep( Fixtures::file( 'new.pdf' ) );
		$foreign = $this->moved . '/notes.txt';
		file_put_contents( $foreign, 'not ours' );
		mkdir( $this->moved . '/other' );
		file_put_contents( $this->moved . '/other/index.php', '<?php' );

		Settings::set( 'privacy.delete_on_uninstall', true );
		Installer::uninstall_site();

		$this->assertFalse( file_exists( $old->path ) );
		$this->assertFalse( is_dir( Storage::default_base() ), 'the default folder is removed' );
		$this->assertFalse( file_exists( $new->path ) );
		$this->assertFalse( is_dir( dirname( $new->path ) ), 'empty month folder removed' );
		$this->assertFalse( file_exists( $this->moved . '/.htaccess' ) );
		$this->assertTrue( file_exists( $foreign ), 'files the plugin did not create stay' );
		$this->assertTrue( file_exists( $this->moved . '/other/index.php' ), 'other folders are not entered' );
		\AmirhpCom\FormsNotifier\Core\DbSchema::install();
	}
}
