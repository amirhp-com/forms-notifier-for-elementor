<?php
/**
 * wpml-config.xml against the controls of the form action.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Elementor\FormAction;
use AmirhpCom\FormsNotifier\Elementor\ProFormSource;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class WpmlConfigTest extends TestCase {

	/** Control types whose value is a text a translator works on. */
	private const TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg' ];

	/** @var \SimpleXMLElement */
	private $xml;

	/** @var array */
	private $controls = [];

	public function setUp(): void {
		$file = FNFE_DIR . 'wpml-config.xml';
		$this->assertTrue( is_readable( $file ), 'wpml-config.xml exists' );
		$this->xml = simplexml_load_string( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$this->assertInstanceOf( \SimpleXMLElement::class, $this->xml );

		FakeChannel::install();
		$widget = new class() {
			public $controls = [];
			public function start_controls_section( $id, $args ) {}
			public function end_controls_section() {}
			public function add_control( $id, $args ) { $this->controls[ $id ] = $args; }
		};
		( new FormAction( new ProFormSource() ) )->register_settings_section( $widget );
		$this->controls = $widget->controls;
	}

	public function test_settings_option_is_not_an_admin_text(): void {
		$this->assertSame( 'wpml-config', $this->xml->getName() );
		$this->assertSame( 0, count( $this->xml->xpath( '//admin-texts' ) ), 'fnfe_settings is translated by the plugin itself' );
	}

	public function test_widget_entry_does_not_replace_the_form_entry_of_wpml(): void {
		$widgets = $this->xml->xpath( '/wpml-config/elementor-widgets/widget' );
		$this->assertCount( 1, $widgets );
		$this->assertNotSame( 'form', (string) $widgets[0]['name'], 'WPML keeps one entry per name' );
		$conditions = $widgets[0]->xpath( 'conditions/condition' );
		$this->assertCount( 1, $conditions );
		$this->assertSame( 'widgetType', (string) $conditions[0]['key'] );
		$this->assertSame( 'form', (string) $conditions[0] );
	}

	public function test_every_declared_field_is_a_text_control(): void {
		$fields = $this->xml->xpath( '/wpml-config/elementor-widgets/widget/fields/field' );
		$this->assertTrue( count( $fields ) > 0 );
		foreach ( $fields as $field ) {
			$id = (string) $field;
			$this->assertArrayHasKey( $id, $this->controls, $id );
			$this->assertContains( $this->controls[ $id ]['type'], self::TEXT_TYPES, $id );
			$this->assertContains( (string) $field['editor_type'], [ 'LINE', 'AREA', 'VISUAL', 'LINK' ], $id );
		}

		$groups = $this->xml->xpath( '/wpml-config/elementor-widgets/widget/fields-in-item' );
		$this->assertTrue( count( $groups ) > 0 );
		foreach ( $groups as $group ) {
			$items = (string) $group['items_of'];
			$this->assertArrayHasKey( $items, $this->controls, $items );
			$this->assertSame( 'repeater', $this->controls[ $items ]['type'], $items );
			$inner = [];
			foreach ( $this->controls[ $items ]['fields'] as $key => $control ) {
				$inner[ $control['name'] ?? $key ] = $control;
			}
			foreach ( $group->field as $field ) {
				$id = (string) $field;
				$this->assertArrayHasKey( $id, $inner, $items . '.' . $id );
				$this->assertContains( $inner[ $id ]['type'], self::TEXT_TYPES, $items . '.' . $id );
			}
		}
	}

	public function test_every_text_control_is_declared(): void {
		$declared = [];
		foreach ( $this->xml->xpath( '/wpml-config/elementor-widgets/widget/fields/field' ) as $field ) {
			$declared[] = (string) $field;
		}
		foreach ( $this->xml->xpath( '/wpml-config/elementor-widgets/widget/fields-in-item' ) as $group ) {
			foreach ( $group->field as $field ) {
				$declared[] = $group['items_of'] . '.' . $field;
			}
		}

		$texts = [];
		foreach ( $this->controls as $id => $control ) {
			// Receivers are addresses, not words: they stay the same on every translation.
			if ( strncmp( $id, 'fnfe_receivers_', 15 ) === 0 ) {
				continue;
			}
			if ( in_array( $control['type'], self::TEXT_TYPES, true ) ) {
				$texts[] = $id;
			}
			if ( $control['type'] === 'repeater' ) {
				foreach ( $control['fields'] as $key => $inner ) {
					if ( in_array( $inner['type'], self::TEXT_TYPES, true ) ) {
						$texts[] = $id . '.' . ( $inner['name'] ?? $key );
					}
				}
			}
		}
		sort( $texts );
		sort( $declared );
		$this->assertSame( $texts, $declared );
	}

	/** The same lookup WPML does: settings[field] and settings[repeater][n][field] of an element whose widgetType matches. */
	public function test_wpml_finds_the_texts_of_a_form_element(): void {
		$element = [
			'id'         => 'a1b2c3d',
			'elType'     => 'widget',
			'widgetType' => 'form',
			'settings'   => [
				'submit_actions' => [ 'fnfe' ],
				'fnfe_template'  => "New message\n{all_fields}",
				'fnfe_buttons'   => [ [ '_id' => 'b1', 'text' => 'Reply', 'type' => 'url', 'value' => 'https://example.com/en/' ] ],
			],
		];
		$found  = [];
		$widget = $this->xml->xpath( '/wpml-config/elementor-widgets/widget' )[0];
		foreach ( $widget->xpath( 'conditions/condition' ) as $condition ) {
			$this->assertSame( (string) $condition, $element[ (string) $condition['key'] ] );
		}
		foreach ( $widget->xpath( 'fields/field' ) as $field ) {
			$found[] = $element['settings'][ (string) $field ] ?? null;
		}
		foreach ( $widget->xpath( 'fields-in-item' ) as $group ) {
			foreach ( $element['settings'][ (string) $group['items_of'] ] as $item ) {
				foreach ( $group->field as $field ) {
					$found[] = $item[ (string) $field ] ?? null;
				}
			}
		}
		$this->assertSame( [ "New message\n{all_fields}", 'Reply', 'https://example.com/en/' ], $found );
	}

	/** Validated against the schema WPML ships, when a copy of WPML is installed (it need not be active). */
	public function test_schema_of_wpml(): void {
		$xsd = WP_PLUGIN_DIR . '/sitepress-multilingual-cms/res/xsd/wpml-config.xsd';
		if ( ! is_readable( $xsd ) || ! class_exists( '\DOMDocument' ) ) {
			return;
		}
		$dom = new \DOMDocument();
		$dom->load( FNFE_DIR . 'wpml-config.xml' );
		$previous = libxml_use_internal_errors( true );
		$valid    = $dom->schemaValidate( $xsd );
		$errors   = array_map(
			static function ( $e ) {
				return trim( $e->message );
			},
			libxml_get_errors()
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->assertTrue( $valid, implode( '; ', $errors ) );
	}
}
