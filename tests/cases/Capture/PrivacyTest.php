<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Privacy\Privacy;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;
use AmirhpCom\FormsNotifier\Tests\FakeChannel;
use AmirhpCom\FormsNotifier\Tests\Fixtures;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class PrivacyTest extends TestCase {

	const EMAIL = 'person@example.org';

	public function setUp(): void {
		Db::reset();
	}

	private function submission( string $email = self::EMAIL, array $meta = [] ): int {
		return SubmissionRepository::insert( Fixtures::submission( [ 'fields' => [ 'name' => Fixtures::field( 'name', '<b>Sara</b>' ), 'mail' => Fixtures::field( 'mail', $email, 'email' ), 'tel' => Fixtures::field( 'tel', Fixtures::PHONE, 'tel' ) ], 'meta' => $meta ] ) );
	}

	private function groups( array $data ): array {
		return array_count_values( array_column( $data, 'group_id' ) );
	}

	public function test_registered(): void {
		$this->assertArrayHasKey( 'forms-notifier-for-elementor', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'forms-notifier-for-elementor', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	public function test_150_submissions(): void {
		for ( $i = 0; $i < 150; $i++ ) {
			$this->submission();
		}
		$other = $this->submission( 'x' . self::EMAIL );
		$first = Privacy::export( self::EMAIL, 1 );
		$this->assertFalse( $first['done'] );
		$this->assertSame( [ 'fnfe-submissions' => 100 ], $this->groups( $first['data'] ) );
		$second = Privacy::export( self::EMAIL, 2 );
		$this->assertTrue( $second['done'] );
		$this->assertSame( [ 'fnfe-submissions' => 50 ], $this->groups( $second['data'] ) );
		$this->assertCount( 150, array_unique( array_merge( array_column( $first['data'], 'item_id' ), array_column( $second['data'], 'item_id' ) ) ) );

		$r = Privacy::erase( self::EMAIL, 1 );
		$this->assertSame( [ 100, 0, [], false ], [ $r['items_removed'], $r['items_retained'], $r['messages'], $r['done'] ] );
		$r = Privacy::erase( self::EMAIL, 2 );
		$this->assertSame( [ 50, true ], [ $r['items_removed'], $r['done'] ] );
		$this->assertSame( 1, SubmissionRepository::count() );
		$this->assertNotNull( SubmissionRepository::find( $other ) );
		$this->assertSame( [], Privacy::export( self::EMAIL )['data'] );
	}

	public function test_messages_files_and_account(): void {
		$user = wp_insert_user( [ 'user_login' => 'fnfe_p_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'user_email' => self::EMAIL, 'role' => 'subscriber' ] );
		try {
			$id  = $this->submission( self::EMAIL, [ 'user_ip' => '203.0.113.5' ] );
			$own = $this->submission( 'other@example.org', [ 'user_id' => $user ] );
			$s        = Fixtures::submission( [ 'fields' => [ 'mail' => Fixtures::field( 'mail', self::EMAIL ) ] ] );
			$s->files = [ Storage::keep( Fixtures::file() ) ];
			SubmissionRepository::insert( $s );
			foreach ( [ self::EMAIL, Fixtures::PHONE, 'desk@example.com' ] as $to ) {
				MessageRepository::insert( [ 'submission_id' => $id, 'channel' => 'email', 'status' => 'sent', 'recipient' => $to ] );
			}
			$loose = MessageRepository::insert( [ 'submission_id' => 0, 'purpose' => 'submitter', 'channel' => 'email', 'status' => 'sent', 'recipient' => self::EMAIL ] );

			$data = Privacy::export( self::EMAIL )['data'];
			$this->assertSame( [ 'fnfe-messages' => 3, 'fnfe-submissions' => 3 ], array_replace( [ 'fnfe-messages' => 0, 'fnfe-submissions' => 0 ], $this->groups( $data ) ) );
			$this->assertNotContains( 'desk@example.com', wp_json_encode( $data ) );
			$this->assertNotContains( '<b>Sara', $data[0]['data'][2]['value'] );

			$path = $s->files[0]->path;
			Privacy::erase( self::EMAIL );
			$this->assertSame( 0, SubmissionRepository::count() );
			$this->assertFalse( file_exists( $path ) );
			$this->assertSame( 0, MessageRepository::query()['total'] );
			$this->assertNull( MessageRepository::find( $loose ) );
			unset( $own );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user );
		}
	}

	public function test_invalid_addresses(): void {
		$this->submission();
		foreach ( [ '', 'x', '%', null, [] ] as $bad ) {
			$this->assertSame( [], Privacy::export( $bad )['data'] );
			$this->assertSame( 0, Privacy::erase( $bad )['items_removed'] );
		}
		$this->assertSame( 1, SubmissionRepository::count() );
	}

	public function test_policy_text(): void {
		FakeChannel::install();
		$ip   = __( 'your IP address', 'forms-notifier-for-elementor' );
		$text = Privacy::policy_text();
		$this->assertContains( $ip, $text );
		$this->assertNotContains( 'Fake fake', $text );
		Settings::set_many( [ 'general.store_ip' => 'none', 'privacy.retention_submissions_days' => 30, 'channels.fake.enabled' => true ] );
		$text = Privacy::policy_text();
		$this->assertNotContains( $ip, $text );
		$this->assertContains( '30', $text );
		$this->assertContains( 'Fake fake', $text );
		$this->assertTrue( (bool) has_action( 'admin_init', [ Privacy::class, 'policy_content' ] ) );
	}
}
