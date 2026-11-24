<?php
/**
 * Site Health and WP-CLI.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Delivery;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\Health\SiteHealth;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class HealthCliTest extends TestCase {

	public static function setUpClass(): void {
		FakeChannel::install( 'fake' );
	}

	public function setUp(): void {
		Db::reset();
		Queue::reset();
		FakeChannel::reset();
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ], 'queue.ticker' => 'cron' ] );
	}

	public function tearDown(): void {
		Queue::reset();
	}

	private function status( string $id ): string {
		return SiteHealth::check( $id )['status'];
	}

	/** Run a command in this process. */
	private function cli( string $command ): object {
		return \WP_CLI::runcommand( $command, [ 'launch' => false, 'return' => 'all', 'exit_error' => false ] );
	}

	public function test_site_health_results_are_valid() {
		$tests = SiteHealth::tests( [ 'direct' => [], 'async' => [] ] );
		$this->assertTrue( count( $tests['direct'] ) >= 11 );
		foreach ( $tests['direct'] as $id => $test ) {
			$result = call_user_func( $test['test'] );
			$this->assertSame( [ 'label', 'status', 'badge', 'description', 'actions', 'test' ], array_keys( $result ), $id );
			$this->assertTrue( in_array( $result['status'], [ 'good', 'recommended', 'critical' ], true ), $id );
			$this->assertSame( $id, $result['test'] );
			$this->assertTrue( in_array( $result['badge']['color'], [ 'blue', 'orange', 'red' ], true ) );
			$this->assertNotSame( '<p></p>', $result['description'], $id );
		}
		HttpMock::assertNoRequests();
		$this->assertArrayHasKey( 'fnfe_bots', $tests['async'] );
		$this->assertSame( 'fnfe_bots', SiteHealth::bots_result()['test'] );
	}

	public function test_checks_react() {
		$this->assertSame( 'good', $this->status( 'channels' ) );
		FakeChannel::$problems = [ 'x' ];
		$this->assertSame( 'critical', $this->status( 'channels' ) );
		FakeChannel::$problems = [];
		Settings::set( 'channels.fake.enabled', false );
		$this->assertSame( 'warning', $this->status( 'channels' ) );

		$this->assertSame( 'good', $this->status( 'queue' ) );
		MessageRepository::insert( [ 'channel' => 'fake', 'status' => 'queued', 'next_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1000 ) ] );
		$this->assertSame( 'critical', $this->status( 'queue' ) );

		$this->assertSame( 'good', $this->status( 'failures' ) );
		for ( $i = 0; $i < 6; $i++ ) {
			MessageRepository::insert( [ 'channel' => 'fake', 'status' => $i < 2 ? 'failed' : 'sent' ] );
		}
		$this->assertSame( 'warning', $this->status( 'failures' ) );

		$this->assertSame( 'good', $this->status( 'secrets' ) );
		update_option( 'fnfe_secrets', [ 'channels.fake.token' => 'fnfe1:' . base64_encode( str_repeat( 'x', 60 ) ) ] );
		$this->assertSame( 'critical', $this->status( 'secrets' ) );

		$this->assertSame( 'good', $this->status( 'database' ) );
		update_option( 'fnfe_db_version', '0' );
		$this->assertSame( 'critical', $this->status( 'database' ) );

		Settings::set( 'miniapp.enabled', true );
		$this->filter( 'home_url', function () {
			return 'http://example.com';
		} );
		$this->assertSame( 'critical', $this->status( 'https' ) );
	}

	public function test_health_list_for_the_dashboard() {
		foreach ( Stats::health() as $check ) {
			$this->assertSame( [ 'id', 'label', 'status', 'message', 'route' ], array_keys( $check ) );
			$this->assertTrue( in_array( $check['status'], [ 'good', 'warning', 'critical' ], true ) );
		}
	}

	public function test_debug_information_has_no_secrets() {
		Settings::set_secret( 'channels.fake.token', 'super-secret-value' );
		$info = SiteHealth::debug_information( [] );
		$this->assertNotContains( 'super-secret-value', wp_json_encode( $info ) );
		$this->assertSame( FNFE_VERSION, $info['forms-notifier-for-elementor']['fields']['version']['value'] );
	}

	public function test_async_route_permission() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/fnfe/v1/health/bots' ) )->get_status() );
	}

	public function test_cli_status_and_lists() {
		FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact', 'post_id' => 45, 'element_id' => 'a1b2c3d' ] );
		$out = $this->cli( 'fnfe status --format=json' );
		$this->assertSame( 0, $out->return_code );
		$this->assertContains( '"fake"', $out->stdout );
		$this->assertContains( 'Contact', $this->cli( 'fnfe forms list --format=json' )->stdout );
		Queue::push( Fixtures::message() );
		$this->assertContains( 'queued', $this->cli( 'fnfe queue list --format=json' )->stdout );
		$this->assertContains( 'Desk', $this->cli( 'fnfe log list --format=json --channel=fake' )->stdout );
		$this->assertSame( 1, $this->cli( 'fnfe log list --status=bogus' )->return_code );
	}

	public function test_cli_test_command() {
		$this->assertSame( 0, $this->cli( 'fnfe test fake' )->return_code );
		$this->assertSame( 'desk', end( FakeChannel::$sent )->recipient->address );
		$this->cli( 'fnfe test fake --to=Other --text=Hello' );
		$this->assertSame( [ 'other', 'Hello' ], [ end( FakeChannel::$sent )->recipient->address, end( FakeChannel::$sent )->body ] );
		$this->assertSame( 1, $this->cli( 'fnfe test fake --to=bad' )->return_code );
		$this->assertSame( 1, $this->cli( 'fnfe test nothing' )->return_code );
		FakeChannel::$results = [ SendResult::failure( 'fake', 'nope' ) ];
		$this->assertContains( 'nope', $this->cli( 'fnfe test fake --text=x' )->stderr );
	}

	public function test_cli_queue_and_log_purge() {
		$id = Queue::push( Fixtures::message() );
		$this->assertSame( 0, $this->cli( 'fnfe queue run --limit=5' )->return_code );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
		MessageRepository::update( $id, [ 'status' => 'failed' ] );
		$this->cli( 'fnfe queue retry --all-failed' );
		$this->assertSame( 'sent', MessageRepository::find( $id )['status'] );
		$this->assertSame( 1, $this->cli( "fnfe queue retry $id" )->return_code );
		$this->assertSame( 1, $this->cli( 'fnfe queue retry' )->return_code );
		MessageRepository::update( $id, [ 'created_gmt' => '2020-01-01 00:00:00' ] );
		$this->assertSame( 1, $this->cli( 'fnfe log purge' )->return_code );
		$this->assertSame( 0, $this->cli( 'fnfe log purge --days=30' )->return_code );
		$this->assertNull( MessageRepository::find( $id ) );
	}

	public function test_cli_send_creates_a_submission() {
		$id  = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact', 'post_id' => 45, 'element_id' => 'a1b2c3d' ] );
		$out = $this->cli( "fnfe send --form=$id --field=name=Sara" );
		$this->assertSame( 0, $out->return_code, $out->stderr );
		$this->assertCount( 1, FakeChannel::$sent );
		$this->assertSame( 0, $this->cli( 'fnfe send --form=45:a1b2c3d --channels=fake' )->return_code );
		$this->assertSame( 1, $this->cli( 'fnfe send --form=999' )->return_code );
	}

	public function test_cli_digest_export_import_cleanup() {
		$this->assertContains( 'digest.submissions', $this->cli( 'fnfe digest' )->stdout );
		$this->assertSame( 1, $this->cli( 'fnfe digest --send' )->return_code );
		Settings::set_many( [ 'digest.channels.fake.enabled' => true, 'digest.channels.fake.receivers' => [ Fixtures::receiver( 'boss' ) ] ] );
		$this->assertSame( 0, $this->cli( 'fnfe digest --send' )->return_code );

		$file = Db::uploads() . '/export.json';
		$this->assertSame( 0, $this->cli( 'fnfe export --file=' . $file )->return_code );
		$this->assertContains( 'forms-notifier-for-elementor', file_get_contents( $file ) );
		Settings::set( 'queue.batch', 7 );
		$this->assertSame( 0, $this->cli( 'fnfe import ' . $file . ' --mode=replace' )->return_code );
		$this->assertSame( 20, Settings::int( 'queue.batch' ) );
		$this->assertSame( 1, $this->cli( 'fnfe import /nonexistent.json' )->return_code );
		$this->assertSame( 0, $this->cli( 'fnfe cleanup' )->return_code );
		$this->assertSame( 1, $this->cli( 'fnfe bot info telegram' )->return_code, 'no token' );
		$this->assertSame( 1, $this->cli( 'fnfe bot nothing telegram' )->return_code );
	}
}
