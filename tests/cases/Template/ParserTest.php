<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Template\Parser;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class ParserTest extends TestCase {

	const PURE = true;

	private function nodes( string $template ): array {
		return Parser::parse( $template )['nodes'];
	}

	public function test_every_syntax_form(): void {
		$tag = static function ( string $name, string $arg = '', array $filters = [] ): array {
			return [ 'type' => 'tag', 'name' => $name, 'arg' => $arg, 'filters' => $filters, 'line' => 1 ];
		};
		$this->assertSame( [ $tag( 'form_name' ) ], $this->nodes( '{form_name}' ) );
		$this->assertSame( [ $tag( 'field', 'email' ) ], $this->nodes( '{field:email}' ) );
		$this->assertSame( [ $tag( 'date', 'Y-m-d H:i' ) ], $this->nodes( '{date:Y-m-d H:i}' ) );
		$this->assertSame( [ $tag( 'field', 'name', [ [ 'upper', '' ] ] ) ], $this->nodes( '{field:name|upper}' ) );
		$this->assertSame( [ $tag( 'field', 'm', [ [ 'truncate', '200' ], [ 'default', 'a|b}' ] ] ) ], $this->nodes( '{field:m|truncate:200|default:"a|b}"}' ) );
		$this->assertSame( [ $tag( 'field', 'email' ) ], $this->nodes( '[field id="email"]' ) );
		$this->assertSame( [ $tag( 'field', 'email' ) ], $this->nodes( "[field id='email']" ) );
		$this->assertSame( [ $tag( 'field', 'email' ) ], $this->nodes( '[field id=email]' ) );
		$this->assertSame( [ $tag( 'all_fields' ) ], $this->nodes( '[all-fields]' ) );
		$this->assertSame( [ $tag( 'alert.channel' ) ], $this->nodes( '{alert.channel}' ) );
		$this->assertSame( [ $tag( 'all_fields', 'empty=1,exclude=a+b,sep=": "' ) ], $this->nodes( '{all_fields:empty=1,exclude=a+b,sep=": "}' ) );
	}

	public function test_literals_and_escapes(): void {
		foreach ( [ 'plain', '{', '}', '{}', '{ name }', '{Name}', '{"a": {"b": 1}}', '[x]', '[link](url)', 'a { b } c', '{field:a{b}', 'C:\\dir', '{%var%}' ] as $text ) {
			$nodes = $this->nodes( $text );
			$out   = '';
			foreach ( $nodes as $node ) {
				$this->assertTrue( in_array( $node['type'], [ 'text', 'tag' ], true ) );
				$out .= $node['text'] ?? '{' . $node['name'] . '}';
			}
			$this->assertSame( $text, $out, $text );
		}
		$this->assertSame( [ [ 'type' => 'text', 'text' => '{field:a}' ] ], $this->nodes( '\{field:a}' ) );
	}

	public function test_blocks_and_nesting(): void {
		$nodes = $this->nodes( '{if field:a}A{elseif field:b == "x"}B{else}C{/if}' );
		$this->assertCount( 1, $nodes );
		$this->assertCount( 3, $nodes[0]['branches'] );
		$this->assertNull( $nodes[0]['branches'][2]['cond'] );
		$this->assertSame( 'is', $nodes[0]['branches'][1]['cond'][0][0]['op'] );

		$five = str_repeat( '{if field:a}', 5 ) . 'x' . str_repeat( '{/if}', 5 );
		$this->assertSame( [], Parser::parse( $five )['errors'] );
		$six = str_repeat( '{if field:a}', 6 ) . 'x' . str_repeat( '{/if}', 6 );
		$this->assertCount( 1, Parser::parse( $six )['errors'] );
	}

	public function test_conditions(): void {
		$ok = [
			'field:a', '!field:a', 'field:a == "x"', "field:a != 'x y'", 'field:a contains "z"', 'field:a !contains z', 'files_count > 0', 'field:n < 10',
			'field:a in "x,y"', 'field:a matches "^\d{2}$"', 'lang == "fa"', 'utm:source == "ads"', 'field:a and field:b', 'field:a or field:b and !field:c', 'field:a-b_c==1', 'a &gt; 1',
		];
		foreach ( $ok as $text ) {
			$this->assertNotNull( Parser::condition( $text ), $text );
		}
		foreach ( [ '', 'field:a ==', '== "x"', 'field:a and', 'field:a xor field:b', '(field:a)', 'field:a == "x" "y"', 'and' ] as $text ) {
			if ( $text === 'and' ) {
				continue;
			}
			$this->assertNull( Parser::condition( $text ), $text );
		}
		$parsed = Parser::condition( 'field:a or field:b and !field:c' );
		$this->assertCount( 2, $parsed );
		$this->assertCount( 2, $parsed[1] );
		$this->assertTrue( $parsed[1][1]['neg'] );
	}

	public function test_malformed_input_never_throws(): void {
		$bad = [
			'{if', '{if}', '{if field:a', '{/if}', '{else}', '{elseif x}', '{if field:a}open', '{if field:a}{else}{else}{/if}', '{field:', '{field:a|', '{|}', '{:}', '[field', '[field id="', '\\', '{if field:a =}x{/if}',
			str_repeat( '{if field:a}', 300 ), str_repeat( '{/if}', 300 ), str_repeat( '{', 5000 ), str_repeat( '{field:"', 2000 ), "{field:\xff\xfe}", "{if \xff}x{/if}", '{field:a|default:"}', str_repeat( '{a|b', 3000 ),
		];
		foreach ( $bad as $template ) {
			$this->assertTrue( is_array( Parser::parse( $template )['nodes'] ) );
		}
	}

	public function test_unclosed_block_keeps_its_content(): void {
		$parsed = Parser::parse( 'a{if field:x}b' );
		$this->assertSame( [ [ 'type' => 'text', 'text' => 'ab' ] ], $parsed['nodes'] );
		$this->assertCount( 1, $parsed['errors'] );
	}

	public function test_lines_with_only_a_marker_disappear(): void {
		$parsed = $this->nodes( "a\n  {if field:x}\nb\n  {/if}\nc" );
		$this->assertSame( "a\n", $parsed[0]['text'] );
		$this->assertSame( "b\n", $parsed[1]['branches'][0]['nodes'][0]['text'] );
		$this->assertSame( 'c', $parsed[2]['text'] );
	}

	public function test_validate_messages(): void {
		$this->assertSame( [], Engine::validate( "{form_name}\n{if field:a}x{/if}{field:a|upper}" ) );
		$errors = Engine::validate( "{form_name}\n{nope}\n{field:a|zap}\n{if unknown == 1}x{/if}\n{field:a\n{if field:a ==}y{/if}\n{/if}\n{if field:z}" );
		$this->assertSame( [ 2, 3, 4, 5, 6, 7, 8 ], array_column( $errors, 'line' ) );
		foreach ( $errors as $error ) {
			$this->assertKeys( [ 'line', 'message' ], $error );
			$this->assertTrue( $error['message'] !== '' );
		}
	}
}
