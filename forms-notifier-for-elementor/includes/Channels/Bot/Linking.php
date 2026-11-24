<?php
/**
 * Bot admins.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\MiniApp\Session;

defined( 'ABSPATH' ) || exit;

/** Messenger accounts that may use admin commands, buttons and the mini app; linked through a one-time deep link. */
final class Linking {

	public const TTL = 15 * MINUTE_IN_SECONDS;

	private const META = 'fnfe_bot_ids';

	/**
	 * One-time code for a WordPress user.
	 *
	 * @param string $platform Platform.
	 * @param int    $user_id  WordPress user.
	 * @return array{url:string,code:string,expires:int} url is '' while the bot name is unknown; the code can also be
	 *                                                   sent to the bot by hand as "/start {code}"
	 */
	public static function create( string $platform, int $user_id ): array {
		$code = 'link_' . Text::uid() . Text::uid();
		set_transient(
			'fnfe_' . $code,
			[
				'user'     => $user_id,
				'platform' => $platform,
				'expires'  => self::now() + self::TTL,
			],
			self::TTL
		);
		return [
			'url'     => self::deep_link( $platform, $code ),
			'code'    => $code,
			'expires' => self::now() + self::TTL,
		];
	}

	/**
	 * Use a code: adds or updates the admin entry.
	 *
	 * @param string $platform Platform.
	 * @param string $code     Payload of /start.
	 * @param array  $from     Messenger user.
	 * @return \WP_User|null the linked user, null when the code is not valid
	 */
	public static function consume( string $platform, string $code, array $from ): ?\WP_User {
		$uid = self::uid( $from['id'] ?? '' );
		if ( $uid === '' || ! preg_match( '/^link_[a-z0-9]{16}$/D', $code ) ) {
			return null;
		}
		$entry = get_transient( 'fnfe_' . $code );
		if ( ! is_array( $entry ) || ( $entry['platform'] ?? '' ) !== $platform || (int) ( $entry['expires'] ?? 0 ) < self::now() ) {
			return null;
		}
		delete_transient( 'fnfe_' . $code );

		$user = get_userdata( (int) ( $entry['user'] ?? 0 ) );
		if ( ! $user ) {
			return null;
		}
		$rows  = self::admins( $platform );
		$known = $rows[ $uid ] ?? null;

		$rows[ $uid ] = [
			'id'       => $known ? $known['id'] : Text::uid( 'e_' ),
			'uid'      => $uid,
			'name'     => self::display_name( $from ),
			'username' => self::username( $from ),
			'wp_user'  => (int) $user->ID,
			'perms'    => [ 'view', 'act' ],
		];
		self::save( $platform, $rows );

		$ids              = self::meta( (int) $user->ID );
		$ids[ $platform ] = $uid;
		update_user_meta( $user->ID, self::META, $ids );
		return $user;
	}

	/**
	 * Admin entries keyed by messenger user id.
	 *
	 * @param string $platform Platform.
	 * @return array<string,array{id:string,uid:string,name:string,username:string,wp_user:int,perms:string[]}>
	 */
	public static function admins( string $platform ): array {
		$out = [];
		foreach ( Settings::arr( "channels.$platform.admins" ) as $row ) {
			$uid = is_array( $row ) ? self::uid( $row['uid'] ?? '' ) : '';
			if ( $uid === '' ) {
				continue;
			}
			$perms       = array_values( array_intersect( [ 'view', 'act' ], (array) ( $row['perms'] ?? [] ) ) );
			$out[ $uid ] = [
				'id'       => (string) ( $row['id'] ?? '' ),
				'uid'      => $uid,
				'name'     => (string) ( $row['name'] ?? '' ),
				'username' => (string) ( $row['username'] ?? '' ),
				'wp_user'  => (int) ( $row['wp_user'] ?? 0 ),
				'perms'    => array_values( array_unique( array_merge( [ 'view' ], $perms ) ) ),
			];
		}
		return $out;
	}

	/**
	 * Admin entry of a messenger user.
	 *
	 * @param string $platform Platform.
	 * @param string $uid      Messenger user id.
	 */
	public static function admin( string $platform, string $uid ): ?array {
		return $uid === '' ? null : ( self::admins( $platform )[ $uid ] ?? null );
	}

	/**
	 * The messenger user is an admin with a permission.
	 *
	 * @param string $platform Platform.
	 * @param string $uid      Messenger user id.
	 * @param string $perm     view | act.
	 */
	public static function can( string $platform, string $uid, string $perm = 'view' ): bool {
		$admin = self::admin( $platform, $uid );
		return $admin !== null && in_array( $perm, $admin['perms'], true );
	}

	/**
	 * Remove an admin. Open mini app sessions end.
	 *
	 * @param string $platform Platform.
	 * @param string $uid      Messenger user id.
	 */
	public static function remove( string $platform, string $uid ): bool {
		$rows = self::admins( $platform );
		if ( ! isset( $rows[ $uid ] ) ) {
			return false;
		}
		$wp_user = $rows[ $uid ]['wp_user'];
		unset( $rows[ $uid ] );
		self::save( $platform, $rows );
		self::forget_meta( $wp_user, $platform );
		return true;
	}

	/**
	 * A WordPress user was deleted: the linked messenger accounts lose their access.
	 *
	 * @param int $user_id WordPress user.
	 */
	public static function on_user_deleted( $user_id ): void {
		foreach ( Updates::PLATFORMS as $platform ) {
			foreach ( self::admins( $platform ) as $uid => $admin ) {
				if ( $admin['wp_user'] > 0 && $admin['wp_user'] === (int) $user_id ) {
					self::remove( $platform, (string) $uid );
				}
			}
		}
	}

	/** The admin list changed in any way: sign everybody out of the mini app. */
	public static function on_admins_changed(): void {
		if ( class_exists( Session::class ) ) {
			Session::revoke_all();
		}
	}

	/**
	 * Deep link that opens the bot with a start parameter.
	 *
	 * @param string $platform Platform.
	 * @param string $payload  Start parameter.
	 */
	public static function deep_link( string $platform, string $payload ): string {
		$bot = (string) State::get( "bot.$platform.me.username", '' );
		return $bot === '' ? '' : ( $platform === 'bale' ? 'https://ble.ir/' : 'https://t.me/' ) . $bot . '?start=' . rawurlencode( $payload );
	}

	/**
	 * Name of a messenger user.
	 *
	 * @param array $from Messenger user.
	 */
	public static function display_name( array $from ): string {
		$name = '';
		foreach ( [ 'first_name', 'last_name' ] as $key ) {
			$name .= is_string( $from[ $key ] ?? null ) ? ' ' . $from[ $key ] : '';
		}
		$name = Text::truncate( sanitize_text_field( $name ), 60 );
		return $name === '' ? self::username( $from ) : $name;
	}

	/**
	 * Numeric messenger user id as a string, '' when it is none.
	 *
	 * @param mixed $value Value.
	 */
	public static function uid( $value ): string {
		$value = is_int( $value ) || is_string( $value ) ? trim( Text::en_digits( (string) $value ) ) : '';
		return preg_match( '/^[1-9]\d{0,19}$/D', $value ) ? $value : '';
	}

	/**
	 * Save the list.
	 *
	 * @param string $platform Platform.
	 * @param array  $rows     Entries.
	 */
	private static function save( string $platform, array $rows ): void {
		// Fires fnfe_settings_saved; BotModule ends the mini app sessions there.
		Settings::set_many( [ "channels.$platform.admins" => array_values( $rows ) ] );
	}

	/**
	 * Remove the link from a WordPress user.
	 *
	 * @param int    $user_id  WordPress user.
	 * @param string $platform Platform.
	 */
	private static function forget_meta( int $user_id, string $platform ): void {
		$ids = $user_id > 0 ? self::meta( $user_id ) : [];
		if ( ! isset( $ids[ $platform ] ) ) {
			return;
		}
		unset( $ids[ $platform ] );
		if ( $ids ) {
			update_user_meta( $user_id, self::META, $ids );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * Linked accounts of a WordPress user.
	 *
	 * @param int $user_id WordPress user.
	 */
	private static function meta( int $user_id ): array {
		$ids = get_user_meta( $user_id, self::META, true );
		return is_array( $ids ) ? $ids : [];
	}

	/**
	 * Username of a messenger user.
	 *
	 * @param array $from Messenger user.
	 */
	private static function username( array $from ): string {
		return is_string( $from['username'] ?? null ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', $from['username'] ) ? $from['username'] : '';
	}

	/** Clock. */
	private static function now(): int {
		return (int) apply_filters( 'fnfe_now', time() );
	}
}
