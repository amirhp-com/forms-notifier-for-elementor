<?php
namespace AmirhpCom\FormsNotifier\Tests\Bot;

use AmirhpCom\FormsNotifier\Channels\Bot\ChatDiscovery;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class DiscoveryTest extends TestCase {

	private $events = [];

	public function setUp(): void {
		Db::reset();
		$this->events = [];
		State::set( 'bot.telegram.me', [ 'id' => '123456', 'username' => 'demo_bot' ] );
		State::set( 'bot.bale.me', [ 'id' => '123456', 'username' => 'demo_bot' ] );
		$this->filter( 'fnfe_chat_discovered', function ( $row, $is_new ) { $this->events[] = [ $row['chat_id'], $row['thread_id'], $is_new ]; }, 10, 2 );
	}

	private function message( array $message, string $platform = 'telegram', string $kind = 'message' ): void {
		ChatDiscovery::handle( $platform, [ 'update_id' => 1, $kind => $message + [ 'message_id' => 5 ] ] );
	}

	private function group( array $more = [], string $platform = 'telegram' ): void {
		$this->message( $more + [ 'chat' => [ 'id' => -500, 'type' => 'group', 'title' => 'Sales' ], 'from' => [ 'id' => 1 ] ], $platform );
	}

	private function forum( array $more ): void {
		$this->message( $more + [ 'chat' => [ 'id' => (int) Fixtures::CHAT, 'type' => 'supergroup', 'title' => 'Sales', 'is_forum' => true ] ] );
	}

	private function row( string $chat, int $thread = 0, string $platform = 'telegram' ): array {
		$row = ChatRepository::find_chat( $platform, $chat, $thread );
		$this->assertNotNull( $row, "$chat/$thread" );
		return $row;
	}

	public function test_private_start(): void {
		ChatDiscovery::handle( 'telegram', Fixtures::update_start() );
		$row = $this->row( '111111' );
		$this->assertSame( [ 'private', 'Sara', 'sara_example', '111111', 'fa', 'active' ], [ $row['type'], $row['title'], $row['username'], $row['user_id'], $row['lang'], $row['status'] ] );
		ChatDiscovery::handle( 'telegram', Fixtures::update_start( 2 ) );
		$this->assertSame( [ [ '111111', 0, true ], [ '111111', 0, false ] ], $this->events );
		$this->assertSame( 1, ChatRepository::query( [ 'platform' => 'telegram' ] )['total'] );
	}

	public function test_group_message(): void {
		$this->group();
		$row = $this->row( '-500' );
		$this->assertSame( [ 'group', 'Sales', '', false ], [ $row['type'], $row['title'], $row['user_id'], $row['is_forum'] ] );
	}

	public function test_hostile_values(): void {
		$this->message( [ 'chat' => [ 'id' => -7, 'type' => 'evil', 'title' => "<script>x</script>Ti\ntle" . str_repeat( 'ا', 300 ), 'username' => 'a"b' ], 'from' => [ 'id' => [ 'x' ] ] ] );
		$row = $this->row( '-7' );
		$this->assertSame( '', $row['type'] );
		$this->assertSame( '', $row['username'] );
		$this->assertNotContains( '<', $row['title'] );
		$this->assertSame( 191, mb_strlen( $row['title'] ) );
		foreach ( [ [ 'id' => 'abc' ], [ 'id' => [] ], [ 'id' => null ], [ 'id' => '1 OR 1' ], 'x' ] as $chat ) {
			$this->message( [ 'chat' => $chat ] );
		}
		$this->assertSame( 1, ChatRepository::query( [] )['total'] );
	}

	public function test_topics(): void {
		ChatDiscovery::handle( 'telegram', Fixtures::update_topic() );
		$this->assertTrue( $this->row( Fixtures::CHAT )['is_forum'] );
		$topic = $this->row( Fixtures::CHAT, 45 );
		$this->assertSame( [ 'Leads', 'Sales', 'supergroup' ], [ $topic['topic_name'], $topic['title'], $topic['type'] ] );

		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'forum_topic_created' => [ 'name' => 'New' ] ] );
		$this->assertSame( 'New', $this->row( Fixtures::CHAT, 46 )['topic_name'] );
		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'forum_topic_edited' => [ 'name' => 'Renamed' ] ] );
		$this->assertSame( 'Renamed', $this->row( Fixtures::CHAT, 46 )['topic_name'] );
		// A later message still carries the first name in its reply header.
		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'reply_to_message' => [ 'forum_topic_created' => [ 'name' => 'New' ] ], 'text' => 'x' ] );
		$this->assertSame( 'Renamed', $this->row( Fixtures::CHAT, 46 )['topic_name'] );

		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'forum_topic_closed' => [] ] );
		$this->assertSame( 'closed', $this->row( Fixtures::CHAT, 46 )['status'] );
		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'text' => 'admin writes' ] );
		$this->assertSame( 'closed', $this->row( Fixtures::CHAT, 46 )['status'] );
		$this->forum( [ 'message_thread_id' => 46, 'is_topic_message' => true, 'forum_topic_reopened' => [] ] );
		$this->assertSame( 'active', $this->row( Fixtures::CHAT, 46 )['status'] );

		$this->forum( [ 'message_thread_id' => 47, 'is_topic_message' => true, 'text' => 'x' ] );
		$this->assertNotSame( '', ChatDiscovery::topic_name( $this->row( Fixtures::CHAT, 47 ) ) );
		$this->assertSame( '', $this->row( Fixtures::CHAT, 47 )['topic_name'] );

		// General topic and reply threads of ordinary groups are no topics.
		$this->forum( [ 'text' => 'general' ] );
		$this->forum( [ 'message_thread_id' => 99, 'text' => 'reply' ] );
		$this->assertCount( 3, ChatRepository::topics( 'telegram', Fixtures::CHAT ) );
	}

	public function test_channel_post(): void {
		$this->message( [ 'chat' => [ 'id' => -1007, 'type' => 'channel', 'title' => 'News', 'username' => 'my_news' ], 'text' => 'hello' ], 'telegram', 'channel_post' );
		$this->assertSame( [ 'channel', 'my_news' ], [ $this->row( '-1007' )['type'], $this->row( '-1007' )['username'] ] );
	}

	public function test_membership(): void {
		$change = function ( string $status, array $more = [], string $type = 'supergroup', int $id = -1009 ): array {
			ChatDiscovery::handle( 'telegram', [ 'update_id' => 1, 'my_chat_member' => [ 'chat' => [ 'id' => $id, 'type' => $type, 'title' => 'Team' ], 'new_chat_member' => [ 'status' => $status ] + $more ] ] );
			return $this->row( (string) $id );
		};
		$row = $change( 'administrator' );
		$this->assertSame( [ 'active', 'administrator' ], [ $row['status'], $row['bot_role'] ] );
		$this->assertSame( 'member', $change( 'member' )['bot_role'] );
		$this->assertSame( 'left', $change( 'restricted', [ 'is_member' => false ] )['status'] );
		$this->assertSame( 'active', $change( 'restricted', [ 'is_member' => true ] )['status'] );
		$row = $change( 'kicked' );
		$this->assertSame( [ 'kicked', '' ], [ $row['status'], $row['bot_role'] ] );
		$this->assertSame( 'left', $change( 'left' )['status'] );
		$this->assertSame( 'left', $change( 'nonsense' )['status'] );
		$this->assertSame( 'kicked', $change( 'kicked', [], 'private', 77 )['status'], 'blocked by a person' );
		$this->assertSame( '77', $this->row( '77' )['user_id'] );
	}

	public function test_bale_membership(): void {
		$this->group( [ 'new_chat_members' => [ [ 'id' => 9 ], [ 'id' => 123456, 'is_bot' => true ] ] ], 'bale' );
		$this->assertSame( [ 'active', 'member' ], [ $this->row( '-500', 0, 'bale' )['status'], $this->row( '-500', 0, 'bale' )['bot_role'] ] );
		$this->group( [ 'left_chat_member' => [ 'id' => 9 ] ], 'bale' );
		$this->assertSame( 'active', $this->row( '-500', 0, 'bale' )['status'] );
		$this->group( [ 'left_chat_member' => [ 'id' => 123456 ] ], 'bale' );
		$this->assertSame( 'left', $this->row( '-500', 0, 'bale' )['status'] );
	}

	public function test_bale_topics_only_in_private_chats(): void {
		$this->message( [ 'message_thread_id' => 8, 'chat' => [ 'id' => 70, 'type' => 'private', 'first_name' => 'S' ], 'from' => [ 'id' => 70 ] ], 'bale' );
		$this->assertNotNull( ChatRepository::find_chat( 'bale', '70', 8 ) );
		$this->message( [ 'message_thread_id' => 8, 'chat' => [ 'id' => 71, 'type' => 'group', 'title' => 'S' ], 'from' => [ 'id' => 70 ] ], 'bale' );
		$this->assertNull( ChatRepository::find_chat( 'bale', '71', 8 ) );
	}

	public function test_migration(): void {
		$this->group();
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( '-500' ) ] );
		$this->message( [ 'chat' => [ 'id' => -500, 'type' => 'group', 'title' => 'Sales' ], 'migrate_to_chat_id' => -1005 ] );
		$this->assertSame( 'migrated', $this->row( '-500' )['status'] );
		$this->assertSame( 'active', $this->row( '-1005' )['status'] );
		$this->assertSame( '-1005', Settings::arr( 'channels.telegram.receivers' )[0]['address'] );
		// The second message of the pair changes nothing more.
		$this->message( [ 'chat' => [ 'id' => -1005, 'type' => 'supergroup', 'title' => 'Sales' ], 'migrate_from_chat_id' => -500 ] );
		$this->assertSame( 'active', $this->row( '-1005' )['status'] );
		$this->assertSame( 2, ChatRepository::query( [] )['total'] );
	}

	public function test_is_receiver(): void {
		$this->assertFalse( ChatDiscovery::is_receiver( 'telegram', '-500' ) );
		Settings::set( 'channels.telegram.receivers', [ Fixtures::receiver( '-500/3' ), Fixtures::receiver( '@My_Group' ), Fixtures::receiver( '-600', [ 'enabled' => false ] ) ] );
		$this->assertTrue( ChatDiscovery::is_receiver( 'telegram', '-500' ) );
		$this->assertTrue( ChatDiscovery::is_receiver( 'telegram', '-1', 'my_group' ) );
		$this->assertFalse( ChatDiscovery::is_receiver( 'telegram', '-600' ) );
		$this->assertFalse( ChatDiscovery::is_receiver( 'bale', '-500' ) );
		$this->assertFalse( ChatDiscovery::is_receiver( 'telegram', '-50' ) );
		$form = FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'Contact' ] );
		FormRepository::save_config( $form, [ 'rules' => [ [ 'effect' => 'add', 'receivers' => [ 'telegram' => [ Fixtures::receiver( '-900' ) ] ] ] ], 'channels' => [ 'bale' => [ 'receivers' => [ Fixtures::receiver( '-901' ) ] ] ] ] );
		$this->assertTrue( ChatDiscovery::is_receiver( 'telegram', '-900' ) );
		$this->assertFalse( ChatDiscovery::is_receiver( 'bale', '-900' ) );
		$this->assertTrue( ChatDiscovery::is_receiver( 'bale', '-901' ) );
	}

	public function test_receiver_options(): void {
		ChatDiscovery::handle( 'telegram', Fixtures::update_start() );
		ChatDiscovery::handle( 'telegram', Fixtures::update_topic() );
		$this->group();
		$this->message( [ 'chat' => [ 'id' => -500, 'type' => 'group', 'title' => 'Sales' ], 'left_chat_member' => [ 'id' => 123456 ] ] );
		$action = Registry::channel( 'telegram' )->actions()['receiver_options'];
		$rows   = $action( [] )['rows'];
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertKeys( [ 'address', 'label', 'group', 'hint', 'meta' ], $row );
		}
		$addresses = array_column( $rows, 'address' );
		$this->assertContains( Fixtures::CHAT . '/45', $addresses );
		$this->assertSame( array_search( Fixtures::CHAT, $addresses, true ) + 1, array_search( Fixtures::CHAT . '/45', $addresses, true ), 'topics follow their forum' );
		$this->assertSame( [ '111111' ], array_column( $action( [ 'type' => 'private' ] )['rows'], 'address' ) );
		$this->assertSame( [ Fixtures::CHAT . '/45' ], array_column( $action( [ 'search' => 'Leads' ] )['rows'], 'address' ) );
		$this->assertSame( [], $action( [ 'search' => 'nothing' ] )['rows'] );
	}
}
