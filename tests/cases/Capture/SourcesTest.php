<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Elementor\AtomicActionProxy;
use AmirhpCom\FormsNotifier\Elementor\AtomicFormSource;
use AmirhpCom\FormsNotifier\Elementor\EditorValues;
use AmirhpCom\FormsNotifier\Elementor\FormAction;
use AmirhpCom\FormsNotifier\Elementor\HelloPlusFormSource;
use AmirhpCom\FormsNotifier\Elementor\ProFormSource;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\TestCase;

require_once __DIR__ . '/Records.php';

final class SourcesTest extends TestCase {

	use Records;

	public function setUp(): void {
		Db::reset();
		$this->skipped  = [];
		$this->captured = [];
		$_POST          = [];
		$_FILES         = [];
		$this->listen();
	}

	public function tearDown(): void {
		$_POST  = [];
		$_FILES = [];
	}

	public function test_registry_and_hooks(): void {
		$this->assertSame( [ 'pro', 'atomic', 'ehp' ], array_keys( Registry::form_sources() ) );
		foreach ( [ 'elementor_pro/forms/actions/register', 'elementor_pro/forms/process', 'elementor_pro/forms/new_record', 'elementor_pro/atomic_forms/actions/register', 'hello_plus/forms/process', 'elementor/document/after_save', 'elementor/editor/after_enqueue_scripts' ] as $hook ) {
			$this->assertTrue( (bool) has_action( $hook ), $hook );
		}
		$this->assertSame( 1, count( array_filter( $GLOBALS['wp_filter']['elementor_pro/forms/new_record']->callbacks[20] ?? [], static function ( $c ) { return is_array( $c['function'] ) && $c['function'][0] instanceof ProFormSource; } ) ), 'registered once' );
	}

	public function test_action_is_registered_with_elementor(): void {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Module' ) ) {
			return;
		}
		$action = \ElementorPro\Modules\Forms\Module::instance()->actions_registrar->get( 'fnfe' );
		$this->assertInstanceOf( FormAction::class, $action );
		$this->assertSame( 'fnfe', $action->get_name() );
	}

	public function test_export_strips_receivers(): void {
		$action  = new FormAction( new ProFormSource() );
		$element = [ 'id' => 'a', 'settings' => [ 'fnfe_receivers_telegram' => '-100', 'fnfe_receivers_email' => 'a@example.com', 'fnfe_receivers_mode' => 'replace', 'fnfe_template' => 'x', 'form_name' => 'y' ] ];
		$this->assertSame( [ 'fnfe_receivers_mode', 'fnfe_template', 'form_name' ], array_keys( $action->on_export( $element )['settings'] ) );
		$this->assertSame( [ 'id' => 'a' ], $action->on_export( [ 'id' => 'a' ] ) );
	}

	public function test_controls(): void {
		FakeChannel::install();
		$widget = new class() {
			public $controls = [];
			public $section  = [];
			public function start_controls_section( $id, $args ) { $this->section = [ $id, $args ]; }
			public function end_controls_section() { $this->section[] = 'closed'; }
			public function add_control( $id, $args ) { $this->controls[ $id ] = $args; }
		};
		( new FormAction( new ProFormSource() ) )->register_settings_section( $widget );
		$this->assertSame( 'section_fnfe', $widget->section[0] );
		$this->assertSame( [ 'submit_actions' => 'fnfe' ], $widget->section[1]['condition'] );
		$this->assertSame( 'closed', $widget->section[2] );
		foreach ( [ 'fnfe_intro', 'fnfe_enabled', 'fnfe_channels', 'fnfe_receivers_fake', 'fnfe_receivers_mode', 'fnfe_template', 'fnfe_buttons', 'fnfe_attach' ] as $id ) {
			$this->assertArrayHasKey( $id, $widget->controls );
			if ( $id !== 'fnfe_intro' ) {
				$this->assertSame( 'none', $widget->controls[ $id ]['render_type'] );
				$this->assertSame( [ 'active' => false ], $widget->controls[ $id ]['dynamic'] );
			}
		}
		$this->assertContains( 'forms/key', $widget->controls['fnfe_intro']['raw'] );

		// Elementor evaluates the conditions in PHP when a form is submitted.
		$conditions = $widget->controls['fnfe_receivers_fake']['conditions'];
		foreach ( [ [ '', true ], [ null, true ], [ [], true ], [ [ 'fake' ], true ], [ [ 'email' ], false ] ] as $case ) {
			$this->assertSame( $case[1], \Elementor\Conditions::check( $conditions, [ 'fnfe_channels' => $case[0] ] ) );
		}
	}

	public function test_editor_values(): void {
		$values = EditorValues::from_settings( [
			'submit_actions'          => [ 'email', 'fnfe' ],
			'fnfe_enabled'            => 'maybe',
			'fnfe_channels'           => [ 'telegram', 'Bad Id', 5, 'telegram' ],
			'fnfe_receivers_telegram' => "-100, 5\n<b>x</b>",
			'fnfe_receivers_mode'     => 'replace',
			'fnfe_receivers_Bad'      => 'x',
			'fnfe_template'           => '<b>Hi</b> {field:name}',
			'fnfe_attach'             => 'everything',
			'fnfe_buttons'            => [ [ '_id' => 'a1', 'text' => 'Open', 'type' => 'evil', 'value' => 'https://example.com/%D8%B3' ], [ 'text' => '' ], 'x' ],
		] );
		$this->assertSame( [
			'fnfe_receivers_mode'     => 'replace',
			'fnfe_channels'           => [ 'telegram' ],
			'fnfe_receivers_telegram' => "-100\n5\nx",
			'fnfe_template'           => '<b>Hi</b> {field:name}',
			'fnfe_buttons'            => [ [ '_id' => 'a1', 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/%D8%B3' ] ],
		], $values );
		$this->assertSame( [], EditorValues::from_settings( [ 'fnfe_enabled' => 'no' ] ) );
		$this->assertSame( [], EditorValues::from_settings( null ) );
	}

	public function test_hello_plus(): void {
		$source   = new HelloPlusFormSource();
		$settings = $this->settings( [ $this->row( 'name' ), $this->row( 'phone', 'ehp-tel' ), $this->row( 'ok', 'ehp-acceptance' ), $this->row( 'msg', 'textarea' ) ] );
		$sent     = [ 'name' => 'O&#039;Neil &amp;lt;', 'phone' => '۰۹۱۲', 'ok' => 'on', 'msg' => 'a &lt; b' ];
		$record   = new FakeRecord( $sent, $settings );
		$handler  = new FakeHandler( [ 'id' => 'abc1234' ] );
		$source->on_process( $record, $handler );
		$source->on_process( $record, $handler );
		$this->assertSame( 1, $this->total() );
		$s = $this->latest();
		$this->assertSame( 'ehp', $s->source );
		$this->assertSame( "O'Neil &lt;", $s->value( 'name' ) );
		$this->assertSame( 'tel', $s->field( 'phone' )['type'] );
		$this->assertSame( '0912', $s->value( 'phone' ) );
		$this->assertSame( __( 'Accepted', 'forms-notifier-for-elementor' ), $s->value( 'ok' ) );
		$this->assertSame( 'a < b', $s->value( 'msg' ) );

		$failed         = new FakeHandler( [ 'id' => 'abc1234' ] );
		$failed->errors = [ 'name' => 'x' ];
		$source->on_process( new FakeRecord( [ 'name' => 'other' ], $settings ), $failed );
		$this->assertSame( 1, $this->total() );

		$found = $source->find_forms( [ $this->element( 'ehp0001', [ $this->row( 'phone', 'ehp-tel' ) ], [], 'ehp-form' ) ], 5 );
		$this->assertSame( 'tel', $found[0]['fields'][0]['type'] );
	}

	public function test_atomic_proxies_are_registered(): void {
		$runner = '\ElementorPro\Modules\AtomicForm\Actions\Action_Runner';
		if ( ! class_exists( $runner ) || ! did_action( 'elementor_pro/atomic_forms/actions/register' ) ) {
			return;
		}
		$this->assertTrue( ( new AtomicFormSource() )->is_available() );
		foreach ( $runner::get_registered_actions() as $type => $action ) {
			if ( strpos( $type, 'fnfe' ) !== 0 ) {
				$this->assertInstanceOf( AtomicActionProxy::class, $action );
				$this->assertSame( $type, $action->get_type() );
			}
		}
	}

	public function test_atomic_submission_through_the_real_runner(): void {
		$runner = '\ElementorPro\Modules\AtomicForm\Actions\Action_Runner';
		if ( ! class_exists( $runner ) || ! did_action( 'elementor_pro/atomic_forms/actions/register' ) ) {
			return;
		}
		eval( 'final class Fnfe_Test_Atomic_Action extends \ElementorPro\Modules\AtomicForm\Actions\Action_Base {
			public static $runs = 0;
			public function get_type(): string { return "fnfe-test"; }
			public function execute( array $d, array $w, array $c ): array { ++self::$runs; return $this->success( "fine", [ "extra" => 1 ] ); }
		}' );
		$source = new AtomicFormSource();
		$runner::register_action( new \Fnfe_Test_Atomic_Action() );
		$source->wrap_actions();

		$path    = $this->upload( 'png', $this->png() );
		$_POST   = [ 'form_fields' => [ [ 'id' => 'f-name' ], [ 'id' => 'f-file' ] ] ];
		$_FILES  = [ 'form_fields' => [ 'name' => [ 1 => [ 'value' => [ 'Photo one.png' ] ] ], 'error' => [ 1 => [ 'value' => [ 0 ] ] ] ] ];
		$data    = [ 'f-name' => 'Sara', 'f-list' => [ 'a', 'b' ], 'f-pass' => 'Hunter2-Clear', 'f-file' => [ 'https://example.com/p.png' ] ];
		$context = [
			'post_id'        => 88,
			'form_id'        => 'atom001',
			'form_name'      => 'Atomic contact',
			'field_metadata' => [ 'f-name' => [ 'label' => 'Name', 'type' => 'text', 'options' => null ], 'f-list' => [ 'label' => 'List', 'type' => 'checkbox', 'options' => [ 'a', 'b' ] ], 'f-pass' => [ 'label' => 'Pass', 'type' => 'password' ], 'f-file' => [ 'label' => 'Photo', 'type' => 'file' ] ],
			'referrer'       => 'https://example.com/?utm_source=x',
			'referer_title'  => 'Home',
			'files'          => [ 'f-file' => [ $path ] ],
		];
		$result = $runner::execute_actions( [ 'fnfe-test', 'fnfe-test' ], $data, [], $context );
		$this->assertTrue( $result['allActionsSucceeded'] );
		$this->assertSame( 1, $result['actionResults'][0]['extra'], 'the result is untouched' );
		$this->assertSame( 2, \Fnfe_Test_Atomic_Action::$runs );
		$this->assertSame( 1, $this->total(), 'captured once' );

		$s = $this->latest();
		$this->assertSame( 'atomic', $s->source );
		$this->assertSame( '88:atom001', $s->form_key );
		$this->assertSame( 'Atomic contact', $s->form_name );
		$this->assertSame( [ 'a', 'b' ], $s->field( 'f-list' )['values'] );
		$this->assertTrue( $s->field( 'f-pass' )['redacted'] );
		$this->assertSame( 'Photo-one.png', $s->files[0]->name );
		$this->assertTrue( $s->files[0]->is_image() );
		$this->assertTrue( $s->files[0]->stored );
		$this->assertSame( [ 'source' => 'x' ], $s->meta['utm'] );
		$this->assertNotContains( 'Hunter2', $this->dump() );
	}

	public function test_atomic_waits_for_the_elementor_id(): void {
		$source  = new AtomicFormSource();
		$context = [ 'post_id' => 89, 'form_id' => 'atom002', 'form_name' => 'x', 'field_metadata' => [], 'pending_actions_count' => 1 ];
		$source->after_action( 'collect-submissions', [ 'a' => '1' ], $context, [ 'status' => 'success', 'submissionId' => 5 ] );
		$this->assertSame( 0, $this->total() );
		$source->after_action( 'email', [ 'a' => '1' ], array_merge( $context, [ 'submission_id' => 5 ] ), [ 'status' => 'failed', 'error' => 'No mail' ] );
		$this->assertSame( 1, $this->total() );
		$this->assertSame( 5, $this->latest()->meta['elementor_submission_id'] );
		$this->assertSame( [ 'No mail' ], $this->latest()->meta['action_errors'] );

		$only = [ 'post_id' => 90, 'form_id' => 'atom003', 'pending_actions_count' => 0 ];
		$source->after_action( 'collect-submissions', [ 'a' => '1' ], $only, [ 'status' => 'success', 'submissionId' => 6 ] );
		$this->assertSame( 2, $this->total() );
		$this->assertSame( 6, $this->latest()->meta['elementor_submission_id'] );
	}

	public function test_atomic_password_cannot_be_renamed_in_the_browser(): void {
		FormRepository::upsert( '91:atom004', [ 'post_id' => 91, 'element_id' => 'atom004', 'source' => 'atomic', 'name' => 'Login', 'fields' => [ [ 'id' => 'pw', 'type' => 'password', 'label' => 'Password' ] ] ] );
		( new AtomicFormSource() )->after_action( 'email', [ 'pw' => 'Hunter2-Clear' ], [ 'post_id' => 91, 'form_id' => 'atom004', 'field_metadata' => [ 'pw' => [ 'type' => 'text', 'label' => 'x' ] ] ], [ 'status' => 'success' ] );
		$this->assertSame( 1, $this->total() );
		$this->assertNotContains( 'Hunter2', $this->dump() );
	}

	public function test_atomic_discovery(): void {
		$text  = static function ( $value ) { return [ '$$type' => 'string', 'value' => $value ]; };
		$html  = static function ( $value ) { return [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => $value ], 'children' => [] ] ]; };
		$field = static function ( $id, $type, $settings ) { return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ]; };
		$form  = [
			'id'       => 'atom005',
			'elType'   => 'e-form',
			'settings' => [ 'form-name' => $text( 'Ask us' ) ],
			'elements' => [
				[ 'id' => 'box', 'elType' => 'e-div-block', 'settings' => [], 'elements' => [
					$field( 'lab1', 'e-form-label', [ 'text' => $html( 'Your <b>mail</b>' ), 'input-id' => $text( 'mail-1' ) ] ),
					$field( 'fld1', 'e-form-input', [ 'type' => $text( 'email' ), '_cssid' => $text( 'mail-1' ) ] ),
				] ],
				$field( 'fld2', 'e-form-textarea', [ 'placeholder' => $text( 'Message' ) ] ),
				$field( 'fld3', 'e-form-file-upload', [] ),
				$field( 'btn', 'e-form-submit-button', [] ),
			],
		];
		$found = ( new AtomicFormSource() )->find_forms( [ [ 'id' => 'r', 'elType' => 'container', 'elements' => [ $form ] ] ], 3 );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Ask us', $found[0]['name'] );
		$this->assertSame( [ [ 'id' => 'fld1', 'type' => 'email', 'label' => 'Your mail' ], [ 'id' => 'fld2', 'type' => 'textarea', 'label' => 'Message' ], [ 'id' => 'fld3', 'type' => 'upload', 'label' => 'fld3' ] ], $found[0]['fields'] );
	}
}
