<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Core\State;

defined( 'ABSPATH' ) || exit;

/** Signed session tokens: none are issued yet. */
final class Session {

	/**
	 * Issue a token.
	 *
	 * @param string   $platform Platform.
	 * @param string   $user_id  Messenger user id.
	 * @param string[] $perms    Permissions.
	 * @return array{token:string,expires:int}
	 */
	public static function issue( string $platform, string $user_id, array $perms ): array {
		unset( $platform, $user_id, $perms );
		return [
			'token'   => '',
			'expires' => 0,
		];
	}

	/**
	 * Verify a token.
	 *
	 * @param string $token Token.
	 * @return array{platform:string,uid:string,perms:string[],exp:int}|null
	 */
	public static function verify( string $token ): ?array {
		unset( $token );
		return null;
	}

	/** Invalidate every token. */
	public static function revoke_all(): void {
		State::set( 'token_version', (int) State::get( 'token_version', 0 ) + 1 );
	}
}
