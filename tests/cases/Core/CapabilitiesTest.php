<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Core;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Tests\TestCase;

final class CapabilitiesTest extends TestCase {

	private function user( string $role ): int {
		$ids = get_users( [ 'role' => $role, 'number' => 1, 'fields' => 'ID' ] );
		return (int) ( $ids[0] ?? 0 );
	}

	public function test_default_mapping(): void {
		$this->assertCount( 4, Capabilities::all() );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertSame( 'manage_options', Capabilities::map( $cap ) );
		}
		$admin = $this->user( 'administrator' );
		$this->assertTrue( Capabilities::can( Capabilities::MANAGE, $admin ) );
		$this->assertFalse( Capabilities::can( 'something_else', $admin ) );
	}

	public function test_filter(): void {
		$this->filter( 'fnfe_capability', static function ( $wp_cap, $cap ) {
			return $cap === Capabilities::SUBMISSIONS ? 'edit_posts' : $wp_cap;
		}, 10, 2 );
		$this->assertSame( 'edit_posts', Capabilities::map( Capabilities::SUBMISSIONS ) );
		$this->assertSame( 'manage_options', Capabilities::map( Capabilities::LOGS ) );
	}

	public function test_empty_mapping_is_not_open(): void {
		$this->filter( 'fnfe_capability', '__return_empty_string' );
		$this->assertSame( 'manage_options', Capabilities::map( Capabilities::MANAGE ) );
	}

	public function test_visitors_are_denied(): void {
		wp_set_current_user( 0 );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertFalse( Capabilities::can( $cap ) );
		}
	}

	public function test_subscriber_denied(): void {
		$id = wp_insert_user( [ 'user_login' => 'fnfe_test_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
		try {
			$this->assertFalse( Capabilities::can( Capabilities::SUBMISSIONS, (int) $id ) );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $id );
		}
	}
}
