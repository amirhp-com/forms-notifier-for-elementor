<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\MiniApp;

use AmirhpCom\FormsNotifier\MiniApp\InitData;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Launch.php';

final class InitDataTest extends TestCase {

	const PURE = true;

	const NOW = 1767261600;

	private const USER = '{"id":111111,"first_name":"Sara & Co","last_name":"A+B","username":"sara_example","language_code":"fa","photo_url":"https:\/\/example.com\/p.jpg"}';

	public function setUp(): void {
		$this->freeze( self::NOW );
	}

	private function pairs( array $overrides = [] ): array {
		return array_filter(
			array_merge(
				[
					'query_id'    => 'AAHdF6IQAAAAAN0XohDhrOrc',
					'user'        => self::USER,
					'auth_date'   => (string) ( self::NOW - 60 ),
					'start_param' => 'sub_12',
					'chat_type'   => 'private',
					'signature'   => 'abc-DEF_123',
				],
				$overrides
			),
			'is_string'
		);
	}

	private function validate( string $data, int $max_age = 86400 ): array {
		return InitData::validate_with( $data, Fixtures::TOKEN, $max_age );
	}

	public function test_valid_vector(): void {
		$data = Launch::sign( $this->pairs() );
		$this->assertTrue( InitData::check_hash( $data, Fixtures::TOKEN ) );
		$r = $this->validate( $data );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['error'] );
		$this->assertSame( '111111', $r['user']['id'] );
		$this->assertSame( 'Sara & Co', $r['user']['first_name'] );
		$this->assertSame( 'A+B', $r['user']['last_name'] );
		$this->assertSame( 'sara_example', $r['user']['username'] );
		$this->assertSame( 'fa', $r['user']['language_code'] );
		$this->assertSame( 'https://example.com/p.jpg', $r['user']['photo_url'] );
		$this->assertSame( 'sub_12', $r['start_param'] );
		$this->assertSame( 'private', $r['chat_type'] );
		$this->assertSame( self::NOW - 60, $r['auth_date'] );
		$this->assertKeys( [ 'ok', 'error', 'user', 'auth_date', 'start_param', 'chat_type' ], $r );
	}

	/** Computed once by hand with another tool, so a shared mistake in sign() would show. */
	public function test_fixed_vector(): void {
		$check  = "auth_date=1767261540\nuser={\"id\":1}";
		$secret = hash_hmac( 'sha256', '123456:TEST-TOKEN', 'WebAppData', true );
		$data   = 'user=%7B%22id%22%3A1%7D&auth_date=1767261540&hash=' . hash_hmac( 'sha256', $check, $secret );
		$this->assertTrue( InitData::check_hash( $data, '123456:TEST-TOKEN' ) );
		$this->assertSame( '1', $this->validate( $data )['user']['id'] );
	}

	public function test_any_changed_byte_fails(): void {
		$data = Launch::sign( $this->pairs() );
		for ( $i = 0, $n = strlen( $data ); $i < $n; $i++ ) {
			$changed      = $data;
			$changed[ $i ] = $data[ $i ] === 'x' ? 'y' : 'x';
			// Hex digits and percent escapes are case insensitive; "x" is neither.
			$this->assertFalse( InitData::check_hash( $changed, Fixtures::TOKEN ), "byte $i" );
		}
		$this->assertFalse( InitData::check_hash( $data . '&extra=1', Fixtures::TOKEN ) );
		$this->assertFalse( InitData::check_hash( $data, Fixtures::TOKEN . 'x' ) );
		$this->assertFalse( InitData::check_hash( $data, '' ) );
	}

	public function test_signature_stays_in_the_check_string(): void {
		$data = Launch::sign( $this->pairs() );
		$this->assertFalse( InitData::check_hash( str_replace( '&signature=abc-DEF_123', '', $data ), Fixtures::TOKEN ) );
		$this->assertTrue( InitData::check_hash( Launch::sign( $this->pairs( [ 'signature' => null ] ) ), Fixtures::TOKEN ) );
	}

	public function test_order_does_not_matter(): void {
		$parts = explode( '&', Launch::sign( $this->pairs() ) );
		$this->assertTrue( InitData::check_hash( implode( '&', array_reverse( $parts ) ), Fixtures::TOKEN ) );
		shuffle( $parts );
		$this->assertTrue( InitData::check_hash( implode( '&', $parts ), Fixtures::TOKEN ) );
	}

	public function test_plus_is_not_a_space(): void {
		$data = Launch::sign( [ 'a' => 'x+y', 'auth_date' => '1', 'user' => '{}' ] );
		$this->assertTrue( InitData::check_hash( $data, Fixtures::TOKEN ) );
		$this->assertTrue( InitData::check_hash( str_replace( '%2B', '+', $data ), Fixtures::TOKEN ) );
		$this->assertFalse( InitData::check_hash( str_replace( '%2B', '%20', $data ), Fixtures::TOKEN ) );
	}

	public function test_missing_and_malformed(): void {
		$this->assertSame( 'missing', $this->validate( '' )['error'] );
		$data = Launch::sign( $this->pairs() );
		$this->assertSame( 'malformed', $this->validate( preg_replace( '/&hash=[a-f0-9]+/', '', $data ) )['error'] );
		$this->assertSame( 'malformed', $this->validate( 'nothing' )['error'] );
		$this->assertSame( 'malformed', $this->validate( $data . '&' )['error'] );
		$this->assertSame( 'malformed', $this->validate( $data . '&user=%7B%7D' )['error'], 'repeated key' );
		$this->assertSame( 'malformed', $this->validate( 'user=%ff&hash=' . str_repeat( 'a', 64 ) )['error'], 'invalid UTF-8' );
		$this->assertSame( 'malformed', $this->validate( str_repeat( 'a', 9000 ) . '=1' )['error'] );
		$this->assertSame( 'bad_hash', $this->validate( 'user=1&hash=abc' )['error'] );
		$this->assertSame( 'bad_hash', $this->validate( 'user=1&hash=' . str_repeat( '0', 64 ) )['error'] );
		$this->assertSame( 'no_token', InitData::validate_with( $data, '', 100 )['error'] );
		$this->assertSame( 'malformed', $this->validate( Launch::sign( $this->pairs( [ 'auth_date' => '12x' ] ) ) )['error'] );
		$this->assertSame( 'malformed', $this->validate( Launch::sign( $this->pairs( [ 'auth_date' => null ] ) ) )['error'] );
	}

	public function test_age(): void {
		$at = function ( int $offset, int $max_age ) {
			return $this->validate( Launch::sign( $this->pairs( [ 'auth_date' => (string) ( self::NOW + $offset ) ] ) ), $max_age )['error'];
		};
		$this->assertSame( '', $at( -3600, 3600 ) );
		$this->assertSame( 'expired', $at( -3601, 3600 ) );
		$this->assertSame( '', $at( 300, 3600 ) );
		$this->assertSame( 'expired', $at( 301, 3600 ) );
	}

	public function test_user(): void {
		$error = function ( $user ) {
			return $this->validate( Launch::sign( $this->pairs( [ 'user' => $user ] ) ) );
		};
		foreach ( [ null, '', '{}', '[]', 'x', '{"id":0}', '{"id":-5}', '{"id":1.5}', '{"id":true}', '{"id":"1 OR 1"}', '{"id":[1]}' ] as $user ) {
			$r = $error( $user );
			$this->assertSame( 'no_user', $r['error'], (string) $user );
			$this->assertFalse( $r['ok'] );
			$this->assertSame( '', $r['user']['id'] );
		}
		$this->assertSame( '98765432109876543210', $error( '{"id":98765432109876543210}' )['user']['id'] );
		$this->assertSame( '7', $error( '{"id":"7"}' )['user']['id'] );
		$user = $error( '{"id":7,"first_name":"<script>x</script>Ali","username":["a"],"photo_url":"javascript:alert(1)","language_code":"<b>"}' )['user'];
		$this->assertSame( 'Ali', $user['first_name'] );
		$this->assertSame( '', $user['username'] );
		$this->assertSame( '', $user['photo_url'] );
		$this->assertSame( '', $user['language_code'] );
	}

	public function test_start_param_is_filtered(): void {
		$r = $this->validate( Launch::sign( $this->pairs( [ 'start_param' => '"><x', 'chat_type' => 'a b' ] ) ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '', $r['start_param'] );
		$this->assertSame( '', $r['chat_type'] );
	}
}
