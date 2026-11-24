<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Contracts\FormatterInterface;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class RegistryTest extends TestCase {

	public function setUp(): void {
		Db::reset();
		FakeChannel::reset();
		FakeChannel::install();
	}

	public function test_channels(): void {
		$this->assertSame( 19, count( Registry::default_channels() ) );
		$this->assertInstanceOf( FakeChannel::class, Registry::channel( 'fake' ) );
		$this->assertNull( Registry::channel( 'nope' ) );
		$this->assertContains( 'telegram', Registry::channel_ids() );
		$this->assertFalse( isset( Registry::ready_channels()['fake'] ) );
		Settings::set( 'channels.fake.enabled', true );
		$this->assertTrue( isset( Registry::ready_channels()['fake'] ) );
	}

	public function test_broken_entries_are_skipped(): void {
		$this->filter( 'fnfe_channels', static function ( $map ) {
			return $map + [ 'missing' => 'No\Such\ChannelClass', 'wrong' => new \stdClass(), 'Bad Id' => FakeChannel::class, 'crash' => \ReflectionClass::class ];
		} );
		Registry::reset();
		foreach ( [ 'missing', 'wrong', 'Bad Id', 'crash' ] as $id ) {
			$this->assertNull( Registry::channel( $id ) );
		}
		$this->assertNotNull( Registry::channel( 'fake' ) );
	}

	public function test_formatters_fall_back_to_text(): void {
		$this->assertInstanceOf( FormatterInterface::class, Registry::formatter( 'telegram_html' ) );
		$this->assertSame( 'text', Registry::formatter( 'no_such_format' )->id() );
		$this->assertSame( 'text', Registry::formatter( 'json' )->id() );
	}

	public function test_providers(): void {
		$this->assertSame( [], Registry::providers( 'no_such_channel' ) );
		$this->assertSame( [], Registry::providers( '../x' ) );
		$this->assertNull( Registry::provider( 'sms', 'nope' ) );
	}

	public function test_public_functions(): void {
		$this->assertSame( FNFE_VERSION, fnfe() );
		$this->assertSame( 'all', fnfe_get_setting( 'general.capture_mode' ) );
		$result = fnfe_send_message( 'fake', ' Desk ', 'Hello <b>x</b>' );
		$this->assertTrue( $result->ok );
		$this->assertSame( 'desk', FakeChannel::$sent[0]->recipient->address );
		$this->assertSame( 'Hello <b>x</b>', FakeChannel::$sent[0]->plain );
		$this->assertSame( 1, MessageRepository::query( [ 'channel' => 'fake', 'status' => 'sent' ] )['total'] );
		$this->assertFalse( fnfe_send_message( 'nope', 'x', 'y' )->ok );
		$this->assertTrue( fnfe_send_message( 'fake', 'bad', 'y' )->permanent );
		FakeChannel::$results = [ new \RuntimeException( 'boom' ) ];
		$this->assertSame( 'boom', fnfe_send_message( 'fake', 'desk', 'y' )->error );
	}

	public function test_fnfe_send(): void {
		$id = fnfe_send( [ 'form_name' => 'Order', 'fields' => [ 'name' => 'سارا', 'items' => [ 'label' => 'Items', 'value' => [ 'a', 'b' ], 'type' => 'checkbox' ] ], 'channels' => [ 'fake' ] ] );
		$this->assertTrue( $id > 0 );
		$s = SubmissionRepository::find( $id );
		$this->assertSame( 'api:custom', $s->form_key );
		$this->assertSame( 'a, b', $s->value( 'items' ) );
		$this->assertSame( [ 'a', 'b' ], $s->field( 'items' )['values'] );
		$this->assertSame( 'api', $s->meta['via'] );
		$this->assertSame( [ 'fake' ], $s->meta['editor']['fnfe_channels'] );
	}

	public function test_default_dispatch_stub(): void {
		Settings::set_many( [ 'channels.fake.enabled' => true, 'channels.fake.receivers' => [ Fixtures::receiver( 'desk' ) ] ] );
		$s = Fixtures::submission();
		SubmissionRepository::insert( $s );
		$ids = \AmirhpCom\FormsNotifier\Delivery\Dispatcher::dispatch( $s, Fixtures::config(), [ 'fake' ] );
		$this->assertCount( 1, $ids );
	}
}
