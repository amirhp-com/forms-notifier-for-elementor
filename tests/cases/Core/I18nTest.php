<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class I18nTest extends TestCase {

	public function test_languages_shape(): void {
		$languages = I18n::languages();
		$this->assertTrue( count( $languages ) >= 1 );
		$defaults = 0;
		foreach ( $languages as $code => $language ) {
			$this->assertKeys( [ 'code', 'name', 'locale', 'rtl', 'default' ], $language );
			$this->assertSame( (string) $code, $language['code'] );
			$defaults += $language['default'] ? 1 : 0;
		}
		$this->assertSame( 1, $defaults );
		$this->assertArrayHasKey( I18n::default_lang(), $languages );
	}

	public function test_single_language_without_plugin(): void {
		if ( I18n::provider() !== '' ) {
			return;
		}
		update_option( 'WPLANG', 'fa_IR' );
		$this->assertSame( [ 'fa' ], array_keys( I18n::languages() ) );
		$this->assertTrue( I18n::languages()['fa']['rtl'] );
		$this->assertSame( 'fa_IR', I18n::locale_of( 'fa' ) );
		$this->assertFalse( I18n::is_multilingual() );
		update_option( 'WPLANG', '' );
		$this->assertSame( 'en', I18n::default_lang() );
		$this->assertSame( 'en', I18n::current() );
		$this->assertSame( '', I18n::post_lang( 1 ) );
		$this->assertSame( 'text', I18n::translate_string( 'text', 'setting:x', 'fa' ) );
	}

	public function test_locales_and_direction(): void {
		$this->assertSame( 'en_US', I18n::locale_of( 'en' ) );
		$this->assertSame( 'pt_BR', I18n::locale_of( 'pt-br' ) );
		$this->assertSame( 'de_DE', I18n::locale_of( 'de' ) );
		$this->assertTrue( I18n::is_rtl( 'fa' ) );
		$this->assertTrue( I18n::is_rtl( 'ar' ) );
		$this->assertFalse( I18n::is_rtl( 'en' ) );
		$this->assertFalse( I18n::is_rtl( 'pt-br' ) );
	}

	public function test_with_language(): void {
		$before = get_locale();
		$other  = strncmp( $before, 'fa', 2 ) === 0 ? 'en' : 'fa';
		$result = I18n::with_language( $other, function () use ( $other ) {
			$this->assertSame( $other, I18n::current() );
			$this->assertSame( I18n::locale_of( $other ), get_locale() );
			$inner = I18n::with_language( 'de', static function () { return I18n::current(); } );
			$this->assertSame( 'de', $inner );
			$this->assertSame( $other, I18n::current() );
			return 'result';
		} );
		$this->assertSame( 'result', $result );
		$this->assertSame( $before, get_locale() );
		$this->assertSame( 'plain', I18n::with_language( '', static function () { return 'plain'; } ) );
	}

	public function test_with_language_restores_on_exception(): void {
		$before = get_locale();
		$lang   = I18n::current();
		$other  = strncmp( $before, 'fa', 2 ) === 0 ? 'en' : 'fa';
		$this->assertThrows( \RuntimeException::class, static function () use ( $other ) {
			I18n::with_language( $other, static function () { throw new \RuntimeException( 'x' ); } );
		} );
		$this->assertSame( $before, get_locale() );
		$this->assertSame( $lang, I18n::current() );
	}

	public function test_bundled_translation_is_loaded_below_6_8(): void {
		global $wp_version;
		$this->assertTrue( \AmirhpCom\FormsNotifier\Plugin::needs_textdomain( '6.7.4' ) );
		$this->assertTrue( \AmirhpCom\FormsNotifier\Plugin::needs_textdomain( '6.5' ) );
		$this->assertFalse( \AmirhpCom\FormsNotifier\Plugin::needs_textdomain( '6.8' ) );
		$this->assertFalse( \AmirhpCom\FormsNotifier\Plugin::needs_textdomain( '7.1.3' ) );

		// Since 6.7 the function only registers the folder for just-in-time loading: record that call.
		global $wp_textdomain_registry;
		$registry = $wp_textdomain_registry;
		$spy      = new class() extends \WP_Textdomain_Registry {
			public $paths = [];
			public function set_custom_path( $domain, $path ) {
				$this->paths[ $domain ] = $path;
				parent::set_custom_path( $domain, $path );
			}
		};
		$version                = $wp_version;
		$wp_version             = '6.7.4';
		$wp_textdomain_registry = $spy;
		try {
			\AmirhpCom\FormsNotifier\Plugin::load_textdomain();
		} finally {
			$wp_version             = $version;
			$wp_textdomain_registry = $registry;
		}
		$this->assertSame( WP_PLUGIN_DIR . '/' . dirname( FNFE_BASENAME ) . '/languages', $spy->paths['forms-notifier-for-elementor'] ?? null, 'registered on 6.7' );
	}
}
