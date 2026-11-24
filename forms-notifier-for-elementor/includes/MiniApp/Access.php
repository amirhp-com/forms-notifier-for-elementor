<?php
/**
 * Who may open the mini app.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\MiniApp;

use AmirhpCom\FormsNotifier\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Allow-list and permissions of messenger users. */
final class Access {

	/**
	 * Is this messenger user on the allow-list of the platform?
	 *
	 * @param string $platform Platform.
	 * @param string $user_id  Messenger user id.
	 */
	public static function allowed( string $platform, string $user_id ): bool {
		$entry = self::entry( $platform, $user_id );
		$user  = $entry ?? [
			'uid'      => $user_id,
			'name'     => '',
			'username' => '',
			'wp_user'  => 0,
			'perms'    => [],
		];

		return preg_match( '/^\d{1,20}$/', $user_id ) === 1 && in_array( $platform, InitData::PLATFORMS, true )
			&& (bool) apply_filters( 'fnfe_miniapp_allowed', $entry !== null, $platform, $user );
	}

	/**
	 * Permissions: 'view' always when allowed; 'act' when miniapp.allow_actions and the admin entry grants it.
	 *
	 * @param string $platform Platform.
	 * @param string $user_id  Messenger user id.
	 * @return string[]
	 */
	public static function perms( string $platform, string $user_id ): array {
		if ( ! self::allowed( $platform, $user_id ) ) {
			return [];
		}
		$perms = [ 'view' ];
		$entry = self::entry( $platform, $user_id );
		if ( $entry && in_array( 'act', $entry['perms'], true ) && Settings::bool( 'miniapp.allow_actions', true ) ) {
			$perms[] = 'act';
		}
		return $perms;
	}

	/**
	 * Admin entries of a platform: channels.{platform}.admins.
	 *
	 * @param string $platform Platform.
	 * @return array[] [ uid, name, username, wp_user, perms ]
	 */
	public static function admins( string $platform ): array {
		if ( ! in_array( $platform, InitData::PLATFORMS, true ) ) {
			return [];
		}
		$out = [];
		foreach ( Settings::arr( "channels.$platform.admins" ) as $row ) {
			$uid = is_array( $row ) && is_scalar( $row['uid'] ?? null ) ? trim( (string) $row['uid'] ) : '';
			if ( ! preg_match( '/^\d{1,20}$/', $uid ) ) {
				continue;
			}
			$out[] = [
				'uid'      => $uid,
				'name'     => is_scalar( $row['name'] ?? null ) ? (string) $row['name'] : '',
				'username' => is_scalar( $row['username'] ?? null ) ? (string) $row['username'] : '',
				'wp_user'  => absint( $row['wp_user'] ?? 0 ),
				'perms'    => array_values( array_filter( (array) ( $row['perms'] ?? [] ), 'is_string' ) ),
			];
		}
		return $out;
	}

	/**
	 * Admin entry of one user. An entry that is linked to a deleted WordPress user does not count.
	 *
	 * @param string $platform Platform.
	 * @param string $user_id  Messenger user id.
	 */
	private static function entry( string $platform, string $user_id ): ?array {
		foreach ( self::admins( $platform ) as $entry ) {
			if ( $entry['uid'] === $user_id ) {
				return $entry['wp_user'] && ! get_userdata( $entry['wp_user'] ) ? null : $entry;
			}
		}
		return null;
	}
}
