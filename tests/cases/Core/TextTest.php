<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class TextTest extends TestCase {

	const PURE = true;

	public function test_digits(): void {
		$this->assertSame( '0912 345 6789', Text::en_digits( '۰۹۱۲ ٣٤٥ ۶۷۸۹' ) );
		$this->assertSame( '۰۹۱۲۳', Text::fa_digits( '0٩12۳' ) );
		$this->assertSame( 'abc', Text::en_digits( 'abc' ) );
	}

	public function test_truncate(): void {
		$this->assertSame( 'سلام', Text::truncate( 'سلام', 4 ) );
		$this->assertSame( 'سلا…', Text::truncate( 'سلام دنیا', 4 ) );
		$this->assertSame( 'ab...', Text::truncate( 'abcdefgh', 5, '...' ) );
		$this->assertSame( '', Text::truncate( 'abc', 0 ) );
		$this->assertSame( 'a', Text::truncate( 'abc', 1, '...' ) );
	}

	public function test_utf16_length(): void {
		$this->assertSame( 4, Text::utf16_length( 'سلام' ) );
		$this->assertSame( 2, Text::utf16_length( '🙂' ) );
		$this->assertSame( 6, Text::utf16_length( "می\u{200C}شود" ) );
		$this->assertSame( 0, Text::utf16_length( '' ) );
	}

	public function test_uid(): void {
		$this->assertMatches( '/^r_[a-z0-9]{8}$/', Text::uid( 'r_' ) );
		$this->assertNotSame( Text::uid(), Text::uid() );
	}

	public function test_lines(): void {
		$this->assertSame( [ 'a', 'b', 'c', '' ], Text::lines( "a\r\nb\rc\n" ) );
	}

	public function test_bool(): void {
		foreach ( [ true, 1, '1', 'yes', 'TRUE', 'on', ' on ' ] as $value ) {
			$this->assertTrue( Text::bool( $value ) );
		}
		foreach ( [ false, 0, '', '0', 'no', 'off', null, [], [ 1 ] ] as $value ) {
			$this->assertFalse( Text::bool( $value ) );
		}
	}
}
