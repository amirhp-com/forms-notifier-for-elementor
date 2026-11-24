<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Single entry point for every adapter: saves and fires the hook. */
final class Capture {

	/** @var array<string,bool> */
	private static $seen = [];

	/**
	 * Handle one native submit event.
	 *
	 * @param Submission $draft     Adapter-built DTO (id 0).
	 * @param string     $event_key Unique per native event in this request.
	 * @return Submission|null saved submission, null when skipped
	 */
	public static function handle( Submission $draft, string $event_key ): ?Submission {
		if ( self::seen( $event_key ) ) {
			return null;
		}
		self::$seen[ $event_key ] = true;
		return self::ingest( $draft );
	}

	/**
	 * True when $event_key was already handled in this request.
	 *
	 * @param string $event_key Event key.
	 */
	public static function seen( string $event_key ): bool {
		return isset( self::$seen[ $event_key ] );
	}

	/**
	 * Programmatic submission: skips dedupe and the duplicate guard.
	 *
	 * @param Submission $draft DTO.
	 */
	public static function ingest( Submission $draft ): ?Submission {
		$draft->form_id = FormRepository::upsert(
			$draft->form_key,
			[
				'post_id'    => $draft->post_id,
				'element_id' => $draft->element_id,
				'source'     => $draft->source,
				'name'       => $draft->form_name,
				'status'     => 'active',
			]
		);
		$draft->uuid    = Crypto::token( 16 );
		$draft->lang    = $draft->lang === '' ? I18n::current() : $draft->lang;
		if ( Settings::bool( 'general.store_submissions', true ) ) {
			SubmissionRepository::insert( $draft );
			FormRepository::touch_submission( $draft->form_id );
		}
		do_action( 'fnfe_submission_captured', $draft, FormRepository::config( $draft->form_id ) );
		return $draft;
	}
}
