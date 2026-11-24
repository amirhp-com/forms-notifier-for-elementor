<?php
/**
 * Capability mapping.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Maps plugin capabilities to WordPress capabilities. Nothing is written to roles. */
final class Capabilities {

	/** Settings, channels, bots, tools. */
	public const MANAGE = 'fnfe_manage';

	/** Per-form configuration. */
	public const FORMS = 'fnfe_manage_forms';

	/** Read submissions and files. */
	public const SUBMISSIONS = 'fnfe_view_submissions';

	/** Read message log, retry. */
	public const LOGS = 'fnfe_view_logs';

	/**
	 * WordPress capability behind a plugin capability.
	 *
	 * @param string $cap Plugin capability.
	 */
	public static function map( string $cap ): string {
		$wp_cap = apply_filters( 'fnfe_capability', 'manage_options', $cap );
		return is_string( $wp_cap ) && $wp_cap !== '' ? $wp_cap : 'manage_options';
	}

	/**
	 * Check a plugin capability.
	 *
	 * @param string $cap     Plugin capability.
	 * @param int    $user_id User id, 0 = current user.
	 */
	public static function can( string $cap, int $user_id = 0 ): bool {
		if ( ! in_array( $cap, self::all(), true ) ) {
			return false;
		}
		$user_id = $user_id ?: get_current_user_id();
		return $user_id > 0 && user_can( $user_id, self::map( $cap ) );
	}

	/** The four plugin capabilities. */
	public static function all(): array {
		return [ self::MANAGE, self::FORMS, self::SUBMISSIONS, self::LOGS ];
	}
}
