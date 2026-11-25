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

	public function test_scrub_and_cut(): void {
		$this->assertSame( 'سلام 🙂', Text::scrub( 'سلام 🙂' ) );
		$this->assertSame( 'OK re?u', Text::scrub( "OK re\xE7u" ) );
		$this->assertTrue( Text::is_utf8( Text::scrub( "\xC7\xD3 \xF0\x9F\x99 \xED\xA0\x80" ) ) );
		$this->assertFalse( Text::is_utf8( "a\xFF" ) );
		$this->assertSame( 'سل', Text::cut_bytes( 'سلام', 5 ) );
		$this->assertSame( 'a', Text::cut_bytes( 'a🙂', 4 ) );
		$this->assertSame( 'a🙂', Text::cut_bytes( 'a🙂', 5 ) );
		$this->assertSame( '', Text::cut_bytes( 'abc', 0 ) );
		$this->assertSame( "\x80\x80", Text::cut_bytes( "\x80\x80\x80", 2 ) );
	}

	/** The same calls in a process whose mbstring functions are gone. */
	public function test_without_mbstring(): void {
		$code = 'define( "ABSPATH", "/" ); require ' . var_export( FNFE_DIR . 'includes/Core/Text.php', true ) . ';'
			. 'use AmirhpCom\FormsNotifier\Core\Text;'
			. '$in = json_decode( base64_decode( $argv[1] ), true );'
			. 'echo json_encode( [ Text::has_mbstring(), Text::lower( $in[0] ), Text::upper( $in[0] ), Text::title( $in[1] ),'
			. ' Text::utf16_length( $in[2] ), Text::scrub( "OK re\xE7u \xF0\x9F\x99" ), Text::scrub( str_repeat( $in[2] . "\xFF", 5000 ) ) === str_repeat( $in[2] . "?", 5000 ) ] );';
		$in   = [ 'ABC سلام É', "it's o'neil سلام abc", "می\u{200C}شود 🙂 é" ];
		$out  = json_decode(
			(string) shell_exec(
				escapeshellarg( PHP_BINARY ) . ' -d disable_functions=mb_strtolower,mb_strtoupper,mb_convert_case,mb_scrub,mb_convert_encoding,mb_strcut,mb_check_encoding -r '
				. escapeshellarg( $code ) . ' ' . escapeshellarg( base64_encode( (string) json_encode( $in ) ) )
			),
			true
		);
		$this->assertSame(
			[ false, 'abc سلام É', 'ABC سلام É', "It's O'neil سلام Abc", Text::utf16_length( $in[2] ), 'OK re?u ???', true ],
			$out
		);
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
