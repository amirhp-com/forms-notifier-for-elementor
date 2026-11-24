<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Installer;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class UninstallTest extends TestCase {

	private static $meta = [];

	public static function setUpClass(): void {
		// User meta is not part of the private copies.
		global $wpdb;
		self::$meta = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ( 'fnfe_ui', 'fnfe_bot_ids' )" );
	}

	public static function tearDownClass(): void {
		DbSchema::install();
		foreach ( self::$meta as $row ) {
			update_user_meta( (int) $row->user_id, $row->meta_key, maybe_unserialize( $row->meta_value ) );
		}
	}

	private function prepare( bool $delete ): string {
		DbSchema::install();
		Db::reset();
		Settings::set( 'privacy.delete_on_uninstall', $delete );
		Settings::set( 'queue.batch', 44 );
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		State::set( 'token_version', 3 );
		Lock::acquire( 'queue' );
		RateLimit::hit( 'x', 5, 60 );
		Cron::ensure_events();
		update_user_meta( 1, 'fnfe_bot_ids', [ 'telegram' => '1' ] );
		$dir = Db::uploads() . '/fnfe-files/2026/01';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/aaaa.pdf', 'x' );
		return $dir . '/aaaa.pdf';
	}

	public function test_keeps_data_by_default(): void {
		$file = $this->prepare( false );
		Installer::uninstall_site();
		HttpMock::assertNoRequests();
		$this->assertTrue( DbSchema::exists() );
		$this->assertSame( 44, Settings::get( 'queue.batch' ) );
		$this->assertSame( Fixtures::TOKEN, Settings::secret( 'channels.telegram.token' ) );
		$this->assertTrue( file_exists( $file ) );
		$this->assertSame( [ 'telegram' => '1' ], get_user_meta( 1, 'fnfe_bot_ids', true ) );
		$this->assertFalse( wp_next_scheduled( Cron::CLEANUP ) );
		$this->assertSame( 0, RateLimit::count( 'x' ) );
		$this->assertTrue( Lock::acquire( 'queue' ), 'locks are gone' );
	}

	public function test_deletes_everything_when_asked(): void {
		global $wpdb;
		$file = $this->prepare( true );
		HttpMock::expect( 'POST', '~^https://api\.telegram\.org/bot' . preg_quote( Fixtures::TOKEN, '~' ) . '/deleteWebhook$~' );
		Installer::uninstall_site();
		$this->assertCount( 1, HttpMock::requests() );
		$this->assertSame( 5, HttpMock::last()['args']['timeout'] );
		foreach ( DbSchema::tables() as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), $table );
		}
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%fnfe%'" );
		$this->assertSame( [], $left );
		$this->assertFalse( file_exists( $file ) );
		$this->assertFalse( is_dir( Db::uploads() . '/fnfe-files' ) );
		$this->assertSame( '', get_user_meta( 1, 'fnfe_bot_ids', true ) );
		$this->assertFalse( wp_next_scheduled( Cron::QUEUE ) );
	}

	public function test_install_seeds_country(): void {
		DbSchema::install();
		Db::reset();
		delete_option( 'fnfe_db_version' );
		update_option( 'WPLANG', 'fa_IR' );
		Installer::install_site();
		$this->assertSame( 'IR', Settings::get( 'general.default_country' ) );
		$this->assertSame( FNFE_DB_VERSION, get_option( 'fnfe_db_version' ) );
		Settings::set( 'general.default_country', '' );
		Installer::install_site();
		$this->assertSame( '', Settings::get( 'general.default_country' ), 'only seeded once' );
	}
}
