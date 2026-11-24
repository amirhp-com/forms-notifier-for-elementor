<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class LocalizedTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	public function test_per_language_value(): void {
		Settings::set( 'digest.template', [ '_' => 'Hello', 'fa' => 'سلام' ] );
		$this->assertSame( 'سلام', Settings::text( 'digest.template', 'fa' ) );
		$this->assertSame( 'Hello', Settings::text( 'digest.template', 'de' ) );
	}

	public function test_fallbacks(): void {
		$this->assertSame( 'fallback', Settings::text( 'digest.template', 'fa', 'fallback' ) );
		Settings::set( 'digest.template', [ '_' => 'Hello', 'fa' => '   ' ] );
		$this->assertSame( 'Hello', Settings::text( 'digest.template', 'fa', 'fallback' ) );
		$this->assertSame( 'x', Settings::text( 'no.such.path', 'fa', 'x' ) );
	}

	public function test_plain_string_accepted(): void {
		$this->assertSame( 'text', Settings::localize( 'text', 'fa' ) );
		$this->assertSame( '', Settings::localize( null, 'fa' ) );
		$this->assertSame( '', Settings::localize( [ 'x' => [] ], 'fa' ) );
	}

	public function test_current_language_is_used(): void {
		$lang = I18n::current();
		$this->assertSame( 'mine', Settings::localize( [ '_' => 'default', $lang => 'mine' ] ) );
		$this->assertSame( 'german', I18n::with_language( 'de', static function () { return Settings::localize( [ '_' => 'default', 'de' => 'german' ] ); } ) );
	}

	public function test_translation_hook_when_value_missing(): void {
		if ( I18n::provider() === '' && ! defined( 'ICL_SITEPRESS_VERSION' ) && ! defined( 'POLYLANG_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '0.0-test' );
		}
		$this->filter( 'wpml_active_languages', static function () { return [ 'fa' => [ 'language_code' => 'fa', 'native_name' => 'فارسی', 'default_locale' => 'fa_IR' ], 'en' => [ 'language_code' => 'en', 'native_name' => 'English', 'default_locale' => 'en_US' ] ]; } );
		$this->filter( 'wpml_default_language', static function () { return 'fa'; } );
		$calls = [];
		$this->filter( 'wpml_translate_single_string', static function ( $value, $context, $name, $lang ) use ( &$calls ) {
			$calls[] = [ $context, $name, $lang ];
			return $lang === 'en' ? 'Translated' : $value;
		}, 10, 4 );
		if ( I18n::provider() !== 'wpml' ) {
			return; // Polylang is active on this site.
		}
		Settings::set( 'digest.template', [ '_' => 'متن', 'de' => 'Eigener Text' ] );
		$this->assertSame( 'Translated', Settings::text( 'digest.template', 'en' ) );
		$this->assertSame( [ [ 'Forms Notifier for Elementor', 'setting:digest.template', 'en' ] ], $calls );
		$this->assertSame( 'متن', Settings::text( 'digest.template', 'fa' ) );
		$this->assertSame( 'Eigener Text', Settings::text( 'digest.template', 'de' ) );
		$this->assertSame( 'متن', Settings::localize( [ '_' => 'متن' ], 'en' ) );
		$this->assertSame( 'Translated', Settings::localize( [ '_' => 'متن' ], 'en', 'form:1:a:x' ) );
	}
}
