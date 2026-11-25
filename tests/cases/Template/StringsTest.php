<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Template;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\I18n\Module;
use AmirhpCom\FormsNotifier\I18n\Strings;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

/** WPML is simulated through its documented hooks; Polylang is checked when it is active. */
final class StringsTest extends TestCase {

	private array $registered = [];
	private array $translations = [];
	private string $current = 'en';

	public function tearDown(): void {
		Strings::forget();
		Db::reset();
	}

	private function wpml(): void {
		defined( 'ICL_SITEPRESS_VERSION' ) || define( 'ICL_SITEPRESS_VERSION', '4.9' );
		$this->filter( 'wpml_active_languages', static function () {
			return [
				'en' => [ 'code' => 'en', 'native_name' => 'English', 'default_locale' => 'en_US' ],
				'fa' => [ 'code' => 'fa', 'native_name' => 'فارسی', 'default_locale' => 'fa_IR' ],
			];
		} );
		$this->filter( 'wpml_default_language', static function () { return 'en'; } );
		$this->filter( 'wpml_current_language', function () { return $this->current; } );
		$this->filter( 'wpml_switch_language', function ( $lang ) { $this->current = (string) $lang; return $lang; } );
		$this->filter( 'wpml_register_single_string', function ( $context, $name, $value ) {
			$this->registered[ $context ][ $name ] = $value;
			return $context;
		}, 10, 3 );
		$this->filter( 'wpml_translate_single_string', function ( $value, $context, $name, $lang ) {
			return $context === Strings::CONTEXT ? ( $this->translations[ $name ][ $lang ] ?? $value ) : $value;
		}, 10, 4 );
	}

	private function seed(): void {
		Settings::set( 'channels.email.template.admin', [ '_' => "Hello {form_name}\nBye" ] );
		Settings::set( 'channels.email.subject.admin', [ '_' => 'Subject', 'fa' => 'موضوع' ] );
		Settings::set( 'channels.slack.buttons', [ [ 'id' => 'b_1', 'text' => [ '_' => 'Open' ], 'type' => 'url', 'value' => 'https://example.com', 'row' => 0, 'style' => '' ] ] );
		$id = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		FormRepository::save_config( $id, [
			'channels'  => [ 'email' => [ 'template_mode' => 'custom', 'template' => [ '_' => 'Form text' ], 'subject' => [ '_' => 'Form subject' ], 'buttons' => [ [ 'id' => 'b_9', 'text' => [ '_' => 'Go' ], 'type' => 'url', 'value' => 'https://example.com' ] ] ] ],
			'submitter' => [ 'channels' => [ 'email' => [ 'enabled' => true, 'template' => [ '_' => 'Thanks' ] ] ] ],
			'fields'    => [ 'labels' => [ 'name' => [ '_' => 'Full name' ] ] ],
		] );
	}

	public function test_names(): void {
		$this->assertSame( 'setting:channels.email.template.admin', Strings::name_for( 'channels.email.template.admin' ) );
		$this->assertSame( 'form:45:a1b2c3d:fields.labels.name', Strings::name_for( 'fields.labels.name', '45:a1b2c3d' ) );
		$long = Strings::name_for( 'fields.labels.' . str_repeat( 'نام', 100 ), '45:a1b2c3d' );
		$this->assertTrue( strlen( $long ) <= 150 && mb_check_encoding( $long, 'UTF-8' ) );
		$this->assertSame( $long, Strings::name_for( 'fields.labels.' . str_repeat( 'نام', 100 ), '45:a1b2c3d' ) );
		$this->assertTrue( $long !== Strings::name_for( 'fields.labels.' . str_repeat( 'نام', 101 ), '45:a1b2c3d' ) );
	}

	public function test_nothing_happens_without_a_multilingual_plugin(): void {
		if ( I18n::provider() !== '' ) {
			return;
		}
		$this->seed();
		$this->filter( 'wpml_register_single_string', function ( $x ) { $this->registered[] = $x; return $x; } );
		Strings::sync_all();
		$this->assertSame( [], $this->registered );
		$this->assertSame( '', Strings::find_name( 'Subject' ) );
	}

	public function test_wpml_registers_every_text(): void {
		if ( I18n::provider() === 'polylang' ) {
			return;
		}
		$this->wpml();
		$this->seed();
		Strings::sync_all();
		$texts = $this->registered[ Strings::CONTEXT ];
		$expected = [
			'setting:channels.email.template.admin'               => "Hello {form_name}\nBye",
			'setting:channels.email.subject.admin'                => 'Subject',
			'setting:channels.slack.buttons.b_1.text'             => 'Open',
			'form:45:a1b2c3d:channels.email.template'             => 'Form text',
			'form:45:a1b2c3d:channels.email.subject'              => 'Form subject',
			'form:45:a1b2c3d:channels.email.buttons.b_9.text'     => 'Go',
			'form:45:a1b2c3d:submitter.channels.email.template'   => 'Thanks',
			'form:45:a1b2c3d:fields.labels.name'                  => 'Full name',
		];
		foreach ( $expected as $name => $value ) {
			$this->assertSame( $value, $texts[ $name ] ?? null, $name );
		}
		$this->assertNotContains( '', $texts );
	}

	public function test_saving_registers_again(): void {
		if ( I18n::provider() === 'polylang' ) {
			return;
		}
		$this->wpml();
		Settings::set( 'channels.email.subject.admin', [ '_' => 'New subject' ] );
		do_action( 'fnfe_settings_saved', [ 'channels.email.subject.admin' ] );
		Module::flush();
		$this->assertSame( 'New subject', $this->registered[ Strings::CONTEXT ]['setting:channels.email.subject.admin'] );
	}

	public function test_wpml_resolution_order(): void {
		if ( I18n::provider() === 'polylang' ) {
			return;
		}
		$this->wpml();
		$this->seed();
		Strings::forget();
		$this->translations = [
			'setting:channels.email.template.admin' => [ 'fa' => 'سلام {form_name}' ],
			'setting:channels.email.subject.admin'  => [ 'fa' => 'ترجمه' ],
			'form:45:a1b2c3d:fields.labels.name'    => [ 'fa' => 'نام کامل' ],
		];
		// Empty tab: translation. Own value: the tab wins. Nothing: the default.
		$this->assertSame( 'سلام {form_name}', Settings::text( 'channels.email.template.admin', 'fa' ) );
		$this->assertSame( 'موضوع', Settings::text( 'channels.email.subject.admin', 'fa' ) );
		$this->assertSame( 'Subject', Settings::text( 'channels.email.subject.admin', 'en' ) );

		$s       = Fixtures::submission();
		$context = Context::for_submission( $s, 'fa', 'email' );
		$this->assertSame( 'سلام Contact', Engine::render( Settings::get( 'channels.email.template.admin' ), $context ) );
		$this->assertSame( 'موضوع', Engine::render_inline( Settings::get( 'channels.email.subject.admin' ), $context ) );
		$context->form = FormRepository::config_by_key( '45:a1b2c3d' );
		$this->assertSame( 'نام کامل', Engine::render( '{label:name}', $context ) );
		$context->lang = 'en';
		$this->assertSame( "Hello Contact\nBye", Engine::render( Settings::get( 'channels.email.template.admin' ), $context ) );
	}

	public function test_the_text_of_the_form_is_preferred(): void {
		if ( I18n::provider() === 'polylang' ) {
			return;
		}
		$this->wpml();
		Settings::set( 'channels.email.subject.admin', [ '_' => 'Same' ] );
		$id = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		FormRepository::save_config( $id, [ 'channels' => [ 'email' => [ 'subject' => [ '_' => 'Same' ] ] ] ] );
		Strings::forget();
		$this->assertSame( 'setting:channels.email.subject.admin', Strings::find_name( 'Same' ) );
		$this->assertSame( 'form:45:a1b2c3d:channels.email.subject', Strings::find_name( 'Same', '45:a1b2c3d' ) );
		$this->assertSame( 'setting:channels.email.subject.admin', Strings::find_name( 'Same', '46:a1b2c3d' ) );
	}

	public function test_sync_route(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'POST', '/fnfe/v1/strings/sync' ) )->get_status() );
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$data = rest_do_request( new \WP_REST_Request( 'POST', '/fnfe/v1/strings/sync' ) )->get_data();
		$this->assertKeys( [ 'ok', 'provider', 'registered' ], $data );
		wp_set_current_user( 0 );
	}
}
