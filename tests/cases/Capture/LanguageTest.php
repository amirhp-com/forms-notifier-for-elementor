<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Capture\LanguageDetector;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class LanguageTest extends TestCase {

	public function test_from_url(): void {
		$codes = [ 'fa', 'en', 'pt-br' ];
		$home  = untrailingslashit( home_url() );
		$cases = [
			$home . '/en/contact/'                => 'en',
			$home . '/EN'                         => 'en',
			$home . '/pt-br/x?y=1'                => 'pt-br',
			$home . '/contact/en/'                => '',
			$home . '/english/'                   => '',
			$home . '/contact/?lang=en'           => 'en',
			$home . '/contact/?lang[]=en'         => '',
			'https://fa.example.com/contact/'     => 'fa',
			'https://example.com/'                => '',
			''                                    => '',
			'not a url'                           => '',
			'http:///bad'                         => '',
		];
		foreach ( $cases as $url => $expected ) {
			$this->assertSame( $expected, LanguageDetector::from_url( (string) $url, $codes ), (string) $url );
		}
	}

	/** WPML is simulated with its hooks; skipped when another multilingual plugin is active. */
	public function test_order_with_wpml_hooks(): void {
		if ( I18n::provider() !== '' ) {
			return;
		}
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '0-test' );
		}
		$current = 'de';
		$posts   = [ 10 => 'en', 45 => 'fr' ];
		$this->filter( 'wpml_active_languages', static function () {
			return [ 'fa' => [ 'code' => 'fa' ], 'en' => [ 'code' => 'en' ], 'de' => [ 'code' => 'de' ], 'fr' => [ 'code' => 'fr' ] ];
		} );
		$this->filter( 'wpml_default_language', static function () { return 'fa'; } );
		$this->filter( 'wpml_current_language', static function () use ( &$current ) { return $current; } );
		$this->filter( 'wpml_post_language_details', static function ( $value, $id ) use ( &$posts ) {
			return isset( $posts[ $id ] ) ? [ 'language_code' => $posts[ $id ] ] : null;
		}, 10, 2 );

		$s = Fixtures::submission( [ 'lang' => '', 'meta' => [ 'queried_id' => 10, 'page_url' => home_url( '/en/x/' ) ] ] );
		$this->assertSame( 'de', LanguageDetector::detect( $s ) );
		$current = 'all';
		$this->assertSame( 'en', LanguageDetector::detect( $s ) );
		$current = 'xx';
		unset( $posts[10] );
		$this->assertSame( 'fr', LanguageDetector::detect( $s ) );
		$posts = [];
		$this->assertSame( 'en', LanguageDetector::detect( $s ) );
		$s->meta['page_url'] = home_url( '/x/' );
		$this->assertSame( 'fa', LanguageDetector::detect( $s ) );
		$this->filter( 'fnfe_submission_language', static function () { return 'de'; } );
		$this->assertSame( 'de', LanguageDetector::detect( $s ) );
	}
}
