<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Template\Markup;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class MarkupTest extends TestCase {

	const PURE = true;

	public function test_keeps_allowed_markup_and_constructs(): void {
		$keep = [
			'<b>x</b> <strong>x</strong> <i>x</i> <em>x</em> <u>x</u> <s>x</s> <del>x</del> <code>x</code> <pre>x</pre> <blockquote>x</blockquote> <spoiler>x</spoiler><br>',
			'<a href="https://example.com/?a=1&b=2">x</a>',
			'<a href="{page_url}">x</a>',
			'<a href="https://example.com/{field:slug}">x</a>',
			'<a href="tel:{phone}">x</a>',
			'<a href="tg://resolve?domain=x">x</a>',
			'{if files_count > 0}x{/if}{if a < 3}y{/if}',
			'{if field:a == "<b>"}x{/if}',
			'{field:a|default:"<none>"}',
			'a < b && c > d',
			'&lt;b&gt; stays text',
			"line 1\nline 2\n\nline 4",
			"pattern:123\n0:{field:name}\n---\ntext",
			'متن فارسی با نیم‌فاصله',
		];
		foreach ( $keep as $template ) {
			$this->assertSame( $template, Markup::sanitize( $template ) );
		}
	}

	public function test_strips_everything_else(): void {
		$cases = [
			'<script>alert(1)</script>x'                      => 'x',
			'<img src=x onerror=alert(1)>x'                   => 'x',
			'<b onclick="x()">y</b>'                          => '<b>y</b>',
			'<a href="javascript:alert(1)">x</a>'             => 'x',
			'<a href="JaVaScRiPt:alert(1)">x</a>'             => 'x',
			'<a href="javascript:{field:a}">x</a>'            => 'x',
			'<a href="data:text/html,x">x</a>'                => 'x',
			'<a>x</a>'                                        => 'x',
			'<a href="/contact" title="y">x</a>'              => 'x',
			'<a class="q" href=\'https://example.com/\' target="_blank">x</a>' => '<a href="https://example.com/">x</a>',
			'<!-- c -->x<?php echo 1; ?>'                     => 'x',
			'<div><p>x</p></div>'                             => 'x',
			'<BR/>x<B>y</B>'                                  => '<br>x<b>y</b>',
			'<<x>script>alert(1)</<x>script>'                 => '',
			"a\r\nb\rc\x00\x02d\x03"                          => "a\nb\ncd",
			'<svg/onload=alert(1)>x'                          => 'x',
			'<style>b{}</style>x'                             => 'x',
		];
		foreach ( $cases as $in => $out ) {
			$this->assertSame( $out, Markup::sanitize( (string) $in ), (string) $in );
		}
	}

	public function test_idempotent(): void {
		$samples = [
			'<<b>b>x</b>', '<<<x>y>z>', '<a href="{x"}">y</a>', '<a href="https://e.com/{if x > 1}a{/if}">y</a>', '<a href=\'https://e.com/"q"\'>y</a>', '<scr<script>ipt>x</scr</script>ipt>', '</a></a><a href="https://e.com">x', '<b <i>x</i>', '<a href="{page_url|default:\'x\'}" onclick="{y}">z</a>', "\x02" . '1' . "\x03<b>",
			'<a href="https://e.com/&quot;&amp;amp;">x</a>', '{if a == "</b>"}<b>{/if}', '<a href="https://e.com?x=<b>">k</a>', '<!-- <b> --><!--<!-- x -->y-->',
		];
		foreach ( $samples as $sample ) {
			$once = Markup::sanitize( $sample );
			$this->assertSame( $once, Markup::sanitize( $once ), $sample );
		}
	}

	public function test_escape_and_plain(): void {
		$this->assertSame( '&lt;b&gt;&amp;amp;', Markup::escape( '<b>&amp;' ) );
		$this->assertSame( "x & y <z>\nw", Markup::to_plain( '<b>x &amp; <i>y</i></b> &lt;z&gt;<br><a href="https://e.com">w</a>' ) );
		$this->assertSame( 'a < b', Markup::to_plain( 'a < b' ) );
	}

	public function test_url(): void {
		foreach ( [ 'https://example.com/x?y=1', 'http://example.com', 'tg://resolve?domain=x', 'mailto:a@example.com', 'tel:+989120000000' ] as $url ) {
			$this->assertSame( $url, Markup::url( $url ) );
		}
		foreach ( [ '', 'example.com', '/x', 'javascript:alert(1)', ' javascript:alert(1)', "java\nscript:alert(1)", 'data:x', 'https://', 'ftp://example.com', 'vbscript:x' ] as $url ) {
			$this->assertSame( '', Markup::url( $url ) );
		}
	}

	public function test_parse_repairs_structure(): void {
		$nodes = Markup::parse( '<b>a<i>b</b>c</i><x>d</x></u>' );
		$this->assertSame( 'b', $nodes[0]['tag'] );
		$this->assertSame( 'i', $nodes[0]['children'][1]['tag'] );
		$this->assertSame( 'abcd', Markup::plain_nodes( $nodes, [] ) );
	}
}
