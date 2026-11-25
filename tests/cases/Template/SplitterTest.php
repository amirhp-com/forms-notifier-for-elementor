<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Template\Splitter;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SplitterTest extends TestCase {

	const PURE = true;

	private static function text(): string {
		$out = '';
		for ( $i = 1; $i <= 40; $i++ ) {
			$out .= "Paragraph $i. سلام دنیا 🙂 & more <words> here! Another sentence *with* _marks_ [x](y).\nSecond line $i" . ( $i % 5 ? "\n" : "\n\n" );
		}
		return trim( $out );
	}

	private function assertBalanced( string $html, string $message ): void {
		$stack = [];
		preg_match_all( '~<(/?)([a-z-]+)[^>]*>|<|&(?!(?:amp|lt|gt|quot);)~', $html, $m, PREG_SET_ORDER );
		foreach ( $m as $tag ) {
			$this->assertTrue( isset( $tag[2] ), $message . ' broken: ' . $tag[0] );
			if ( $tag[1] === '/' ) {
				$this->assertSame( array_pop( $stack ), $tag[2], $message );
			} else {
				$stack[] = $tag[2];
			}
		}
		$this->assertSame( [], $stack, $message );
	}

	public function test_html_is_balanced_at_any_length(): void {
		$formatter = Registry::formatter( 'telegram_html' );
		$html      = '<b>' . $formatter->escape( mb_substr( self::text(), 0, 900 ) ) . '</b> <a href="https://example.com/?a=1&amp;b=2">' . $formatter->escape( 'a long <label> & text' ) . '</a><blockquote><i>' . $formatter->escape( mb_substr( self::text(), 900, 900 ) ) . "</i></blockquote>\n<pre>" . $formatter->escape( "code & <more>\n  indented" ) . '</pre><tg-spoiler>end</tg-spoiler>';
		for ( $limit = 20; $limit <= 2200; $limit += $limit < 200 ? 1 : 37 ) {
			$parts = $formatter->split( $html, $limit );
			$this->assertTrue( count( $parts ) >= 1 && count( $parts ) <= Splitter::MAX_PARTS, "limit $limit" );
			foreach ( $parts as $part ) {
				$this->assertBalanced( $part, "limit $limit" );
				$this->assertTrue( $formatter->length( $part ) <= $limit + ( $limit < 60 ? 60 : 0 ), "limit $limit length " . $formatter->length( $part ) );
			}
		}
	}

	public function test_nothing_is_lost_when_it_fits_into_the_parts(): void {
		$formatter = Registry::formatter( 'telegram_html' );
		$html      = '<b>' . $formatter->escape( self::text() ) . '</b>';
		$parts     = $formatter->split( $html, 900 );
		$this->assertTrue( count( $parts ) > 2 && count( $parts ) < Splitter::MAX_PARTS );
		$plain = '';
		foreach ( $parts as $part ) {
			$plain .= html_entity_decode( strip_tags( $part ) );
		}
		$this->assertSame( preg_replace( '/\s+/u', '', self::text() ), preg_replace( '/\s+/u', '', $plain ) );
		$this->assertFalse( Splitter::was_truncated() );
	}

	public function test_short_text_and_empty_text(): void {
		$this->assertSame( [ 'abc' ], Splitter::split_plain( 'abc', 10 ) );
		$this->assertSame( [ str_repeat( 'a', 10 ) ], Splitter::split_plain( str_repeat( 'a', 10 ), 10 ) );
		$this->assertSame( [], Splitter::split_plain( '', 10 ) );
		$this->assertSame( [], Splitter::split_html( "  \n ", 10 ) );
		$this->assertSame( [ self::text() ], Splitter::split_plain( self::text(), 0 ) );
	}

	public function test_border_preference(): void {
		$a = str_repeat( 'word ', 15 ) . 'end.';
		$this->assertSame( [ $a, $a ], Splitter::split_plain( "$a\n\n$a", 130 ) );
		$this->assertSame( [ $a, $a ], Splitter::split_plain( "$a\n$a", 130 ) );
		$this->assertSame( [ $a, $a ], Splitter::split_plain( "$a $a", 110 ) );
		$parts = Splitter::split_plain( trim( str_repeat( 'word ', 30 ) ), 100 );
		foreach ( $parts as $part ) {
			$this->assertMatches( '/^(word ?)+$/', $part );
		}
	}

	public function test_long_word_and_grapheme_safety(): void {
		$parts = Splitter::split_plain( str_repeat( '👨‍👩‍👧', 60 ), 50, [ Text::class, 'utf16_length' ] );
		foreach ( $parts as $part ) {
			$this->assertSame( '', str_replace( '👨‍👩‍👧', '', strpos( $part, '…' ) === false ? $part : explode( "\n", $part )[0] ) );
			$this->assertTrue( Text::utf16_length( $part ) <= 50 );
		}
		$this->assertTrue( mb_check_encoding( implode( '', Splitter::split_plain( "ab\xff" . str_repeat( 'x', 50 ), 20 ) ), 'UTF-8' ) );
	}

	public function test_at_most_ten_parts_with_a_note(): void {
		$text  = trim( str_repeat( 'word ', 2000 ) );
		$parts = Splitter::split_plain( $text, 200 );
		$this->assertCount( 10, $parts );
		$this->assertMatches( '/\n… \((\d+) more characters\)$/', $parts[9] );
		foreach ( $parts as $part ) {
			$this->assertTrue( mb_strlen( $part ) <= 200 );
		}
		$this->assertTrue( Splitter::was_truncated() );
		preg_match( '/\((\d+) more/', $parts[9], $m );
		$shown = 0;
		foreach ( $parts as $part ) {
			$shown += substr_count( $part, 'word' );
		}
		$this->assertSame( 2000 - $shown, (int) ceil( $m[1] / 5 ) );
		$with = Splitter::append_link( $parts, 'https://example.com/1' );
		$this->assertContains( 'https://example.com/1', $with[9] );

		Splitter::split_plain( 'short', 200 );
		$this->assertSame( [ 'short' ], Splitter::append_link( [ 'short' ], 'https://example.com/1' ) );
	}

	public function test_markdown_v2_parts_are_valid(): void {
		$formatter = Registry::formatter( 'telegram_md' );
		$canonical = '<b>' . htmlspecialchars( mb_substr( self::text(), 0, 700 ) ) . '</b><i>x<u>y</u></i> <a href="https://example.com/a_(b)">la_bel</a> <code>a`b\\c d</code><pre>' . htmlspecialchars( mb_substr( self::text(), 0, 500 ) ) . '</pre><spoiler>' . htmlspecialchars( mb_substr( self::text(), 0, 300 ) ) . '</spoiler>';
		$md        = $formatter->convert( $canonical );
		foreach ( [ 60, 100, 250, 400, 1000, 1500 ] as $limit ) {
			foreach ( $formatter->split( $md, $limit ) as $part ) {
				$this->assertMarkdownV2( $part, "limit $limit" );
				$this->assertTrue( $formatter->length( $part ) <= $limit + ( $limit < 100 ? 60 : 0 ), "limit $limit: " . $formatter->length( $part ) );
			}
		}
	}

	/** Small MarkdownV2 parser: throws on an unescaped special character or an unclosed entity. */
	private function assertMarkdownV2( string $text, string $message ): void {
		$open  = [];
		$i     = 0;
		$count = strlen( $text );
		while ( $i < $count ) {
			if ( $text[ $i ] === '\\' ) {
				$i += 2;
				continue;
			}
			if ( substr( $text, $i, 3 ) === '```' || $text[ $i ] === '`' ) {
				$mark = substr( $text, $i, 3 ) === '```' ? '```' : '`';
				$j    = $i + strlen( $mark );
				while ( $j < $count && substr( $text, $j, strlen( $mark ) ) !== $mark ) {
					$j += $text[ $j ] === '\\' ? 2 : 1;
				}
				$this->assertTrue( $j < $count, $message . ' unclosed code' );
				$i = $j + strlen( $mark );
				continue;
			}
			if ( $text[ $i ] === '>' ) {
				$this->assertTrue( $i === 0 || $text[ $i - 1 ] === "\n", $message . ' stray >' );
				++$i;
				continue;
			}
			if ( preg_match( '~\G\[(?:\\\\.|[^\]\\\\])*\]\((?:\\\\.|[^)\\\\])*\)~s', $text, $m, 0, $i ) ) {
				$i += strlen( $m[0] );
				continue;
			}
			foreach ( [ '||', '__', '*', '_', '~' ] as $mark ) {
				if ( substr( $text, $i, strlen( $mark ) ) === $mark ) {
					if ( end( $open ) === $mark ) {
						array_pop( $open );
					} else {
						$this->assertFalse( in_array( $mark, $open, true ), $message . ' crossed ' . $mark );
						$open[] = $mark;
					}
					$i += strlen( $mark );
					continue 2;
				}
			}
			$this->assertFalse( strpos( '[]()#+-=|{}.!', $text[ $i ] ), $message . ' unescaped ' . substr( $text, $i, 10 ) );
			++$i;
		}
		$this->assertSame( [], $open, $message );
	}

	public function test_other_marks_are_closed_and_reopened(): void {
		$words = trim( str_repeat( 'word ', 60 ) );
		foreach ( [ 'whatsapp' => '*', 'slack' => '*', 'markdown' => '**', 'bale_md' => '*' ] as $format => $mark ) {
			$formatter = Registry::formatter( $format );
			$parts     = $formatter->split( $formatter->convert( '<b>' . $words . '</b>' ), 120 );
			$this->assertTrue( count( $parts ) > 1 );
			foreach ( $parts as $part ) {
				$this->assertSame( $mark, substr( $part, 0, strlen( $mark ) ), $format );
				$this->assertSame( $mark, substr( $part, -strlen( $mark ) ), $format );
				$this->assertTrue( $formatter->length( $part ) <= 120, $format );
			}
			$parts = $formatter->split( $formatter->convert( '<pre>' . $words . '</pre>' ), 120 );
			foreach ( $parts as $part ) {
				$this->assertSame( 2, substr_count( $part, '```' ), $format );
			}
			$parts = $formatter->split( $formatter->convert( '<blockquote>' . $words . '</blockquote>' ), 120 );
			if ( $format !== 'bale_md' ) {
				foreach ( $parts as $part ) {
					$this->assertSame( '> ', substr( $part, 0, 2 ), $format );
				}
			}
			// Intraword underscores are no marks.
			$parts = $formatter->split( trim( str_repeat( 'john_doe ', 40 ) ), 120 );
			$this->assertNotContains( '_ ', implode( '|', $parts ) );
		}
		$slack = Registry::formatter( 'slack' );
		foreach ( $slack->split( trim( str_repeat( '<https://example.com/x|la bel> &amp; ', 30 ) ), 100 ) as $part ) {
			$this->assertSame( 0, preg_match( '/<[^>]*$|&[a-z]*$/', $part ) );
		}
	}
}
