<?php
/**
 * Capture pipeline.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Elementor\FieldNormalizer;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for every adapter.
 *
 * Adapters may put facts about the form into meta 'form' ([ fields, editor, post_title, post_type ]); Capture moves them
 * to the forms table and removes the key. A draft with meta 'via' = 'import' is history: it is saved whatever the
 * switches of its form say, keeps its date and triggers no notification.
 */
final class Capture {

	/** @var array<string,bool> */
	private static $seen = [];

	/**
	 * Handle one native submit event. Idempotent per event key in a request.
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
		return self::process( $draft, true );
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
		return self::process( $draft, false );
	}

	/**
	 * Drop a draft: stored copies are removed and the hook fires.
	 *
	 * @param Submission $draft  Draft.
	 * @param string     $reason disabled | duplicate | filtered | empty.
	 */
	public static function skip( Submission $draft, string $reason ): void {
		self::discard_files( $draft->files );
		$draft->files = [];
		do_action( 'fnfe_submission_skipped', $draft, $reason );
	}

	/**
	 * The form notifies at all, given how the submission arrived.
	 *
	 * @param FormConfig $form   Form config.
	 * @param array      $editor Editor overrides.
	 * @param string     $via    action | auto | api | test.
	 */
	public static function is_active( FormConfig $form, array $editor, string $via ): bool {
		if ( Resolver::form_active( $form, array_merge( $editor, [ 'via' => $via ] ) ) ) {
			return true;
		}
		return $via === 'action'
			&& Settings::str( 'general.capture_mode', 'all' ) === 'selected'
			&& ( $editor['fnfe_enabled'] ?? '' ) !== 'no'
			&& $form->enabled() !== 'off';
	}

	/**
	 * The pipeline.
	 *
	 * @param Submission $s     Draft.
	 * @param bool       $guard Duplicate guard is on.
	 */
	private static function process( Submission $s, bool $guard ): ?Submission {
		$import = ( $s->meta['via'] ?? '' ) === 'import';
		$editor = is_array( $s->meta['editor'] ?? null ) ? $s->meta['editor'] : [];

		$s->form_key  = mb_substr( $s->form_key, 0, 64 );
		$s->form_name = mb_substr( FieldNormalizer::clean( $s->form_name ), 0, 191 );
		$s->form_id   = self::form_row( $s, $import );
		$form         = FormRepository::config( $s->form_id );

		if ( ! $import && ! self::is_active( $form, $editor, (string) ( $s->meta['via'] ?? '' ) ) ) {
			self::skip( $s, 'disabled' );
			return null;
		}

		FieldNormalizer::normalize( $s, $form, ! $guard );
		self::drop_redacted_files( $s );
		if ( FieldNormalizer::is_empty( $s ) ) {
			self::skip( $s, 'empty' );
			return null;
		}

		if ( ! apply_filters( 'fnfe_should_capture', true, $s, $form ) ) {
			self::skip( $s, 'filtered' );
			return null;
		}

		self::apply_privacy( $s );
		if ( $guard && DuplicateGuard::is_duplicate( $s ) ) {
			self::skip( $s, 'duplicate' );
			return null;
		}

		if ( $s->lang === '' ) {
			$s->lang = LanguageDetector::detect( $s );
		}
		$s->files = self::safe_files( $s->files );
		$s->uuid  = Crypto::token( 16 );
		if ( ! $import || $s->created_gmt === '' ) {
			$s->created_gmt = gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) );
		}

		$filtered = apply_filters( 'fnfe_submission', $s );
		$s        = $filtered instanceof Submission ? $filtered : $s;

		if ( $import || Settings::bool( 'general.store_submissions', true ) ) {
			SubmissionRepository::insert( $s );
			if ( ! $import ) {
				FormRepository::touch_submission( $s->form_id );
			}
		}

		if ( $import ) {
			do_action( 'fnfe_submission_imported', $s, $form );
		} else {
			do_action( 'fnfe_submission_captured', $s, $form );
		}
		return $s;
	}

	/**
	 * Create or refresh the form row.
	 *
	 * @param Submission $s      Draft; meta 'form' is removed.
	 * @param bool       $import Existing rows are left alone.
	 * @return int form id
	 */
	private static function form_row( Submission $s, bool $import ): int {
		$facts = is_array( $s->meta['form'] ?? null ) ? $s->meta['form'] : [];
		unset( $s->meta['form'] );

		if ( $import ) {
			$row = FormRepository::find_by_key( $s->form_key );
			if ( $row ) {
				return $row['id'];
			}
		}

		$data = [
			'post_id'    => $s->post_id,
			'element_id' => $s->element_id,
			'source'     => $s->source,
			'name'       => $s->form_name,
			'status'     => $import && ( ! $s->post_id || ! get_post( $s->post_id ) ) ? 'missing' : 'active',
		];
		$post = $s->post_id ? get_post( $s->post_id ) : null;
		if ( $post ) {
			$data['post_title'] = FieldNormalizer::clean( (string) $post->post_title );
			$data['post_type']  = (string) $post->post_type;
		}
		foreach ( [ 'fields', 'editor', 'post_title', 'post_type' ] as $key ) {
			if ( array_key_exists( $key, $facts ) ) {
				$data[ $key ] = $facts[ $key ];
			}
		}
		return FormRepository::upsert( $s->form_key, $data );
	}

	/**
	 * Address and user agent as the settings allow them.
	 *
	 * @param Submission $s Submission.
	 */
	private static function apply_privacy( Submission $s ): void {
		$mode = Settings::str( 'general.store_ip', 'full' );
		if ( isset( $s->meta['user_ip'] ) ) {
			$ip = is_string( $s->meta['user_ip'] ) ? $s->meta['user_ip'] : '';
			if ( $mode === 'anonymize' ) {
				$ip = Request::anonymize_ip( $ip );
			} elseif ( $mode !== 'full' ) {
				$ip = '';
			}
			$s->meta['user_ip'] = $ip;
		}
		if ( isset( $s->meta['user_agent'] ) && ! Settings::bool( 'general.store_user_agent', true ) ) {
			$s->meta['user_agent'] = '';
		}
	}

	/**
	 * Files that may be used; copies of the others are removed.
	 *
	 * @param Attachment[] $files Files.
	 * @return Attachment[]
	 */
	private static function safe_files( array $files ): array {
		$out = [];
		foreach ( $files as $file ) {
			if ( ! $file instanceof Attachment ) {
				continue;
			}
			if ( PathGuard::is_denied_ext( $file->ext ) || PathGuard::is_denied_ext( pathinfo( $file->name, PATHINFO_EXTENSION ) ) || ( $file->path !== '' && ! PathGuard::is_safe( $file->path ) ) ) {
				self::discard_files( [ $file ] );
				continue;
			}
			$out[] = $file;
		}
		return $out;
	}

	/**
	 * Uploads of a redacted field are not kept either.
	 *
	 * @param Submission $s Submission.
	 */
	private static function drop_redacted_files( Submission $s ): void {
		$keep = [];
		$drop = [];
		foreach ( $s->files as $file ) {
			$field = $s->field( $file->field_id );
			if ( $field && ! empty( $field['redacted'] ) ) {
				$drop[] = $file;
			} else {
				$keep[] = $file;
			}
		}
		self::discard_files( $drop );
		$s->files = $keep;
	}

	/**
	 * Delete stored copies that were never saved.
	 *
	 * @param array $files Files.
	 */
	private static function discard_files( array $files ): void {
		foreach ( $files as $file ) {
			if ( $file instanceof Attachment && $file->stored && ! $file->id ) {
				Storage::delete( $file );
			}
		}
	}
}
