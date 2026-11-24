<?php
/**
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\MiniApp;

use AmirhpCom\FormsNotifier\Tests\Fixtures;

/** Launch data and admin entries of the mini app tests. */
final class Launch {

	/** Signs on its own, not through the class under test. */
	public static function sign( array $pairs, string $token = Fixtures::TOKEN ): string {
		$lines = [];
		foreach ( $pairs as $key => $value ) {
			$lines[] = "$key=$value";
		}
		sort( $lines, SORT_STRING );
		$pairs['hash'] = bin2hex( hash_hmac( 'sha256', implode( chr( 10 ), $lines ), hash_hmac( 'sha256', $token, 'WebAppData', true ), true ) );

		$query = [];
		foreach ( $pairs as $key => $value ) {
			$query[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
		return implode( '&', $query );
	}

	public static function admin( string $uid, array $perms, int $wp_user = 0 ): array {
		return [ 'uid' => $uid, 'name' => 'Sara', 'username' => 'sara_example', 'wp_user' => $wp_user, 'perms' => $perms ];
	}
}
