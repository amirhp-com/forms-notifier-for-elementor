<?php
/**
 * Forms.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Capture\FileCleanup;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Elementor\FormScanner;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** /forms. */
final class FormsController extends Controller {

	/** Seconds one scan request may take. */
	private const SCAN_BUDGET = 20;

	/** Register the routes. */
	public function register_routes(): void {
		$forms = $this->can( Capabilities::FORMS );
		$id    = [
			'id' => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];

		register_rest_route(
			self::NS,
			'/forms',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'items' ],
				'permission_callback' => $forms,
				'args'                => array_merge(
					$this->paging_args(),
					[
						'search'  => $this->string_arg(),
						'status'  => $this->string_arg(),
						'source'  => $this->string_arg(),
						'orderby' => $this->string_arg( 'name', [ 'name', 'last_submission', 'last_submission_gmt', 'submissions_count' ], 'sanitize_key' ),
						'order'   => $this->string_arg( 'asc', [ 'asc', 'desc' ], 'sanitize_key' ),
					]
				),
			]
		);
		register_rest_route(
			self::NS,
			'/forms/scan',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'scan' ],
				'permission_callback' => $forms,
				'args'                => [],
			]
		);
		register_rest_route(
			self::NS,
			'/forms/key/(?P<key>\d+:[A-Za-z0-9_-]{1,40})',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'item_by_key' ],
				'permission_callback' => $forms,
				'args'                => [
					'key' => array_merge( $this->string_arg(), [ 'required' => true ] ),
				],
			]
		);
		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'item' ],
					'permission_callback' => $forms,
					'args'                => $id,
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $forms,
					'args'                => array_merge( $id, [ 'config' => array_merge( $this->object_arg(), [ 'required' => true ] ) ] ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => $forms,
					'args'                => array_merge(
						$id,
						[
							'with_submissions' => [
								'type'              => 'boolean',
								'default'           => false,
								'sanitize_callback' => 'rest_sanitize_boolean',
								'validate_callback' => 'rest_validate_request_arg',
							],
						]
					),
				],
			]
		);
		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test' ],
				'permission_callback' => $forms,
				'args'                => array_merge(
					$id,
					[
						'channels' => [
							'type'              => 'array',
							'default'           => [],
							'items'             => [ 'type' => 'string' ],
							'sanitize_callback' => static function ( $value ): array {
								return array_values( array_intersect( array_map( 'sanitize_key', wp_parse_list( $value ) ), array_keys( Registry::channels() ) ) );
							},
							'validate_callback' => 'rest_validate_request_arg',
						],
						'lang'     => $this->string_arg(),
					]
				),
			]
		);
	}

	/**
	 * List. The envelope also says when the last scan ended.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function items( \WP_REST_Request $request ): \WP_REST_Response {
		$orderby  = (string) $request['orderby'];
		$response = $this->list_response(
			FormRepository::query(
				[
					'search'   => (string) $request['search'],
					'status'   => array_map( 'sanitize_key', wp_parse_list( (string) $request['status'] ) ),
					'source'   => array_map( 'sanitize_key', wp_parse_list( (string) $request['source'] ) ),
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
					'orderby'  => $orderby === 'last_submission' ? 'last_submission_gmt' : $orderby,
					'order'    => (string) $request['order'],
				]
			),
			[ Controller::class, 'form_summary' ]
		);

		$last = (int) State::get( 'scan_last', 0 );
		$data = $response->get_data();

		$data['last_scan'] = $last ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : null;
		$response->set_data( $data );
		return $response;
	}

	/** Scan for up to 20 seconds; call again while done is false. */
	public function scan(): \WP_REST_Response {
		return rest_ensure_response( FormScanner::run( self::SCAN_BUDGET ) );
	}

	/**
	 * One form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item( \WP_REST_Request $request ) {
		$row = FormRepository::find( (int) $request['id'] );
		return $row ? rest_ensure_response( self::full( $row ) ) : $this->not_found();
	}

	/**
	 * One form by key; its document is scanned when the form is not known yet.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item_by_key( \WP_REST_Request $request ) {
		$key = (string) $request['key'];
		$row = FormRepository::find_by_key( $key );
		if ( ! $row ) {
			FormScanner::scan_post( absint( $key ) );
			$row = FormRepository::find_by_key( $key );
		}
		return $row ? rest_ensure_response( self::full( $row ) ) : $this->not_found();
	}

	/**
	 * Save the config. Addresses a channel rejects are left out and reported.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = FormRepository::find( $id );
		if ( ! $row ) {
			return $this->not_found();
		}

		$errors = [];
		$config = self::checked_config( FormConfig::normalize( (array) $request['config'] ), $errors );
		if ( $config['link'] && ( $config['link'] === $id || ! FormRepository::find( $config['link'] ) ) ) {
			$config['link'] = 0;
			$errors['link'] = __( 'The linked form does not exist.', 'forms-notifier-for-elementor' );
		}
		if ( ! FormRepository::save_config( $id, $config ) ) {
			return $this->error( 'save_failed', __( 'The form could not be saved.', 'forms-notifier-for-elementor' ), 500, $errors );
		}
		return rest_ensure_response(
			[
				'ok'     => ! $errors,
				'config' => self::client_config( FormRepository::find( $id )['config'] ),
				'errors' => (object) $errors,
			]
		);
	}

	/**
	 * Delete a form that no longer exists on the site.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		$row = FormRepository::find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}
		if ( $row['status'] !== 'missing' ) {
			return $this->error( 'form_exists', __( 'Only forms that no longer exist on the site can be deleted.', 'forms-notifier-for-elementor' ), 409 );
		}
		if ( $request['with_submissions'] ) {
			if ( ! Capabilities::can( Capabilities::MANAGE ) ) {
				return $this->error( 'forbidden', __( 'You do not have access to this.', 'forms-notifier-for-elementor' ), rest_authorization_required_code() );
			}
			do {
				$page = SubmissionRepository::query(
					[
						'form_id'  => $row['id'],
						'per_page' => 100,
					]
				);
				$done = 0;
				foreach ( $page['items'] as $s ) {
					$done += FileCleanup::delete_submission( $s->id ) ? 1 : 0;
				}
			} while ( $done );
		}
		return rest_ensure_response( [ 'ok' => FormRepository::delete( $row['id'] ) ] );
	}

	/**
	 * Send a sample submission right now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$row = FormRepository::find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found();
		}

		$sample             = Engine::sample_submission( $row['id'], (string) $request['lang'] );
		$sample->id         = 0;
		$sample->form_id    = $row['id'];
		$sample->form_key   = $row['form_key'];
		$sample->post_id    = $row['post_id'];
		$sample->element_id = $row['element_id'];
		$sample->source     = $row['source'];
		$sample->form_name  = $row['name'];
		$sample->meta       = array_merge(
			$sample->meta,
			[
				'via'    => 'test',
				'editor' => $row['editor'],
			]
		);

		Settings::overlay( [ 'general.delivery_mode' => 'inline' ] );
		try {
			$ids     = Dispatcher::dispatch( $sample, FormRepository::config( $row['id'] ), (array) $request['channels'] );
			$waiting = array_filter(
				$ids,
				static function ( $id ): bool {
					$message = MessageRepository::find( (int) $id );
					return $message && in_array( $message['status'], [ 'queued', 'retry' ], true ) && ! (int) $message['attempts'];
				}
			);
			if ( $waiting ) {
				Queue::run_ids( array_map( 'intval', array_values( $waiting ) ) );
			}
		} finally {
			Settings::clear_overlay();
		}

		$messages = [];
		foreach ( $ids as $id ) {
			$message = MessageRepository::find( (int) $id );
			if ( $message ) {
				$messages[] = [
					'channel'   => (string) $message['channel'],
					'recipient' => $message['recipient_label'] !== '' ? (string) $message['recipient_label'] : (string) $message['recipient'],
					'ok'        => $message['status'] === 'sent',
					'error'     => (string) $message['error'],
				];
			}
		}
		return rest_ensure_response(
			[
				'ok'       => $messages && ! in_array( false, array_column( $messages, 'ok' ), true ),
				'messages' => $messages,
			]
		);
	}

	/**
	 * Complete form for the form screen.
	 *
	 * @param array $row Row.
	 */
	private static function full( array $row ): array {
		$summary   = self::form_summary( $row );
		$form      = FormRepository::config( $row['id'] );
		$editor    = $row['editor'];
		$effective = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$route  = $form->route( $id );
			$source = 'global';
			if ( $route['enabled'] !== 'inherit' || $route['receivers_mode'] !== 'inherit' || $route['template_mode'] !== 'inherit' || $route['buttons_mode'] !== 'inherit' ) {
				$source = 'form';
			}
			if ( ! empty( $editor['fnfe_channels'] ) || ! empty( $editor[ 'fnfe_receivers_' . $id ] ) ) {
				$source = 'editor';
			}
			$effective[ $id ] = [
				'active'    => in_array( $id, $summary['channels'], true ),
				'receivers' => ( $channel->capabilities()['receivers'] ?? '' ) === 'list' ? count( Resolver::receivers( $id, $form ) ) : 1,
				'source'    => $source,
			];
		}
		return [
			'form'      => $summary,
			'fields'    => array_values( $row['fields'] ),
			'config'    => self::client_config( $row['config'] ),
			'editor'    => (object) $editor,
			'effective' => [ 'channels' => (object) $effective ],
		];
	}

	/**
	 * Config in JSON shape: maps stay objects even when empty.
	 *
	 * @param array $config Normalised config.
	 */
	private static function client_config( array $config ): array {
		foreach ( $config['channels'] as $id => $route ) {
			$config['channels'][ $id ]['options'] = (object) $route['options'];
		}
		foreach ( $config['rules'] as $i => $rule ) {
			$config['rules'][ $i ]['receivers'] = (object) $rule['receivers'];
		}
		$config['channels']              = (object) $config['channels'];
		$config['submitter']['channels'] = (object) $config['submitter']['channels'];
		$config['fields']['labels']      = (object) $config['fields']['labels'];
		return $config;
	}

	/**
	 * Let the channels check the addresses.
	 *
	 * @param array $config Normalised config.
	 * @param array $errors Path => message, by reference.
	 */
	private static function checked_config( array $config, array &$errors ): array {
		foreach ( $config['channels'] as $id => $route ) {
			$config['channels'][ $id ]['receivers'] = self::checked_receivers( (string) $id, $route['receivers'], "channels.$id.receivers", $errors );
		}
		foreach ( $config['rules'] as $i => $rule ) {
			foreach ( $rule['receivers'] as $id => $receivers ) {
				$config['rules'][ $i ]['receivers'][ $id ] = self::checked_receivers( (string) $id, $receivers, "rules.$i.receivers.$id", $errors );
			}
		}
		return $config;
	}

	/**
	 * Receivers a channel accepts. Unknown channels keep theirs: the plugin that adds them may be inactive.
	 *
	 * @param string $channel   Channel id.
	 * @param array  $receivers Receivers.
	 * @param string $path      Path for errors.
	 * @param array  $errors    Path => message, by reference.
	 */
	private static function checked_receivers( string $channel, array $receivers, string $path, array &$errors ): array {
		if ( ! Registry::channel( $channel ) ) {
			return $receivers;
		}
		$field = Schema::normalize_field(
			[
				'key'     => 'receivers',
				'type'    => 'receivers',
				'channel' => $channel,
			],
			'channels.' . $channel
		);

		list( $clean, $error ) = FieldSanitizer::sanitize( $field, $receivers );
		if ( $error !== '' ) {
			$errors[ $path ] = $error;
		}
		return is_array( $clean ) ? $clean : [];
	}

	/** Unknown form. */
	private function not_found(): \WP_Error {
		return $this->error( 'form_not_found', __( 'This form does not exist.', 'forms-notifier-for-elementor' ), 404 );
	}
}
