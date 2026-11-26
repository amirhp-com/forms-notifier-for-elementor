<?php
/**
 * Elementor submissions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Reads the submissions Elementor collected itself and imports them without notifying anybody. */
final class SubmissionImporter {

	/** Rows per step. */
	private const BATCH = 50;

	/** Post meta in which Elementor keeps labels and types of forms it saw. */
	private const SNAPSHOT = '__elementor_forms_snapshot';

	/** Elementor's tables exist. */
	public static function is_available(): bool {
		return self::table_exists( self::submissions() ) && self::table_exists( self::values() );
	}

	/**
	 * How much there is to import.
	 *
	 * @param string $form_key Form key, '' = all forms.
	 * @return array{available:bool,total:int,imported:int,forms:array[]}
	 */
	public static function status( string $form_key = '' ): array {
		global $wpdb;

		$out = [
			'available' => self::is_available(),
			'total'     => 0,
			'imported'  => 0,
			'forms'     => [],
		];
		if ( ! $out['available'] ) {
			return $out;
		}

		list( $where, $params ) = self::where( $form_key );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds literals and placeholders only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.post_id, e.element_id, MAX( e.form_name ) AS name, COUNT( e.id ) AS total, COUNT( s.known ) AS imported FROM %i e
				LEFT JOIN ( SELECT DISTINCT elementor_submission_id AS known FROM %i WHERE elementor_submission_id > 0 ) s ON s.known = e.id
				WHERE $where GROUP BY e.post_id, e.element_id ORDER BY e.post_id ASC, e.element_id ASC",
				array_merge( [ self::submissions(), SubmissionRepository::table() ], $params )
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $rows as $row ) {
			$key  = absint( $row['post_id'] ) . ':' . $row['element_id'];
			$form = FormRepository::find_by_key( $key );

			$out['total']    += (int) $row['total'];
			$out['imported'] += (int) $row['imported'];
			$out['forms'][]   = [
				'form_key' => $key,
				'form_id'  => $form ? $form['id'] : 0,
				'name'     => $form ? $form['name'] : FieldNormalizer::clean( (string) $row['name'] ),
				'total'    => (int) $row['total'],
				'imported' => (int) $row['imported'],
			];
		}
		return $out;
	}

	/**
	 * Import the next batch.
	 *
	 * @param string $form_key Form key, '' = all forms.
	 * @param int    $after    Elementor id to continue after.
	 * @param int    $limit    Rows, at most 50.
	 * @return array{imported:int,skipped:int,cursor:int,done:bool}
	 */
	public static function import( string $form_key = '', int $after = 0, int $limit = self::BATCH ): array {
		global $wpdb;

		$limit = max( 1, min( self::BATCH, $limit ) );
		$out   = [
			'imported' => 0,
			'skipped'  => 0,
			'cursor'   => max( 0, $after ),
			'done'     => true,
		];
		if ( ! self::is_available() ) {
			return $out;
		}

		list( $where, $params ) = self::where( $form_key );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds literals and placeholders only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.* FROM %i e WHERE e.id > %d AND $where ORDER BY e.id ASC LIMIT %d",
				array_merge( [ self::submissions(), $out['cursor'] ], $params, [ $limit ] )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $rows ) {
			return $out;
		}

		$known = self::known( array_map( 'intval', array_column( $rows, 'id' ) ) );
		$forms = [];
		foreach ( $rows as $row ) {
			$id            = (int) $row['id'];
			$out['cursor'] = $id;
			$saved         = isset( $known[ $id ] ) ? null : Capture::ingest( self::draft( $row ) );
			if ( $saved && $saved->id ) {
				++$out['imported'];
				$forms[ $saved->form_id ] = true;
			} else {
				++$out['skipped'];
			}
		}
		array_map( [ self::class, 'recount' ], array_keys( $forms ) );
		$out['done'] = count( $rows ) < $limit;
		return $out;
	}

	/**
	 * Id of the row Elementor just saved for a form, 0 when there is none.
	 *
	 * @param int    $post_id    Document id.
	 * @param string $element_id Element id.
	 */
	public static function latest_id( int $post_id, string $element_id ): int {
		global $wpdb;

		$table = self::submissions();
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		$suppress = $wpdb->suppress_errors( true );
		$id       = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE post_id = %d AND element_id = %s AND created_at_gmt >= %s ORDER BY id DESC LIMIT 1',
				$table,
				$post_id,
				$element_id,
				gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) - 15 )
			)
		);
		$wpdb->suppress_errors( $suppress );
		return $id;
	}

	/**
	 * Draft of one Elementor row.
	 *
	 * @param array $row Row.
	 */
	private static function draft( array $row ): Submission {
		global $wpdb;

		$draft              = new Submission();
		$draft->post_id     = absint( $row['post_id'] );
		$draft->element_id  = sanitize_text_field( (string) $row['element_id'] );
		$draft->form_key    = $draft->post_id . ':' . $draft->element_id;
		$form               = FormRepository::find_by_key( $draft->form_key );
		$draft->source      = $form ? (string) $form['source'] : 'pro';
		$draft->form_name   = (string) $row['form_name'] !== '' ? (string) $row['form_name'] : ( $form ? (string) $form['name'] : $draft->element_id );
		$draft->status      = ! empty( $row['is_read'] ) ? 'read' : 'new';
		$draft->created_gmt = preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $row['created_at_gmt'] ) ? (string) $row['created_at_gmt'] : '';

		$types  = self::definitions( $draft, $form );
		$values = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT `key`, value FROM %i WHERE submission_id = %d ORDER BY id ASC', self::values(), (int) $row['id'] ), ARRAY_A );
		foreach ( $values as $value ) {
			$id   = sanitize_text_field( (string) $value['key'] );
			$type = (string) ( $types[ $id ]['type'] ?? 'text' );
			if ( $id === '' ) {
				continue;
			}
			$draft->fields[ $id ] = [
				'id'      => $id,
				'type'    => $type,
				'label'   => (string) ( $types[ $id ]['label'] ?? '' ),
				'raw'     => (string) $value['value'],
				'is_file' => $type === 'upload',
			];
			if ( $type === 'upload' ) {
				$draft->files = array_merge( $draft->files, self::files( $id, (string) $value['value'] ) );
			}
		}

		$user = absint( $row['user_id'] ) ? get_userdata( absint( $row['user_id'] ) ) : false;

		$draft->meta = [
			'page_url'                => esc_url_raw( (string) $row['referer'], [ 'http', 'https' ] ),
			'page_title'              => FieldNormalizer::label( (string) ( $row['referer_title'] ?? '' ), '', '' ),
			'user_id'                 => $user ? (int) $user->ID : 0,
			'user_login'              => $user ? (string) $user->user_login : '',
			'user_email'              => $user ? (string) $user->user_email : '',
			'user_ip'                 => filter_var( (string) $row['user_ip'], FILTER_VALIDATE_IP ) ? (string) $row['user_ip'] : '',
			'user_agent'              => mb_substr( sanitize_text_field( (string) $row['user_agent'] ), 0, 400 ),
			'via'                     => 'import',
			'elementor_submission_id' => (int) $row['id'],
			'editor'                  => [],
			'form'                    => [ 'fields' => FieldNormalizer::definitions( array_values( $types ) ) ],
		];
		return $draft;
	}

	/**
	 * Types and labels: the form as it is now, completed by Elementor's snapshot.
	 *
	 * @param Submission $draft Draft with its identity.
	 * @param array|null $form  Form row.
	 * @return array<string,array>
	 */
	private static function definitions( Submission $draft, ?array $form ): array {
		$out = [];
		foreach ( json_decode( (string) get_post_meta( $draft->post_id, self::SNAPSHOT, true ), true ) ?: [] as $snapshot ) {
			if ( is_array( $snapshot ) && (string) ( $snapshot['id'] ?? '' ) === $draft->element_id && is_array( $snapshot['fields'] ?? null ) ) {
				$out = array_column( array_filter( $snapshot['fields'], 'is_array' ), null, 'id' );
			}
		}
		foreach ( $form ? $form['fields'] : [] as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) ) {
				$out[ $field['id'] ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Files behind the URLs Elementor stored. They stay where they are. Only files in Elementor's own upload folder count:
	 * when a visitor sends no file, Elementor stores whatever text was posted for the field, so any other URL is typed.
	 *
	 * @param string $field_id Field id.
	 * @param string $value    URLs joined with " , ".
	 */
	private static function files( string $field_id, string $value ): array {
		$out = [];
		$folder = PathGuard::forms_dir();
		foreach ( $folder === '' ? [] : preg_split( '/\s+,\s+|,\s*(?=https?:)/', $value ) as $url ) {
			$path = PathGuard::url_to_path( trim( $url ), $folder );
			if ( $path !== '' ) {
				$out[] = AttachmentFactory::make( $field_id, $path, trim( $url ) );
			}
		}
		return $out;
	}

	/**
	 * Elementor ids that were captured or imported before.
	 *
	 * @param int[] $ids Elementor ids.
	 * @return array<int,bool>
	 */
	private static function known( array $ids ): array {
		global $wpdb;
		$holders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $holders holds placeholders only.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT elementor_submission_id FROM %i WHERE elementor_submission_id IN ( $holders )", array_merge( [ SubmissionRepository::table() ], $ids ) ) );
		return array_fill_keys( array_map( 'intval', $found ), true );
	}

	/**
	 * Counter and last date of a form after an import.
	 *
	 * @param int $form_id Form row id.
	 */
	private static function recount( int $form_id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, MAX( created_gmt ) AS last_date FROM %i WHERE form_id = %d', SubmissionRepository::table(), $form_id ), ARRAY_A );
		if ( $row ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET submissions_count = CASE WHEN submissions_count > %d THEN submissions_count ELSE %d END, last_submission_gmt = %s WHERE id = %d',
					FormRepository::table(),
					(int) $row['total'],
					(int) $row['total'],
					(string) $row['last_date'],
					$form_id
				)
			);
		}
	}

	/**
	 * Conditions on Elementor's table (alias e).
	 *
	 * @param string $form_key Form key, '' = all forms.
	 * @return array{0:string,1:array}
	 */
	private static function where( string $form_key ): array {
		if ( $form_key === '' ) {
			return [ 'e.status <> %s', [ 'trash' ] ];
		}
		$parts = explode( ':', $form_key, 2 );
		return [ 'e.status <> %s AND e.post_id = %d AND e.element_id = %s', [ 'trash', absint( $parts[0] ), $parts[1] ?? '' ] ];
	}

	/** Elementor's submissions table. */
	private static function submissions(): string {
		global $wpdb;
		return $wpdb->prefix . 'e_submissions';
	}

	/** Elementor's values table. */
	private static function values(): string {
		global $wpdb;
		return $wpdb->prefix . 'e_submissions_values';
	}

	/**
	 * A table exists. Checked once per request.
	 *
	 * @param string $table Table name.
	 */
	private static function table_exists( string $table ): bool {
		static $found = [];
		global $wpdb;
		if ( empty( $found[ $table ] ) ) {
			$found[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}
		return $found[ $table ];
	}
}
