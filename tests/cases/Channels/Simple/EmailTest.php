<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Channels\Simple;

use AmirhpCom\FormsNotifier\Channels\Email\EmailChannel;
use AmirhpCom\FormsNotifier\Channels\Email\EmailLayout;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Template\Extras;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class EmailTest extends TestCase {

	private array $mails = [];

	public function tearDown(): void {
		Settings::clear_overlay();
	}

	private function intercept( bool $result = true ): void {
		$this->filter( 'pre_wp_mail', function ( $pre, $atts ) use ( $result ) {
			$this->mails[] = $atts;
			if ( ! $result ) {
				do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'SMTP says no' ) );
			}
			return $result;
		}, 10, 2 );
	}

	private function message( string $to = 'team@example.com' ): Message {
		$m          = new Message( 'email', new Recipient( 'email', $to ) );
		$m->format  = 'email_html';
		$m->lang    = 'en';
		$m->subject = "Sub\r\nBcc: x@example.com";
		$m->body    = '<b>Hi</b>';
		$m->plain   = 'Hi';
		return $m;
	}

	public function test_capabilities_and_addresses(): void {
		$channel = new EmailChannel();
		$this->assertSame( 'email', $channel->capabilities()['receiver_kind'] );
		$this->assertTrue( $channel->capabilities()['submitter'] );
		$this->assertSame( 'a@example.com', $channel->normalize_address( ' a@example.com ' ) );
		foreach ( [ '', 'x', 'a@b', "a@example.com\nBcc: b@example.com", 'a@example.com,b@example.com', 'Name <a@example.com>' ] as $bad ) {
			$this->assertSame( '', $channel->normalize_address( $bad ), $bad );
		}
	}

	public function test_one_message_per_receiver_with_clean_headers(): void {
		$this->intercept();
		Settings::overlay( [ 'channels.email.from_name' => "Shop \"A\" <x>\r\nBcc: y@example.com", 'channels.email.from_email' => 'shop@example.com' ] );
		$m                         = $this->message();
		$m->meta['reply_to_email'] = 'sara@example.com';
		$result                    = ( new EmailChannel() )->send( $m );
		$this->assertTrue( $result->ok );
		$this->assertTrue( $result->response !== '' );
		$mail = $this->mails[0];
		$this->assertSame( 'team@example.com', $mail['to'] );
		$this->assertSame( 'Sub Bcc: x@example.com', $mail['subject'] );
		$this->assertSame( [ 'Content-Type: text/html; charset=UTF-8', 'From: Shop A x Bcc: y@example.com <shop@example.com>', 'Reply-To: sara@example.com' ], $mail['headers'] );
		$this->assertContains( '<b>Hi</b>', $mail['message'] );
		Settings::overlay( [ 'channels.email.reply_to_submitter' => false ] );
		( new EmailChannel() )->send( $m );
		$this->assertNotContains( 'Reply-To', implode( ' ', $this->mails[1]['headers'] ) );
		$m->purpose = 'submitter';
		Settings::overlay( [ 'channels.email.reply_to_submitter' => true ] );
		( new EmailChannel() )->send( $m );
		$this->assertNotContains( 'Reply-To', implode( ' ', $this->mails[2]['headers'] ) );
	}

	public function test_failure_is_captured_and_hooks_are_removed(): void {
		$this->intercept( false );
		$before = [ has_action( 'wp_mail_failed' ), has_action( 'phpmailer_init' ) ];
		$count  = count( $GLOBALS['wp_filter']['phpmailer_init']->callbacks[ PHP_INT_MAX ] ?? [] );
		$result = ( new EmailChannel() )->send( $this->message() );
		$this->assertFalse( $result->ok );
		$this->assertFalse( $result->permanent );
		$this->assertSame( 'SMTP says no', $result->error );
		$this->assertSame( $before, [ has_action( 'wp_mail_failed' ), has_action( 'phpmailer_init' ) ] );
		$this->assertSame( $count, count( $GLOBALS['wp_filter']['phpmailer_init']->callbacks[ PHP_INT_MAX ] ?? [] ) );
		$bad = ( new EmailChannel() )->send( $this->message( 'nope' ) );
		$this->assertTrue( $bad->permanent );
	}

	public function test_plain_text_alternative_reaches_the_mailer(): void {
		// A mailer that cannot connect: what it was going to send is inspected afterwards.
		$this->filter( 'phpmailer_init', static function ( $mailer ) {
			$mailer->Mailer  = 'smtp';
			$mailer->Host    = '127.0.0.1';
			$mailer->Port    = 1;
			$mailer->Timeout = 1;
		} );
		Settings::overlay( [ 'channels.email.from_email' => 'shop@example.com' ] );
		$m        = $this->message();
		$m->plain = 'متن ساده';
		$result   = ( new EmailChannel() )->send( $m );
		$this->assertFalse( $result->ok );
		$this->assertTrue( $result->error !== '' );
		$mailer = $GLOBALS['phpmailer'];
		$this->assertSame( [ 'متن ساده', 'UTF-8', 'shop@example.com' ], [ $mailer->AltBody, $mailer->CharSet, $mailer->From ] );
		$this->assertContains( 'multipart/alternative', $mailer->getSentMIMEMessage() );
	}

	public function test_alt_body_is_set_by_the_scoped_hook(): void {
		$seen = [];
		$this->intercept();
		$this->filter( 'pre_wp_mail', static function ( $pre ) use ( &$seen ) {
			$mailer          = new class() { public $AltBody = ''; };
			foreach ( $GLOBALS['wp_filter']['phpmailer_init']->callbacks[ PHP_INT_MAX ] as $hook ) {
				$hook['function']( $mailer );
			}
			$seen[] = $mailer->AltBody;
			return $pre;
		}, 20 );
		$m        = $this->message();
		$m->plain = 'متن ساده';
		( new EmailChannel() )->send( $m );
		$this->assertSame( [ 'متن ساده' ], $seen );
	}

	public function test_attachments_and_total_limit(): void {
		$this->intercept();
		$m              = $this->message();
		$a              = Fixtures::file( 'a.pdf', 'one' );
		$b              = Fixtures::file( 'a.pdf', 'two' );
		$big            = Fixtures::file( 'big.pdf', str_repeat( 'x', 15 * MB_IN_BYTES ) );
		$big->url       = 'https://example.com/big.pdf';
		$gone           = Fixtures::file( 'gone.pdf', 'x' );
		unlink( $gone->path );
		$m->attachments = [ $a, $b, $big, $gone ];
		( new EmailChannel() )->send( $m );
		$this->assertSame( [ 'a.pdf' => $a->path, '2-a.pdf' => $b->path ], $this->mails[0]['attachments'] );
		$this->assertContains( 'https://example.com/big.pdf', $this->mails[0]['message'] );
	}

	public function test_reply_address_is_attached_when_the_message_is_built(): void {
		$delivery           = new Delivery( 'email', new Recipient( 'email', 'team@example.com' ) );
		$delivery->template = 'x';
		$message            = Dispatcher::build( $delivery, Fixtures::submission(), Fixtures::config() );
		$this->assertSame( Fixtures::EMAIL, $message->meta['reply_to_email'] );
	}

	public function test_layout(): void {
		$html = EmailLayout::wrap( '<b>x</b>', [ 'layout' => 'branded', 'subject' => '<s>', 'site' => 'A & <B>', 'url' => 'https://example.com/', 'lang' => 'fa', 'rtl' => true, 'buttons' => [ [ 'text' => '<go>', 'value' => 'https://example.com/?a="b"' ] ] ] );
		$this->assertContains( 'dir="rtl"', $html );
		$this->assertContains( 'text-align:right', $html );
		$this->assertContains( 'A &amp; &lt;B&gt;', $html );
		$this->assertContains( '&lt;go&gt;', $html );
		$this->assertNotContains( '<img', $html );
		$this->assertNotContains( '<go>', $html );
		$plain = EmailLayout::wrap( 'x', [ 'layout' => 'plain', 'rtl' => false ] );
		$this->assertNotContains( '<table', $plain );
		$this->assertContains( 'dir="ltr"', $plain );
	}

	public function test_links_are_not_shown_twice(): void {
		$m        = $this->message();
		$m->links = [ [ 'name' => 'cv.pdf', 'url' => rest_url( 'fnfe/v1/file/7' ) . '?t=aaa&e=2', 'size' => 1 ], [ 'name' => 'b.pdf', 'url' => rest_url( 'fnfe/v1/file/77' ) . '?t=bbb&e=2', 'size' => 1 ] ];
		$m->body  = '<a href="' . esc_html( rest_url( 'fnfe/v1/file/7' ) . '?t=zzz&e=1' ) . '">cv.pdf</a>';
		$this->assertSame( [ 'b.pdf' ], array_column( Extras::missing_links( $m->body, $m->links ), 'name' ) );
		$this->assertContains( '>b.pdf</a>', Extras::compose( $m ) );
		$m->body = '';
		$this->assertCount( 2, Extras::missing_links( '', $m->links ) );
	}

	public function test_channel_test_action(): void {
		$this->intercept();
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$request = new \WP_REST_Request( 'POST', '/fnfe/v1/channels/email/test' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'to' => 'me@example.com', 'values' => [ 'channels.email.layout' => 'plain' ] ] ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertNotContains( '<table', $this->mails[0]['message'] );
		wp_set_current_user( 0 );
	}
}
