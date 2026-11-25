<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Capture\FileCleanup;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

final class FilesTest extends TestCase {

	use Records;

	private static $admin = 0;

	public static function setUpClass(): void {
		self::$admin = (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
	}

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'rest_pre_serve_request' );
	}

	private function saved( string $name = 'cv.pdf', string $content = 'fake file' ): Attachment {
		$s        = Fixtures::submission();
		$s->files = [ Storage::keep( Fixtures::file( $name, $content ) ) ];
		SubmissionRepository::insert( $s );
		return $s->files[0];
	}

	private function get( string $path, array $params = [] ) {
		$request = new \WP_REST_Request( 'GET', '/fnfe/v1' . $path );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	private function link_params( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	public function test_path_guard(): void {
		$base = Db::uploads();
		$ok   = $base . '/a.pdf';
		file_put_contents( $ok, 'x' );
		wp_mkdir_p( $base . '/sub' );
		$this->assertTrue( PathGuard::is_safe( $ok ) );
		$this->assertFalse( PathGuard::is_safe( $base . '/sub/../a.pdf' ) );
		$this->assertFalse( PathGuard::is_safe( $base . '/../../etc/hosts' ) );
		$this->assertFalse( PathGuard::is_safe( '/etc/hosts' ) );
		$this->assertFalse( PathGuard::is_safe( $base . '/none.pdf' ) );
		$this->assertFalse( PathGuard::is_safe( $base . '/sub' ) );
		$this->assertFalse( PathGuard::is_safe( '' ) );
		$this->assertFalse( PathGuard::is_safe( "$ok\0.jpg" ) );
		$this->assertFalse( PathGuard::is_safe( 'phar://' . $ok ) );
		$this->assertFalse( PathGuard::is_safe( 'file://' . $ok ) );

		// Links out of the root, links to a forbidden type, links that stay inside.
		@unlink( $base . '/out.txt' );
		symlink( '/etc/hosts', $base . '/out.txt' );
		$this->assertFalse( PathGuard::is_safe( $base . '/out.txt' ) );
		@unlink( $base . '/in.pdf' );
		symlink( $ok, $base . '/in.pdf' );
		$this->assertTrue( PathGuard::is_safe( $base . '/in.pdf' ) );
		foreach ( [ 'x.php', 'x.PHP', 'x.phtml', 'x.svg', 'x.html', '.htaccess', 'x.sh', 'x.js' ] as $name ) {
			file_put_contents( $base . '/' . $name, 'x' );
			$this->assertFalse( PathGuard::is_safe( $base . '/' . $name ), $name );
		}
		@unlink( $base . '/alias.pdf' );
		symlink( $base . '/x.php', $base . '/alias.pdf' );
		$this->assertFalse( PathGuard::is_safe( $base . '/alias.pdf' ) );

		// A sibling directory that only starts with the same name.
		wp_mkdir_p( $base . '-evil' );
		file_put_contents( $base . '-evil/a.pdf', 'x' );
		$this->assertFalse( PathGuard::is_safe( $base . '-evil/a.pdf' ) );
		unlink( $base . '-evil/a.pdf' );
		rmdir( $base . '-evil' );

		$this->filter( 'fnfe_allowed_file_roots', static function () { return [ '/', 'phar://x', 5 ]; } );
		$this->assertSame( [], PathGuard::roots() );
		$this->assertFalse( PathGuard::is_safe( $ok ) );
	}

	public function test_denied_extensions(): void {
		foreach ( [ 'php', 'PHP5', '.phar', 'svg', 'html', 'exe', 'htaccess', ' php ' ] as $ext ) {
			$this->assertTrue( PathGuard::is_denied_ext( $ext ), $ext );
		}
		foreach ( [ 'pdf', 'jpg', '', 'docx' ] as $ext ) {
			$this->assertFalse( PathGuard::is_denied_ext( $ext ), $ext );
		}
		$this->filter( 'fnfe_denied_extensions', static function ( $list ) { $list[] = 'docx'; return $list; } );
		$this->assertTrue( PathGuard::is_denied_ext( 'docx' ) );
	}

	public function test_url_to_path(): void {
		$base = wp_upload_dir( null, false )['baseurl'];
		file_put_contents( Db::uploads() . '/a b.pdf', 'x' );
		$this->assertSame( Db::uploads() . '/a b.pdf', PathGuard::url_to_path( $base . '/a%20b.pdf?x=1#y' ) );
		foreach ( [ $base . '/../a%20b.pdf', $base . '/%2e%2e/%2e%2e/etc/hosts', $base . '/none.pdf', 'https://evil.example/a.pdf', $base . '-evil/a.pdf', $base . '/a%00.pdf', $base . '/..%5C..%5Cx', '', 'javascript:x', '/etc/hosts' ] as $url ) {
			$this->assertSame( '', PathGuard::url_to_path( $url ), $url );
		}
	}

	public function test_keep(): void {
		$file = Fixtures::file( 'photo.png', $this->png(), 'photo' );
		$copy = Storage::keep( $file );
		$this->assertTrue( $copy->stored );
		$this->assertTrue( $copy->exists() );
		$this->assertMatches( '#/fnfe-files/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $copy->path );
		$this->assertSame( 'photo.png', $copy->name );
		$this->assertSame( 'image/png', $copy->mime );
		$this->assertTrue( $copy->is_image() );
		$this->assertTrue( is_file( Storage::dir() . 'index.php' ) );
		$this->assertContains( 'Require all denied', file_get_contents( Storage::dir() . '.htaccess' ) );
		$this->assertTrue( file_exists( $file->path ), 'the original stays' );

		// Content decides: a script with an image name is no image.
		$fake = Storage::keep( Fixtures::file( 'fake.png', '<?php echo 1;' ) );
		$this->assertFalse( $fake->is_image() );

		$outside       = new Attachment();
		$outside->path = '/etc/hosts';
		$outside->name = 'hosts.txt';
		$this->assertNull( Storage::keep( $outside ) );
		$bad      = Fixtures::file( 'a.pdf' );
		$bad->ext = 'php';
		$this->assertNull( Storage::keep( $bad ) );
	}

	public function test_delete_only_inside_the_folder(): void {
		$copy = Storage::keep( Fixtures::file() );
		$this->assertTrue( Storage::delete( $copy ) );
		$this->assertFalse( file_exists( $copy->path ) );
		$this->assertFalse( Storage::delete( $copy ) );
		$original         = Fixtures::file();
		$original->stored = true;
		$this->assertFalse( Storage::delete( $original ) );
		$this->assertTrue( file_exists( $original->path ) );
		$original->path = Storage::dir() . '../' . basename( $original->path );
		$this->assertFalse( Storage::delete( $original ) );
	}

	public function test_links_and_route(): void {
		$file  = $this->saved( 'رزومه "x".pdf' );
		$query = $this->link_params( Storage::link( $file ) );
		$this->assertSame( '0', $query['e'] );
		$r = $this->get( '/file/' . $file->id, $query );
		$this->assertSame( 200, $r->get_status() );
		$headers = $r->get_headers();
		$this->assertContains( "attachment; filename=\"", $headers['Content-Disposition'] );
		$this->assertContains( "filename*=UTF-8''" . rawurlencode( 'رزومه' ), $headers['Content-Disposition'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'private, no-store', $headers['Cache-Control'] );
		$this->assertSame( '9', $headers['Content-Length'] );
		$this->assertNotContains( "\n", $headers['Content-Disposition'] );

		$other = $this->saved();
		foreach ( [
			[ $file->id, [] ],
			[ $file->id, [ 't' => 'x', 'e' => 0 ] ],
			[ $file->id, [ 't' => $query['t'], 'e' => 99999999999 ] ],
			[ $other->id, $query ],
			[ 999999, $query ],
		] as $case ) {
			$r = $this->get( '/file/' . $case[0], $case[1] );
			$this->assertSame( 403, $r->get_status() );
			$this->assertSame( 'fnfe_bad_signature', $r->get_data()['code'] );
		}

		// A session that may view submissions needs no signature.
		wp_set_current_user( self::$admin );
		$this->assertSame( 200, $this->get( '/file/' . $file->id )->get_status() );
		$this->assertSame( 404, $this->get( '/file/999999' )->get_status() );
		Storage::delete( $file );
		$this->assertSame( 404, $this->get( '/file/' . $file->id )->get_status() );
	}

	public function test_expiring_link(): void {
		$file = $this->saved();
		$now  = time();
		$this->filter( 'fnfe_now', static function () use ( &$now ) { return $now; }, PHP_INT_MAX );
		$query = $this->link_params( Storage::link( $file, 2 ) );
		$this->assertTrue( Storage::verify( $file->id, $query['t'], (int) $query['e'] ) );
		$now += 2 * DAY_IN_SECONDS + 1;
		$this->assertFalse( Storage::verify( $file->id, $query['t'], (int) $query['e'] ) );
		$this->assertSame( 403, $this->get( '/file/' . $file->id, $query )->get_status() );
		$this->assertFalse( Storage::verify( $file->id, $query['t'], 0 ), 'the expiry is part of the signature' );
		$this->assertSame( '', Storage::link( new Attachment() ) );
	}

	public function test_content_types(): void {
		wp_set_current_user( self::$admin );
		foreach ( [ 'p.png' => [ $this->png(), 'image/png' ], 'n.txt' => [ 'text', 'application/octet-stream' ], 'fake.png' => [ '<html><script>1</script>', 'application/octet-stream' ] ] as $name => $case ) {
			$file = $this->saved( $name, $case[0] );
			$this->assertSame( $case[1], $this->get( '/file/' . $file->id )->get_headers()['Content-Type'], $name );
		}
	}

	public function test_other_requests_are_not_served(): void {
		$file    = $this->saved( 'a.pdf', 'streamed content' );
		$request = new \WP_REST_Request( 'GET', '/fnfe/v1/file/' . $file->id );
		$request->set_query_params( $this->link_params( Storage::link( $file ) ) );
		$response = rest_do_request( $request );
		$this->assertFalse( apply_filters( 'rest_pre_serve_request', false, $response, new \WP_REST_Request( 'GET', '/wp/v2/posts' ), rest_get_server() ) );
	}

	public function test_rate_limit(): void {
		$file  = $this->saved();
		$query = $this->link_params( Storage::link( $file ) );
		for ( $i = 0; $i < 60; $i++ ) {
			$this->get( '/file/' . $file->id, $query );
		}
		$this->assertSame( 429, $this->get( '/file/' . $file->id, $query )->get_status() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertSame( 200, $this->get( '/file/' . $file->id, $query )->get_status() );
	}

	public function test_policy_layers(): void {
		$this->assertSame( 'link', Policy::resolve( 'telegram' )['mode'] );
		Settings::set_many( [ 'attachments.mode' => 'off', 'attachments.channels' => [ 'telegram' => 'send', 'bale' => 'inherit' ] ] );
		$this->assertSame( 'send', Policy::resolve( 'telegram' )['mode'] );
		$this->assertSame( 'off', Policy::resolve( 'bale' )['mode'] );
		$form = Fixtures::config( [ 'attachments' => [ 'mode' => 'both', 'types' => 'images', 'max_files' => 2, 'keep_copy' => false ] ] );
		$this->assertSame( 'both', Policy::resolve( 'telegram', $form )['mode'] );
		$this->assertSame( 2, Policy::resolve( 'telegram', $form )['max_files'] );
		$this->assertFalse( Policy::keeps_copies( $form ) );
		$this->assertTrue( Policy::keeps_copies( Fixtures::config() ) );
		$this->filter( 'fnfe_attachment_policy', static function ( $p, $channel, $form ) { $p['max_mb'] = 3; return $p; }, 10, 3 );
		$this->assertSame( 3, Policy::resolve( 'x' )['max_mb'] );
		$this->assertFalse( array_key_exists( 'channels', Policy::resolve( 'x' ) ) );
	}

	public function test_policy_modes(): void {
		$uploads = new FakeChannel( 'up', [ 'attachments' => true ] );
		$links   = new FakeChannel( 'ln', [ 'attachments' => false ] );
		$none    = new FakeChannel( 'no', [ 'attachments' => false, 'links' => false ] );
		$s       = Fixtures::submission();
		$s->files = [ Storage::keep( Fixtures::file( 'a.pdf', 'aaa' ) ), Fixtures::file( 'b.png', $this->png() ) ];
		SubmissionRepository::insert( $s );
		$s->files[1]->url = 'https://example.com/b.png';

		$count = static function ( array $r ) { return [ count( $r['send'] ), count( $r['links'] ), count( $r['skipped'] ) ]; };
		$base  = Policy::resolve( '' );
		$this->assertSame( [ 0, 0, 0 ], $count( Policy::apply( [ 'mode' => 'off' ] + $base, $s, $uploads ) ) );
		$this->assertSame( [ 0, 2, 0 ], $count( Policy::apply( [ 'mode' => 'link' ] + $base, $s, $uploads ) ) );
		$this->assertSame( [ 2, 0, 0 ], $count( Policy::apply( [ 'mode' => 'send' ] + $base, $s, $uploads ) ) );
		$this->assertSame( [ 2, 2, 0 ], $count( Policy::apply( [ 'mode' => 'both' ] + $base, $s, $uploads ) ) );
		$this->assertSame( [ 0, 2, 0 ], $count( Policy::apply( [ 'mode' => 'send' ] + $base, $s, $links ) ) );
		$r = Policy::apply( [ 'mode' => 'send' ] + $base, $s, $none );
		$this->assertSame( [ 0, 0, 2 ], $count( $r ) );
		$this->assertSame( 'no_link', $r['skipped'][0]['reason'] );

		$r = Policy::apply( [ 'mode' => 'link' ] + $base, $s, $links );
		$this->assertContains( '/fnfe/v1/file/' . $s->files[0]->id, rawurldecode( $r['links'][0]['url'] ) );
		$this->assertSame( 'https://example.com/b.png', $r['links'][1]['url'] );

		$r = Policy::apply( [ 'mode' => 'send', 'types' => 'images' ] + $base, $s, $uploads );
		$this->assertSame( [ 1, 0, 1 ], $count( $r ) );
		$this->assertSame( [ 'name' => 'a.pdf', 'reason' => 'type' ], $r['skipped'][0] );
		$this->assertSame( [ 1, 0, 1 ], $count( Policy::apply( [ 'mode' => 'send', 'types' => 'custom', 'extensions' => [ 'PDF' ] ] + $base, $s, $uploads ) ) );

		// Too large or too many: a link when possible.
		$this->assertSame( [ 1, 1, 0 ], $count( Policy::apply( [ 'mode' => 'send' ] + $base, $s, $uploads, 10 ) ) );
		$this->assertSame( [ 1, 1, 0 ], $count( Policy::apply( [ 'mode' => 'send', 'max_files' => 1 ] + $base, $s, $uploads ) ) );
		$s->files[1]->url = '';
		$r = Policy::apply( [ 'mode' => 'send' ] + $base, $s, $uploads, 10 );
		$this->assertSame( 'size', $r['skipped'][0]['reason'] );
		$r = Policy::apply( [ 'mode' => 'send', 'max_files' => 1 ] + $base, $s, $uploads );
		$this->assertSame( 'count', $r['skipped'][0]['reason'] );

		unlink( $s->files[0]->path );
		$this->assertSame( 'missing', Policy::apply( [ 'mode' => 'send' ] + $base, $s, $uploads )['skipped'][0]['reason'] );
		$evil       = Fixtures::file( 'c.pdf' );
		$evil->path = '/etc/hosts';
		$s->files   = [ $evil ];
		$this->assertSame( 'unsafe', Policy::apply( [ 'mode' => 'send' ] + $base, $s, $uploads )['skipped'][0]['reason'] );
	}

	public function test_policy_filter(): void {
		$s        = Fixtures::submission();
		$s->files = [ Fixtures::file() ];
		$seen     = null;
		$evil       = Fixtures::file();
		$evil->path = '/etc/hosts';
		$this->filter( 'fnfe_attachments', static function ( $result, $message, $submission ) use ( &$seen, $evil ) {
			$seen             = [ $message instanceof Message, $message->channel, $submission->form_key ];
			$result['send'][] = $evil;
			return $result;
		}, 10, 3 );
		$r = Policy::apply( [ 'mode' => 'send' ], $s, new FakeChannel( 'up', [ 'attachments' => true ] ) );
		$this->assertSame( [ true, 'up', '45:a1b2c3d' ], $seen );
		$this->assertCount( 1, $r['send'], 'unsafe files of a filter are dropped' );
	}

	public function test_retention(): void {
		$old  = $this->saved();
		$sub  = FileRepository::submission_id( $old->id );
		MessageRepository::insert( [ 'submission_id' => $sub, 'channel' => 'fake', 'status' => 'sent' ] );
		$this->freeze( time() + 10 * DAY_IN_SECONDS );

		FileCleanup::run();
		$this->assertTrue( file_exists( $old->path ), 'nothing happens by default' );

		Settings::set( 'privacy.retention_files_days', 5 );
		FileCleanup::run();
		$this->assertFalse( file_exists( $old->path ) );
		$this->assertSame( '', FileRepository::find( $old->id )->path );
		$this->assertNotNull( SubmissionRepository::find( $sub ) );

		Settings::set( 'privacy.retention_submissions_days', 5 );
		FileCleanup::run();
		$this->assertNull( SubmissionRepository::find( $sub ) );
		$this->assertNull( FileRepository::find( $old->id ) );
		$this->assertSame( [], MessageRepository::for_submission( $sub ) );
	}

	public function test_orphans(): void {
		$known   = $this->saved();
		$orphan  = Storage::keep( Fixtures::file() );
		$fresh   = Storage::keep( Fixtures::file() );
		$awaited = Storage::keep( Fixtures::file() );
		foreach ( [ $known, $orphan, $awaited ] as $file ) {
			touch( $file->path, time() - 5 * DAY_IN_SECONDS );
		}
		MessageRepository::insert( [ 'channel' => 'fake', 'status' => 'retry', 'payload' => [ 'attachments' => [ $awaited->to_array() ] ] ] );
		FileCleanup::run();
		$this->assertTrue( file_exists( $known->path ) );
		$this->assertFalse( file_exists( $orphan->path ) );
		$this->assertTrue( file_exists( $fresh->path ) );
		$this->assertTrue( file_exists( $awaited->path ) );
		$this->assertTrue( is_file( Storage::dir() . 'index.php' ) );
	}
}
