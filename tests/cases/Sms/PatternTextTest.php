<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PatternTextTest extends TestCase {

	const PURE = true;

	public function test_header_aliases(): void {
		foreach ( [ 'pattern', 'pcode', 'patterncode', 'bodyid', 'template', 'PATTERN', 'Template' ] as $alias ) {
			foreach ( [ ':', '=', ' : ', ' = ', ': ' ] as $separator ) {
				$out = PatternText::parse( $alias . $separator . 'abc-12' );
				$this->assertSame( 'abc-12', $out['code'] ?? null, $alias . $separator );
				$this->assertSame( [], $out['vars'] );
				$this->assertSame( '', $out['text'] );
				$this->assertSame( '', $out['lang'] );
				$this->assertSame( [], $out['codes'] );
			}
		}
		$this->assertSame( '590428', PatternText::parse( 'pattern:۵۹۰۴۲۸' )['code'] );
		$this->assertSame( '590428', PatternText::parse( "\n\n  pattern:590428  \r\n" )['code'] );
		$this->assertSame( '590428', PatternText::parse( "\u{FEFF}pattern:590428" )['code'] );
		$this->assertSame( '590428', PatternText::parse( "\u{200F}pattern:590428" )['code'] );
	}

	public function test_normal_texts(): void {
		foreach ( [
			'',
			'Hello',
			'pattern: free text of an offer',
			'pattern:',
			'pattern',
			'template:{form_name}',
			'pattern://example.com',
			"Hello\npattern:123",
			'mypattern:12',
			'pattern@smsir:12',
			'{field:x}',
		] as $text ) {
			$this->assertNull( PatternText::parse( $text ), $text );
		}
	}

	public function test_variables(): void {
		foreach ( [
			'code:12'                  => [ 'code' => '12' ],
			'code=12'                  => [ 'code' => '12' ],
			'code = 12'                => [ 'code' => '12' ],
			'{code}:12'                => [ 'code' => '12' ],
			'%code%:12'                => [ 'code' => '12' ],
			'%code% = 12'              => [ 'code' => '12' ],
			'0:{field:name}'           => [ 0 => '{field:name}' ],
			'۰:x'                      => [ 0 => 'x' ],
			'{۱}:x'                    => [ 1 => 'x' ],
			'٢:x'                      => [ 2 => 'x' ],
			'{field:name}'             => [ 0 => '{field:name}' ],
			'{form_name}'              => [ 0 => '{form_name}' ],
			'a bare value'             => [ 0 => 'a bare value' ],
			'https://example.com/a?b=c' => [ 0 => 'https://example.com/a?b=c' ],
			'link:https://example.com' => [ 'link' => 'https://example.com' ],
			'link=https://example.com' => [ 'link' => 'https://example.com' ],
			'time:12:30'               => [ 'time' => '12:30' ],
			'empty:'                   => [ 'empty' => '' ],
			'{lang}:fa'                => [ 'lang' => 'fa' ],
			'lang:{lang}'              => [ 'lang' => '{lang}' ],
			'first-name.x_1:y'         => [ 'first-name.x_1' => 'y' ],
		] as $line => $expected ) {
			$this->assertSame( $expected, PatternText::parse( "pattern:1\n" . $line )['vars'], (string) $line );
		}
		$this->assertSame( [ 0 => 'a', 'n' => 'b', 1 => 'c', 5 => 'd', 6 => 'e' ], PatternText::parse( "pattern:1\na\n\nn:b\nc\n5:d\ne" )['vars'] );
	}

	public function test_codes_and_language(): void {
		$out = PatternText::parse( "pattern:1\npattern@smsir:۱۲۳\nbodyid@MeliPayamak = 77\nlang:en_US\nx:y" );
		$this->assertSame( [ 'smsir' => '123', 'melipayamak' => '77' ], $out['codes'] );
		$this->assertSame( 'en_US', $out['lang'] );
		$this->assertSame( [ 'x' => 'y' ], $out['vars'] );
		$this->assertSame( 'fa', PatternText::parse( "template:welcome\nlang = fa" )['lang'] );
		$this->assertSame( 'pt-BR', PatternText::parse( "template:welcome\nLang:pt-BR" )['lang'] );
	}

	public function test_text(): void {
		$out = PatternText::parse( "pattern:1\r\na:b\r\n-----\r\nLine one\r\n\r\npattern:2\r\n---\r\nend\r\n" );
		$this->assertSame( [ 'a' => 'b' ], $out['vars'] );
		$this->assertSame( "Line one\n\npattern:2\n---\nend", $out['text'] );
		$this->assertSame( '', PatternText::parse( "pattern:1\n---" )['text'] );
		$this->assertSame( [ 0 => '--' ], PatternText::parse( "pattern:1\n--" )['vars'] );
	}

	public function test_compose_round_trip(): void {
		foreach ( [
			[ '590428', [], '' ],
			[ 'abc', [ 'a' => '1', 'b' => 'two words' ], 'Text' ],
			[ 'abc', [ 'one', 'two' ], "Line\n\nLine" ],
			[ 'abc', [ 'u' => '//example.com', 'v' => 'https://example.com' ], '' ],
			[ 'abc', [ 'lang' => 'fa' ], '' ],
			[ 'abc', [ 3 => 'x', 'y' => '' ], 'متن' ],
		] as $case ) {
			$out = PatternText::parse( PatternText::compose( $case[0], $case[1], $case[2] ) );
			$this->assertSame( $case, [ $out['code'], $out['vars'], $out['text'] ] );
		}
		$this->assertSame( "pattern:1\na:x y", PatternText::compose( '1', [ 'a' => "x\ny" ] ) );
	}

	public function test_values(): void {
		$this->assertSame( [ 'a', 'b', 'c' ], PatternText::values( [ 2 => 'c', 0 => 'a', 1 => 'b' ] ) );
		$this->assertSame( [ 'z', 'a' ], PatternText::values( [ 'n' => 'z', 0 => 'a' ] ) );
		$this->assertTrue( PatternText::is_positional( [ 1 => 'x' ] ) );
		$this->assertTrue( PatternText::is_positional( [] ) );
		$this->assertFalse( PatternText::is_positional( [ 1 => 'x', 'a' => 'y' ] ) );
	}
}
