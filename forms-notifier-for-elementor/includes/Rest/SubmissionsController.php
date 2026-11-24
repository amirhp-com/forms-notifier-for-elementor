<?php
/**
 * Submissions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Capture\FileCleanup;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Elementor\SubmissionImporter;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** /submissions. */
final class SubmissionsController extends Controller {

	private const STATUSES = [ 'new', 'read', 'done', 'spam', 'trash' ];

	/** Most ids of one bulk request. */
	private const MAX_BULK = 500;

	/** Seconds one import request may take. */
	private const IMPORT_BUDGET = 10;

	/** Register the routes. */
	public function register_routes(): void {
		$view   = $this->can( Capabilities::SUBMISSIONS );
		$manage = $this->can( Capabilities::MANAGE );
		$id     = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];

		register_rest_route(
			self::NS,
			'/submissions',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'items' ],
				'permission_callback' => $view,
				'args'                => array_merge( $this->paging_args(), $this->filter_args() ),
			]
		);
		register_rest_route(
			self::NS,
			'/submissions/export',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export' ],
				'permission_callback' => $view,
				'args'                => array_merge(
					$this->filter_args(),
					[ 'format' => $this->string_arg( 'csv', [ 'csv' ], 'sanitize_key' ) ]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/submissions/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk' ],
				'permission_callback' => $view,
				'args'                => [
					'action' => array_merge( $this->string_arg( '', [ 'read', 'done', 'spam', 'trash', 'delete' ], 'sanitize_key' ), [ 'required' => true ] ),
					'ids'    => [
						'type'              => 'array',
						'required'          => true,
						'items'             => [ 'type' => 'integer' ],
						'sanitize_callback' => 'wp_parse_id_list',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/submissions/import',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'import_status' ],
					'permission_callback' => $manage,
					'args'                => [ 'form_id' => $this->id_arg() ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'import' ],
					'permission_callback' => $manage,
					'args'                => [
						'form_id' => $this->id_arg(),
						'cursor'  => $this->id_arg(),
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/submissions/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'item' ],
					'permission_callback' => $view,
					'args'                => $id,
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $view,
					'args'                => array_merge( $id, [ 'status' => array_merge( $this->string_arg( '', self::STATUSES, 'sanitize_key' ), [ 'required' => true ] ) ] ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => $manage,
					'args'                => $id,
				],
			]
		);
		register_rest_route(
			self::NS,
			'/submissions/(?P<id>\d+)/resend',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resend' ],
				'permission_callback' => $this->can( Capabilities::LOGS ),
				'args'                => array_merge( $id, [ 'channels' => self::channels_arg() ] ),
			]
		);
	}

	/**
	 * List with the number of rows per status. Without a status filter spam and trash are left out.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function items( \WP_REST_Request $request ): \WP_REST_Response {
		$args = self::filters( $request ) + [
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		];

		$response = $this->list_response( SubmissionRepository::query( $args ), [ Controller::class, 'submission_summary' ] );

		$data = $response->get_data();
		foreach ( self::STATUSES as $status ) {
			$data['counts'][ $status ] = SubmissionRepository::count( array_merge( $args, [ 'status' => $status ] ) );
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * One submission with its messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item( \WP_REST_Request $request ) {
		$s = SubmissionRepository::find( (int) $request['id'] );
		if ( ! $s ) {
			return $this->not_found();
		}
		return rest_ensure_response(
			[
				'submission' => self::submission_full( $s ),
				'messages'   => array_map( [ Controller::class, 'message_summary' ], MessageRepository::for_submission( $s->id ) ),
			]
		);
	}

	/**
	 * Change the status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! SubmissionRepository::find( $id ) ) {
			return $this->not_found();
		}
		self::set_status( $id, (string) $request['status'] );
		return rest_ensure_response(
			[
				'ok'         => true,
				'submission' => self::submission_full( SubmissionRepository::find( $id ) ),
			]
		);
	}

	/**
	 * Delete for good.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		return FileCleanup::delete_submission( (int) $request['id'] ) ? rest_ensure_response( [ 'ok' => true ] ) : $this->not_found();
	}

	/**
	 * Plan and queue the notifications again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resend( \WP_REST_Request $request ) {
		$s = SubmissionRepository::find( (int) $request['id'] );
		if ( ! $s ) {
			return $this->not_found();
		}
		$ids = Dispatcher::dispatch( $s, FormRepository::config( $s->form_id ), (array) $request['channels'] );
		if ( $ids ) {
			Queue::kick();
		}
		return rest_ensure_response(
			[
				'ok'     => true,
				'queued' => count( $ids ),
			]
		);
	}

	/**
	 * Same action for many submissions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = (string) $request['action'];
		$ids    = array_slice( array_filter( (array) $request['ids'] ), 0, self::MAX_BULK );
		if ( $action === 'delete' && ! Capabilities::can( Capabilities::MANAGE ) ) {
			return $this->error( 'forbidden', __( 'You do not have access to this.', 'forms-notifier-for-elementor' ), rest_authorization_required_code() );
		}
		$affected = 0;
		foreach ( $ids as $id ) {
			$affected += ( $action === 'delete' ? FileCleanup::delete_submission( (int) $id ) : self::set_status( (int) $id, $action ) ) ? 1 : 0;
		}
		return rest_ensure_response(
			[
				'ok'       => true,
				'affected' => $affected,
			]
		);
	}

	/**
	 * CSV download.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export( \WP_REST_Request $request ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary stream, no file of the site.
		$handle = fopen( 'php://temp/maxmemory:4194304', 'w+b' );
		if ( ! $handle ) {
			return $this->error( 'export_failed', __( 'The export could not be created.', 'forms-notifier-for-elementor' ), 500 );
		}
		self::write_csv( $handle, self::filters( $request ) );

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="submissions-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'private, no-store' );

		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $served_request ) use ( $request, $handle ) {
				if ( $served || $served_request !== $request ) {
					return $served;
				}
				while ( ob_get_level() > 0 && ob_end_clean() ) {
					continue;
				}
				rewind( $handle );
				fpassthru( $handle );
				fclose( $handle );
				return true;
			},
			10,
			3
		);
		// phpcs:enable
		return $response;
	}

	/**
	 * What can be imported from Elementor's own submissions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_status( \WP_REST_Request $request ) {
		$key = $this->import_key( (int) $request['form_id'] );
		return is_wp_error( $key ) ? $key : rest_ensure_response( SubmissionImporter::status( $key ) );
	}

	/**
	 * Import for a few seconds; call again with the returned cursor until done.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$key = $this->import_key( (int) $request['form_id'] );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! SubmissionImporter::is_available() ) {
			return $this->error( 'import_unavailable', __( 'Elementor has not collected any submissions on this site.', 'forms-notifier-for-elementor' ), 409 );
		}
		if ( ! Settings::bool( 'general.store_submissions', true ) ) {
			return $this->error( 'storing_off', __( 'Storing submissions is switched off in the settings.', 'forms-notifier-for-elementor' ), 409 );
		}

		$deadline = microtime( true ) + self::IMPORT_BUDGET;
		$result   = [
			'imported' => 0,
			'skipped'  => 0,
			'cursor'   => (int) $request['cursor'],
			'done'     => false,
		];
		do {
			$step                = SubmissionImporter::import( $key, $result['cursor'] );
			$result['imported'] += $step['imported'];
			$result['skipped']  += $step['skipped'];
			$result['cursor']    = $step['cursor'];
			$result['done']      = $step['done'];
		} while ( ! $result['done'] && microtime( true ) < $deadline );

		return rest_ensure_response( array_merge( [ 'ok' => true ], $result ) );
	}

	/**
	 * Write the export.
	 *
	 * @param resource $handle Stream.
	 * @param array    $args   Filters.
	 */
	public static function write_csv( $handle, array $args ): void {
		$columns = [];
		self::each(
			$args,
			static function ( Submission $s ) use ( &$columns ) {
				foreach ( $s->fields as $id => $field ) {
					$columns[ $id ] = $columns[ $id ] ?? (string) $field['label'];
				}
			}
		);

		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Temporary stream.
		self::put(
			$handle,
			array_merge(
				[
					__( 'ID', 'forms-notifier-for-elementor' ),
					__( 'Date', 'forms-notifier-for-elementor' ),
					__( 'Form', 'forms-notifier-for-elementor' ),
					__( 'Status', 'forms-notifier-for-elementor' ),
					__( 'Language', 'forms-notifier-for-elementor' ),
					__( 'Page', 'forms-notifier-for-elementor' ),
					__( 'IP address', 'forms-notifier-for-elementor' ),
				],
				array_values( $columns )
			)
		);
		self::each(
			$args,
			static function ( Submission $s ) use ( $handle, $columns ) {
				$row = [
					(string) $s->id,
					get_date_from_gmt( $s->created_gmt, 'Y-m-d H:i:s' ),
					$s->form_name,
					$s->status,
					$s->lang,
					(string) ( $s->meta['page_url'] ?? '' ),
					(string) ( $s->meta['user_ip'] ?? '' ),
				];
				foreach ( array_keys( $columns ) as $id ) {
					$row[] = $s->value( (string) $id );
				}
				self::put( $handle, $row );
			}
		);
	}

	/**
	 * Change a status and tell others.
	 *
	 * @param int    $id     Submission id.
	 * @param string $status Status.
	 */
	private static function set_status( int $id, string $status ): bool {
		$s = SubmissionRepository::find( $id );
		if ( ! $s || ! SubmissionRepository::set_status( $id, $status ) ) {
			return false;
		}
		if ( $s->status !== $status ) {
			do_action( 'fnfe_submission_status_changed', $id, $status, $s->status, 'admin' );
		}
		return true;
	}

	/**
	 * Call back for every submission, page by page.
	 *
	 * @param array    $args     Filters.
	 * @param callable $callback Gets a Submission.
	 */
	private static function each( array $args, callable $callback ): void {
		$page = 0;
		do {
			++$page;
			$result = SubmissionRepository::query(
				array_merge(
					$args,
					[
						'page'     => $page,
						'per_page' => 100,
					]
				)
			);
			array_map( $callback, $result['items'] );
		} while ( $page * 100 < $result['total'] && $result['items'] );
	}

	/**
	 * One CSV row. Cells a spreadsheet would run as a formula are prefixed.
	 *
	 * @param resource $handle Stream.
	 * @param string[] $row    Cells.
	 */
	private static function put( $handle, array $row ): void {
		$row = array_map(
			static function ( string $cell ): string {
				return $cell !== '' && strpos( "=+-@\t\r\n", $cell[0] ) !== false ? "'" . $cell : $cell;
			},
			$row
		);
		fputcsv( $handle, $row, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Temporary stream.
	}

	/**
	 * Repository args of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function filters( \WP_REST_Request $request ): array {
		$status = array_values( array_intersect( wp_parse_list( (string) $request['status'] ), self::STATUSES ) );
		return [
			'form_id' => (int) $request['form_id'],
			'status'  => $status ? $status : [ 'new', 'read', 'done' ],
			'search'  => (string) $request['search'],
			'from'    => (string) $request['from'],
			'to'      => (string) $request['to'],
			'order'   => (string) $request['order'],
		];
	}

	/** Arg schemas of the filters. */
	private function filter_args(): array {
		return [
			'form_id' => $this->id_arg(),
			'status'  => $this->string_arg(),
			'search'  => $this->string_arg(),
			'from'    => $this->string_arg(),
			'to'      => $this->string_arg(),
			'order'   => $this->string_arg( 'desc', [ 'asc', 'desc' ], 'sanitize_key' ),
		];
	}

	/** Arg schema of an optional id. */
	private function id_arg(): array {
		return [
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/** Arg schema of a channel list. */
	private static function channels_arg(): array {
		return [
			'type'              => 'array',
			'default'           => [],
			'items'             => [ 'type' => 'string' ],
			'sanitize_callback' => static function ( $value ): array {
				return array_values( array_intersect( array_map( 'sanitize_key', wp_parse_list( $value ) ), array_keys( Registry::channels() ) ) );
			},
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/**
	 * Form key of an import.
	 *
	 * @param int $form_id Form row id, 0 = all.
	 * @return string|\WP_Error
	 */
	private function import_key( int $form_id ) {
		if ( ! $form_id ) {
			return '';
		}
		$row = FormRepository::find( $form_id );
		return $row ? (string) $row['form_key'] : $this->error( 'form_not_found', __( 'This form does not exist.', 'forms-notifier-for-elementor' ), 404 );
	}

	/** Unknown submission. */
	private function not_found(): \WP_Error {
		return $this->error( 'submission_not_found', __( 'This submission does not exist.', 'forms-notifier-for-elementor' ), 404 );
	}
}
