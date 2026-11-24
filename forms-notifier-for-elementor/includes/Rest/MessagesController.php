<?php
/**
 * Message log, queue, numbers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Alerts;
use AmirhpCom\FormsNotifier\Delivery\Digest;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\Delivery\Ticker;
use AmirhpCom\FormsNotifier\Delivery\Worker;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** /messages, /queue/run, /stats, /alerts/test, /digest/send. */
final class MessagesController extends Controller {

	private const STATUSES = [ 'queued', 'sending', 'sent', 'retry', 'failed', 'skipped', 'cancelled' ];

	/** Most rows of one export. */
	private const EXPORT_LIMIT = 20000;

	/** Most ids of one bulk action. */
	private const BULK_LIMIT = 200;

	/** Register the routes. */
	public function register_routes(): void {
		$logs   = $this->can( Capabilities::LOGS );
		$manage = $this->can( Capabilities::MANAGE );
		$id     = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];

		$this->route( '/messages', \WP_REST_Server::READABLE, 'index', $logs, $this->filter_args() + $this->paging_args() );
		$this->route( '/messages/export', \WP_REST_Server::READABLE, 'export', $logs, $this->filter_args() );
		$this->route(
			'/messages/bulk',
			\WP_REST_Server::CREATABLE,
			'bulk',
			$logs,
			[
				'action' => array_merge( $this->string_arg( '', [ 'retry', 'cancel', 'delete' ], 'sanitize_key' ), [ 'required' => true ] ),
				'ids'    => [
					'type'              => 'array',
					'required'          => true,
					'maxItems'          => self::BULK_LIMIT,
					'items'             => [ 'type' => 'integer' ],
					'sanitize_callback' => static function ( $ids ): array {
						return array_values( array_unique( array_filter( wp_parse_id_list( $ids ) ) ) );
					},
					'validate_callback' => 'rest_validate_request_arg',
				],
			]
		);
		$this->route( '/messages/(?P<id>\d+)', \WP_REST_Server::READABLE, 'show', $logs, $id );
		$this->route( '/messages/(?P<id>\d+)/retry', \WP_REST_Server::CREATABLE, 'retry', $logs, $id );
		$this->route( '/queue/run', \WP_REST_Server::CREATABLE, 'run', $logs, [] );
		$this->route(
			'/stats',
			\WP_REST_Server::READABLE,
			'stats',
			$this->can( ...Capabilities::all() ),
			[ 'range' => $this->string_arg( '7d', [ 'today', '7d', '30d', 'all' ], 'sanitize_text_field' ) ]
		);
		$this->route( '/alerts/test', \WP_REST_Server::CREATABLE, 'test_alert', $manage, $this->overlay_args() );
		$this->route( '/digest/send', \WP_REST_Server::CREATABLE, 'send_digest', $manage, $this->overlay_args() );
	}

	/**
	 * Log.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$args             = self::filters( $request );
		$args['page']     = (int) $request['page'];
		$args['per_page'] = (int) $request['per_page'];
		return $this->list_response( MessageRepository::query( $args ), [ self::class, 'message_summary' ] );
	}

	/**
	 * One entry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$row = MessageRepository::find( (int) $request['id'] );
		return $row ? rest_ensure_response( [ 'message' => self::message_full( $row ) ] ) : $this->not_found();
	}

	/**
	 * Send again now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( \WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = MessageRepository::find( $id );
		if ( ! $row ) {
			return $this->not_found();
		}
		if ( ! Queue::retry( $id ) ) {
			return $this->error( 'cannot_retry', self::refusal( $row ), 409 );
		}
		Worker::update_submission( (int) $row['submission_id'] );
		Queue::run_ids( [ $id ] );

		$row = MessageRepository::find( $id );
		return $row
			? rest_ensure_response(
				[
					'ok'      => $row['status'] === 'sent',
					'message' => self::message_summary( $row ),
				]
			)
			: $this->not_found();
	}

	/**
	 * Several entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		$action   = (string) $request['action'];
		$affected = 0;
		$touched  = [];
		foreach ( (array) $request['ids'] as $id ) {
			$row = MessageRepository::find( (int) $id );
			if ( ! $row ) {
				continue;
			}
			if ( $action === 'retry' ) {
				$done = Queue::retry( (int) $id );
			} elseif ( $action === 'cancel' ) {
				$done = Queue::cancel( (int) $id );
			} else {
				// A message that is being sent right now keeps its row.
				$busy = $row['status'] === 'sending' && (string) $row['locked_until_gmt'] > Queue::gmt();
				$done = ! $busy && MessageRepository::delete( (int) $id );
			}
			if ( $done ) {
				++$affected;
				$touched[ (int) $row['submission_id'] ] = true;
			}
		}
		foreach ( array_keys( $touched ) as $submission_id ) {
			Worker::update_submission( $submission_id );
		}
		if ( $action === 'retry' && $affected ) {
			Queue::kick();
		}
		return rest_ensure_response(
			[
				'ok'       => $affected > 0,
				'affected' => $affected,
			]
		);
	}

	/**
	 * CSV of the filtered log.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$args             = self::filters( $request );
		$args['per_page'] = 100;
		$args['page']     = 1;

		$csv  = "\xEF\xBB\xBF" . self::csv_line( [ 'id', 'date', 'channel', 'provider', 'purpose', 'recipient', 'status', 'attempts', 'error', 'form', 'submission' ] );
		$done = 0;
		do {
			$page = MessageRepository::query( $args );
			foreach ( $page['items'] as $row ) {
				$summary = self::message_summary( $row );
				$label   = $summary['recipient_label'] === '' ? '' : $summary['recipient_label'] . ' ';
				$csv    .= self::csv_line(
					[
						$summary['id'],
						$summary['created'],
						$summary['channel'],
						$summary['provider'],
						$summary['purpose'],
						$label === '' ? $summary['recipient'] : $label . '(' . $summary['recipient'] . ')',
						$summary['status'],
						$summary['attempts'],
						$summary['error'],
						$summary['form_name'],
						$summary['submission_id'],
					]
				);
			}
			$count = count( $page['items'] );
			$done += $count;
			++$args['page'];
		} while ( $count === 100 && $done < self::EXPORT_LIMIT );

		$response = new \WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="messages-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Cache-Control', 'private, no-store' );
		add_filter( 'rest_pre_serve_request', [ self::class, 'serve_csv' ], 10, 2 );
		return $response;
	}

	/**
	 * Print a CSV response as it is instead of as JSON.
	 *
	 * @param bool  $served Already served.
	 * @param mixed $result Response.
	 */
	public static function serve_csv( $served, $result ) {
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$headers = array_change_key_case( $result->get_headers() );
		$data    = $result->get_data();
		if ( ! is_string( $data ) || strpos( (string) ( $headers['content-type'] ?? '' ), 'text/csv' ) !== 0 ) {
			return $served;
		}
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; cells are quoted and neutralised by csv_line().
		return true;
	}

	/** Run the queue. */
	public function run(): \WP_REST_Response {
		MessageRepository::release_stale();
		return rest_ensure_response( Queue::run() );
	}

	/**
	 * Numbers, health and queue state.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function stats( \WP_REST_Request $request ): \WP_REST_Response {
		$data               = Stats::summary( (string) $request['range'] );
		$next               = Ticker::next_run();
		$data['by_channel'] = (object) $data['by_channel'];
		$data['health']     = Stats::health();
		$data['queue']      = [
			'pending'  => Queue::pending(),
			'next_run' => $next ? gmdate( 'Y-m-d\TH:i:s\Z', $next ) : null,
		];
		return rest_ensure_response( $data );
	}

	/**
	 * Test alert, also with unsaved values.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function test_alert( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->broadcast( $request, [ Alerts::class, 'test' ] );
	}

	/**
	 * Digest now, also with unsaved values.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function send_digest( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->broadcast( $request, [ Digest::class, 'send' ] );
	}

	/**
	 * One CSV line; cells that a spreadsheet would run as a formula are neutralised.
	 *
	 * @param array $cells Cells.
	 */
	public static function csv_line( array $cells ): string {
		$out = [];
		foreach ( $cells as $cell ) {
			$cell = is_scalar( $cell ) ? (string) $cell : '';
			if ( ! is_numeric( $cell ) || $cell[0] === '+' ) {
				$cell = preg_replace( '/^([=+\-@\t\r])/', "'$1", $cell );
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out ) . "\r\n";
	}

	/**
	 * Register one route.
	 *
	 * @param string   $path       Path.
	 * @param string   $methods    Methods.
	 * @param string   $callback   Method of this class.
	 * @param callable $permission Permission callback.
	 * @param array    $args       Args.
	 */
	private function route( string $path, string $methods, string $callback, callable $permission, array $args ): void {
		register_rest_route(
			self::NS,
			$path,
			[
				'methods'             => $methods,
				'callback'            => [ $this, $callback ],
				'permission_callback' => $permission,
				'args'                => $args,
			]
		);
	}

	/** Arg schemas of the log filters. */
	private function filter_args(): array {
		$date = [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $value ): bool {
				return is_string( $value ) && ( $value === '' || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) );
			},
		];
		$ids  = [
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
		return [
			'status'        => [
				'type'              => [ 'array', 'string' ],
				'default'           => [],
				'sanitize_callback' => static function ( $value ): array {
					return array_values( array_intersect( wp_parse_list( $value ), self::STATUSES ) );
				},
				'validate_callback' => static function ( $value ): bool {
					if ( ! is_string( $value ) && ! is_array( $value ) ) {
						return false;
					}
					$list = array_filter( wp_parse_list( $value ), 'strlen' );
					return array_diff( $list, self::STATUSES ) === [];
				},
			],
			'channel'       => $this->string_arg( '', [], 'sanitize_key' ),
			'purpose'       => $this->string_arg( '', [ '', 'admin', 'submitter', 'alert', 'digest', 'test' ], 'sanitize_key' ),
			'form_id'       => $ids,
			'submission_id' => $ids,
			'search'        => $this->string_arg(),
			'from'          => $date,
			'to'            => $date,
			'order'         => $this->string_arg( 'desc', [ 'asc', 'desc' ], 'sanitize_key' ),
		];
	}

	/**
	 * Query args of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function filters( \WP_REST_Request $request ): array {
		$args = [];
		foreach ( [ 'status', 'channel', 'purpose', 'form_id', 'submission_id', 'search', 'from', 'to', 'order' ] as $key ) {
			$args[ $key ] = $request[ $key ];
		}
		return $args;
	}

	/**
	 * Run a broadcast with the unsaved values of the request.
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param callable         $callback Returns the results.
	 */
	private function broadcast( \WP_REST_Request $request, callable $callback ): \WP_REST_Response {
		$this->apply_overlay( $request );
		try {
			$results = $callback();
		} catch ( \Throwable $e ) {
			Logger::error( 'alerts', $e->getMessage() );
			return rest_ensure_response( $this->action_result( [ 'message' => Logger::clean( $e->getMessage() ) ] ) );
		} finally {
			Settings::clear_overlay();
		}
		if ( ! $results ) {
			return rest_ensure_response(
				$this->action_result(
					[
						'message' => __( 'Switch on a channel that is ready and add a receiver first.', 'forms-notifier-for-elementor' ),
						'level'   => 'warning',
					]
				)
			);
		}

		$rows = [];
		$sent = 0;
		foreach ( $results as $item ) {
			$sent  += $item['result']->ok ? 1 : 0;
			$rows[] = [
				'channel'   => $item['channel'],
				'recipient' => $item['recipient'],
				'result'    => $item['result']->ok ? _x( 'Sent', 'message status', 'forms-notifier-for-elementor' ) : $item['result']->error,
			];
		}
		return rest_ensure_response(
			$this->action_result(
				[
					'ok'      => $sent === count( $results ),
					'message' => sprintf(
						/* translators: %1$d: messages sent, %2$d: all messages */
						__( 'Sent %1$d of %2$d messages.', 'forms-notifier-for-elementor' ),
						$sent,
						count( $results )
					),
					'level'   => $sent === count( $results ) ? 'success' : ( $sent ? 'warning' : 'danger' ),
					'rows'    => $rows,
					'columns' => [
						[
							'key'   => 'channel',
							'label' => __( 'Channel', 'forms-notifier-for-elementor' ),
						],
						[
							'key'   => 'recipient',
							'label' => __( 'Receiver', 'forms-notifier-for-elementor' ),
						],
						[
							'key'   => 'result',
							'label' => __( 'Result', 'forms-notifier-for-elementor' ),
						],
					],
				]
			)
		);
	}

	/**
	 * Why a row cannot be sent again.
	 *
	 * @param array $row Row.
	 */
	private static function refusal( array $row ): string {
		if ( $row['status'] === 'sent' ) {
			return __( 'This message was already sent.', 'forms-notifier-for-elementor' );
		}
		if ( $row['status'] === 'sending' ) {
			return __( 'This message is being sent right now.', 'forms-notifier-for-elementor' );
		}
		return __( 'The content of this message is not stored, and it cannot be written again from its submission.', 'forms-notifier-for-elementor' );
	}

	/** 404. */
	private function not_found(): \WP_Error {
		return $this->error( 'not_found', __( 'This message does not exist.', 'forms-notifier-for-elementor' ), 404 );
	}
}
