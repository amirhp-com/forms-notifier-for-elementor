<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class CryptoTest extends TestCase {

	const PURE = true;

	public function test_sign_and_verify(): void {
		$signature = Crypto::sign( 'data', 'file' );
		$this->assertMatches( '/^[a-f0-9]{64}$/', $signature );
		$this->assertTrue( Crypto::verify( 'data', $signature, 'file' ) );
		$this->assertFalse( Crypto::verify( 'Data', $signature, 'file' ) );
		$this->assertFalse( Crypto::verify( 'data', '', 'file' ) );
		$this->assertFalse( Crypto::verify( 'data', substr( $signature, 0, 63 ), 'file' ) );
	}

	public function test_purposes_are_separate(): void {
		$this->assertNotSame( Crypto::sign( 'data', 'file' ), Crypto::sign( 'data', 'app' ) );
		$this->assertFalse( Crypto::verify( 'data', Crypto::sign( 'data', 'app' ), 'file' ) );
		$this->assertFalse( Crypto::verify( 'data', Crypto::sign( 'data' ), 'file' ) );
	}

	public function test_token(): void {
		$this->assertMatches( '/^[a-f0-9]{32}$/', Crypto::token() );
		$this->assertMatches( '/^[a-f0-9]{48}$/', Crypto::token( 24 ) );
		$this->assertNotSame( Crypto::token(), Crypto::token() );
	}

	public function test_weak_salts(): void {
		$this->assertTrue( Crypto::is_weak_salt( '' ) );
		$this->assertTrue( Crypto::is_weak_salt( 'short' ) );
		$this->assertTrue( Crypto::is_weak_salt( 'put your unique phrase here' ) );
		$this->assertTrue( Crypto::is_weak_salt( 'Put your unique phrase here, or something longer' ) );
		$this->assertFalse( Crypto::is_weak_salt( str_repeat( 'x7', 32 ) ) );
		$this->assertTrue( is_bool( Crypto::uses_weak_salts() ) );
	}
}
