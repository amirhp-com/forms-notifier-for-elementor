<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class FormatterTest extends TestCase {

	const PURE = true;

	const Z = "\u{200B}";

	private function convert( string $format, string $canonical ): string {
		return Registry::formatter( $format )->convert( $canonical );
	}

	public function test_conversion_tables(): void {
		$table = [
			'<b>x</b>'                             => [ 'text' => 'x', 'telegram_html' => '<b>x</b>', 'telegram_md' => '*x*', 'bale_md' => '*x*', 'whatsapp' => '*x*', 'slack' => '*x*', 'markdown' => '**x**' ],
			'<em>x</em>'                           => [ 'text' => 'x', 'telegram_html' => '<i>x</i>', 'telegram_md' => '_x_', 'bale_md' => '_x_', 'whatsapp' => '_x_', 'slack' => '_x_', 'markdown' => '*x*' ],
			'<u>x</u>'                             => [ 'text' => 'x', 'telegram_html' => '<u>x</u>', 'telegram_md' => '__x__', 'bale_md' => 'x', 'whatsapp' => 'x', 'slack' => 'x', 'markdown' => '__x__' ],
			'<del>x</del>'                         => [ 'text' => 'x', 'telegram_html' => '<s>x</s>', 'telegram_md' => '~x~', 'bale_md' => 'x', 'whatsapp' => '~x~', 'slack' => '~x~', 'markdown' => '~~x~~' ],
			'<code>x</code>'                       => [ 'text' => 'x', 'telegram_html' => '<code>x</code>', 'telegram_md' => '`x`', 'bale_md' => '```x```', 'whatsapp' => '`x`', 'slack' => '`x`', 'markdown' => '`x`' ],
			'<pre>x</pre>'                         => [ 'text' => 'x', 'telegram_html' => '<pre>x</pre>', 'telegram_md' => "```\nx\n```\n", 'bale_md' => '```x```', 'whatsapp' => '```x```', 'slack' => '```x```', 'markdown' => "```\nx\n```\n" ],
			'<a href="https://example.com/">x</a>' => [ 'text' => 'x (https://example.com/)', 'telegram_html' => '<a href="https://example.com/">x</a>', 'telegram_md' => '[x](https://example.com/)', 'bale_md' => '[x](https://example.com/)', 'whatsapp' => 'x: https://example.com/', 'slack' => '<https://example.com/|x>', 'markdown' => '[x](https://example.com/)', 'email_html' => '<a href="https://example.com/">x</a>' ],
			'<a href="https://example.com/">https://example.com/</a>' => [ 'text' => 'https://example.com/', 'whatsapp' => 'https://example.com/', 'slack' => '<https://example.com/>', 'bale_md' => 'https://example.com/', 'markdown' => 'https://example.com/' ],
			"<blockquote>x\ny</blockquote>"        => [ 'text' => "x\ny", 'telegram_html' => "<blockquote>x\ny</blockquote>", 'telegram_md' => ">x\n>y\n", 'bale_md' => "x\ny", 'whatsapp' => "> x\n> y\n", 'slack' => "> x\n> y\n", 'markdown' => "> x\n> y\n" ],
			'<spoiler>x</spoiler>'                 => [ 'text' => 'x', 'telegram_html' => '<tg-spoiler>x</tg-spoiler>', 'telegram_md' => '||x||', 'bale_md' => 'x', 'whatsapp' => 'x', 'slack' => 'x', 'markdown' => '||x||', 'email_html' => 'x' ],
			'a<br>b'                               => [ 'text' => "a\nb", 'telegram_html' => "a\nb", 'email_html' => "a<br>\nb" ],
			'&lt;b&gt; &amp; &quot;'               => [ 'text' => '<b> & "', 'telegram_html' => '&lt;b&gt; &amp; "', 'slack' => '&lt;b&gt; &amp; "', 'email_html' => '&lt;b&gt; &amp; &quot;' ],
			'<b> </b><i></i>'                      => [ 'text' => ' ', 'telegram_md' => ' ', 'markdown' => ' ' ],
			'<b>x<b>y</b></b>'                     => [ 'telegram_html' => '<b>xy</b>', 'telegram_md' => '*xy*' ],
			'<code><b>x</b></code>'                => [ 'telegram_html' => '<code>x</code>', 'telegram_md' => '`x`', 'slack' => '`x`' ],
			'<b>x'                                 => [ 'telegram_html' => '<b>x</b>', 'telegram_md' => '*x*' ],
			'<b> x </b>y'                          => [ 'telegram_md' => ' *x* y', 'whatsapp' => ' *x* y' ],
			"<b>x\ny</b>"                          => [ 'whatsapp' => "*x*\n*y*", 'slack' => "*x*\n*y*", 'bale_md' => "*x*\n*y*", 'markdown' => "**x**\n**y**" ],
		];
		foreach ( $table as $in => $row ) {
			foreach ( $row as $format => $expected ) {
				$this->assertSame( $expected, $this->convert( $format, (string) $in ), $format . ' ' . $in );
			}
		}
	}

	public function test_unsafe_links_lose_the_target(): void {
		foreach ( [ 'text', 'telegram_html', 'telegram_md', 'bale_md', 'whatsapp', 'slack', 'markdown', 'email_html' ] as $format ) {
			$this->assertSame( 'x', $this->convert( $format, '<a href="javascript:alert(1)">x</a>' ), $format );
			$this->assertSame( 'x', $this->convert( $format, '<a href="/relative">x</a>' ), $format );
		}
	}

	public function test_markdown_v2_escapes_all_special_characters(): void {
		$formatter = Registry::formatter( 'telegram_md' );
		$special   = str_split( '_*[]()~`>#+-=|{}.!' );
		$this->assertCount( 18, $special );
		foreach ( $special as $char ) {
			$this->assertSame( '\\' . $char, $formatter->escape( $char ), $char );
			$this->assertSame( 'a\\' . $char . 'b', $formatter->convert( 'a' . htmlspecialchars( $char ) . 'b' ), $char );
			$inside = $formatter->convert( '<code>a' . htmlspecialchars( $char ) . 'b</code>' );
			$this->assertSame( $char === '`' ? '`a\\`b`' : '`a' . $char . 'b`', $inside, 'code ' . $char );
			$pre = $formatter->convert( '<pre>a' . htmlspecialchars( $char ) . 'b</pre>' );
			$this->assertSame( "```\na" . ( $char === '`' ? '\\`' : $char ) . "b\n```\n", $pre, 'pre ' . $char );
			$link = $formatter->convert( '<a href="https://example.com/">a' . htmlspecialchars( $char ) . 'b</a>' );
			$this->assertSame( '[a\\' . $char . 'b](https://example.com/)', $link, 'label ' . $char );
		}
		$this->assertSame( '\\\\', $formatter->escape( '\\' ) );
		$this->assertSame( '`\\\\`', $formatter->convert( '<code>\\</code>' ) );
		$this->assertSame( '[x](https://example.com/a_(b\\).c)', $formatter->convert( '<a href="https://example.com/a_(b).c">x</a>' ) );
	}

	public function test_markdown_v2_keeps_underline_and_italic_apart(): void {
		$this->assertSame( '__' . self::Z . '_x_' . self::Z . '__', $this->convert( 'telegram_md', '<u><i>x</i></u>' ) );
		$this->assertSame( '_' . self::Z . '__x__' . self::Z . '_', $this->convert( 'telegram_md', '<i><u>x</u></i>' ) );
		$this->assertSame( '_x_' . self::Z . '__y__', $this->convert( 'telegram_md', '<i>x</i><u>y</u>' ) );
	}

	public function test_bale_spacing(): void {
		$this->assertSame( '*Name* : Sara', $this->convert( 'bale_md', '<b>Name</b>: Sara' ) );
		$this->assertSame( 'a *b* c', $this->convert( 'bale_md', 'a<b>b</b>c' ) );
		$this->assertSame( "a\n*b*\nc", $this->convert( 'bale_md', "a\n<b>b</b>\nc" ) );
		$this->assertSame( 'a *b* c', $this->convert( 'bale_md', 'a<u><b>b</b></u>c' ) );
		$this->assertSame( '*a b*', $this->convert( 'bale_md', '<b>a <i>b</i></b>' ) );
		$this->assertSame( '*a* _b_', $this->convert( 'bale_md', '<b>a</b><i>b</i>' ) );
		$this->assertSame( '*a*', $this->convert( 'bale_md', '<b> a </b>' ) === ' *a* ' ? '*a*' : 'wrong' );
	}

	public function test_bale_values(): void {
		$bale = Registry::formatter( 'bale_md' );
		$this->assertSame( '∗x∗', $bale->escape( '*x*' ) );
		$this->assertSame( '＿x＿', $bale->escape( '_x_' ) );
		$this->assertSame( 'john_doe@example.com', $bale->escape( 'john_doe@example.com' ) );
		$this->assertSame( '5 ∗ 3', $bale->escape( '5 * 3' ) === '5 ∗ 3' ? '5 ∗ 3' : $bale->escape( '5 * 3' ) );
		$this->assertSame( '[a] (https://evil.example)', $bale->escape( '[a](https://evil.example)' ) );
		$this->assertNotContains( '```', $bale->escape( '```x```' ) );
		$this->assertSame( 'https://example.com/a%5Fb', $bale->escape( 'https://example.com/a_b' ) );
	}

	/** What a visitor types must come out as the same characters, and nothing of it may be markup. */
	public function test_injection_in_every_format(): void {
		$values = [
			'{site_url}', '{if field:name}x{/if}', '<b>x</b>', '*x*', '_x_', '~x~', '**x**', '__x__', '||x||', '[a](https://evil.example/)', '`x`', '```x```', 'a & b', '&lt;b&gt;', '<a href="https://evil.example">x</a>', '<https://evil.example|x>', '<!channel>', '@everyone', '> quote', '# head',
			'سلام *دنیا* hello _world_ ۱۲۳', '🙂 *x* 👨‍👩‍👧', str_repeat( 'abc *x* ', 625 ), "line\n> q\n# h\n- l", "\x02" . '0' . "\x03", '\\', '\\*x*', 'javascript:alert(1)', '</b></code>', '<tg-spoiler>x</tg-spoiler>',
		];
		foreach ( $values as $value ) {
			$s                             = Fixtures::submission();
			$s->fields['message']['value'] = $value;
			$context                       = Context::for_submission( $s, 'en', 'x' );
			foreach ( [ 'text', 'telegram_html', 'telegram_md', 'bale_md', 'whatsapp', 'slack', 'markdown', 'email_html' ] as $format ) {
				$out = Engine::render( '[{field:message}]', $context, $format );
				$this->assertSame( self::expected( $format, $value ), $out, $format . ': ' . mb_substr( $value, 0, 40 ) );
			}
		}
	}

	private static function expected( string $format, string $value ): string {
		$formatter = Registry::formatter( $format );
		$clean     = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return $formatter->convert( '[' ) . $formatter->escape( $clean ) . $formatter->convert( ']' );
	}

	public function test_escaped_values_are_inert(): void {
		$html = Registry::formatter( 'telegram_html' )->escape( '<b>x</b> & <a href="y">' );
		$this->assertSame( '&lt;b&gt;x&lt;/b&gt; &amp; &lt;a href="y"&gt;', $html );

		$email = Registry::formatter( 'email_html' )->escape( "<script>\"'&" );
		$this->assertSame( '&lt;script&gt;&quot;&#039;&amp;', $email );

		// MarkdownV2: every special character is preceded by a backslash.
		$md = Registry::formatter( 'telegram_md' )->escape( '*a* _b_ [c](d) `e` ||f|| ~g~' );
		$this->assertSame( 0, preg_match( '/(?<!\\\\)[_*\[\]()~`|]/', $md ) );

		$markdown = Registry::formatter( 'markdown' )->escape( "*a* _b_ [c](d) `e` ||f|| ~g~ <@1>\n# h\n> q\n- l\n1. n" );
		$this->assertSame( 0, preg_match( '/(?<!\\\\)[_*\[\]~`|<]/', $markdown ) );
		$this->assertSame( 0, preg_match( '/^\s*([#>-]|\d+\.)/m', $markdown ) );
		$this->assertNotContains( '@everyone', Registry::formatter( 'markdown' )->escape( '@everyone @all @here @channel' ) );

		foreach ( [ 'whatsapp', 'slack' ] as $format ) {
			$out = Registry::formatter( $format )->escape( 'x *a* _b_ ~c~ `d`' );
			$this->assertSame( 0, preg_match( '/(^|\s)[*_~](?=\w)|`/u', $out ), $format );
			$this->assertContains( 'john_doe@example.com', Registry::formatter( $format )->escape( 'john_doe@example.com' ) );
		}
		$slack = Registry::formatter( 'slack' )->escape( '<!channel> <https://evil.example|x> &' );
		$this->assertSame( 0, preg_match( '/[<>]|&(?!(amp|lt|gt);)/', $slack ) );
		$bale = Registry::formatter( 'bale_md' )->escape( 'x *a* _b_ [c](d) ```e```' );
		$this->assertSame( 0, preg_match( '/(^|\s)[*_](?=\S)|\]\(|```/u', $bale ) );
	}

	public function test_values_inside_marks_links_and_code(): void {
		$s                          = Fixtures::submission();
		$s->fields['name']['value'] = 'a*b_c`d](e)<f>&';
		$context                    = Context::for_submission( $s, 'en' );
		$this->assertSame( '<b>a*b_c`d](e)&lt;f&gt;&amp;</b>', Engine::render( '<b>{field:name}</b>', $context, 'telegram_html' ) );
		$this->assertSame( '`a*b_c\\`d](e)<f>&`', Engine::render( '<code>{field:name}</code>', $context, 'telegram_md' ) );
		$this->assertSame( '[a\\*b\\_c\\`d\\]\\(e\\)<f\\>&](https://example.com/)', Engine::render( '<a href="https://example.com/">{field:name}</a>', $context, 'telegram_md' ) );
		$this->assertSame( '<a href="https://example.com/">a*b_c`d](e)&lt;f&gt;&amp;</a>', Engine::render( '<a href="https://example.com/">{field:name}</a>', $context, 'email_html' ) );
		$s->fields['name']['value'] = "two\nlines";
		$this->assertSame( ">two\n>lines", Engine::render( '<blockquote>{field:name}</blockquote>', $context, 'telegram_md' ) );
		$this->assertSame( '<https://example.com/|two lines>', Engine::render( '<a href="https://example.com/">{field:name}</a>', $context, 'slack' ) );
	}

	public function test_link_target_from_a_value(): void {
		$s                          = Fixtures::submission();
		$context                    = Context::for_submission( $s, 'en' );
		$s->fields['name']['value'] = 'javascript:alert(1)';
		$this->assertSame( 'x', Engine::render( '<a href="{field:name}">x</a>', $context, 'telegram_html' ) );
		$s->fields['name']['value'] = 'https://example.org/"><b>x';
		$out                        = Engine::render( '<a href="{field:name}">x</a>', $context, 'email_html' );
		$this->assertSame( 0, preg_match( '/<b>|"">/', $out ) );
		$this->assertSame( '<a href="https://example.com/contact/">x</a>', Engine::render( '<a href="{page_url}">x</a>', $context, 'telegram_html' ) );
		$this->assertSame( '<a href="tel:+989120000000">x</a>', Engine::render( '<a href="tel:{field:phone}">x</a>', $context, 'telegram_html' ) );
	}

	public function test_third_party_formatter_gets_escape_and_convert(): void {
		$this->filter( 'fnfe_formatters', static function ( array $map ) {
			$map['shout'] = new class() implements \AmirhpCom\FormsNotifier\Contracts\FormatterInterface {
				public function id(): string { return 'shout'; }
				public function escape( string $value ): string { return '(' . $value . ')'; }
				public function convert( string $canonical ): string { return strtoupper( $canonical ); }
				public function limit(): int { return 0; }
				public function split( string $converted, int $limit = 0 ): array { return [ $converted ]; }
				public function length( string $converted ): int { return strlen( $converted ); }
			};
			return $map;
		} );
		Registry::reset();
		$s                          = Fixtures::submission();
		$s->fields['name']['value'] = '{form_name} hi';
		$this->assertSame( '<B>X</B> ({form_name} hi)!', Engine::render( '<b>x</b> {field:name}!', Context::for_submission( $s, 'en' ), 'shout' ) );
	}

	public function tearDown(): void {
		Registry::reset();
	}

	public function test_lengths(): void {
		$html = Registry::formatter( 'telegram_html' );
		$this->assertSame( 4, $html->length( '<b>a&amp;b</b> ' ) );
		$this->assertSame( 2, $html->length( '🙂' ) );
		$this->assertSame( 4, $html->length( 'سلام' ) );
		$this->assertSame( Text::utf16_length( "سلام 🙂\n<x>" ), $html->length( $html->escape( "سلام 🙂\n<x>" ) ) );
		$this->assertSame( 2, $html->length( 'a ' ) );
		$md = Registry::formatter( 'telegram_md' );
		foreach ( [ 'a*b_c', 'x.y!', '🙂-سلام', '\\ ` |' ] as $text ) {
			$this->assertSame( Text::utf16_length( $text ), $md->length( $md->escape( $text ) ), $text );
			$this->assertSame( Text::utf16_length( $text ), $md->length( '*' . $md->escape( $text ) . '*' ), $text );
		}
		$this->assertSame( 4, $md->length( '[te\\.x](https://example.com/very/long)' ) );
	}
}
