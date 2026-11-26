<?php
/**
 * REST controller base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Defaults;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Permission helpers, arg schemas, list envelope, errors, serialisers. */
abstract class Controller {

	public const NS = 'fnfe/v1';

	/** @var array<int,string> form names of this request */
	private static $form_names = [];

	/** Register the routes of the controller. */
	abstract public function register_routes(): void;

	/**
	 * Permission callback factory: any of the given plugin caps.
	 *
	 * @param string ...$caps Plugin capabilities.
	 */
	protected function can( string ...$caps ): callable {
		return static function () use ( $caps ) {
			foreach ( $caps as $cap ) {
				if ( Capabilities::can( $cap ) ) {
					return true;
				}
			}
			return new \WP_Error(
				'fnfe_forbidden',
				__( 'You do not have access to this.', 'forms-notifier-for-elementor' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		};
	}

	/**
	 * List response.
	 *
	 * @param array    $envelope items, total, page, per_page.
	 * @param callable $map      Maps one item.
	 */
	protected function list_response( array $envelope, callable $map ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'items'    => array_map( $map, array_values( $envelope['items'] ) ),
				'total'    => (int) $envelope['total'],
				'page'     => (int) $envelope['page'],
				'per_page' => (int) $envelope['per_page'],
			]
		);
	}

	/**
	 * Error in the plugin's shape.
	 *
	 * @param string $code    Reason without prefix.
	 * @param string $message Translated message.
	 * @param int    $status  HTTP status.
	 * @param array  $fields  Path => message.
	 */
	protected function error( string $code, string $message, int $status = 400, array $fields = [] ): \WP_Error {
		$data = [ 'status' => $status ];
		if ( $fields ) {
			$data['fields'] = $fields;
		}
		return new \WP_Error( strncmp( $code, 'fnfe_', 5 ) === 0 ? $code : 'fnfe_' . $code, $message, $data );
	}

	/** Arg schemas of page and per_page. */
	protected function paging_args(): array {
		return [
			'page'     => [
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'per_page' => [
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Arg schema of a string.
	 *
	 * @param string $default  Default.
	 * @param array  $enum     Allowed values, [] = any.
	 * @param string $sanitize Sanitise callback.
	 */
	protected function string_arg( string $default = '', array $enum = [], string $sanitize = 'sanitize_text_field' ): array {
		$arg = [
			'type'              => 'string',
			'default'           => $default,
			'sanitize_callback' => $sanitize,
			'validate_callback' => 'rest_validate_request_arg',
		];
		if ( $enum ) {
			$arg['enum'] = $enum;
		}
		return $arg;
	}

	/** Arg schema of a free-form object that is sanitised field by field later. */
	protected function object_arg(): array {
		return [
			'type'              => 'object',
			'default'           => [],
			'sanitize_callback' => static function ( $value ): array {
				return is_array( $value ) ? $value : [];
			},
			'validate_callback' => static function ( $value ): bool {
				return is_array( $value );
			},
		];
	}

	/** Arg schemas of the unsaved values overlay. */
	protected function overlay_args(): array {
		return [
			'values'  => $this->object_arg(),
			'secrets' => $this->object_arg(),
		];
	}

	/**
	 * Applies 'values'/'secrets' request params as a Settings overlay after schema sanitising.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	protected function apply_overlay( \WP_REST_Request $request ): void {
		list( $values ) = FieldSanitizer::settings( (array) $request->get_param( 'values' ) );

		$secrets = [];
		foreach ( (array) $request->get_param( 'secrets' ) as $path => $secret ) {
			if ( Schema::is_secret( (string) $path ) ) {
				$secrets[ (string) $path ] = FieldSanitizer::secret( $secret );
			}
		}
		Settings::overlay( $values, $secrets );
	}

	/**
	 * Complete an ActionResult and clean its html.
	 *
	 * @param mixed $result Output of an action.
	 */
	protected function action_result( $result ): array {
		$result = array_merge(
			[
				'ok'      => false,
				'message' => '',
				'level'   => '',
				'rows'    => [],
				'columns' => [],
				'data'    => [],
				'html'    => '',
			],
			is_array( $result ) ? $result : []
		);

		$result['ok']   = (bool) $result['ok'];
		$result['html'] = wp_kses(
			(string) $result['html'],
			[
				'a'      => [
					'href'   => true,
					'target' => true,
					'rel'    => true,
				],
				'code'   => [],
				'strong' => [],
				'em'     => [],
				'br'     => [],
				'ul'     => [],
				'li'     => [],
			]
		);
		if ( ! in_array( $result['level'], [ 'success', 'info', 'warning', 'danger' ], true ) ) {
			$result['level'] = $result['ok'] ? 'success' : 'danger';
		}
		$result['data'] = (object) $result['data'];
		return $result;
	}

	/**
	 * FormSummary.
	 *
	 * @param array $row Row of FormRepository.
	 */
	public static function form_summary( array $row ): array {
		$config   = FormRepository::config( (int) $row['id'] );
		$editor   = is_array( $row['editor'] ?? null ) ? $row['editor'] : [];
		$active   = Resolver::form_active( $config, $editor );
		$only     = array_filter( (array) ( $editor['fnfe_channels'] ?? [] ), 'is_string' );
		$channels = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$mode = $config->route( $id )['enabled'];
			if ( $active && ( $mode === 'on' || ( $mode === 'inherit' && $channel->is_enabled() ) ) && ( ! $only || in_array( $id, $only, true ) ) ) {
				$channels[] = $id;
			}
		}
		$post_id = (int) $row['post_id'];

		return [
			'id'                => (int) $row['id'],
			'form_key'          => (string) $row['form_key'],
			'name'              => (string) $row['name'],
			'source'            => (string) $row['source'],
			'post_id'           => $post_id,
			'post_title'        => (string) $row['post_title'],
			'post_type'         => (string) $row['post_type'],
			'edit_url'          => $post_id ? admin_url( 'post.php?post=' . $post_id . '&action=elementor' ) : '',
			'status'            => (string) $row['status'],
			'enabled'           => $config->enabled(),
			'active'            => $active,
			'submissions_count' => (int) $row['submissions_count'],
			'last_submission'   => self::iso( $row['last_submission_gmt'] ?? null ),
			'fields_count'      => count( is_array( $row['fields'] ?? null ) ? $row['fields'] : [] ),
			'channels'          => $channels,
		];
	}

	/**
	 * SubmissionSummary.
	 *
	 * @param Submission $s Submission.
	 */
	public static function submission_summary( Submission $s ): array {
		$preview = [];
		foreach ( $s->fields as $field ) {
			$value = trim( preg_replace( '/\s+/u', ' ', (string) ( $field['value'] ?? '' ) ) );
			if ( $value !== '' ) {
				$preview[] = $field['label'] . ': ' . $value;
			}
			if ( count( $preview ) === 3 ) {
				break;
			}
		}
		return [
			'id'              => $s->id,
			'form_id'         => $s->form_id,
			'form_name'       => $s->form_name,
			'status'          => $s->status,
			'lang'            => $s->lang,
			'created'         => self::iso( $s->created_gmt ),
			'created_local'   => self::local_datetime( $s->created_gmt ),
			'preview'         => Text::truncate( implode( __( ', ', 'forms-notifier-for-elementor' ), $preview ), 160 ),
			'files_count'     => count( $s->files ),
			'delivery_status' => (string) ( $s->meta['delivery_status'] ?? 'none' ),
		];
	}

	/**
	 * SubmissionFull.
	 *
	 * @param Submission $s           Submission.
	 * @param bool       $signed_urls File URLs carry a signature (callers without a WordPress session).
	 * @param int        $link_ttl    Seconds a signed URL is valid.
	 */
	public static function submission_full( Submission $s, bool $signed_urls = false, int $link_ttl = DAY_IN_SECONDS ): array {
		$fields = [];
		foreach ( $s->fields as $id => $field ) {
			$fields[] = [
				'id'       => (string) ( $field['id'] ?? $id ),
				'type'     => (string) ( $field['type'] ?? '' ),
				'label'    => (string) ( $field['label'] ?? $id ),
				'value'    => (string) ( $field['value'] ?? '' ),
				'values'   => array_values( (array) ( $field['values'] ?? [] ) ),
				'is_file'  => ! empty( $field['is_file'] ),
				'redacted' => ! empty( $field['redacted'] ),
			];
		}

		$files = [];
		foreach ( $s->files as $file ) {
			if ( $signed_urls ) {
				$url = Storage::link( $file, 0, max( 1, $link_ttl ) );
			} else {
				$url = $file->id ? add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), Urls::rest( 'file/' . $file->id ) ) : '';
			}
			$files[] = [
				'id'       => $file->id,
				'field_id' => $file->field_id,
				'name'     => $file->name,
				'size'     => $file->size,
				'mime'     => $file->mime,
				'is_image' => $file->is_image(),
				'url'      => $file->exists() ? $url : '',
			];
		}

		return array_merge(
			self::submission_summary( $s ),
			[
				'uuid'   => $s->uuid,
				'fields' => $fields,
				'files'  => $files,
				'meta'   => [
					'page_url'   => (string) ( $s->meta['page_url'] ?? '' ),
					'page_title' => (string) ( $s->meta['page_title'] ?? '' ),
					'user_id'    => (int) ( $s->meta['user_id'] ?? 0 ),
					'user_login' => (string) ( $s->meta['user_login'] ?? '' ),
					'user_ip'    => (string) ( $s->meta['user_ip'] ?? '' ),
					'user_agent' => (string) ( $s->meta['user_agent'] ?? '' ),
					'via'        => (string) ( $s->meta['via'] ?? '' ),
				],
			]
		);
	}

	/**
	 * MessageSummary.
	 *
	 * @param array $row Row of MessageRepository.
	 */
	public static function message_summary( array $row ): array {
		$channel = Registry::channel( (string) $row['channel'] );
		return [
			'id'              => (int) $row['id'],
			'submission_id'   => (int) $row['submission_id'],
			'form_id'         => (int) $row['form_id'],
			'form_name'       => self::form_name( (int) $row['form_id'] ),
			'channel'         => (string) $row['channel'],
			'channel_label'   => $channel ? $channel->label() : (string) $row['channel'],
			'provider'        => (string) $row['provider'],
			'purpose'         => (string) $row['purpose'],
			'recipient'       => (string) $row['recipient'],
			'recipient_label' => (string) $row['recipient_label'],
			'lang'            => (string) $row['lang'],
			'status'          => (string) $row['status'],
			'attempts'        => (int) $row['attempts'],
			'max_attempts'    => (int) $row['max_attempts'],
			'error'           => (string) $row['error'],
			'created'         => self::iso( $row['created_gmt'] ),
			'sent'            => self::iso( $row['sent_gmt'] ?? null ),
			'next_attempt'    => self::iso( $row['next_attempt_gmt'] ?? null ),
		];
	}

	/**
	 * MessageFull.
	 *
	 * @param array $row Row of MessageRepository.
	 */
	public static function message_full( array $row ): array {
		$payload     = is_array( $row['payload'] ?? null ) ? $row['payload'] : [];
		$attachments = [];
		foreach ( (array) ( $payload['attachments'] ?? [] ) as $file ) {
			$attachments[] = [
				'name' => (string) ( $file['name'] ?? '' ),
				'size' => (int) ( $file['size'] ?? 0 ),
			];
		}
		return array_merge(
			self::message_summary( $row ),
			[
				'subject'     => (string) $row['subject'],
				'body'        => (string) $row['body'],
				'response'    => (string) $row['response'],
				'http_code'   => (int) $row['http_code'],
				'remote_id'   => (string) $row['remote_id'],
				'parent_id'   => (int) $row['parent_id'],
				'buttons'     => array_values( (array) ( $payload['buttons'] ?? [] ) ),
				'attachments' => $attachments,
				'links'       => array_values( (array) ( $payload['links'] ?? [] ) ),
			]
		);
	}

	/**
	 * ChannelMeta.
	 *
	 * @param ChannelInterface $c Channel.
	 */
	public static function channel_meta( ChannelInterface $c ): array {
		$id = $c->id();
		return [
			'id'              => $id,
			'label'           => $c->label(),
			'description'     => $c->description(),
			'group'           => $c->group(),
			'icon'            => $c->icon(),
			'enabled'         => $c->is_enabled(),
			'ready'           => $c->is_ready(),
			'problems'        => array_values( $c->problems() ),
			'capabilities'    => $c->capabilities(),
			'format'          => $c->format(),
			'receivers_count' => count( Settings::arr( "channels.$id.receivers" ) ),
			'has_schema'      => true,
		];
	}

	/**
	 * Schema field for a client: the normalised field.
	 *
	 * @param array  $fields Raw fields.
	 * @param string $prefix Key prefix.
	 */
	protected static function client_fields( array $fields, string $prefix = '' ): array {
		$out = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$out[] = self::client_field( Schema::normalize_field( $field, $prefix ) );
			}
		}
		return $out;
	}

	/**
	 * Sections for a client.
	 *
	 * @param array $sections Normalised sections.
	 */
	protected static function client_sections( array $sections ): array {
		$defaults = Defaults::all();
		foreach ( $sections as $i => $section ) {
			foreach ( $section['fields'] as $j => $field ) {
				$default = Settings::path_get( $defaults, $field['key'], $found );
				if ( $found && $field['type'] !== 'secret' ) {
					$field['default'] = $default;
				}
				$sections[ $i ]['fields'][ $j ] = self::client_field( $field );
			}
		}
		return array_values( $sections );
	}

	/**
	 * Settings value in JSON shape: maps stay objects even when empty.
	 *
	 * @param array $field Field.
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected static function client_value( array $field, $value ) {
		if ( is_array( $value ) && ( $field['type'] === 'keyvalue' || $field['localized'] ) ) {
			return (object) $value;
		}
		return $value;
	}

	/**
	 * State of a secret for a client; never the value.
	 *
	 * @param string $path Secret path.
	 */
	protected static function secret_state( string $path ): array {
		return [
			'set'      => Settings::has_secret( $path ),
			'masked'   => Settings::masked( $path ),
			'constant' => Settings::secret_is_constant( $path ),
		];
	}

	/**
	 * ISO 8601 UTC, null for an empty date.
	 *
	 * @param string|null $gmt 'Y-m-d H:i:s' UTC.
	 */
	public static function iso( $gmt ): ?string {
		if ( ! is_string( $gmt ) || $gmt === '' || strncmp( $gmt, '0000', 4 ) === 0 ) {
			return null;
		}
		return str_replace( ' ', 'T', $gmt ) . 'Z';
	}

	/**
	 * Date and time in the site timezone; Jalali when the calendar setting says so.
	 *
	 * @param string $gmt 'Y-m-d H:i:s' UTC.
	 */
	public static function local_datetime( string $gmt ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $gmt, new \DateTimeZone( 'UTC' ) );
		if ( ! $date ) {
			return '';
		}
		$local = $date->setTimezone( wp_timezone() );
		if ( self::calendar() !== 'jalali' ) {
			return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() );
		}
		list( $year, $month, $day ) = self::to_jalali( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), (int) $local->format( 'j' ) );

		$text = sprintf( '%04d/%02d/%02d %s', $year, $month, $day, $local->format( 'H:i' ) );
		return strncmp( determine_locale(), 'fa', 2 ) === 0 ? Text::fa_digits( $text ) : $text;
	}

	/** Calendar of the panel: gregorian | jalali. */
	public static function calendar(): string {
		$calendar = Settings::str( 'general.calendar', 'auto' );
		if ( $calendar === 'auto' ) {
			$calendar = strncmp( determine_locale(), 'fa', 2 ) === 0 ? 'jalali' : 'gregorian';
		}
		return $calendar === 'jalali' ? 'jalali' : 'gregorian';
	}

	/**
	 * Name of a form.
	 *
	 * @param int $form_id Row id.
	 */
	private static function form_name( int $form_id ): string {
		if ( ! $form_id ) {
			return '';
		}
		if ( ! isset( self::$form_names[ $form_id ] ) ) {
			$row                          = FormRepository::find( $form_id );
			self::$form_names[ $form_id ] = $row ? (string) $row['name'] : '';
		}
		return self::$form_names[ $form_id ];
	}

	/**
	 * Field with JSON-safe maps.
	 *
	 * @param array $field Normalised field.
	 */
	private static function client_field( array $field ): array {
		$field['attrs']   = (object) $field['attrs'];
		$field['action']  = (object) $field['action'];
		$field['default'] = self::client_value( $field, $field['default'] );
		$field['options'] = self::client_options( $field['options'] );
		$field['fields']  = array_map( [ self::class, 'client_field' ], $field['fields'] );
		return $field;
	}

	/**
	 * Options as an ordered list of [ value, label, desc?, group? ]; JSON objects lose the order of numeric keys.
	 *
	 * @param array $options Options.
	 */
	private static function client_options( array $options ): array {
		$out = [];
		foreach ( $options as $value => $option ) {
			if ( ! is_array( $option ) ) {
				$option = [ 'label' => (string) $option ];
			}
			$out[] = array_merge(
				[
					'value' => (string) $value,
					'label' => '',
				],
				$option
			);
		}
		return $out;
	}

	/**
	 * Gregorian to Jalali.
	 *
	 * @param int $gy Year.
	 * @param int $gm Month.
	 * @param int $gd Day.
	 * @return int[] year, month, day
	 */
	private static function to_jalali( int $gy, int $gm, int $gd ): array {
		$days_before = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];
		$gy2         = $gm > 2 ? $gy + 1 : $gy;
		$days        = 355666 + 365 * $gy + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $days_before[ $gm - 1 ];
		$jy          = -1595 + 33 * intdiv( $days, 12053 );
		$days       %= 12053;
		$jy         += 4 * intdiv( $days, 1461 );
		$days       %= 1461;
		if ( $days > 365 ) {
			--$days;
			$jy   += intdiv( $days, 365 );
			$days %= 365;
		}
		return $days < 186
			? [ $jy, 1 + intdiv( $days, 31 ), 1 + $days % 31 ]
			: [ $jy, 7 + intdiv( $days - 186, 30 ), 1 + ( $days - 186 ) % 30 ];
	}
}
