<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\MiniApp;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\MiniApp\Access;
use AmirhpCom\FormsNotifier\MiniApp\InitData;
use AmirhpCom\FormsNotifier\MiniApp\Session;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Launch.php';

final class SessionTest extends TestCase {

	const NOW = 1767261600;

	private $now = self::NOW;

	public function setUp(): void {
		Db::reset();
		$this->now = self::NOW;
		$this->filter(
			'fnfe_now',
			function () {
				return $this->now;
			},
			PHP_INT_MAX
		);
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
		Settings::set_secret( 'channels.bale.token', '654321:TEST-TOKEN' );
		Settings::set_many(
			[
				'channels.telegram.admins' => [ Launch::admin( '111111', [ 'view', 'act' ] ), Launch::admin( '222222', [ 'view' ] ) ],
				'channels.bale.admins'     => [ Launch::admin( '333333', [ 'view', 'act' ] ) ],
			]
		);
	}

	private function payload( string $token ): array {
		return json_decode( base64_decode( strtr( explode( '.', $token )[0], '-_', '+/' ) ), true );
	}

	private function forge( array $payload ): string {
		$json   = wp_json_encode( $payload );
		$encode = static function ( $data ) {
			return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
		};
		return $encode( $json ) . '.' . $encode( hex2bin( Crypto::sign( $json, 'app' ) ) );
	}

	public function test_access(): void {
		$this->assertTrue( Access::allowed( 'telegram', '111111' ) );
		$this->assertFalse( Access::allowed( 'telegram', '333333' ), 'lists are per platform' );
		$this->assertFalse( Access::allowed( 'bale', '111111' ) );
		$this->assertFalse( Access::allowed( 'telegram', '' ) );
		$this->assertFalse( Access::allowed( 'other', '111111' ) );
		$this->assertSame( [ 'view', 'act' ], Access::perms( 'telegram', '111111' ) );
		$this->assertSame( [ 'view' ], Access::perms( 'telegram', '222222' ) );
		$this->assertSame( [], Access::perms( 'telegram', '999' ) );
		Settings::set( 'miniapp.allow_actions', false );
		$this->assertSame( [ 'view' ], Access::perms( 'telegram', '111111' ) );
	}

	public function test_access_ignores_broken_entries(): void {
		Settings::set( 'channels.telegram.admins', [ 'x', [], [ 'uid' => [ '1' ] ], [ 'uid' => 'abc' ], [ 'uid' => 444444, 'perms' => 'act' ], [ 'uid' => '555555' ] ] );
		$this->assertSame( [ '444444', '555555' ], array_column( Access::admins( 'telegram' ), 'uid' ) );
		$this->assertSame( [ 'view' ], Access::perms( 'telegram', '555555' ), 'without perms only view' );
	}

	public function test_access_filter_and_deleted_user(): void {
		Settings::set( 'channels.telegram.admins', [ Launch::admin( '111111', [ 'view', 'act' ], 99999999 ) ] );
		$this->assertFalse( Access::allowed( 'telegram', '111111' ), 'linked WordPress user was deleted' );
		$seen = [];
		$this->filter(
			'fnfe_miniapp_allowed',
			static function ( $allowed, $platform, $user ) use ( &$seen ) {
				$seen = [ $allowed, $platform, $user['uid'] ];
				return $user['uid'] === '777777';
			},
			10,
			3
		);
		$this->assertTrue( Access::allowed( 'telegram', '777777' ) );
		$this->assertSame( [ false, 'telegram', '777777' ], $seen );
		$this->assertSame( [ 'view' ], Access::perms( 'telegram', '777777' ), 'a filter grants view only' );
		$this->assertFalse( Access::allowed( 'telegram', 'abc' ) );
	}

	public function test_issue_and_verify(): void {
		$s = Session::issue( 'telegram', '111111', [ 'view', 'act' ] );
		$this->assertSame( self::NOW + 7200, $s['expires'] );
		$this->assertMatches( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $s['token'] );
		$this->assertSame( [ 'platform' => 'telegram', 'uid' => '111111', 'perms' => [ 'view', 'act' ], 'exp' => self::NOW + 7200 ], Session::verify( $s['token'] ) );
		$this->assertNotContains( 'TEST-TOKEN', base64_decode( explode( '.', $s['token'] )[0] ) );
	}

	public function test_tampering(): void {
		$token = Session::issue( 'telegram', '222222', [ 'view' ] )['token'];
		for ( $i = 0, $n = strlen( $token ); $i < $n; $i++ ) {
			$changed       = $token;
			$changed[ $i ] = $token[ $i ] === 'A' ? 'B' : 'A';
			$this->assertNull( Session::verify( $changed ), "byte $i" );
		}
		list( $body, $signature ) = explode( '.', $token );
		$payload      = $this->payload( $token );
		$payload['r'] = [ 'view', 'act' ];
		$this->assertNull( Session::verify( rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' ) . '.' . $signature ) );
		foreach ( [ '', '.', 'a.b', $body, $body . '.', '.' . $signature, $token . 'A', $token . '.x', str_repeat( 'a', 3000 ) . '.' . $signature ] as $bad ) {
			$this->assertNull( Session::verify( $bad ), $bad );
		}
	}

	public function test_signed_with_the_wrong_purpose(): void {
		$json  = wp_json_encode( $this->payload( Session::issue( 'telegram', '111111', [ 'view' ] )['token'] ) );
		$token = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) . '.' . rtrim( strtr( base64_encode( hex2bin( Crypto::sign( $json, 'file' ) ) ), '+/', '-_' ), '=' );
		$this->assertNull( Session::verify( $token ) );
	}

	public function test_expiry(): void {
		$token     = Session::issue( 'telegram', '111111', [ 'view' ] )['token'];
		$this->now = self::NOW + 7199;
		$this->assertNotNull( Session::verify( $token ) );
		$this->now = self::NOW + 7200;
		$this->assertNull( Session::verify( $token ) );
	}

	public function test_revoke_all(): void {
		$token = Session::issue( 'telegram', '111111', [ 'view' ] )['token'];
		Session::revoke_all();
		$this->assertNull( Session::verify( $token ) );
		$this->assertNotNull( Session::verify( Session::issue( 'telegram', '111111', [ 'view' ] )['token'] ) );
	}

	public function test_removed_admin_and_reduced_perms(): void {
		$token = Session::issue( 'telegram', '111111', [ 'view', 'act' ] )['token'];
		Settings::set( 'channels.telegram.admins', [ Launch::admin( '111111', [ 'view' ] ) ] );
		$this->assertSame( [ 'view' ], Session::verify( $token )['perms'], 'perms are the ones the admin still has' );
		Settings::set( 'channels.telegram.admins', [ Launch::admin( '222222', [ 'view' ] ) ] );
		$this->assertNull( Session::verify( $token ) );
	}

	public function test_more_perms_need_a_new_sign_in(): void {
		$token = Session::issue( 'telegram', '222222', [ 'view' ] )['token'];
		Settings::set( 'channels.telegram.admins', [ Launch::admin( '222222', [ 'view', 'act' ] ) ] );
		$this->assertSame( [ 'view' ], Session::verify( $token )['perms'] );
	}

	public function test_new_bot_token_ends_sessions(): void {
		$token = Session::issue( 'telegram', '111111', [ 'view' ] )['token'];
		$bale  = Session::issue( 'bale', '333333', [ 'view' ] )['token'];
		Settings::set_secret( 'channels.telegram.token', '123456:OTHER-TOKEN' );
		$this->assertNull( Session::verify( $token ) );
		$this->assertNotNull( Session::verify( $bale ) );
		Settings::set_secret( 'channels.telegram.token', '' );
		$this->assertNull( Session::verify( Session::issue( 'telegram', '111111', [ 'view' ] )['token'] ) );
	}

	public function test_other_site_of_a_network(): void {
		$payload = $this->payload( Session::issue( 'telegram', '111111', [ 'view' ] )['token'] );
		$this->assertNotNull( Session::verify( $this->forge( $payload ) ) );
		$payload['b'] = 2;
		$this->assertNull( Session::verify( $this->forge( $payload ) ) );
	}

	public function test_validate_reads_settings(): void {
		$pairs = [ 'user' => '{"id":111111}', 'auth_date' => (string) ( self::NOW - 4000 ) ];
		$data  = Launch::sign( $pairs );
		$this->assertTrue( InitData::validate( 'telegram', $data )['ok'] );
		$this->assertSame( 'bad_hash', InitData::validate( 'bale', $data )['error'] );
		$this->assertTrue( InitData::validate( 'bale', Launch::sign( $pairs, '654321:TEST-TOKEN' ) )['ok'] );
		$this->assertSame( 'expired', InitData::validate( 'telegram', $data, 3600 )['error'] );
		Settings::set( 'miniapp.max_age', 3600 );
		$this->assertSame( 'expired', InitData::validate( 'telegram', $data )['error'] );
		$this->assertSame( 'no_token', InitData::validate( 'other', $data )['error'] );
		$this->assertSame( 'missing', InitData::validate( 'telegram', '' )['error'] );
	}
}
