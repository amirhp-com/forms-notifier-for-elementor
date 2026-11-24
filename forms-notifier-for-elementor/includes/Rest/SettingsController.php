<?php
/**
 * Settings routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Defaults;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** GET and POST /settings. */
final class SettingsController extends Controller {

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'read' ],
					'permission_callback' => $this->can( Capabilities::MANAGE ),
					'args'                => [ 'tab' => $this->string_arg( '', [], 'sanitize_key' ) ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => $this->can( Capabilities::MANAGE ),
					'args'                => $this->overlay_args(),
				],
			]
		);
	}

	/**
	 * Values, secret states and defaults.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		$tab      = (string) $request->get_param( 'tab' );
		$fields   = $tab === '' ? Schema::fields() : self::fields_of( $tab );
		$defaults = [];
		$tree     = Defaults::all();
		foreach ( $fields as $path => $field ) {
			if ( $field['type'] !== 'secret' ) {
				$default           = Settings::path_get( $tree, $path, $found );
				$defaults[ $path ] = self::client_value( $field, $found ? $default : $field['default'] );
			}
		}
		return rest_ensure_response(
			[
				'values'   => (object) self::values( array_keys( $fields ) ),
				'secrets'  => (object) self::secrets( $fields ),
				'defaults' => (object) $defaults,
			]
		);
	}

	/**
	 * Save. Valid keys are saved even if others fail.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$values = (array) $request->get_param( 'values' );

		list( $clean, $errors ) = FieldSanitizer::settings( $values );

		$secrets = [];
		foreach ( (array) $request->get_param( 'secrets' ) as $path => $value ) {
			$path  = (string) $path;
			$error = self::secret_error( $path, $value, $clean );
			if ( $error !== '' ) {
				$errors[ $path ] = $error;
				continue;
			}
			$secrets[ $path ] = $value === null ? '' : FieldSanitizer::secret( $value );
		}
		$secrets += self::inline_row_secrets( $values, $clean );

		if ( $errors && ! $clean && ! $secrets ) {
			return $this->error( 'invalid_settings', __( 'Nothing was saved. Check the marked fields.', 'forms-notifier-for-elementor' ), 400, $errors );
		}

		if ( $clean ) {
			Settings::save( $clean );
		}
		foreach ( $secrets as $path => $value ) {
			Settings::set_secret( $path, $value );
		}

		$fields = [];
		foreach ( array_merge( array_keys( $clean ), array_keys( $secrets ) ) as $path ) {
			$field = Schema::field( $path );
			$row   = $field ? null : Schema::row_secret( $path );
			if ( $row ) {
				$path  = $row['repeater'];
				$field = Schema::field( $path );
			}
			if ( $field ) {
				$fields[ $path ] = $field;
			}
		}
		return rest_ensure_response(
			[
				'ok'      => ! $errors,
				'values'  => (object) self::values( array_keys( $clean ) ),
				'secrets' => (object) self::secrets( $fields ),
				'errors'  => (object) $errors,
			]
		);
	}

	/**
	 * Current values of paths.
	 *
	 * @param string[] $paths Paths.
	 */
	private static function values( array $paths ): array {
		$tree = Settings::all();
		$out  = [];
		foreach ( $paths as $path ) {
			$field = Schema::field( $path );
			if ( $field && $field['type'] !== 'secret' ) {
				$value        = Settings::path_get( $tree, $path, $found );
				$out[ $path ] = self::client_value( $field, $found ? $value : $field['default'] );
			}
		}
		return $out;
	}

	/**
	 * Secret states of fields: plain secrets, repeater row secrets, secret receivers.
	 *
	 * @param array<string,array> $fields Fields.
	 */
	private static function secrets( array $fields ): array {
		$out = [];
		foreach ( $fields as $path => $field ) {
			if ( $field['type'] === 'secret' ) {
				$out[ $path ] = self::secret_state( $path );
			} elseif ( $field['type'] === 'repeater' ) {
				foreach ( Settings::arr( $path ) as $row ) {
					foreach ( $field['fields'] as $sub ) {
						if ( $sub['type'] === 'secret' && ! empty( $row['id'] ) ) {
							$key         = $path . '.' . $row['id'] . '.' . $sub['key'];
							$out[ $key ] = self::secret_state( $key );
						}
					}
				}
			} elseif ( $field['type'] === 'receivers' && $field['channel'] !== '' ) {
				$channel = Registry::channel( $field['channel'] );
				if ( ! $channel || ( $channel->capabilities()['receiver_kind'] ?? '' ) !== 'secret' ) {
					continue;
				}
				foreach ( Settings::arr( $path ) as $receiver ) {
					$address = (string) ( $receiver['address'] ?? '' );
					if ( Settings::is_placeholder( $address ) ) {
						$key         = 'channels.' . $field['channel'] . '.receiver_secrets.' . substr( $address, strlen( Settings::PLACEHOLDER ) );
						$out[ $key ] = self::secret_state( $key );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Why a secret cannot be saved, '' when it can.
	 *
	 * @param string $path  Path.
	 * @param mixed  $value New value.
	 * @param array  $clean Clean values of the same request.
	 */
	private static function secret_error( string $path, $value, array $clean ): string {
		if ( ! Schema::is_secret( $path ) ) {
			return __( 'Unknown setting.', 'forms-notifier-for-elementor' );
		}
		if ( Settings::secret_is_constant( $path ) ) {
			return __( 'This value is set in wp-config.php and cannot be changed here.', 'forms-notifier-for-elementor' );
		}
		if ( $value !== null && ! is_string( $value ) ) {
			return __( 'Enter a text value.', 'forms-notifier-for-elementor' );
		}
		$row = Schema::row_secret( $path );
		if ( $row ) {
			$rows = $clean[ $row['repeater'] ] ?? Settings::arr( $row['repeater'] );
			if ( ! in_array( $row['row'], array_column( $rows, 'id' ), true ) ) {
				return __( 'Unknown setting.', 'forms-notifier-for-elementor' );
			}
		}
		return '';
	}

	/**
	 * Secrets sent inside repeater rows instead of the secrets object.
	 *
	 * @param array $values Untrusted values.
	 * @param array $clean  Clean values.
	 * @return array<string,string>
	 */
	private static function inline_row_secrets( array $values, array $clean ): array {
		$out = [];
		foreach ( $clean as $path => $rows ) {
			$field = Schema::field( $path );
			if ( ! $field || $field['type'] !== 'repeater' || ! is_array( $values[ $path ] ) ) {
				continue;
			}
			$ids = array_column( $rows, 'id' );
			foreach ( $values[ $path ] as $row ) {
				$id = is_array( $row ) && is_string( $row['id'] ?? null ) ? $row['id'] : '';
				if ( ! in_array( $id, $ids, true ) ) {
					continue;
				}
				foreach ( $field['fields'] as $sub ) {
					$value = $row[ $sub['key'] ] ?? '';
					if ( $sub['type'] === 'secret' && is_string( $value ) && $value !== '' && strpos( $value, '••••' ) !== 0 ) {
						$out[ "$path.$id.{$sub['key']}" ] = FieldSanitizer::secret( $value );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Fields of one tab.
	 *
	 * @param string $tab Tab id.
	 * @return array<string,array>
	 */
	private static function fields_of( string $tab ): array {
		$all = Schema::fields();
		$out = [];
		foreach ( Schema::sections_for( $tab ) as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( isset( $all[ $field['key'] ] ) ) {
					$out[ $field['key'] ] = $all[ $field['key'] ];
				}
			}
		}
		return $out;
	}
}
