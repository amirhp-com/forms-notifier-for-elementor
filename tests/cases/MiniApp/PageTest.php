<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\MiniApp;

use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\MiniApp\MenuButton;
use AmirhpCom\FormsNotifier\MiniApp\Module;
use AmirhpCom\FormsNotifier\MiniApp\Page;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PageTest extends TestCase {

	private static $html = '';

	public function setUp(): void {
		Db::reset();
		Settings::set( 'miniapp.enabled', true );
		Settings::set_secret( 'channels.telegram.token', Fixtures::TOKEN );
	}

	private function html(): string {
		if ( self::$html === '' ) {
			$this->filter(
				'wp_print_scripts',
				static function () {
					echo '<script>foreign()</script>';
				}
			);
			$this->filter(
				'wp_head',
				static function () {
					echo '<!-- foreign head -->';
				}
			);
			ob_start();
			Page::render( 'telegram' );
			self::$html = (string) ob_get_clean();
		}
		return self::$html;
	}

	public function test_document(): void {
		$html = $this->html();
		$this->assertMatches( '/^\s*<!DOCTYPE html>/', $html );
		$this->assertMatches( '/<html [^>]*lang="[a-zA-Z-]+"/', $html );
		$this->assertContains( 'width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no', $html );
		$this->assertMatches( '/<body class="fnfe-root fnfe-miniapp" data-theme="auto" data-platform="telegram"/', $html );
		$this->assertContains( '<div id="fnfe-miniapp"></div>', $html );
		$this->assertContains( '<noscript>', $html );
		$this->assertMatches( '/<\/html>\s*$/', $html );
	}

	public function test_only_own_assets(): void {
		$html = $this->html();
		$this->assertNotContains( 'foreign', $html );
		preg_match_all( '/<script[^>]+src=[\'"]([^\'"]+)/', $html, $scripts );
		$this->assertTrue( count( $scripts[1] ) >= 5 );
		foreach ( $scripts[1] as $src ) {
			$own = strpos( $src, FNFE_URL . 'assets/js/' ) === 0 || strpos( $src, includes_url( 'js/dist/' ) ) === 0 || strpos( $src, '/wp-includes/js/dist/' ) === 0;
			$this->assertTrue( $own, $src );
		}
		preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+)/', $html, $links );
		foreach ( $links[1] as $href ) {
			$this->assertTrue( strpos( $href, FNFE_URL . 'assets/css/' ) === 0, $href );
		}
		foreach ( [ 'core/fnfe.js', 'miniapp/bridge.js', 'miniapp/app.js', 'tokens.css', 'miniapp.css' ] as $file ) {
			$this->assertContains( $file, $html );
		}
		$this->assertNotContains( 'telegram-web-app.js', $html );
		$this->assertNotContains( 'miniapp.js', $html );
	}

	public function test_no_handwritten_tags(): void {
		$html = $this->html();
		$this->assertSame( 0, preg_match( '/<[a-z][^>]*\son[a-z]+\s*=/i', preg_replace( '#<script.*?</script>#s', '', $html ) ), 'inline event handlers' );
		$this->assertSame( 0, preg_match( '/<style/i', $html ) );
		$this->assertSame( 0, preg_match( '/\sstyle\s*=/i', $html ) );
		$this->assertSame( 0, preg_match( '/javascript:/i', $html ) );
		// WordPress gives every script it prints an id that ends in -js, -js-before, -js-after or -js-translations.
		preg_match_all( '/<script\b[^>]*>/', $html, $tags );
		foreach ( $tags[0] as $tag ) {
			$this->assertMatches( '/id=[\'"][a-z0-9-]+-js(-before|-after|-translations|-extra)?[\'"]/', $tag );
		}
		$source = file_get_contents( FNFE_DIR . 'includes/MiniApp/Page.php' );
		$this->assertSame( 0, preg_match( '/<(script|style)\b/i', $source ) );
	}

	public function test_config_is_public(): void {
		$html = $this->html();
		$this->assertContains( 'window.fnfeBoot = ', $html );
		$this->assertNotContains( Fixtures::TOKEN, $html );
		$this->assertNotContains( 'nonce', strtolower( wp_json_encode( Page::config( 'telegram' ) ) ) );
		$config = Page::config( 'bale' );
		$this->assertKeys( [ 'app', 'platform', 'rest', 'site', 'bot', 'version', 'page_size', 'strings' ], $config );
		$this->assertSame( [ 'root' ], array_keys( $config['rest'] ) );
		$this->assertKeys( [ 'name', 'lang', 'locale', 'rtl', 'calendar', 'timezone' ], $config['site'] );
		$this->assertSame( 'bale', $config['platform'] );
	}

	public function test_config_filter_and_bot_url(): void {
		$this->filter(
			'fnfe_miniapp_config',
			static function ( $config, $platform ) {
				$config['x'] = $platform;
				return $config;
			},
			10,
			2
		);
		$this->assertSame( 'telegram', Page::config( 'telegram' )['x'] );
		$this->assertSame( '', Page::bot_url( 'telegram' ) );
		State::set( 'bot.telegram.me', [ 'username' => 'sample_bot' ] );
		State::set( 'bot.bale.me', [ 'username' => 'bad/../name' ] );
		$this->assertSame( 'https://t.me/sample_bot', Page::bot_url( 'telegram' ) );
		$this->assertSame( '', Page::bot_url( 'bale' ) );
	}

	public function test_headers(): void {
		$headers = Page::headers();
		$this->assertSame( 'no-store, private', $headers['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertNotContains( 'X-Frame-Options', array_keys( $headers ) );
		$this->assertNotContains( 'Content-Security-Policy', array_keys( $headers ) );
	}

	public function test_availability(): void {
		$this->assertTrue( Module::is_available( 'telegram' ) );
		$this->assertTrue( Module::is_available( 'bale' ) );
		$this->assertFalse( Module::is_available( 'x' ) );
		$this->assertFalse( Module::is_available( [ 'telegram' ] ) );
		Settings::set( 'miniapp.enabled', false );
		$this->assertFalse( Module::is_available( 'telegram' ) );
	}

	public function test_disabled_is_a_404(): void {
		global $wp_query;
		$before = $wp_query;
		foreach ( [ [ 'telegram', false ], [ 'icq', true ] ] as $case ) {
			$wp_query = new \WP_Query();
			$wp_query->set( Module::QUERY_VAR, $case[0] );
			Settings::set( 'miniapp.enabled', $case[1] );
			Module::serve();
			$this->assertTrue( $wp_query->is_404(), $case[0] );
		}
		$wp_query = new \WP_Query();
		Module::serve();
		$this->assertFalse( $wp_query->is_404(), 'other requests are left alone' );
		$wp_query = $before;
	}

	public function test_rewrite_rule_and_flush_flag(): void {
		global $wp_rewrite;
		$rules = $wp_rewrite->extra_rules_top;
		Db::options( [ 'fnfe_state' => null ] );

		$wp_rewrite->extra_rules_top = [];
		Module::rewrite();
		$this->assertSame( 'index.php?fnfe_app=$matches[1]', $wp_rewrite->extra_rules_top['^fnfe-app/(telegram|bale)/?$'] );
		$this->assertContains( 'fnfe_app', Module::query_vars( [] ) );

		Settings::set_many( [ 'miniapp.page_size' => 10 ] );
		$this->assertNull( State::get( 'flush_rewrite' ) );
		Settings::set_many( [ 'miniapp.enabled' => false ] );
		$this->assertTrue( State::get( 'flush_rewrite' ) );

		$flushed = 0;
		$this->filter(
			'generate_rewrite_rules',
			static function () use ( &$flushed ) {
				++$flushed;
			}
		);
		$wp_rewrite->extra_rules_top = [];
		Module::rewrite();
		Module::rewrite();
		$this->assertSame( 1, $flushed, 'flushed once' );
		$this->assertFalse( isset( $wp_rewrite->extra_rules_top['^fnfe-app/(telegram|bale)/?$'] ), 'no rule while the app is off' );
		$this->assertNull( State::get( 'flush_rewrite' ) );
		$wp_rewrite->extra_rules_top = $rules;
	}

	public function test_settings_sections(): void {
		Schema::flush();
		$sections = Schema::sections_for( 'miniapp' );
		$this->assertSame( [ 'miniapp_main', 'miniapp_session', 'miniapp_setup' ], array_column( $sections, 'id' ) );
		foreach ( [ 'enabled', 'max_age', 'session_ttl', 'page_size', 'show_ip', 'allow_actions' ] as $key ) {
			$this->assertNotNull( Schema::field( 'miniapp.' . $key ), $key );
		}
		$this->assertContains( 'fnfe', wp_json_encode( $sections ) );
		$guide = $sections[2]['fields'][0];
		$this->assertSame( 'guide', $guide['type'] );
		$this->assertSame( Urls::miniapp( 'telegram' ), $guide['steps'][0]['code'] );
		$topics = Module::help( [] );
		$this->assertKeys( [ 'title', 'group', 'body', 'steps' ], $topics['miniapp'] );
	}

	public function test_menu_button(): void {
		HttpMock::expect( 'POST', '#/setChatMenuButton$#', [ 'ok' => true, 'result' => true ] );
		$this->filter(
			'home_url',
			static function ( $url ) {
				return preg_replace( '#^https?://[^/]+#', 'https://example.com', $url );
			}
		);
		Settings::set_many( [ 'channels.telegram.enabled' => true, 'channels.telegram.menu_button' => true ] );
		MenuButton::sync();
		$sent = HttpMock::requests( '#setChatMenuButton#' );
		$this->assertCount( 1, $sent );
		$button = $this->menu_button( $sent[0] );
		$this->assertSame( 'web_app', $button['type'] );
		$this->assertSame( Urls::miniapp( 'telegram' ), $button['web_app']['url'] );
		MenuButton::sync();
		$this->assertCount( 1, HttpMock::requests( '#setChatMenuButton#' ), 'nothing is sent twice' );
		Settings::set( 'miniapp.enabled', false );
		MenuButton::sync();
		$sent = HttpMock::requests( '#setChatMenuButton#' );
		$this->assertCount( 2, $sent );
		$this->assertSame( 'default', $this->menu_button( $sent[1] )['type'] );
		remove_action( 'shutdown', [ MenuButton::class, 'sync' ] );
	}

	private function menu_button( array $request ): array {
		$button = $request['json']['menu_button'] ?? $request['form']['menu_button'] ?? [];
		return is_string( $button ) ? json_decode( $button, true ) : $button;
	}
}
