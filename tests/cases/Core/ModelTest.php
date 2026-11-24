<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class ModelTest extends TestCase {

	const PURE = true;

	private function json( array $data ): array {
		return json_decode( json_encode( $data ), true );
	}

	public function test_attachment_round_trip(): void {
		$file           = new Attachment();
		$file->id       = 7;
		$file->field_id = 'cv';
		$file->name     = 'cv.PDF';
		$file->path     = '/x/cv.pdf';
		$file->mime     = 'application/pdf';
		$file->size     = 12;
		$file->ext      = 'pdf';
		$file->stored   = true;
		$this->assertSame( $file->to_array(), Attachment::from_array( $this->json( $file->to_array() ) )->to_array() );
		$this->assertFalse( $file->exists() );
		$this->assertFalse( $file->is_image() );
	}

	public function test_attachment_normalises(): void {
		$file = Attachment::from_array( [ 'name' => 'Photo.JPG', 'mime' => 'image/jpeg', 'url' => 'attached', 'junk' => 1 ] );
		$this->assertSame( 'jpg', $file->ext );
		$this->assertSame( '', $file->url );
		$this->assertTrue( $file->is_image() );
		$this->assertFalse( Attachment::from_array( [ 'name' => 'x.svg', 'mime' => 'image/svg+xml' ] )->is_image() );
		$this->assertTrue( Attachment::from_array( [ 'path' => __FILE__ ] )->exists() );
	}

	public function test_submission_round_trip(): void {
		$s          = Fixtures::submission();
		$s->files[] = Attachment::from_array( [ 'field_id' => 'cv', 'name' => 'a.pdf' ] );
		$s->files[] = Attachment::from_array( [ 'field_id' => 'photo', 'name' => 'b.png' ] );
		$copy       = Submission::from_array( $this->json( $s->to_array() ) );
		$this->assertSame( $s->to_array(), $copy->to_array() );
		$this->assertSame( [ 'name', 'email', 'phone', 'topics', 'message', 'empty' ], array_keys( $copy->fields ) );
	}

	public function test_submission_accessors(): void {
		$s          = Fixtures::submission();
		$s->files[] = Attachment::from_array( [ 'field_id' => 'cv', 'name' => 'a.pdf' ] );
		$this->assertSame( 'سارا 🙂', $s->value( 'name' ) );
		$this->assertSame( 'x', $s->value( 'empty', 'x' ) );
		$this->assertSame( 'x', $s->value( 'missing', 'x' ) );
		$this->assertNull( $s->field( 'missing' ) );
		$this->assertSame( 'email', $s->first_of_type( 'email' )['id'] );
		$this->assertNull( $s->first_of_type( 'url' ) );
		$this->assertCount( 1, $s->files_for( 'cv' ) );
		$this->assertCount( 0, $s->files_for( 'name' ) );
	}

	public function test_submission_completes_fields(): void {
		$s = Submission::from_array( [ 'fields' => [ 'a' => [ 'value' => 'x' ], 'bad' => 'string' ] ] );
		$this->assertSame( [ 'a' ], array_keys( $s->fields ) );
		$this->assertKeys( [ 'id', 'type', 'label', 'value', 'values', 'raw', 'is_file', 'redacted', 'hidden' ], $s->fields['a'] );
		$this->assertSame( 'a', $s->fields['a']['label'] );
	}

	public function test_recipient_round_trip(): void {
		$r       = new Recipient( 'telegram', Fixtures::CHAT . '/45', 'Sales', 'alert' );
		$r->lang = 'fa';
		$r->meta = [ 'rid' => 'r_1', 'thread' => 45 ];
		$this->assertSame( $r->to_array(), Recipient::from_array( $this->json( $r->to_array() ) )->to_array() );
	}

	public function test_delivery_defaults(): void {
		$d = new Delivery( 'sms', new Recipient( 'sms', Fixtures::PHONE ), 'submitter' );
		$this->assertSame( 'submitter', $d->purpose );
		$this->assertSame( '', $d->rule_id );
		$this->assertSame( [], $d->buttons );
	}

	public function test_message_round_trip(): void {
		$m                = Fixtures::message( 'telegram', Fixtures::CHAT, '<b>سلام</b> 🙂' );
		$m->purpose       = 'submitter';
		$m->lang          = 'fa';
		$m->format        = 'telegram_html';
		$m->subject       = 'S';
		$m->pattern       = [ 'code' => '590428', 'vars' => [ 'a' => '1', 'b' => '2' ], 'lang' => '', 'codes' => [] ];
		$m->buttons       = [ [ [ 'text' => 'Open', 'type' => 'url', 'value' => 'https://example.com', 'style' => '' ] ] ];
		$m->attachments[] = Attachment::from_array( [ 'id' => 3, 'name' => 'a.pdf', 'path' => '/x' ] );
		$m->links         = [ [ 'name' => 'a.pdf', 'url' => 'https://example.com/a', 'size' => 5 ] ];
		$m->options       = [ 'silent' => true ];
		$m->meta          = [ 'submission_id' => 9, 'test' => false ];
		$m->data          = [ 'event' => 'x' ];
		$copy             = Message::from_array( $this->json( $m->to_array() ) );
		$this->assertSame( $m->to_array(), $copy->to_array() );
		$this->assertInstanceOf( Attachment::class, $copy->attachments[0] );
		$this->assertInstanceOf( Recipient::class, $copy->recipient );
	}

	public function test_message_from_junk(): void {
		$m = Message::from_array( [ 'channel' => 'sms', 'body' => [ 'x' ], 'buttons' => 'no', 'recipient' => 'no', 'unknown' => 1 ] );
		$this->assertSame( '', $m->body );
		$this->assertSame( [], $m->buttons );
		$this->assertSame( 'sms', $m->recipient->channel );
	}

	public function test_send_result(): void {
		$ok = SendResult::success( 'telegram', 'ok', '12,13' )->with_meta( [ 'parts' => 2 ] )->with_meta( [ 'x' => 1 ] );
		$this->assertTrue( $ok->ok );
		$this->assertSame( [ 'parts' => 2, 'x' => 1 ], $ok->meta );
		$this->assertSame( '12,13', $ok->to_array()['remote_id'] );
		$bad = SendResult::failure( 'sms', 'no credit', true, str_repeat( 'x', 5000 ), 402 );
		$this->assertFalse( $bad->ok );
		$this->assertTrue( $bad->permanent );
		$this->assertSame( 402, $bad->http_code );
		$this->assertSame( 2000, strlen( $bad->response ) );
	}

	public function test_send_result_scrubs_secrets(): void {
		Logger::scrub( 'sup3r-secret-value' );
		$r = SendResult::failure( 'x', 'bad sup3r-secret-value', false, '{"t":"sup3r-secret-value"}' );
		$this->assertSame( 'bad ***', $r->error );
		$this->assertSame( '{"t":"***"}', $r->response );
	}
}
