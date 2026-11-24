<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Defaults;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function setUp(): void {
		Db::delete_options();
		Db::flush();
	}

	public function tearDown(): void {
		Db::delete_options();
		Db::flush();
	}

	public function test_defaults(): void {
		$this->assertSame( 'all', Settings::get( 'general.capture_mode' ) );
		$this->assertSame( 'x', Settings::get( 'general.nope', 'x' ) );
		$this->assertSame( 'x', Settings::get( 'general.capture_mode.deeper', 'x' ) );
		$this->assertTrue( Settings::bool( 'general.store_submissions' ) );
		$this->assertSame( 30, Settings::int( 'general.duplicate_window' ) );
		$this->assertSame( [ 60, 300, 900, 3600, 21600 ], Settings::arr( 'queue.backoff' ) );
		$this->assertSame( 'd', Settings::str( 'queue.backoff', 'd' ) );
	}

	public function test_default_tree_matches_the_contract(): void {
		$tree = Defaults::base();
		$this->assertSame( [ 'general', 'channels', 'attachments', 'queue', 'alerts', 'digest', 'miniapp', 'privacy', 'advanced' ], array_keys( $tree ) );
		$this->assertKeys( [ 'enabled', 'receivers', 'template', 'subject', 'buttons', 'fallback' ], $tree['channels']['telegram'] );
	}

	public function test_set_and_get_nested(): void {
		Settings::set( 'general.quiet_hours.from', '21:30' );
		$this->assertSame( '21:30', Settings::get( 'general.quiet_hours.from' ) );
		$this->assertSame( '07:00', Settings::get( 'general.quiet_hours.to' ) );
		Settings::set( 'custom.deep.path', [ 'a' => 1 ] );
		$this->assertSame( 1, Settings::get( 'custom.deep.path.a' ) );
	}

	public function test_only_changes_are_stored(): void {
		Settings::set( 'general.capture_mode', 'selected' );
		$this->assertSame( [ 'general' => [ 'capture_mode' => 'selected' ] ], get_option( 'fnfe_settings' ) );
		Settings::set( 'general.capture_mode', 'all' );
		$this->assertSame( [], get_option( 'fnfe_settings' ) );
	}

	public function test_lists_are_replaced(): void {
		Settings::set( 'general.skip_types', [ 'html' ] );
		$this->assertSame( [ 'html' ], Settings::get( 'general.skip_types' ) );
		Settings::set( 'general.skip_types', [] );
		$this->assertSame( [], Settings::get( 'general.skip_types' ) );
	}

	public function test_localized_texts_are_atomic(): void {
		$this->filter( 'fnfe_default_settings', static function ( $tree ) {
			$tree['digest']['template'] = [ '_' => 'Hello', 'fa' => 'سلام' ];
			return $tree;
		} );
		Db::flush();
		Settings::set( 'digest.template', [ '_' => 'Hi' ] );
		$this->assertSame( [ '_' => 'Hi' ], Settings::get( 'digest.template' ) );
	}

	public function test_delete(): void {
		Settings::set( 'queue.batch', 50 );
		$seen = [];
		$this->filter( 'fnfe_setting_updated', static function ( ...$args ) use ( &$seen ) { $seen[] = $args; }, 10, 3 );
		Settings::delete( 'queue.batch' );
		$this->assertSame( 20, Settings::get( 'queue.batch' ) );
		$this->assertSame( [ [ 'queue.batch', 20, 50 ] ], $seen );
		$this->assertSame( [], get_option( 'fnfe_settings' ) );
	}

	public function test_set_fires_hook_with_old_value(): void {
		$seen = [];
		$this->filter( 'fnfe_setting_updated', static function ( ...$args ) use ( &$seen ) { $seen[] = $args; }, 10, 3 );
		Settings::set( 'queue.batch', 10 );
		$this->assertSame( [ [ 'queue.batch', 10, 20 ] ], $seen );
	}

	public function test_set_many_fires_once(): void {
		$saved = [];
		$updated = 0;
		$writes = 0;
		$this->filter( 'fnfe_settings_saved', static function ( $paths ) use ( &$saved ) { $saved[] = $paths; } );
		$this->filter( 'fnfe_setting_updated', static function () use ( &$updated ) { ++$updated; } );
		$this->filter( 'update_option_fnfe_settings', static function () use ( &$writes ) { ++$writes; } );
		$this->filter( 'add_option_fnfe_settings', static function () use ( &$writes ) { ++$writes; } );
		Settings::set_many( [ 'queue.batch' => 10, 'queue.ticker' => 'cron', 'queue.max_attempts' => 5 ] );
		$this->assertSame( [ [ 'queue.batch', 'queue.ticker' ] ], $saved );
		$this->assertSame( 0, $updated );
		$this->assertSame( 1, $writes );
		$this->assertSame( 'cron', Settings::get( 'queue.ticker' ) );
	}

	public function test_overlay_wins_and_clears(): void {
		Settings::set( 'queue.batch', 10 );
		Settings::overlay( [ 'queue.batch' => 99, 'channels.telegram.enabled' => true ], [ 'channels.telegram.token' => 'overlay-token' ] );
		$this->assertSame( 99, Settings::get( 'queue.batch' ) );
		$this->assertTrue( Settings::all()['channels']['telegram']['enabled'] );
		$this->assertSame( 99, Settings::arr( 'queue' )['batch'] );
		$this->assertSame( 'overlay-token', Settings::secret( 'channels.telegram.token' ) );
		$this->assertTrue( Settings::has_secret( 'channels.telegram.token' ) );
		$this->assertSame( [ 'queue' => [ 'batch' => 10 ] ], get_option( 'fnfe_settings' ) );
		Settings::clear_overlay();
		$this->assertSame( 10, Settings::get( 'queue.batch' ) );
		$this->assertSame( '', Settings::secret( 'channels.telegram.token' ) );
	}

	public function test_overlay_is_not_persisted_by_set(): void {
		Settings::overlay( [ 'queue.batch' => 99 ] );
		Settings::set( 'queue.ticker', 'cron' );
		Settings::clear_overlay();
		$this->assertSame( [ 'queue' => [ 'ticker' => 'cron' ] ], get_option( 'fnfe_settings' ) );
	}

	public function test_schema_defaults(): void {
		FakeChannel::install();
		$this->assertSame( 'a', Settings::get( 'channels.fake.mode' ) );
		$this->assertSame( [ '_' => '' ], Settings::get( 'channels.fake.greeting' ) );
		$this->assertSame( [], Settings::get( 'channels.fake.endpoints' ) );
	}

	public function test_cache_is_kept_per_site(): void {
		Settings::set( 'queue.batch', 11 );
		$this->assertSame( 11, Settings::all()['queue']['batch'] );
		global $blog_id;
		$real    = $blog_id;
		$blog_id = 987;
		try {
			// Another site reads its own option.
			$this->filter( 'pre_option_fnfe_settings', static function () { return [ 'queue' => [ 'batch' => 77 ] ]; } );
			$this->assertSame( 77, Settings::all()['queue']['batch'] );
			$this->assertSame( 77, Settings::get( 'queue.batch' ) );
		} finally {
			$blog_id = $real;
		}
		$this->assertSame( 11, Settings::all()['queue']['batch'] );
	}

	public function test_external_option_change_is_seen(): void {
		Settings::get( 'queue.batch' );
		update_option( 'fnfe_settings', [ 'queue' => [ 'batch' => 33 ] ] );
		$this->assertSame( 33, Settings::get( 'queue.batch' ) );
	}

	public function test_state(): void {
		$this->assertSame( 'd', State::get( 'bot.telegram.offset', 'd' ) );
		State::set( 'bot.telegram.offset', 12 );
		State::set( 'token_version', 2 );
		$this->assertSame( 12, State::get( 'bot.telegram.offset' ) );
		State::delete( 'bot.telegram.offset' );
		State::delete( 'nope.nope' );
		$this->assertNull( State::get( 'bot.telegram.offset' ) );
		$this->assertSame( 2, State::get( 'token_version' ) );
	}
}
