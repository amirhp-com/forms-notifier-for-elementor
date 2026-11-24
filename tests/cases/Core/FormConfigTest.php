<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class FormConfigTest extends TestCase {

	public static function setUpClass(): void {
		FakeChannel::install();
	}

	public function test_defaults_filled(): void {
		$this->assertSame( FormConfig::defaults(), FormConfig::normalize( [] ) );
		$config = new FormConfig( 3, '1:a', [] );
		$this->assertSame( 'inherit', $config->enabled() );
		$this->assertSame( FormConfig::defaults(), $config->to_array() );
	}

	public function test_route_is_always_complete(): void {
		$config = new FormConfig( 1, '1:a', [ 'channels' => [ 'fake' => [ 'enabled' => 'on' ] ] ] );
		$keys   = [ 'enabled', 'receivers_mode', 'receivers', 'template_mode', 'template', 'subject', 'buttons_mode', 'buttons', 'options' ];
		$this->assertKeys( $keys, $config->route( 'fake' ) );
		$this->assertSame( 'on', $config->route( 'fake' )['enabled'] );
		$this->assertKeys( $keys, $config->route( 'sms' ) );
		$this->assertSame( 'inherit', $config->route( 'sms' )['enabled'] );
	}

	public function test_junk_dropped(): void {
		$clean = FormConfig::normalize(
			[
				'evil'     => 1,
				'enabled'  => 'maybe',
				'link'     => '-5',
				'channels' => [ 'BAD ID' => [], 'fake' => [ 'enabled' => [ 'x' ], 'nope' => 1, 'options' => [ 'silent' => 'yes', 'other' => 1 ] ] ],
				'rules'    => [ 'x', [ 'effect' => 'explode', 'conditions' => [ [ 'field' => 'plan', 'op' => 'zzz' ], 'y' ], 'channels' => [ 'fake', 'B A D' ] ] ],
				'fields'   => [ 'phone' => [ 'x' ], 'sensitive' => [ 'a', 'a', '' ], 'labels' => [ 'name' => [ '_' => 'Name', 'fa' => 'نام', '..' => 'x' ] ] ],
			]
		);
		$this->assertKeys( array_keys( FormConfig::defaults() ), $clean );
		$this->assertSame( 'inherit', $clean['enabled'] );
		$this->assertSame( 5, $clean['link'] );
		$this->assertSame( [ 'fake' ], array_keys( $clean['channels'] ) );
		$this->assertSame( [ 'silent' => true ], $clean['channels']['fake']['options'] );
		$this->assertCount( 1, $clean['rules'] );
		$this->assertSame( 'is', $clean['rules'][0]['conditions'][0]['op'] );
		$this->assertSame( [ 'fake' ], $clean['rules'][0]['channels'] );
		$this->assertMatches( '/^q_[a-z0-9]{8}$/', $clean['rules'][0]['id'] );
		$this->assertSame( '', $clean['fields']['phone'] );
		$this->assertSame( [ 'a' ], $clean['fields']['sensitive'] );
		$this->assertSame( [ '_' => 'Name', 'fa' => 'نام' ], $clean['fields']['labels']['name'] );
	}

	public function test_normalize_is_idempotent(): void {
		$raw   = [
			'enabled'     => 'on',
			'channels'    => [
				'fake' => [
					'receivers'     => [ [ 'id' => 'r_aaaaaaaa', 'address' => 'Desk', 'langs' => [ 'fa', 'xx!' ] ] ],
					'template_mode' => 'custom',
					'template'      => [ '_' => '<b>Hi</b> {field:name}', 'fa' => 'سلام {name}' ],
					'buttons'       => [ [ 'id' => 'b_bbbbbbbb', 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/?q={field:name}&x=%D8%B3', 'row' => 1 ] ],
				],
			],
			'attachments' => [ 'mode' => 'send', 'types' => 'custom', 'extensions' => [ '.PDF', 'p d f', '' ], 'max_mb' => 99999 ],
		];
		$once  = FormConfig::normalize( $raw );
		$this->assertSame( $once, FormConfig::normalize( $once ) );
		$route = $once['channels']['fake'];
		$this->assertSame( [ 'fa' ], $route['receivers'][0]['langs'] );
		$this->assertSame( 'https://example.com/?q={field:name}&x=%D8%B3', $route['buttons'][0]['value'] );
		$this->assertSame( '<b>Hi</b> {field:name}', $route['template']['_'] );
		$this->assertSame( [ 'pdf' ], $once['attachments']['extensions'] );
		$this->assertSame( 2048, $once['attachments']['max_mb'] );
	}

	public function test_scripts_are_removed_from_templates(): void {
		$clean = FormConfig::normalize( [ 'channels' => [ 'fake' => [ 'template' => '<script>alert(1)</script><b onclick="x">ok</b><img src=x>' ] ] ] );
		$text  = $clean['channels']['fake']['template']['_'];
		$this->assertNotContains( '<script', $text );
		$this->assertNotContains( 'onclick', $text );
		$this->assertNotContains( '<img', $text );
		$this->assertContains( '<b>ok</b>', $text );
	}

	public function test_button_urls(): void {
		$clean = FormConfig::normalize( [ 'channels' => [ 'fake' => [ 'buttons' => [ [ 'text' => 'x', 'type' => 'url', 'value' => 'javascript:alert(1)' ], [ 'text' => 'y', 'type' => 'url', 'value' => '{page_url}' ] ] ] ] ] );
		$this->assertSame( '', $clean['channels']['fake']['buttons'][0]['value'] );
		$this->assertSame( '{page_url}', $clean['channels']['fake']['buttons'][1]['value'] );
	}

	public function test_get_path(): void {
		$config = new FormConfig( 1, '1:a', [ 'fields' => [ 'phone' => 'tel_1' ] ] );
		$this->assertSame( 'tel_1', $config->get( 'fields.phone' ) );
		$this->assertSame( 'x', $config->get( 'fields.nope.deep', 'x' ) );
	}
}
