<?php
/**
 * Schema review over the real channels: labels, defaults, no orphan settings.
 *
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SchemaReviewTest extends TestCase {

	public function setUp(): void {
		Db::reset();
		Schema::flush();
	}

	public function test_every_field_has_a_label_and_a_default(): void {
		$fields = Schema::fields();
		$this->assertTrue( count( $fields ) > 300, 'all channels are in the schema' );
		foreach ( $fields as $path => $field ) {
			$this->assertNotSame( '', trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) ), $path . ' label' );
			$this->assertTrue( array_key_exists( 'default', $field ) || Settings::get( $path ) !== null, $path . ' default' );
		}
	}

	public function test_tabs_are_ordered_and_every_section_has_a_tab(): void {
		$this->assertSame( [ 'general', 'attachments', 'queue', 'alerts', 'digest', 'miniapp', 'privacy', 'advanced' ], array_keys( Schema::tabs() ) );
		$tabs = array_merge( array_keys( Schema::tabs() ), \AmirhpCom\FormsNotifier\Core\Registry::channel_ids() );
		$seen = [];
		foreach ( Schema::sections() as $section ) {
			$this->assertContains( $section['tab'] ?? '', $tabs, (string) ( $section['id'] ?? '' ) );
			$seen[] = $section['tab'];
		}
		// Sorted by tab: each tab is one run of sections.
		$runs = array_values( array_unique( $seen ) );
		$this->assertSame( count( $runs ), count( array_filter( array_keys( $seen ), static function ( $i ) use ( $seen ) { return $i === 0 || $seen[ $i ] !== $seen[ $i - 1 ]; } ) ) );
	}

	/**
	 * A stored setting without a field is never shown and never cleaned. The only ones allowed are the generic slots
	 * every channel gets from the channel base (a template, subject or buttons for a purpose the channel does not
	 * offer, the receivers of a channel that has none), which the channel ignores.
	 */
	public function test_no_orphan_settings(): void {
		$orphans = [];
		$walk    = static function ( array $tree, string $prefix ) use ( &$walk, &$orphans ) {
			foreach ( $tree as $key => $value ) {
				$path = $prefix . $key;
				if ( Schema::field( $path ) ) {
					continue;
				}
				if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					$walk( $value, $path . '.' );
				} else {
					$orphans[] = $path;
				}
			}
		};
		$walk( Settings::all(), '' );
		$unexpected = array_values(
			array_filter(
				$orphans,
				static function ( $path ) {
					return ! preg_match( '/^channels\.[a-z0-9_]+\.((template|subject)\.(admin|submitter)\._|buttons|receivers)$/', $path );
				}
			)
		);
		$this->assertSame( [], $unexpected );
	}
}
