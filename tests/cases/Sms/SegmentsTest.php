<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Sms;

use AmirhpCom\FormsNotifier\Channels\Sms\Segments;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SegmentsTest extends TestCase {

	const PURE = true;

	public function test_parts(): void {
		foreach ( [
			[ '', 0 ],
			[ 'a', 1 ],
			[ str_repeat( 'a', 160 ), 1 ],
			[ str_repeat( 'a', 161 ), 2 ],
			[ str_repeat( 'a', 306 ), 2 ],
			[ str_repeat( 'a', 307 ), 3 ],
			[ str_repeat( '€', 80 ), 1 ],
			[ str_repeat( '€', 81 ), 2 ],
			[ str_repeat( 'س', 70 ), 1 ],
			[ str_repeat( 'س', 71 ), 2 ],
			[ str_repeat( 'س', 134 ), 2 ],
			[ str_repeat( 'س', 135 ), 3 ],
			[ str_repeat( '😀', 35 ), 1 ],
			[ str_repeat( '😀', 36 ), 2 ],
		] as $case ) {
			$this->assertSame( $case[1], Segments::parts( $case[0] ), mb_substr( $case[0], 0, 3 ) . mb_strlen( $case[0] ) );
		}
		$this->assertTrue( Segments::is_gsm( "Hello @ [x] {y} ^ ~ | \\ é €\n" ) );
		$this->assertFalse( Segments::is_gsm( 'سلام' ) );
		$this->assertFalse( Segments::is_gsm( '…' ) );
	}

	public function test_limit(): void {
		$long = str_repeat( 'abc ', 100 );
		$this->assertSame( $long, Segments::limit( $long, 0 ) );
		$this->assertSame( 'short', Segments::limit( 'short', 1 ) );
		foreach ( [ 1, 2 ] as $parts ) {
			$out = Segments::limit( $long, $parts );
			$this->assertSame( $parts, Segments::parts( $out ) );
			$this->assertSame( '...', substr( $out, -3 ) );
			$out = Segments::limit( str_repeat( 'سلام ', 100 ), $parts );
			$this->assertSame( $parts, Segments::parts( $out ) );
			$this->assertSame( '…', mb_substr( $out, -1 ) );
		}
	}
}
