<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Autoloader;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class AutoloaderTest extends TestCase {

	const PURE = true;

	public function test_maps_nested_namespaces(): void {
		$path = Autoloader::path( 'AmirhpCom\FormsNotifier\Template\Format\TextFormatter' );
		$this->assertSame( realpath( FNFE_DIR . 'includes/Template/Format/TextFormatter.php' ), realpath( $path ) );
		$this->assertTrue( class_exists( \AmirhpCom\FormsNotifier\Template\Format\TextFormatter::class ) );
	}

	public function test_unknown_class_returns_quietly(): void {
		$this->assertFalse( class_exists( 'AmirhpCom\FormsNotifier\Nope\Missing' ) );
		$this->assertSame( '', Autoloader::path( 'Other\Vendor\Thing' ) );
		$this->assertSame( '', Autoloader::path( 'AmirhpCom\FormsNotifier\..\..\wp-config' ) );
		$this->assertSame( '', Autoloader::path( 'AmirhpCom\FormsNotifierOther\Thing' ) );
	}
}
