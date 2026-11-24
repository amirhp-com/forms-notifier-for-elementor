<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FileRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class RepositoryTest extends TestCase {

	const NOW = 1767261600; // 2026-01-01 10:00:00 UTC

	public function setUp(): void {
		Db::reset();
		$this->freeze( self::NOW );
	}

	private function submission( array $overrides = [] ): Submission {
		$s = Fixtures::submission( $overrides );
		SubmissionRepository::insert( $s );
		return $s;
	}

	/* Forms */

	public function test_form_upsert_by_key(): void {
		$id = FormRepository::upsert( '45:a1b2c3d', [ 'post_id' => 45, 'element_id' => 'a1b2c3d', 'name' => 'تماس 🙂', 'fields' => Fixtures::form_fields(), 'editor' => [ 'fnfe_enabled' => 'yes' ] ] );
		$this->assertTrue( $id > 0 );
		$this->assertSame( $id, FormRepository::upsert( '45:a1b2c3d', [ 'name' => 'New name' ] ) );
		$row = FormRepository::find( $id );
		$this->assertSame( 'New name', $row['name'] );
		$this->assertSame( 45, $row['post_id'] );
		$this->assertSame( Fixtures::form_fields(), $row['fields'] );
		$this->assertSame( [ 'fnfe_enabled' => 'yes' ], $row['editor'] );
		$this->assertSame( $id, FormRepository::find_by_key( '45:a1b2c3d' )['id'] );
		$this->assertNull( FormRepository::find_by_key( 'x' ) );
		$this->assertNull( FormRepository::find( 999 ) );
		$this->assertSame( 0, FormRepository::upsert( '', [] ) );
	}

	public function test_form_config(): void {
		$id = FormRepository::upsert( '1:a', [ 'name' => 'A' ] );
		$this->assertSame( 'inherit', FormRepository::config( $id )->enabled() );
		$seen = [];
		$this->filter( 'fnfe_form_config_saved', static function ( $form_id, $config ) use ( &$seen ) { $seen[] = [ $form_id, $config['enabled'] ]; }, 10, 2 );
		$this->assertTrue( FormRepository::save_config( $id, [ 'enabled' => 'on', 'fields' => [ 'labels' => [ 'x' => [ '_' => 'نام 🙂' ] ] ] ] ) );
		$this->assertSame( [ [ $id, 'on' ] ], $seen );
		$config = FormRepository::config_by_key( '1:a' );
		$this->assertSame( 'on', $config->enabled() );
		$this->assertSame( $id, $config->form_id );
		$this->assertSame( 'نام 🙂', $config->get( 'fields.labels.x._' ) );
		$this->assertFalse( FormRepository::save_config( 999, [] ) );
		$this->assertSame( 'inherit', FormRepository::config_by_key( 'nope' )->enabled() );
	}

	public function test_form_link_is_followed_once(): void {
		$a = FormRepository::upsert( '1:a', [ 'name' => 'A' ] );
		$b = FormRepository::upsert( '2:b', [ 'name' => 'B' ] );
		$c = FormRepository::upsert( '3:c', [ 'name' => 'C' ] );
		FormRepository::save_config( $a, [ 'enabled' => 'on' ] );
		FormRepository::save_config( $b, [ 'enabled' => 'off', 'link' => $a ] );
		FormRepository::save_config( $c, [ 'link' => $b ] );
		$config = FormRepository::config( $b );
		$this->assertSame( 'on', $config->enabled() );
		$this->assertSame( $b, $config->form_id );
		$this->assertSame( $a, $config->get( 'link' ) );
		$this->assertSame( 'off', FormRepository::config( $c )->enabled() );
		FormRepository::save_config( $a, [ 'link' => $a ] );
		$this->assertSame( 0, FormRepository::config( $a )->get( 'link' ) );
	}

	public function test_form_config_moves_secret_receivers(): void {
		FakeChannel::install( 'hook', [ 'receiver_kind' => 'secret' ] );
		$id = FormRepository::upsert( '1:a', [] );
		FormRepository::save_config( $id, [ 'channels' => [ 'hook' => [ 'receivers' => [ Fixtures::receiver( 'https://hooks.example.com/T9', [ 'id' => 'r_x' ] ) ] ] ] ] );
		$this->assertSame( 'secret:r_x', FormRepository::config( $id )->route( 'hook' )['receivers'][0]['address'] );
		$this->assertSame( 'https://hooks.example.com/T9', Settings::secret( 'channels.hook.receiver_secrets.r_x' ) );
		Settings::prune_receiver_secrets( 'hook' );
		$this->assertTrue( Settings::has_secret( 'channels.hook.receiver_secrets.r_x' ) );
		FormRepository::save_config( $id, [] );
		Settings::prune_receiver_secrets( 'hook' );
		$this->assertFalse( Settings::has_secret( 'channels.hook.receiver_secrets.r_x' ) );
	}

	public function test_form_query_and_missing(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			FormRepository::upsert( "$i:f", [ 'post_id' => $i, 'name' => "Form $i", 'source' => $i > 3 ? 'atomic' : 'pro' ] );
		}
		$page = FormRepository::query( [ 'per_page' => 2, 'page' => 2, 'orderby' => 'name', 'order' => 'asc' ] );
		$this->assertKeys( [ 'items', 'total', 'page', 'per_page' ], $page );
		$this->assertSame( 5, $page['total'] );
		$this->assertSame( [ 'Form 3', 'Form 4' ], array_column( $page['items'], 'name' ) );
		$this->assertSame( 2, FormRepository::query( [ 'source' => 'atomic' ] )['total'] );
		$this->assertSame( 1, FormRepository::query( [ 'search' => 'form 5' ] )['total'] );
		$this->assertSame( 100, FormRepository::query( [ 'per_page' => 5000 ] )['per_page'] );
		$this->assertSame( 3, FormRepository::mark_missing_except( [ '1:f', '2:f' ] ) );
		$this->assertSame( 3, FormRepository::query( [ 'status' => 'missing' ] )['total'] );
		$id = FormRepository::find_by_key( '1:f' )['id'];
		FormRepository::touch_submission( $id );
		FormRepository::touch_submission( $id );
		$this->assertSame( 2, FormRepository::find( $id )['submissions_count'] );
		$this->assertSame( '2026-01-01 10:00:00', FormRepository::find( $id )['last_submission_gmt'] );
		$this->assertTrue( FormRepository::delete( $id ) );
		$this->assertFalse( FormRepository::delete( $id ) );
	}

	/* Submissions */

	public function test_submission_crud(): void {
		$s = Fixtures::submission( [ 'form_id' => 3 ] );
		$s->files[] = Fixtures::file();
		$id = SubmissionRepository::insert( $s );
		$this->assertTrue( $id > 0 );
		$this->assertSame( $id, $s->id );
		$this->assertMatches( '/^[a-f0-9]{32}$/', $s->uuid );
		$copy = SubmissionRepository::find( $id );
		$this->assertSame( $s->fields, $copy->fields );
		$this->assertSame( array_keys( $s->fields ), array_keys( $copy->fields ) );
		$this->assertSame( 45, $copy->post_id );
		$this->assertSame( 'a1b2c3d', $copy->element_id );
		$this->assertSame( 'none', $copy->meta['delivery_status'] );
		$this->assertSame( 'newsletter', $copy->meta['utm']['source'] );
		$this->assertCount( 1, $copy->files );
		$this->assertSame( $s->files[0]->path, $copy->files[0]->path );
		$this->assertSame( $id, SubmissionRepository::find_by_uuid( $s->uuid )->id );
		$this->assertNull( SubmissionRepository::find( 999 ) );
	}

	public function test_json_is_stored_readable(): void {
		global $wpdb;
		$s   = $this->submission();
		$raw = $wpdb->get_row( $wpdb->prepare( 'SELECT fields, search_text FROM %i WHERE id = %d', SubmissionRepository::table(), $s->id ) );
		$this->assertContains( 'سارا 🙂', $raw->fields );
		$this->assertNotContains( '\u0633', $raw->fields );
		$this->assertContains( 'سارا', $raw->search_text );
	}

	public function test_submission_status_and_meta(): void {
		$s = $this->submission();
		$this->assertTrue( SubmissionRepository::set_status( $s->id, 'done' ) );
		$this->assertFalse( SubmissionRepository::set_status( $s->id, 'weird' ) );
		$this->assertFalse( SubmissionRepository::set_status( 999, 'done' ) );
		SubmissionRepository::set_delivery_status( $s->id, 'partial' );
		SubmissionRepository::set_delivery_status( $s->id, 'weird' );
		SubmissionRepository::update_meta( $s->id, [ 'elementor_submission_id' => 77, 'page_title' => null ] );
		$copy = SubmissionRepository::find( $s->id );
		$this->assertSame( 'done', $copy->status );
		$this->assertSame( 'partial', $copy->meta['delivery_status'] );
		$this->assertSame( 77, $copy->meta['elementor_submission_id'] );
		$this->assertFalse( isset( $copy->meta['page_title'] ) );
		$this->assertSame( 'auto', $copy->meta['via'] );
	}

	public function test_submission_search_and_filters(): void {
		$this->submission( [ 'form_id' => 1 ] );
		$this->submission( [ 'form_id' => 2, 'fields' => [ 'q' => Fixtures::field( 'q', '100% Pure_Gold' ) ], 'created_gmt' => '2026-01-02 22:00:00' ] );
		$this->submission( [ 'form_id' => 2, 'fields' => [ 'q' => Fixtures::field( 'q', '1000 Puregold' ) ], 'status' => 'spam', 'created_gmt' => '2026-01-03 10:00:00' ] );
		$this->assertSame( 1, SubmissionRepository::count( [ 'search' => 'سارا' ] ) );
		$this->assertSame( 1, SubmissionRepository::count( [ 'search' => '100% pure_gold' ] ) );
		$this->assertSame( 0, SubmissionRepository::count( [ 'search' => 'pure%gold' ] ) );
		$this->assertSame( 2, SubmissionRepository::count( [ 'form_id' => 2 ] ) );
		$this->assertSame( 2, SubmissionRepository::count( [ 'status' => [ 'new', 'read' ] ] ) );
		$this->assertSame( 1, SubmissionRepository::query( [ 'status' => 'spam' ] )['total'] );
		$page = SubmissionRepository::query( [ 'per_page' => 2 ] );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( '1000 Puregold', $page['items'][0]->value( 'q' ) );
		update_option( 'timezone_string', 'Asia/Tehran' );
		$this->assertSame( 2, SubmissionRepository::count( [ 'from' => '2026-01-03', 'to' => '2026-01-03' ] ) );
		update_option( 'timezone_string', 'UTC' );
		$this->assertSame( 1, SubmissionRepository::count( [ 'from' => '2026-01-03', 'to' => '2026-01-03' ] ) );
		$this->assertSame( 3, SubmissionRepository::count( [ 'from' => 'garbage' ] ) );
	}

	public function test_recent_duplicate(): void {
		$s    = $this->submission( [ 'created_gmt' => '2026-01-01 09:59:40' ] );
		$hash = SubmissionRepository::content_hash( $s );
		$this->assertTrue( SubmissionRepository::recent_duplicate( $hash, '203.0.113.5', 30 ) );
		$this->assertFalse( SubmissionRepository::recent_duplicate( $hash, '203.0.113.5', 10 ) );
		$this->assertFalse( SubmissionRepository::recent_duplicate( $hash, '203.0.113.6', 30 ) );
		$this->assertFalse( SubmissionRepository::recent_duplicate( $hash, '203.0.113.5', 0 ) );
		$this->assertNotSame( $hash, SubmissionRepository::content_hash( Fixtures::submission( [ 'form_key' => '46:x' ] ) ) );
	}

	public function test_ids_by_email(): void {
		$mk = function ( string $value, string $type = 'email' ) {
			return $this->submission( [ 'fields' => [ 'e' => Fixtures::field( 'e', $value, $type ) ] ] )->id;
		};
		$a = $mk( 'sara_x@example.com' );
		$b = $mk( "Reach me:\nsara_x@example.com.", 'textarea' );
		$mk( 'not-sara_x@example.com' );
		$mk( 'sara_x@example.com.au' );
		$mk( 'sarazx@example.com' );
		$c = $mk( 'SARA_X@EXAMPLE.COM' );
		$this->assertSame( [ $a, $b, $c ], SubmissionRepository::ids_by_email( 'sara_x@example.com' ) );
		$this->assertSame( [ $b ], SubmissionRepository::ids_by_email( 'sara_x@example.com', 2, 1 ) );
		$this->assertSame( [], SubmissionRepository::ids_by_email( 'sara_x@example.com', 4, 1 ) );
		$this->assertSame( [], SubmissionRepository::ids_by_email( 'nope' ) );
	}

	public function test_delete_removes_stored_files_only(): void {
		$s      = Fixtures::submission();
		$public = Fixtures::file( 'public.pdf' );
		$dir    = \AmirhpCom\FormsNotifier\Attachments\Storage::dir();
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'aaaa.pdf', 'x' );
		$stored         = Fixtures::file( 'stored.pdf' );
		$stored->path   = $dir . 'aaaa.pdf';
		$stored->stored = true;
		$s->files       = [ $public, $stored ];
		SubmissionRepository::insert( $s );
		$deleted = [];
		$this->filter( 'fnfe_submission_deleted', static function ( $id ) use ( &$deleted ) { $deleted[] = $id; } );
		$this->assertTrue( SubmissionRepository::delete( $s->id ) );
		$this->assertSame( [ $s->id ], $deleted );
		$this->assertFalse( file_exists( $stored->path ) );
		$this->assertTrue( file_exists( $public->path ) );
		$this->assertSame( [], FileRepository::for_submission( $s->id ) );
		$this->assertFalse( SubmissionRepository::delete( $s->id ) );
	}

	public function test_older_than(): void {
		$old = $this->submission( [ 'created_gmt' => '2025-12-01 00:00:00' ] );
		$this->submission();
		$this->assertSame( [ $old->id ], SubmissionRepository::older_than( 10 ) );
		$this->assertSame( [], SubmissionRepository::older_than( 0 ) );
	}

	/* Files */

	public function test_files(): void {
		$file = Fixtures::file( 'رزومه.pdf' );
		$id   = FileRepository::insert( 5, $file );
		$this->assertSame( $id, $file->id );
		$copy = FileRepository::find( $id );
		$this->assertSame( $file->to_array(), $copy->to_array() );
		$this->assertMatches( '/^[a-f0-9]{32}$/', FileRepository::token( $id ) );
		$this->assertSame( '', FileRepository::token( 999 ) );
		$this->assertSame( 5, FileRepository::submission_id( $id ) );
		$stored         = Fixtures::file();
		$stored->stored = true;
		$stored_id      = FileRepository::insert( 5, $stored );
		$this->assertCount( 2, FileRepository::for_submission( 5 ) );
		$this->assertTrue( FileRepository::find( $stored_id )->stored );
		$this->filter( 'fnfe_now', static function () { return self::NOW + 5 * DAY_IN_SECONDS; }, PHP_INT_MAX );
		$this->assertSame( [ $stored_id ], array_column( FileRepository::older_than( 3 ), 'id' ) );
		$this->assertTrue( FileRepository::clear_path( $stored_id ) );
		$this->assertSame( '', FileRepository::find( $stored_id )->path );
		$this->assertTrue( FileRepository::delete( $id ) );
		$this->assertNull( FileRepository::find( $id ) );
	}

	/* Messages */

	private function row( array $overrides = [] ): int {
		return MessageRepository::insert( array_merge( [ 'channel' => 'fake', 'recipient' => 'desk', 'submission_id' => 1, 'form_id' => 2, 'body' => 'متن 🙂', 'payload' => Fixtures::message()->to_array() ], $overrides ) );
	}

	public function test_message_crud(): void {
		$id  = $this->row( [ 'evil' => 'x', 'recipient_label' => str_repeat( 'ی', 300 ) ] );
		$row = MessageRepository::find( $id );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( '2026-01-01 10:00:00', $row['next_attempt_gmt'] );
		$this->assertSame( 'متن 🙂', $row['body'] );
		$this->assertSame( 'Hello', $row['payload']['body'] );
		$this->assertSame( 191, mb_strlen( $row['recipient_label'] ) );
		$this->assertNull( $row['sent_gmt'] );
		$this->assertTrue( MessageRepository::update( $id, [ 'status' => 'sent', 'attempts' => 1, 'sent_gmt' => '2026-01-01 10:00:05', 'payload' => null, 'id' => 99 ] ) );
		$row = MessageRepository::find( $id );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( [], $row['payload'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertTrue( MessageRepository::delete( $id ) );
		$this->assertNull( MessageRepository::find( $id ) );
	}

	public function test_message_query(): void {
		$this->row( [ 'status' => 'sent', 'channel' => 'sms', 'purpose' => 'test' ] );
		$this->row( [ 'status' => 'failed', 'error' => 'No credit left' ] );
		$this->row( [ 'submission_id' => 9 ] );
		$this->assertSame( 2, MessageRepository::query( [ 'status' => [ 'sent', 'failed' ] ] )['total'] );
		$this->assertSame( 1, MessageRepository::query( [ 'channel' => 'sms' ] )['total'] );
		$this->assertSame( 1, MessageRepository::query( [ 'purpose' => 'test' ] )['total'] );
		$this->assertSame( 1, MessageRepository::query( [ 'search' => 'credit' ] )['total'] );
		$this->assertSame( 3, MessageRepository::query( [ 'search' => 'متن' ] )['total'] );
		$this->assertSame( 1, MessageRepository::query( [ 'submission_id' => 9 ] )['total'] );
		$this->assertCount( 2, MessageRepository::for_submission( 1 ) );
		$this->assertSame( [], MessageRepository::for_submission( 0 ) );
		$this->assertSame( [ 'failed' => 1, 'queued' => 1, 'sent' => 1 ], ( static function ( $c ) { ksort( $c ); return $c; } )( MessageRepository::counts() ) );
		$this->assertSame( 1, MessageRepository::counts_by_channel()['sms']['sent'] );
		$this->assertSame( [], MessageRepository::counts( '2027-01-01 00:00:00' ) );
	}

	public function test_claim_order_and_due(): void {
		$late   = $this->row( [ 'next_attempt_gmt' => '2026-01-01 09:59:59' ] );
		$early  = $this->row( [ 'next_attempt_gmt' => '2026-01-01 09:00:00', 'status' => 'retry' ] );
		$this->row( [ 'next_attempt_gmt' => '2026-01-01 10:00:01' ] );
		$this->row( [ 'status' => 'failed' ] );
		$this->row( [ 'locked_until_gmt' => '2026-01-01 11:00:00' ] );
		$claimed = MessageRepository::claim( 10 );
		$this->assertSame( [ $early, $late ], array_column( $claimed, 'id' ) );
		$this->assertSame( 'sending', $claimed[0]['status'] );
		$this->assertSame( '2026-01-01 10:05:00', $claimed[0]['locked_until_gmt'] );
		$this->assertSame( [], MessageRepository::claim( 10 ) );
	}

	public function test_claim_is_atomic(): void {
		$ids = [ $this->row(), $this->row(), $this->row() ];
		// A second process takes the first candidate between the select and the update of the first process.
		$stolen = false;
		$this->filter( 'query', function ( $query ) use ( &$stolen, $ids ) {
			if ( ! $stolen && strpos( $query, 'UPDATE' ) === 0 && strpos( $query, "status = 'sending'" ) !== false ) {
				$stolen = true;
				$this->assertNotNull( MessageRepository::take( $ids[0] ) );
			}
			return $query;
		} );
		$claimed = array_column( MessageRepository::claim( 10 ), 'id' );
		$this->assertTrue( $stolen );
		$this->assertSame( [ $ids[1], $ids[2] ], $claimed );
		$this->assertNull( MessageRepository::take( $ids[0] ) );
		$this->assertCount( 1, MessageRepository::claim( 1 ) === [] ? [ 1 ] : [] );
	}

	public function test_release_stale(): void {
		$id = $this->row();
		MessageRepository::claim( 1, 60 );
		$this->assertSame( 0, MessageRepository::release_stale() );
		$this->filter( 'fnfe_now', static function () { return self::NOW + 61; }, PHP_INT_MAX );
		$this->assertSame( 1, MessageRepository::release_stale() );
		$row = MessageRepository::find( $id );
		$this->assertSame( 'retry', $row['status'] );
		$this->assertNull( $row['locked_until_gmt'] );
		$this->assertCount( 1, MessageRepository::claim( 5 ) );
	}

	public function test_message_retention(): void {
		$sent = $this->row( [ 'status' => 'sent', 'created_gmt' => '2025-01-01 00:00:00' ] );
		$wait = $this->row( [ 'created_gmt' => '2025-01-01 00:00:00' ] );
		$new  = $this->row( [ 'status' => 'sent' ] );
		$this->assertSame( 1, MessageRepository::clear_payloads_older_than( 30 ) );
		$this->assertSame( [], MessageRepository::find( $sent )['payload'] );
		$this->assertNotSame( [], MessageRepository::find( $wait )['payload'] );
		$this->assertSame( 1, MessageRepository::purge_older_than( 30 ) );
		$this->assertNull( MessageRepository::find( $sent ) );
		$this->assertNotNull( MessageRepository::find( $wait ) );
		$this->assertNotNull( MessageRepository::find( $new ) );
		$this->assertSame( 0, MessageRepository::purge_older_than( 0 ) );
	}

	/* Chats */

	public function test_chat_upsert_and_query(): void {
		$id = ChatRepository::upsert( 'telegram', Fixtures::CHAT, 0, [ 'type' => 'supergroup', 'title' => 'فروش', 'is_forum' => true, 'meta' => [ 'a' => 1 ] ] );
		$this->assertSame( $id, ChatRepository::upsert( 'telegram', Fixtures::CHAT, 0, [ 'title' => 'فروش ۲' ] ) );
		$topic = ChatRepository::upsert( 'telegram', Fixtures::CHAT, 45, [ 'type' => 'supergroup', 'topic_name' => 'Leads' ] );
		ChatRepository::upsert( 'bale', '123', 0, [ 'type' => 'private', 'username' => 'sara_example' ] );
		$row = ChatRepository::find( $id );
		$this->assertSame( 'فروش ۲', $row['title'] );
		$this->assertTrue( $row['is_forum'] );
		$this->assertSame( [ 'a' => 1 ], $row['meta'] );
		$this->assertSame( $topic, ChatRepository::find_chat( 'telegram', Fixtures::CHAT, 45 )['id'] );
		$this->assertSame( 1, ChatRepository::query( [ 'platform' => 'telegram' ] )['total'] );
		$this->assertSame( 2, ChatRepository::query( [ 'platform' => 'telegram', 'with_topics' => true ] )['total'] );
		$this->assertSame( 1, ChatRepository::query( [ 'type' => [ 'private', 'channel' ] ] )['total'] );
		$this->assertSame( 1, ChatRepository::query( [ 'search' => 'sara_' ] )['total'] );
		$this->assertSame( [ 'Leads' ], array_column( ChatRepository::topics( 'telegram', Fixtures::CHAT ), 'topic_name' ) );
		$this->assertTrue( ChatRepository::delete( $topic ) );
		$this->assertSame( 0, ChatRepository::upsert( '', '', 0, [] ) );
	}

	public function test_chat_migrate(): void {
		ChatRepository::upsert( 'telegram', '-100', 0, [ 'type' => 'group', 'title' => 'G' ] );
		ChatRepository::upsert( 'telegram', '-100', 7, [ 'topic_name' => 'T' ] );
		ChatRepository::migrate( 'telegram', '-100', '-1009' );
		ChatRepository::migrate( 'telegram', '-100', '-1009' );
		$new = ChatRepository::find_chat( 'telegram', '-1009' );
		$this->assertSame( 'G', $new['title'] );
		$this->assertSame( 'supergroup', $new['type'] );
		$this->assertSame( 'active', $new['status'] );
		$this->assertSame( 'T', ChatRepository::find_chat( 'telegram', '-1009', 7 )['topic_name'] );
		$old = ChatRepository::find_chat( 'telegram', '-100' );
		$this->assertSame( 'migrated', $old['status'] );
		$this->assertSame( '-1009', $old['meta']['migrated_to'] );
	}

	/* Log */

	public function test_log(): void {
		LogRepository::insert( 'info', 'bot', 'سلام', [ 'a' => 'ب' ] );
		LogRepository::insert( 'error', 'sms', 'broken', [] );
		$page = LogRepository::query( [ 'level' => 'info' ] );
		$this->assertSame( 1, $page['total'] );
		$this->assertSame( [ 'a' => 'ب' ], $page['items'][0]['data'] );
		$this->assertSame( 1, LogRepository::query( [ 'context' => 'sms' ] )['total'] );
		$this->assertSame( 1, LogRepository::query( [ 'search' => 'brok' ] )['total'] );
		$this->filter( 'fnfe_now', static function () { return self::NOW + 40 * DAY_IN_SECONDS; }, PHP_INT_MAX );
		$this->assertSame( 2, LogRepository::purge_older_than( 30 ) );
		LogRepository::insert( 'info', 'x', 'y', [] );
		LogRepository::truncate();
		$this->assertSame( 0, LogRepository::query()['total'] );
	}
}
