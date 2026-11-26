<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Defaults;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class SchemaTest extends TestCase {

	public function setUp(): void {
		Db::reset();
		FakeChannel::install();
	}

	private function clean( array $field, $value, ?string &$error = null ) {
		list( $clean, $error ) = FieldSanitizer::sanitize( $field + [ 'key' => 'x', 'label' => 'X' ], $value );
		return $clean;
	}

	public function test_normalisation_defaults(): void {
		$field = Schema::normalize_field( [ 'key' => 'token', 'type' => 'secret' ], 'channels.fake.' );
		$this->assertKeys( [ 'key', 'type', 'label', 'desc', 'placeholder', 'default', 'options', 'options_source', 'width', 'required', 'localized', 'show_if', 'attrs', 'sanitize', 'fields', 'item_label', 'action', 'steps', 'channel', 'purpose', 'level', 'constant' ], $field );
		$this->assertSame( 'channels.fake.token', $field['key'] );
		$this->assertSame( 'FNFE_CHANNELS_FAKE_TOKEN', $field['constant'] );
		$this->assertSame( false, Schema::normalize_field( [ 'type' => 'toggle' ] )['default'] );
		$this->assertSame( [], Schema::normalize_field( [ 'type' => 'tags' ] )['default'] );
		$this->assertSame( [ '_' => 'Hi' ], Schema::normalize_field( [ 'type' => 'text', 'localized' => true, 'default' => 'Hi' ] )['default'] );
		$this->assertFalse( Schema::normalize_field( [ 'type' => 'number', 'localized' => true ] )['localized'] );
	}

	public function test_relative_keys_become_absolute(): void {
		$field = Schema::normalize_field( [ 'key' => 'greeting', 'show_if' => [ [ 'key' => 'mode', 'op' => 'is', 'value' => 'b' ], [ 'key' => 'advanced.debug_log', 'op' => 'truthy' ] ], 'action' => [ 'send' => [ 'token' ] ] ], 'channels.fake.' );
		$this->assertSame( 'channels.fake.greeting', $field['key'] );
		$this->assertSame( 'channels.fake.mode', $field['show_if'][0]['key'] );
		$this->assertSame( 'advanced.debug_log', $field['show_if'][1]['key'] );
		$this->assertSame( [ 'channels.fake.token' ], $field['action']['send'] );
		$this->assertSame( $field, Schema::normalize_field( $field, 'channels.fake.' ) );
	}

	public function test_sections_and_fields(): void {
		$this->assertCount( 1, Schema::sections_for( 'fake' ) );
		$this->assertArrayHasKey( 'channels.fake.mode', Schema::fields() );
		$this->assertFalse( isset( Schema::fields()['channels.fake.hint'] ) );
		$this->assertTrue( Schema::is_secret( 'channels.fake.token' ) );
		$this->assertFalse( Schema::is_secret( 'channels.fake.mode' ) );
		$this->assertNull( Schema::field( 'no.such.field' ) );
		$tabs = array_column( Schema::sections(), 'tab' );
		$this->assertSame( 'general', $tabs[0] );
		$this->assertSame( 'fake', end( $tabs ) );
	}

	public function test_section_filter_and_replacement(): void {
		$this->filter( 'fnfe_settings_sections', static function ( array $sections ) {
			$sections[] = [ 'id' => 'mine', 'tab' => 'advanced', 'priority' => 1, 'fields' => [ [ 'key' => 'advanced.mine', 'type' => 'number', 'default' => 4 ] ] ];
			$sections[] = [ 'id' => 'queue_runner', 'tab' => 'queue', 'title' => 'Replaced', 'fields' => [] ];
			return $sections;
		} );
		Schema::flush();
		Settings::flush_cache();
		$this->assertSame( 'mine', Schema::sections_for( 'advanced' )[0]['id'] );
		$this->assertSame( 4, Settings::get( 'advanced.mine' ) );
		$this->assertContains( 'Replaced', array_column( Schema::sections_for( 'queue' ), 'title' ) );
		$this->assertCount( 2, Schema::sections_for( 'queue' ) );
	}

	public function test_core_sections_cover_the_defaults(): void {
		$missing = [];
		$walk    = static function ( array $tree, string $prefix ) use ( &$walk, &$missing ) {
			foreach ( $tree as $key => $value ) {
				$path = $prefix . $key;
				if ( Schema::field( $path ) ) {
					continue;
				}
				if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					$walk( $value, $path . '.' );
				} elseif ( $path !== 'attachments.channels' ) {
					$missing[] = $path;
				}
			}
		};
		$walk( array_intersect_key( Defaults::base(), array_flip( [ 'general', 'attachments', 'queue', 'privacy', 'advanced' ] ) ), '' );
		$this->assertSame( [], $missing );
	}

	public function test_core_fields_accept_their_defaults(): void {
		foreach ( Schema::fields() as $path => $field ) {
			if ( $field['type'] === 'secret' || strncmp( $path, 'channels.', 9 ) === 0 ) {
				continue;
			}
			$value = Settings::get( $path );
			list( $clean, $error ) = FieldSanitizer::sanitize( $field, $value );
			$this->assertSame( '', $error, $path );
			$this->assertSame( $value, $clean, $path );
		}
	}

	public function test_text_types(): void {
		$this->assertSame( 'a b', $this->clean( [ 'type' => 'text' ], " a\n b<script>x</script> " ) );
		$this->assertSame( 'https://x/%D8%B3', $this->clean( [ 'type' => 'text' ], 'https://x/%D8%B3' ) );
		$this->assertSame( '', $this->clean( [ 'type' => 'text' ], [ 'array' ] ) );
		$this->assertSame( "a\nb", $this->clean( [ 'type' => 'textarea' ], "a\nb<b></b>" ) );
		$this->assertSame( [ '_' => 'x', 'fa' => 'ی' ], $this->clean( [ 'type' => 'text', 'localized' => true ], [ '_' => 'x', 'fa' => 'ی', 'de' => '', 'bad key' => 'z' ] ) );
		$this->assertSame( [ '_' => 'x' ], $this->clean( [ 'type' => 'text', 'localized' => true ], 'x' ) );
		$this->assertSame( '<b>x</b>', $this->clean( [ 'type' => 'template' ], '<b style="x">x</b><script></script>' ) );
	}

	public function test_number_bounds(): void {
		$field = [ 'type' => 'number', 'attrs' => [ 'min' => 1, 'max' => 10 ] ];
		$this->assertSame( 5, $this->clean( $field, '۵' ) );
		$this->assertSame( 10, $this->clean( $field, 99 ) );
		$this->assertSame( 1, $this->clean( $field, -3 ) );
		$this->assertSame( 0, $this->clean( [ 'type' => 'number' ], -3 ) );
		$this->assertSame( -3, $this->clean( [ 'type' => 'number', 'attrs' => [ 'min' => -5 ] ], -3 ) );
		$this->assertSame( 1.5, $this->clean( [ 'type' => 'number', 'attrs' => [ 'step' => 0.5 ] ], '1.5' ) );
		$this->clean( $field, 'abc', $error );
		$this->assertNotSame( '', $error );
	}

	public function test_url_email_tel_time(): void {
		$this->assertSame( 'https://example.com/x?a=1&b=2', $this->clean( [ 'type' => 'url' ], ' https://example.com/x?a=1&b=2 ' ) );
		foreach ( [ 'javascript:alert(1)', 'ftp://x', 'not a url', '//x' ] as $bad ) {
			$this->assertSame( '', $this->clean( [ 'type' => 'url' ], $bad, $error ), $bad );
			$this->assertNotSame( '', $error, $bad );
		}
		$this->assertSame( 'a@example.com', $this->clean( [ 'type' => 'email' ], ' a@example.com', $error ) );
		$this->assertSame( '', $error );
		$this->clean( [ 'type' => 'email' ], 'nope', $error );
		$this->assertNotSame( '', $error );
		$this->assertSame( '+989120000000', $this->clean( [ 'type' => 'tel' ], '+۹۸ 912-000 0000' ) );
		$this->assertSame( '23:59', $this->clean( [ 'type' => 'time' ], '23:59' ) );
		$this->clean( [ 'type' => 'time' ], '24:00', $error );
		$this->assertNotSame( '', $error );
	}

	public function test_option_whitelist(): void {
		$field = [ 'type' => 'select', 'options' => [ 'a' => 'A', 1 => 'One', [ 'value' => 'card', 'label' => 'Card' ] ] ];
		$this->assertSame( 'a', $this->clean( $field, 'a' ) );
		$this->assertSame( '1', $this->clean( $field, 1 ) );
		$this->assertSame( 'card', $this->clean( $field, 'card' ) );
		$this->assertSame( '', $this->clean( $field, 'evil', $error ) );
		$this->assertNotSame( '', $error );
		$this->assertSame( [ 'a' ], $this->clean( [ 'type' => 'multiselect', 'options' => [ 'a' => 'A', 'b' => 'B' ] ], [ 'a', 'a' ], $error ) );
		$this->assertSame( '', $error );
		$this->clean( [ 'type' => 'checkboxes', 'options' => [ 'a' => 'A' ] ], [ 'a', 'z' ], $error );
		$this->assertNotSame( '', $error );
		$this->assertSame( 'fake', $this->clean( [ 'type' => 'select', 'options_source' => 'channels' ], 'fake' ) );
		$this->clean( [ 'type' => 'select', 'options_source' => 'channels' ], 'nope', $error );
		$this->assertNotSame( '', $error );
		$this->assertSame( 'IR', $this->clean( [ 'type' => 'select', 'options_source' => 'countries' ], 'IR' ) );
		$this->assertSame( 'administrator', $this->clean( [ 'type' => 'select', 'options_source' => 'roles' ], 'administrator' ) );
	}

	public function test_toggle_secret_code(): void {
		$this->assertSame( true, $this->clean( [ 'type' => 'toggle' ], '1' ) );
		$this->assertSame( false, $this->clean( [ 'type' => 'toggle' ], 'off' ) );
		$this->assertSame( 'p<ss w0rd', $this->clean( [ 'type' => 'secret' ], "  p<ss w0rd\x00 " ) );
		$this->assertSame( 'a<b onclick="x">b', $this->clean( [ 'type' => 'secret' ], 'a<b onclick="x">b' ) );
		$this->assertSame( 'Pa<ss>w0rd', $this->clean( [ 'type' => 'secret' ], 'Pa<ss>w0rd' ) );
		$this->assertSame( 'a<b>c', $this->clean( [ 'type' => 'secret' ], "a<b>c\x07" ) );
		$this->assertSame( '{"a":"<b>"}', $this->clean( [ 'type' => 'code' ], '{"a":"<b>"}' ) );
		$this->clean( [ 'type' => 'code' ], str_repeat( 'x', 102401 ), $error );
		$this->assertNotSame( '', $error );
		$this->clean( [ 'type' => 'code', 'sanitize' => 'json' ], '{bad', $error );
		$this->assertNotSame( '', $error );
	}

	public function test_lists(): void {
		$this->assertSame( [ 'pdf', 'png' ], $this->clean( [ 'type' => 'tags' ], "pdf, png\npdf" ) );
		$this->assertSame( [ 60, 60, 300 ], $this->clean( [ 'type' => 'tags', 'sanitize' => 'int' ], [ '60', '۶۰', 300 ] ) );
		$this->assertSame( [ 'X-A' => '1', 'X-B' => '2' ], $this->clean( [ 'type' => 'keyvalue' ], [ 'X-A' => '1', 0 => [ 'key' => 'X-B', 'value' => '2' ], '' => 'x', 'X-C' => [] ] ) );
	}

	public function test_required(): void {
		foreach ( [ [ 'text', '' ], [ 'tags', [] ], [ 'receivers', [] ], [ 'select', '' ] ] as $case ) {
			$this->clean( [ 'type' => $case[0], 'required' => true ], $case[1], $error );
			$this->assertNotSame( '', $error, $case[0] );
		}
		$this->clean( [ 'type' => 'text', 'localized' => true, 'required' => true ], [ '_' => '' ], $error );
		$this->assertNotSame( '', $error );
	}

	public function test_non_value_types_are_errors(): void {
		foreach ( [ 'notice', 'status', 'action', 'guide', 'heading', 'unknown' ] as $type ) {
			$this->clean( [ 'type' => $type ], 'x', $error );
			$this->assertNotSame( '', $error, $type );
		}
	}

	public function test_repeater(): void {
		$field = Schema::field( 'channels.fake.endpoints' );
		list( $rows, $error ) = FieldSanitizer::sanitize( $field, [ [ 'id' => 'e_1', 'name' => ' One ', 'url' => 'https://example.com', 'sign_secret' => 'leak', 'junk' => 1 ], 'x', [ 'name' => 'Two' ] ] );
		$this->assertSame( '', $error );
		$this->assertSame( [ 'id' => 'e_1', 'name' => 'One', 'url' => 'https://example.com' ], $rows[0] );
		$this->assertMatches( '/^e_[a-z0-9]{8}$/', $rows[1]['id'] );
		list( , $error ) = FieldSanitizer::sanitize( $field, [ [ 'id' => 'e_1', 'name' => '' ] ] );
		$this->assertContains( 'Name', $error );
		$nested = [ 'key' => 'x', 'type' => 'repeater', 'fields' => [ [ 'key' => 'items', 'type' => 'repeater', 'fields' => [ [ 'key' => 'n', 'type' => 'number', 'attrs' => [ 'max' => 3 ] ] ] ] ] ];
		list( $rows ) = FieldSanitizer::sanitize( $nested, [ [ 'id' => 'a', 'items' => [ [ 'id' => 'b', 'n' => 9 ] ] ] ] );
		$this->assertSame( 3, $rows[0]['items'][0]['n'] );
	}

	public function test_repeater_secret_path(): void {
		$this->assertTrue( Schema::is_secret( 'channels.fake.endpoints.e_1.sign_secret' ) );
		$this->assertFalse( Schema::is_secret( 'channels.fake.endpoints.e_1.name' ) );
		$this->assertFalse( Schema::is_secret( 'channels.fake.endpoints.e 1.sign_secret' ) );
		$row = Schema::row_secret( 'channels.fake.endpoints.e_1.sign_secret' );
		$this->assertSame( 'channels.fake.endpoints', $row['repeater'] );
		$this->assertSame( 'e_1', $row['row'] );
	}

	public function test_receivers_go_through_the_channel(): void {
		$field = Schema::field( 'channels.fake.receivers' );
		list( $rows, $error ) = FieldSanitizer::sanitize( $field, [ [ 'address' => ' DESK ', 'label' => '<b>Desk</b>', 'enabled' => false, 'lang' => 'fa', 'meta' => [ 'type' => 'group', 'x' => new \stdClass() ] ], [ 'address' => '{field:phone}' ], [ 'label' => 'no address' ] ] );
		$this->assertSame( '', $error );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'desk', $rows[0]['address'] );
		$this->assertSame( 'Desk', $rows[0]['label'] );
		$this->assertFalse( $rows[0]['enabled'] );
		$this->assertSame( [ 'type' => 'group' ], $rows[0]['meta'] );
		$this->assertMatches( '/^r_[a-z0-9]{8}$/', $rows[0]['id'] );
		$this->assertTrue( $rows[1]['enabled'] );
		list( , $error ) = FieldSanitizer::sanitize( $field, [ [ 'address' => 'bad one' ], [ 'address' => 'ok' ] ] );
		$this->assertContains( 'bad one', $error );
	}

	public function test_settings_map(): void {
		list( $clean, $errors ) = FieldSanitizer::settings( [ 'queue.batch' => 5, 'nope.key' => 1, 'channels.fake.token' => 'x', 'general.capture_mode' => 'zzz' ] );
		$this->assertSame( [ 'queue.batch' => 5 ], $clean );
		$this->assertSame( [ 'nope.key', 'channels.fake.token', 'general.capture_mode' ], array_keys( $errors ) );
	}

	public function test_channel_base_schema(): void {
		$channel = new class() extends \AmirhpCom\FormsNotifier\Channels\AbstractChannel {
			public function id(): string { return 'based'; }
			public function label(): string { return 'Based'; }
			public function capabilities(): array { return array_merge( parent::capabilities(), [ 'buttons' => [ 'url' ], 'subject' => true, 'submitter' => true ] ); }
			public function send( \AmirhpCom\FormsNotifier\Model\Message $message ): \AmirhpCom\FormsNotifier\Model\SendResult { throw new \RuntimeException( 'never thrown further' ); }
			protected function own_sections(): array { return [ [ 'id' => 'based_own', 'title' => 'Own', 'fields' => [ $this->field( 'server', 'url', 'Server' ) ] ] ]; }
		};
		fnfe_register_channel( 'based', $channel );
		$ids = array_column( Schema::sections_for( 'based' ), 'id' );
		$this->assertSame( [ 'based_main', 'based_own', 'based_receivers', 'based_template', 'based_buttons', 'based_fallback' ], $ids );
		foreach ( [ 'enabled', 'server', 'receivers', 'template.admin', 'template.submitter', 'subject.admin', 'subject.submitter', 'buttons', 'fallback' ] as $key ) {
			$this->assertNotNull( Schema::field( 'channels.based.' . $key ), $key );
		}
		$this->assertFalse( $channel->is_ready() );
		Settings::set( 'channels.based.enabled', true );
		$this->assertTrue( $channel->is_ready() );
		$result = $channel->test( new \AmirhpCom\FormsNotifier\Model\Recipient( 'based', 'x' ) );
		$this->assertFalse( $result->ok );
		$this->assertNotSame( '', $channel->default_template( 'admin' ) );
	}
}
