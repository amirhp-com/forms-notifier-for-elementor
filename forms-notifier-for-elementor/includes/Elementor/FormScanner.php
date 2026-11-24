<?php
/**
 * Form discovery.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Contracts\FormSourceInterface;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Finds the forms inside Elementor documents and keeps the forms table in step. */
final class FormScanner {

	/** Documents per query. */
	private const BATCH = 50;

	/** State key of a pass that is under way. */
	private const CURSOR = 'scan_cursor';

	private const STATUSES = [ 'publish', 'private', 'draft', 'pending', 'future' ];

	/**
	 * Continue the pass that is under way, or start one.
	 *
	 * @param int $seconds Time budget.
	 * @return array{found:int,added:int,updated:int,missing:int,done:bool,documents:int}
	 */
	public static function run( int $seconds = 10 ): array {
		if ( ! Lock::acquire( 'scan', 2 * MINUTE_IN_SECONDS ) ) {
			return self::result( self::progress(), false );
		}
		try {
			return self::pass( microtime( true ) + max( 1, $seconds ) );
		} finally {
			Lock::release( 'scan' );
		}
	}

	/**
	 * Scan one document.
	 *
	 * @param int  $post_id   Post id.
	 * @param bool $was_saved The document was saved in this request: read the stored data.
	 * @return array{found:int,added:int,updated:int,keys:string[]}
	 */
	public static function scan_post( int $post_id, bool $was_saved = false ): array {
		$out  = [
			'found'   => 0,
			'added'   => 0,
			'updated' => 0,
			'keys'    => [],
		];
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type === 'revision' || ! in_array( $post->post_status, self::STATUSES, true ) ) {
			return $out;
		}

		$elements = self::elements( $post_id, $was_saved );
		foreach ( self::sources() as $source ) {
			try {
				$forms = $source->find_forms( $elements, $post_id );
			} catch ( \Throwable $e ) {
				Logger::warning( 'scan', $e->getMessage(), [ 'post_id' => $post_id ] );
				continue;
			}
			foreach ( $forms as $form ) {
				$element_id = sanitize_text_field( (string) ( $form['element_id'] ?? '' ) );
				$template   = absint( $form['global_template_id'] ?? 0 );
				// A reference to a global widget: the form lives in the template, which is scanned on its own.
				if ( $element_id === '' || ( $template && $template !== $post_id ) ) {
					continue;
				}
				$state = self::save( $post, $element_id, $source->id(), $form );
				++$out['found'];
				$out['keys'][] = $post_id . ':' . $element_id;
				if ( isset( $out[ $state ] ) ) {
					++$out[ $state ];
				}
			}
		}
		return $out;
	}

	/**
	 * Incremental scan of a saved document; forms that disappeared from it become missing.
	 *
	 * @param object $document Elementor document.
	 */
	public static function on_document_saved( $document ): void {
		try {
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ( method_exists( $document, 'is_autosave' ) && $document->is_autosave() ) ) {
				return;
			}
			$post_id = (int) $document->get_main_id();
			$post    = get_post( $post_id );
			if ( ! $post || ! in_array( $post->post_status, self::STATUSES, true ) ) {
				return;
			}
			$found = self::scan_post( $post_id, true );
			foreach ( self::rows_of_post( $post_id ) as $row ) {
				if ( $row['status'] === 'active' && ! in_array( $row['form_key'], $found['keys'], true ) ) {
					self::set_status( $row, 'missing' );
				}
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'scan', $e->getMessage() );
		}
	}

	/**
	 * A trashed or restored post takes its forms along.
	 *
	 * @param string   $status New status.
	 * @param string   $old    Old status.
	 * @param \WP_Post $post   Post.
	 */
	public static function on_post_status( $status, $old, $post ): void {
		if ( $status === $old || ! $post instanceof \WP_Post || $post->post_type === 'revision' || ( $status !== 'trash' && $old !== 'trash' ) ) {
			return;
		}
		foreach ( self::rows_of_post( (int) $post->ID ) as $row ) {
			if ( $status === 'trash' && $row['status'] === 'active' ) {
				self::set_status( $row, 'trashed' );
			} elseif ( $status !== 'trash' && $row['status'] === 'trashed' ) {
				self::set_status( $row, 'active' );
			}
		}
	}

	/**
	 * A deleted post leaves its forms behind as missing.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_post_deleted( $post_id ): void {
		foreach ( self::rows_of_post( absint( $post_id ) ) as $row ) {
			if ( $row['status'] !== 'missing' ) {
				self::set_status( $row, 'missing' );
			}
		}
	}

	/**
	 * Process batches until the pass is complete or the time is over.
	 *
	 * @param float $deadline Time.
	 */
	private static function pass( float $deadline ): array {
		$progress = self::progress();

		do {
			$posts = self::candidates( (int) $progress['meta_id'] );
			foreach ( $posts as $row ) {
				$progress['meta_id'] = (int) $row['meta_id'];
				++$progress['documents'];
				if ( $row['post_status'] === 'trash' ) {
					foreach ( self::rows_of_post( (int) $row['post_id'] ) as $form ) {
						if ( $form['status'] === 'active' ) {
							self::set_status( $form, 'trashed' );
						}
					}
				} else {
					$found = self::scan_post( (int) $row['post_id'] );
					foreach ( [ 'found', 'added', 'updated' ] as $key ) {
						$progress[ $key ] += $found[ $key ];
					}
				}
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
			$done = count( $posts ) < self::BATCH && ( ! $posts || (int) end( $posts )['meta_id'] === $progress['meta_id'] );
		} while ( ! $done && microtime( true ) < $deadline );

		if ( ! $done ) {
			State::set( self::CURSOR, $progress );
			return self::result( $progress, false );
		}

		$progress['missing'] = self::finish( (string) $progress['started'] );
		State::delete( self::CURSOR );
		State::set( 'scan_last', (int) apply_filters( 'fnfe_now', time() ) );

		$result = self::result( $progress, true );
		do_action( 'fnfe_forms_scanned', $result );
		return $result;
	}

	/**
	 * Forms not seen since the pass began become missing.
	 *
	 * @param string $started Start of the pass, UTC.
	 * @return int rows changed
	 */
	private static function finish( string $started ): int {
		global $wpdb;

		$seen    = $wpdb->get_col( $wpdb->prepare( 'SELECT form_key FROM %i WHERE last_seen_gmt >= %s', FormRepository::table(), $started ) );
		$missing = FormRepository::mark_missing_except( $seen );

		// Forms of posts that left the trash for good.
		$trashed = $wpdb->get_results(
			$wpdb->prepare( 'SELECT f.form_key, f.post_id, f.status FROM %i f LEFT JOIN %i p ON p.ID = f.post_id WHERE f.status = %s AND p.ID IS NULL', FormRepository::table(), $wpdb->posts, 'trashed' ),
			ARRAY_A
		);
		foreach ( (array) $trashed as $row ) {
			self::set_status( $row, 'missing' );
			++$missing;
		}
		return $missing;
	}

	/**
	 * Next documents that may hold a form.
	 *
	 * @param int $after Last meta id.
	 * @return array[] meta_id, post_id, post_status
	 */
	private static function candidates( int $after ): array {
		global $wpdb;

		$markers = [];
		foreach ( self::sources() as $source ) {
			if ( ! method_exists( $source, 'markers' ) ) {
				// A third-party source that does not say what to look for gets every document.
				$markers = [ '"elType"' ];
				break;
			}
			$markers = array_merge( $markers, array_filter( (array) $source->markers(), 'is_string' ) );
		}
		if ( ! $markers ) {
			return [];
		}

		$statuses = array_merge( self::STATUSES, [ 'trash' ] );
		$like     = [];
		foreach ( $markers as $marker ) {
			$like[] = '%' . $wpdb->esc_like( $marker ) . '%';
		}

		$status_sql = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$like_sql   = implode( ' OR ', array_fill( 0, count( $like ), 'm.meta_value LIKE %s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The variables hold placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_id, m.post_id, p.post_status FROM %i m INNER JOIN %i p ON p.ID = m.post_id
				WHERE m.meta_key = %s AND m.meta_id > %d AND p.post_type <> 'revision' AND p.post_status IN ( $status_sql ) AND ( $like_sql )
				ORDER BY m.meta_id ASC LIMIT %d",
				array_merge( [ $wpdb->postmeta, $wpdb->posts, '_elementor_data', $after ], $statuses, $like, [ self::BATCH ] )
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : [];
	}

	/** Available sources. */
	private static function sources(): array {
		return array_filter(
			Registry::form_sources(),
			static function ( FormSourceInterface $source ): bool {
				return $source->is_available();
			}
		);
	}

	/**
	 * Element tree of a document.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $stored  Skip the document object, which converts empty documents while the editor is open.
	 */
	private static function elements( int $post_id, bool $stored = false ): array {
		if ( ! $stored && class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->documents ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
				if ( $document && method_exists( $document, 'get_elements_data' ) ) {
					$elements = $document->get_elements_data();
					if ( is_array( $elements ) && $elements ) {
						return $elements;
					}
				}
			} catch ( \Throwable $e ) {
				Logger::debug( 'scan', $e->getMessage(), [ 'post_id' => $post_id ] );
			}
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $data ) && $data !== '' ? json_decode( $data, true ) : $data;
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Insert or refresh one form.
	 *
	 * @param \WP_Post $post       Document.
	 * @param string   $element_id Element id.
	 * @param string   $source     Source id.
	 * @param array    $form       Output of find_forms().
	 * @return string added | updated | same
	 */
	private static function save( \WP_Post $post, string $element_id, string $source, array $form ): string {
		$key  = $post->ID . ':' . $element_id;
		$name = trim( (string) ( $form['name'] ?? '' ) );
		$data = [
			'post_id'    => (int) $post->ID,
			'element_id' => $element_id,
			'source'     => $source,
			/* translators: %1$s: element id of a form without a name */
			'name'       => mb_substr( $name === '' ? sprintf( __( 'Form %1$s', 'forms-notifier-for-elementor' ), $element_id ) : $name, 0, 191 ),
			'post_title' => mb_substr( FieldNormalizer::clean( (string) $post->post_title ), 0, 191 ),
			'post_type'  => (string) $post->post_type,
			'status'     => 'active',
			'fields'     => is_array( $form['fields'] ?? null ) ? $form['fields'] : [],
			'editor'     => is_array( $form['editor'] ?? null ) ? $form['editor'] : [],
		];
		$old  = FormRepository::find_by_key( $key );
		$id   = FormRepository::upsert( $key, $data );

		if ( ! $old ) {
			self::link_translation( $id, $post, $element_id );
			return 'added';
		}
		foreach ( $data as $column => $value ) {
			if ( $old[ $column ] !== $value ) {
				return 'updated';
			}
		}
		return 'same';
	}

	/**
	 * A new form inside a translation uses the settings of the same form in the original.
	 *
	 * @param int      $id         Form row id.
	 * @param \WP_Post $post       Document.
	 * @param string   $element_id Element id.
	 */
	private static function link_translation( int $id, \WP_Post $post, string $element_id ): void {
		$original = self::original( $post );
		if ( ! $id || ! $original || $original === (int) $post->ID ) {
			return;
		}
		$row = FormRepository::find_by_key( $original . ':' . $element_id );
		if ( ! $row ) {
			// The original may come later in the pass. It is an original itself, so this does not go round in circles.
			self::scan_post( $original );
			$row = FormRepository::find_by_key( $original . ':' . $element_id );
		}
		if ( $row && $row['id'] !== $id ) {
			// A linked form is followed once only: point at the end of the chain.
			FormRepository::save_config( $id, [ 'link' => $row['config']['link'] ? $row['config']['link'] : $row['id'] ] );
		}
	}

	/**
	 * Post in the default language a post translates, 0 when there is none.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function original( \WP_Post $post ): int {
		$provider = I18n::provider();
		if ( $provider === 'wpml' ) {
			return absint( apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, I18n::default_lang() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		}
		if ( $provider === 'polylang' && function_exists( 'pll_get_post' ) ) {
			return absint( pll_get_post( $post->ID, I18n::default_lang() ) );
		}
		return 0;
	}

	/**
	 * Forms of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array[] form_key, post_id, status
	 */
	private static function rows_of_post( int $post_id ): array {
		global $wpdb;
		if ( $post_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_key, post_id, status FROM %i WHERE post_id = %d', FormRepository::table(), $post_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Change the status of a form.
	 *
	 * @param array  $row    Row with form_key.
	 * @param string $status active | missing | trashed.
	 */
	private static function set_status( array $row, string $status ): void {
		FormRepository::upsert( (string) $row['form_key'], [ 'status' => $status ] );
	}

	/** Progress of the pass under way, or a fresh one. */
	private static function progress(): array {
		$stored = State::get( self::CURSOR, [] );
		$now    = (int) apply_filters( 'fnfe_now', time() );
		$fresh  = [
			'meta_id'   => 0,
			'started'   => gmdate( 'Y-m-d H:i:s', $now ),
			'found'     => 0,
			'added'     => 0,
			'updated'   => 0,
			'missing'   => 0,
			'documents' => 0,
		];
		// A pass nobody continued for a day starts over.
		if ( ! is_array( $stored ) || empty( $stored['started'] ) || (int) strtotime( $stored['started'] . ' UTC' ) < $now - DAY_IN_SECONDS ) {
			return $fresh;
		}
		return array_merge( $fresh, array_intersect_key( $stored, $fresh ) );
	}

	/**
	 * Result for callers.
	 *
	 * @param array $progress Progress.
	 * @param bool  $done     The pass is complete.
	 */
	private static function result( array $progress, bool $done ): array {
		return [
			'found'     => (int) $progress['found'],
			'added'     => (int) $progress['added'],
			'updated'   => (int) $progress['updated'],
			'missing'   => (int) $progress['missing'],
			'done'      => $done,
			'documents' => (int) $progress['documents'],
		];
	}
}
