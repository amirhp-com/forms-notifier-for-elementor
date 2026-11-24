<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SecretsTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	public function test_round_trip(): void {
		foreach ( [ 'a', Fixtures::TOKEN, 'رمز 🙂', str_repeat( 'x', 5000 ) ] as $plain ) {
			$stored = Crypto::encrypt( $plain );
			$this->assertMatches( '/^fnfe1:/', $stored );
			$this->assertNotContains( $plain, substr( $stored, 6 ) === $plain ? $stored : 'fine' );
			$this->assertSame( $plain, Crypto::decrypt( $stored ) );
		}
		$this->assertSame( '', Crypto::encrypt( '' ) );
	}

	public function test_different_nonce_each_time(): void {
		$this->assertNotSame( Crypto::encrypt( 'same' ), Crypto::encrypt( 'same' ) );
	}

	public function test_tampered_is_empty(): void {
		$stored = Crypto::encrypt( 'secret value' );
		$raw    = base64_decode( substr( $stored, 6 ) );
		$raw    = substr( $raw, 0, -1 ) . chr( ord( substr( $raw, -1 ) ) ^ 1 );
		$this->assertSame( '', Crypto::decrypt( 'fnfe1:' . base64_encode( $raw ) ) );
		foreach ( [ '', 'plain', 'fnfe1:', 'fnfe1:%%%', 'fnfe1:' . base64_encode( 'short' ), 'fnfe2:' . substr( $stored, 6 ) ] as $bad ) {
			$this->assertSame( '', Crypto::decrypt( $bad ) );
		}
	}

	public function test_salt_change_is_detected(): void {
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		$this->filter( 'salt', static function () { return 'another salt another salt another salt'; } );
		$this->assertSame( '', Settings::secret( 'channels.telegram.token' ) );
		$this->assertTrue( Settings::has_secret( 'channels.telegram.token' ) );
		$this->assertTrue( Settings::secret_is_broken( 'channels.telegram.token' ) );
	}

	public function test_store(): void {
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		$raw = get_option( 'fnfe_secrets' );
		$this->assertMatches( '/^fnfe1:/', $raw['channels.telegram.token'] );
		$this->assertNotContains( Fixtures::TOKEN, serialize( $raw ) );
		$this->assertSame( Fixtures::TOKEN, Settings::secret( 'channels.telegram.token' ) );
		$this->assertTrue( Settings::has_secret( 'channels.telegram.token' ) );
		$this->assertSame( [ 'channels.telegram.token' ], Settings::secret_paths( 'channels.telegram.' ) );
		Settings::set_secret( 'channels.telegram.token', '' );
		$this->assertFalse( Settings::has_secret( 'channels.telegram.token' ) );
		$this->assertSame( [], get_option( 'fnfe_secrets' ) );
	}

	public function test_not_autoloaded(): void {
		global $wpdb;
		Settings::set_secret( 'a.b', 'value' );
		$autoload = $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'fnfe_secrets'" );
		$this->assertTrue( in_array( $autoload, [ 'no', 'off' ], true ) );
	}

	public function test_constant_override(): void {
		$this->assertSame( 'FNFE_CHANNELS_MY_CHANNEL_API_KEY', Settings::secret_constant( 'channels.my-channel.api_key' ) );
		defined( 'FNFE_CHANNELS_CONST_TOKEN' ) || define( 'FNFE_CHANNELS_CONST_TOKEN', 'from-wp-config' );
		Settings::set_secret( 'channels.const.token', 'from-store' );
		Settings::overlay( [], [ 'channels.const.token' => 'from-overlay' ] );
		$this->assertSame( 'from-wp-config', Settings::secret( 'channels.const.token' ) );
		$this->assertTrue( Settings::secret_is_constant( 'channels.const.token' ) );
		$this->assertFalse( Settings::secret_is_constant( 'channels.other.token' ) );
		Settings::clear_overlay();
	}

	public function test_masked(): void {
		$this->assertSame( '', Settings::masked( 'a.b' ) );
		Settings::set_secret( 'a.b', 'abcdefgh1234' );
		$this->assertSame( '••••••1234', Settings::masked( 'a.b' ) );
		Settings::set_secret( 'a.b', '1234' );
		$this->assertSame( '••••••', Settings::masked( 'a.b' ) );
	}

	public function test_never_in_all(): void {
		FakeChannel::install();
		Settings::set_secret( 'channels.fake.token', 'hidden-token-value' );
		$this->assertNotContains( 'hidden-token-value', serialize( Settings::all() ) );
		$this->assertNull( Settings::get( 'channels.fake.token' ) );
	}

	public function test_scrubbed_from_logger(): void {
		Settings::set_secret( 'a.b', 'very/secret+value' );
		Settings::secret( 'a.b' );
		Logger::error( 'test', 'Failed for very/secret+value', [ 'url' => 'https://x/very/secret+value', 'deep' => [ rawurlencode( 'very/secret+value' ) ] ] );
		$row = LogRepository::query()['items'][0];
		$this->assertNotContains( 'very/secret', $row['message'] . wp_json_encode( $row['data'] ) );
		$this->assertNotContains( rawurlencode( 'very/secret+value' ), wp_json_encode( $row['data'] ) );
		$this->assertContains( '***', $row['message'] );
	}

	public function test_short_secrets_do_not_mangle_text(): void {
		Logger::scrub( 'ab' );
		$this->assertSame( 'abc', Logger::clean( 'abc' ) );
	}

	public function test_secret_receivers_move_to_the_store(): void {
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
		$in  = [ Fixtures::receiver( 'https://hooks.example.com/T1', [ 'id' => 'r_one' ] ), Fixtures::receiver( 'secret:r_two', [ 'id' => 'r_two' ] ) ];
		$out = Settings::store_receiver_secrets( 'hook', $in );
		$this->assertSame( 'secret:r_one', $out[0]['address'] );
		$this->assertSame( 'secret:r_two', $out[1]['address'] );
		$this->assertSame( 'https://hooks.example.com/T1', Settings::secret( 'channels.hook.receiver_secrets.r_one' ) );
		$this->assertFalse( Settings::has_secret( 'channels.hook.receiver_secrets.r_two' ) );
		FakeChannel::install( 'plain' );
		$this->assertSame( $in, Settings::store_receiver_secrets( 'plain', $in ) );
	}
}
