<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Channels\Simple;

use AmirhpCom\FormsNotifier\Channels\Chat;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\HttpMock;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class ChatTest extends TestCase {

	const URLS = [
		'slack'      => 'https://hooks.slack.com/services/T000/B000/TESTTOKEN',
		'discord'    => 'https://discord.com/api/webhooks/123456/TEST-token_x',
		'googlechat' => 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=K&token=T',
		'teams'      => 'https://prod-1.westeurope.logic.example.com/workflows/abc/triggers/manual/paths/invoke?sig=TEST',
		'mattermost' => 'https://chat.example.com/hooks/abcdefghijklmnopqrstuvwxyz',
		'rocketchat' => 'https://rocket.example.com/hooks/abc123/def456',
	];

	public function setUp(): void {
		HttpMock::reset();
	}

	public function tearDown(): void {
		Settings::clear_overlay();
		wp_set_current_user( 0 );
	}

	private function message( string $id, string $body = '<b>Hi</b> there', bool $buttons = true ): Message {
		$channel    = Registry::channel( $id );
		$m          = new Message( $id, new Recipient( $id, self::URLS[ $id ] ) );
		$m->format  = $channel->format();
		$m->lang    = 'en';
		$m->body    = Registry::formatter( $m->format )->convert( $body );
		$m->plain   = wp_strip_all_tags( $body );
		$m->buttons = $buttons ? [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com/s?a=1&b=2' ], [ 'text' => 'Read', 'type' => 'callback', 'value' => 'read' ] ], [ [ 'text' => 'Bad', 'type' => 'url', 'value' => 'javascript:alert(1)' ] ] ] : [];
		return $m;
	}

	public function test_addresses(): void {
		foreach ( self::URLS as $id => $url ) {
			$channel = Registry::channel( $id );
			$this->assertSame( 'secret', $channel->capabilities()['receiver_kind'], $id );
			$this->assertSame( $url, $channel->normalize_address( ' ' . $url . ' ' ), $id );
			foreach ( [ '', 'x', 'https://evil.example/', 'javascript:alert(1)', str_replace( 'https://', 'https://u:p@', $url ), 'ftp://example.com/hooks/a/b' ] as $bad ) {
				if ( $id === 'teams' && $bad === 'https://evil.example/' ) {
					continue;
				}
				$this->assertSame( '', $channel->normalize_address( $bad ), $id . ' ' . $bad );
			}
		}
		$this->assertSame( '', Registry::channel( 'teams' )->normalize_address( 'https://x.webhook.office.com/webhookb2/abc' ) );
		$this->assertSame( '', Registry::channel( 'slack' )->normalize_address( 'http://hooks.slack.com/services/T/B/x' ) );
	}

	public function test_slack_payload(): void {
		HttpMock::expect( 'POST', '~hooks\.slack~', 'ok' );
		$result = ( new Chat\SlackChannel() )->send( $this->message( 'slack' ) );
		$this->assertTrue( $result->ok );
		$this->assertSame(
			[
				'text'         => '*Hi* there',
				'blocks'       => [
					[ 'type' => 'section', 'text' => [ 'type' => 'mrkdwn', 'text' => '*Hi* there' ] ],
					[ 'type' => 'actions', 'elements' => [ [ 'type' => 'button', 'text' => [ 'type' => 'plain_text', 'text' => 'Open' ], 'url' => 'https://example.com/s?a=1&b=2', 'action_id' => 'fnfe_0' ] ] ],
				],
				'unfurl_links' => false,
			],
			HttpMock::last()['json']
		);
		$this->assertSame( 0, HttpMock::last()['args']['redirection'] );
	}

	public function test_slack_sections_and_errors(): void {
		HttpMock::expect( 'POST', '~.~', 'ok' );
		( new Chat\SlackChannel() )->send( $this->message( 'slack', str_repeat( 'word ', 1500 ), false ) );
		$blocks = HttpMock::last()['json']['blocks'];
		$this->assertTrue( count( $blocks ) >= 3 );
		foreach ( $blocks as $block ) {
			$this->assertTrue( mb_strlen( $block['text']['text'] ) <= 3000 );
		}
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', 'no_service', 404 );
		$result = ( new Chat\SlackChannel() )->send( $this->message( 'slack' ) );
		$this->assertTrue( $result->permanent );
		$this->assertContains( 'no_service', $result->error );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', '', 429, [ 'retry-after' => '3' ] );
		$result = ( new Chat\SlackChannel() )->send( $this->message( 'slack' ) );
		$this->assertSame( [ false, 3 ], [ $result->permanent, $result->retry_after ] );
	}

	public function test_discord_payload(): void {
		HttpMock::expect( 'POST', '~wait=true$~', [ 'id' => '99' ] );
		Settings::overlay( [ 'channels.discord.username' => 'Forms' ] );
		$result = ( new Chat\DiscordChannel() )->send( $this->message( 'discord' ) );
		$this->assertSame( '99', $result->remote_id );
		$this->assertSame(
			[ 'content' => "**Hi** there\n\n[Open](https://example.com/s?a=1&b=2)", 'allowed_mentions' => [ 'parse' => [] ], 'username' => 'Forms', 'flags' => 4 ],
			HttpMock::last()['json']
		);
		$m                     = $this->message( 'discord' );
		$m->recipient->address = self::URLS['discord'] . '?thread_id=5';
		( new Chat\DiscordChannel() )->send( $m );
		$this->assertContains( '?thread_id=5&wait=true', HttpMock::last()['url'] );
	}

	public function test_discord_split_files_and_errors(): void {
		HttpMock::queue( 'POST', '~.~', [ [ 'id' => '1' ], [ 'id' => '2' ], [ 'id' => '3' ] ] );
		$m              = $this->message( 'discord', str_repeat( 'word ', 900 ), false );
		$m->attachments = [ Fixtures::file( 'a.pdf', 'AAA' ), Fixtures::file( 'b.png', 'BBB' ) ];
		$result         = ( new Chat\DiscordChannel() )->send( $m );
		$this->assertSame( '1,2,3', $result->remote_id );
		$requests = HttpMock::requests();
		foreach ( $requests as $i => $request ) {
			$content = $i < 2 ? $request['json']['content'] : json_decode( $request['parts']['payload_json']['value'], true )['content'];
			$this->assertTrue( mb_strlen( $content ) <= 2000 );
		}
		$this->assertSame( 'AAA', $requests[2]['parts']['files[0]']['value'] );
		$this->assertSame( 'b.png', $requests[2]['parts']['files[1]']['filename'] );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'message' => 'You are being rate limited.', 'retry_after' => 1.3 ], 429 );
		$result = ( new Chat\DiscordChannel() )->send( $this->message( 'discord' ) );
		$this->assertSame( [ false, 2 ], [ $result->permanent, $result->retry_after ] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'message' => 'Unknown Webhook', 'code' => 10015 ], 404 );
		$result = ( new Chat\DiscordChannel() )->send( $this->message( 'discord' ) );
		$this->assertTrue( $result->permanent );
		$this->assertContains( 'Unknown Webhook', $result->error );
	}

	public function test_google_chat_payload(): void {
		HttpMock::expect( 'POST', '~chat\.googleapis~', [ 'name' => 'spaces/AAA/messages/1' ] );
		$result = ( new Chat\GoogleChatChannel() )->send( $this->message( 'googlechat', '<b>Hi</b> &lt;users/all&gt; &lt;https://evil.example|x&gt;' ) );
		$this->assertSame( 'spaces/AAA/messages/1', $result->remote_id );
		$json = HttpMock::last()['json'];
		$this->assertSame( "*Hi* <\u{200B}users/all> <\u{200B}https://evil.example|x>", $json['text'] );
		$this->assertSame( [ [ 'text' => 'Open', 'onClick' => [ 'openLink' => [ 'url' => 'https://example.com/s?a=1&b=2' ] ] ] ], $json['cardsV2'][0]['card']['sections'][0]['widgets'][0]['buttonList']['buttons'] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', '<html>403</html>', 403 );
		$this->assertTrue( ( new Chat\GoogleChatChannel() )->send( $this->message( 'googlechat' ) )->permanent );
	}

	public function test_teams_payload(): void {
		HttpMock::expect( 'POST', '~.~', '', 202 );
		$m       = $this->message( 'teams', "<b>Hi</b>\nthere\n\nend" );
		$m->lang = 'fa';
		$result  = ( new Chat\TeamsChannel() )->send( $m );
		$this->assertTrue( $result->ok );
		$json = HttpMock::last()['json'];
		$this->assertSame( 'message', $json['type'] );
		$card = $json['attachments'][0];
		$this->assertSame( 'application/vnd.microsoft.card.adaptive', $card['contentType'] );
		$this->assertSame( '1.4', $card['content']['version'] );
		$this->assertTrue( $card['content']['rtl'] );
		$this->assertSame(
			[
				[ 'type' => 'TextBlock', 'text' => '**Hi**', 'wrap' => true, 'spacing' => 'None' ],
				[ 'type' => 'TextBlock', 'text' => 'there', 'wrap' => true, 'spacing' => 'None' ],
				[ 'type' => 'TextBlock', 'text' => 'end', 'wrap' => true, 'spacing' => 'Medium' ],
			],
			$card['content']['body']
		);
		$this->assertSame( [ [ 'type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => 'https://example.com/s?a=1&b=2' ] ], $card['content']['actions'] );
		( new Chat\TeamsChannel() )->send( $this->message( 'teams', str_repeat( "سطر فارسی نسبتا بلند\n", 500 ) ) );
		foreach ( HttpMock::requests() as $request ) {
			$this->assertTrue( strlen( $request['body'] ) < 28 * 1024, (string) strlen( $request['body'] ) );
		}
	}

	public function test_mattermost_and_rocketchat(): void {
		Settings::overlay( [ 'channels.mattermost.username' => 'Forms', 'channels.mattermost.icon_url' => 'https://example.com/i.png' ] );
		HttpMock::expect( 'POST', '~chat\.example~', 'ok' );
		$this->assertTrue( ( new Chat\MattermostChannel() )->send( $this->message( 'mattermost' ) )->ok );
		$this->assertSame( [ 'text' => "**Hi** there\n\n[Open](https://example.com/s?a=1&b=2)", 'username' => 'Forms', 'icon_url' => 'https://example.com/i.png' ], HttpMock::last()['json'] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'x', 'message' => 'Invalid webhook' ], 400 );
		$result = ( new Chat\MattermostChannel() )->send( $this->message( 'mattermost' ) );
		$this->assertContains( 'Invalid webhook', $result->error );

		HttpMock::reset();
		HttpMock::expect( 'POST', '~rocket~', [ 'success' => true ] );
		$this->assertTrue( ( new Chat\RocketChatChannel() )->send( $this->message( 'rocketchat', 'Hi', false ) )->ok );
		$this->assertSame( [ 'text' => 'Hi' ], HttpMock::last()['json'] );
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'success' => false, 'error' => 'invalid-channel' ] );
		$result = ( new Chat\RocketChatChannel() )->send( $this->message( 'rocketchat' ) );
		$this->assertSame( [ false, true, 'invalid-channel' ], [ $result->ok, $result->permanent, $result->error ] );
	}

	public function test_every_chat_maps_errors_and_never_throws(): void {
		foreach ( array_keys( self::URLS ) as $id ) {
			$channel = Registry::channel( $id );
			foreach ( [ 500 => false, 503 => false, 429 => false, 400 => true, 401 => true, 404 => true ] as $code => $permanent ) {
				HttpMock::reset();
				HttpMock::expect( 'POST', '~.~', 'x', $code );
				$result = $channel->send( $this->message( $id ) );
				$this->assertSame( [ false, $permanent ], [ $result->ok, $result->permanent ], "$id $code" );
			}
			HttpMock::reset();
			HttpMock::expect( 'POST', '~.~', new \WP_Error( 'http', 'cURL error for ' . self::URLS[ $id ] ) );
			$result = $channel->send( $this->message( $id ) );
			$this->assertFalse( $result->permanent );
			$this->assertNotContains( self::URLS[ $id ], $result->error . $result->response, $id );
			HttpMock::reset();
			$m = $this->message( $id, '', false );
			$this->assertTrue( $channel->send( $m )->permanent, $id );
			$m->recipient->address = 'secret:r_unknown';
			$m->body               = 'x';
			$this->assertTrue( $channel->send( $m )->permanent, $id );
			HttpMock::assertNoRequests();
		}
	}

	public function test_address_lives_in_the_secret_store(): void {
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		foreach ( self::URLS as $id => $url ) {
			$save = new \WP_REST_Request( 'POST', '/fnfe/v1/settings' );
			$save->set_header( 'content-type', 'application/json' );
			$save->set_body( wp_json_encode( [ 'values' => [ "channels.$id.enabled" => true, "channels.$id.receivers" => [ [ 'id' => 'r_1', 'address' => $url, 'label' => 'Room' ] ] ] ] ) );
			$data = rest_do_request( $save )->get_data();
			$this->assertTrue( $data['ok'], $id );
			$this->assertNotContains( $url, wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
			$this->assertNotContains( $url, wp_json_encode( get_option( 'fnfe_settings' ), JSON_UNESCAPED_SLASHES ) );
			$this->assertTrue( Registry::channel( $id )->is_ready(), $id );
			HttpMock::expect( 'POST', '~^' . preg_quote( $url, '~' ) . '~', [ 'ok' => true ] );
			$result = rest_do_request( new \WP_REST_Request( 'POST', "/fnfe/v1/channels/$id/test" ) )->get_data();
			$this->assertTrue( $result['ok'], $id . ' ' . $result['message'] );
			$this->assertNotContains( $url, Logger::clean( "x $url" ) );
		}
	}


	// Review B, I4 and M11.

	public function test_a_failed_part_is_not_followed_by_the_first_one_again(): void {
		$m                     = $this->message( 'discord', implode( ' ', range( 1000, 1800 ) ), false );
		$m->meta['message_id'] = 4242;
		HttpMock::queue( 'POST', '~.~', [ [ 'id' => '1' ], [ 'code' => 429, 'body' => [ 'message' => 'slow down', 'retry_after' => 1 ] ] ] );
		$first = ( new Chat\DiscordChannel() )->send( $m );
		$this->assertSame( [ false, 1 ], [ $first->ok, $first->meta['parts_sent'] ] );
		$posted = array_map( function ( $r ) { return $r['json']['content']; }, HttpMock::requests() );
		HttpMock::reset();
		HttpMock::queue( 'POST', '~.~', [ [ 'id' => '2' ], [ 'id' => '3' ] ] );
		$second = ( new Chat\DiscordChannel() )->send( $m );
		$this->assertTrue( $second->ok );
		$this->assertSame( '1,2,3', $second->remote_id );
		$again = array_map( function ( $r ) { return $r['json']['content']; }, HttpMock::requests() );
		$this->assertCount( 2, $again );
		$this->assertSame( $posted[1], $again[0], 'goes on with the part that failed' );
		$this->assertFalse( in_array( $posted[0], $again, true ), 'the first part is not posted twice' );
		// Finished: a new attempt of another row starts from the beginning.
		$this->assertFalse( get_transient( 'fnfe_parts_' . md5( 'discord|4242|' . $m->recipient->address ) ) );
	}

	public function test_discord_files_over_the_limit_become_links(): void {
		HttpMock::expect( 'POST', '~.~', [ 'id' => '1' ] );
		$m              = $this->message( 'discord', 'Hi', false );
		$small          = Fixtures::file( 'a.pdf', 'AAA' );
		$big            = Fixtures::file( 'big.pdf', str_repeat( 'x', 21 * MB_IN_BYTES ) );
		$big->url       = 'https://example.com/big.pdf';
		$lost           = Fixtures::file( 'lost.pdf', str_repeat( 'y', 21 * MB_IN_BYTES ) );
		$m->attachments = [ $small, $big, $lost ];
		$result         = ( new Chat\DiscordChannel() )->send( $m );
		unlink( $big->path );
		unlink( $lost->path );
		$request = HttpMock::last();
		$this->assertSame( 'AAA', $request['parts']['files[0]']['value'] );
		$this->assertFalse( isset( $request['parts']['files[1]'] ) );
		$this->assertContains( 'https://example.com/big.pdf', json_decode( $request['parts']['payload_json']['value'], true )['content'] );
		$this->assertSame( [ 'lost.pdf' ], $result->meta['files_failed'] );
		$this->assertSame( [], $m->links, 'the caller\'s message is unchanged' );
	}

	public function test_ntfy_goes_on_after_the_delivered_parts(): void {
		Settings::overlay( [ 'channels.ntfy.server' => 'https://ntfy.example.com' ] );
		$m                     = new Message( 'ntfy', new Recipient( 'ntfy', 'alerts_topic' ) );
		$m->format             = 'markdown';
		$m->body               = implode( ' سلام ', range( 1000, 2400 ) );
		$m->meta['message_id'] = 77;
		HttpMock::queue( 'POST', '~.~', [ [ 'id' => 'a' ], [ 'code' => 500, 'body' => 'down' ] ] );
		$this->assertFalse( Registry::channel( 'ntfy' )->send( $m )->ok );
		$first = HttpMock::requests()[0]['json']['message'];
		HttpMock::reset();
		HttpMock::expect( 'POST', '~.~', [ 'id' => 'b' ] );
		$this->assertTrue( Registry::channel( 'ntfy' )->send( $m )->ok );
		$again = array_map( function ( $r ) { return $r['json']['message']; }, HttpMock::requests() );
		$this->assertTrue( count( $again ) >= 1 );
		$this->assertFalse( in_array( $first, $again, true ), 'the first part is not sent again' );
	}
}
