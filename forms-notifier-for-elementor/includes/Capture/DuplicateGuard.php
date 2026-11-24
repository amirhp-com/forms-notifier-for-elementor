<?php
/**
 * Duplicate guard.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Ignores the same content from the same visitor inside a short window. */
final class DuplicateGuard {

	/**
	 * The submission repeats a recent one. Asking also remembers it.
	 *
	 * Stored addresses are compared while they are complete. Without stored rows or with shortened addresses a signed
	 * fingerprint in a transient is used, so visitors that share a shortened address are still told apart.
	 *
	 * @param Submission $s Normalised submission; meta.user_ip is the address as it will be stored.
	 */
	public static function is_duplicate( Submission $s ): bool {
		$window = Settings::int( 'general.duplicate_window', 30 );
		if ( $window <= 0 ) {
			return false;
		}
		$hash = SubmissionRepository::content_hash( $s );

		if ( Settings::bool( 'general.store_submissions', true ) && Settings::str( 'general.store_ip', 'full' ) === 'full' ) {
			return SubmissionRepository::recent_duplicate( $hash, (string) ( $s->meta['user_ip'] ?? '' ), $window );
		}

		$now  = (int) apply_filters( 'fnfe_now', time() );
		$key  = 'fnfe_dup_' . substr( Crypto::sign( $hash . '|' . Request::ip(), 'duplicate' ), 0, 40 );
		$last = (int) get_transient( $key );
		if ( $last > $now - $window ) {
			return true;
		}
		set_transient( $key, $now, $window );
		return false;
	}
}
