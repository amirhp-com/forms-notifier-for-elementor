<?php
/**
 * Tools routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Repository\LogRepository;
use AmirhpCom\FormsNotifier\Tools\ImportExport;

defined( 'ABSPATH' ) || exit;

/** /tools/export, /tools/import, /tools/system, /logs. */
final class ToolsController extends Controller {

	/** Register the routes. */
	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE );

		register_rest_route(
			self::NS,
			'/tools/export',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export' ],
				'permission_callback' => $manage,
				'args'                => [
					'include' => [
						'type'              => [ 'array', 'string' ],
						'default'           => [ 'settings', 'forms' ],
						'sanitize_callback' => static function ( $value ): array {
							return array_values( array_intersect( [ 'settings', 'forms' ], wp_parse_list( $value ) ) );
						},
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) || is_array( $value );
						},
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/tools/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import' ],
				'permission_callback' => $manage,
				'args'                => [
					'data' => array_merge( $this->object_arg(), [ 'required' => true ] ),
					'mode' => $this->string_arg( 'merge', [ 'merge', 'replace' ], 'sanitize_key' ),
				],
			]
		);
		register_rest_route(
			self::NS,
			'/tools/system',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( ToolsController::system() );
				},
				'permission_callback' => $manage,
				'args'                => [],
			]
		);
		register_rest_route(
			self::NS,
			'/logs',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'logs' ],
					'permission_callback' => $manage,
					'args'                => array_merge(
						$this->paging_args(),
						[
							'level'   => $this->string_arg( '', [ '', 'debug', 'info', 'warning', 'error' ], 'sanitize_key' ),
							'context' => $this->string_arg( '', [], 'sanitize_key' ),
							'search'  => $this->string_arg(),
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => static function () {
						LogRepository::truncate();
						return rest_ensure_response( [ 'ok' => true ] );
					},
					'permission_callback' => $manage,
					'args'                => [],
				],
			]
		);
	}

	/**
	 * Export download.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$response = rest_ensure_response( ImportExport::export( (array) $request['include'] ) );
		$response->header( 'Content-Disposition', 'attachment; filename="forms-notifier-' . gmdate( 'Y-m-d' ) . '.json"' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > ImportExport::MAX_BYTES ) {
			return $this->error( 'too_large', __( 'The file is larger than 2 MB.', 'forms-notifier-for-elementor' ), 413 );
		}
		$result = ImportExport::import( (array) $request['data'], (string) $request['mode'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * System log.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function logs( \WP_REST_Request $request ): \WP_REST_Response {
		$page = LogRepository::query(
			[
				'level'    => (string) $request['level'],
				'context'  => (string) $request['context'],
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			]
		);
		return $this->list_response(
			$page,
			static function ( array $row ): array {
				return [
					'id'      => $row['id'],
					'level'   => $row['level'],
					'context' => $row['context'],
					'message' => (string) $row['message'],
					'data'    => (object) $row['data'],
					'created' => self::iso( $row['created_gmt'] ),
				];
			}
		);
	}

	/** Versions, tables, cron, limits. */
	public static function system(): array {
		global $wpdb;

		$tables = [];
		foreach ( DbSchema::tables() as $name => $table ) {
			$exists          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
			$tables[ $name ] = [
				'table'  => $table,
				'exists' => $exists,
				'rows'   => $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : 0,
			];
		}

		$cron = [];
		foreach ( [ Cron::QUEUE, Cron::POLL, Cron::CLEANUP, Cron::DIGEST, Cron::SCAN ] as $hook ) {
			$next          = wp_next_scheduled( $hook );
			$cron[ $hook ] = $next ? gmdate( 'Y-m-d\TH:i:s\Z', $next ) : null;
		}

		return [
			'plugin'    => FNFE_VERSION,
			'database'  => [
				'version'  => (string) get_option( 'fnfe_db_version', '' ),
				'expected' => FNFE_DB_VERSION,
				'server'   => $wpdb->db_version(),
				'tables'   => $tables,
			],
			'wordpress' => [
				'version'   => get_bloginfo( 'version' ),
				'multisite' => is_multisite(),
				'locale'    => I18n::site_locale(),
				'timezone'  => wp_timezone_string(),
				'debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			],
			'php'       => [
				'version'             => PHP_VERSION,
				'memory_limit'        => (string) ini_get( 'memory_limit' ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
				'post_max_size'       => (string) ini_get( 'post_max_size' ),
				'extensions'          => [
					'sodium'   => extension_loaded( 'sodium' ),
					'mbstring' => extension_loaded( 'mbstring' ),
					'intl'     => extension_loaded( 'intl' ),
					'curl'     => extension_loaded( 'curl' ),
				],
			],
			'elementor' => [
				'core' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'pro'  => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			],
			'i18n'      => [
				'provider'  => I18n::provider(),
				'languages' => array_keys( I18n::languages() ),
			],
			'cron'      => [
				'disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'action_scheduler' => Cron::uses_action_scheduler(),
				'events'           => $cron,
			],
			'channels'  => [
				'registered' => array_keys( Registry::channels() ),
				'ready'      => array_keys( Registry::ready_channels() ),
			],
		];
	}
}
