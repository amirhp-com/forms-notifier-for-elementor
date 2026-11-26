<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Health\SiteHealth;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class RequirementsTest extends TestCase {

	public function setUp(): void {
		Db::reset();
	}

	public function test_site_health_names_a_missing_mbstring(): void {
		$this->assertSame( 'good', SiteHealth::extensions( true )[0] );
		$this->assertSame( 'warning', SiteHealth::extensions( false )[0] );
		$this->assertContains( 'mbstring', SiteHealth::extensions( false )[1] );
		$tests = SiteHealth::tests( [] );
		$this->assertArrayHasKey( 'fnfe_extensions', $tests['direct'] );
		$this->assertSame( 'good', SiteHealth::check( 'extensions' )['status'] );
	}

	/**
	 * A whole capture, stored and searched, in a process whose mbstring functions are gone (WordPress then supplies
	 * mb_substr() and mb_strlen() only).
	 */
	public function test_submissions_are_kept_without_mbstring(): void {
		// mb_check_encoding stays: another plugin of the test site stops loading without it. Text::is_utf8() does not use it.
		$disable = 'mb_strtolower,mb_strtoupper,mb_convert_case,mb_scrub,mb_convert_encoding,mb_strcut,mb_substr,mb_strlen,mb_detect_encoding';
		$script  = __DIR__ . '/fixtures/no-mbstring.php';
		$out     = (string) shell_exec(
			escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d disable_functions=' . $disable . ' ' . escapeshellarg( $script )
			. ' ' . escapeshellarg( ABSPATH ) . ' ' . escapeshellarg( home_url() ) . ' 2>&1'
		);
		$this->assertContains( 'RESULT {"mbstring":false,"stored":true,"found":true,"valid":true}', $out, $out );
	}
}
