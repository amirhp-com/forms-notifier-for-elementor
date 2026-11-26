<?php
/**
 * Per-form configuration.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** Thin wrapper over the per-form config array. */
final class FormConfig {

	/** @var int */
	public int $form_id = 0;

	/** @var string */
	public string $form_key = '';

	/** @var array normalised config array (always complete: defaults filled) */
	public array $data = [];

	/** The empty config. */
	public static function defaults(): array {
		return [
			'v'           => 1,
			'link'        => 0,
			'enabled'     => 'inherit',
			'channels'    => [],
			'submitter'   => [
				'enabled'       => false,
				'consent_field' => '',
				'channels'      => [],
			],
			'rules'       => [],
			'attachments' => [ 'mode' => 'inherit' ],
			'fields'      => [
				'phone'     => '',
				'email'     => '',
				'name'      => '',
				'country'   => '',
				'sensitive' => [],
				'exclude'   => [], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Field ids left out of messages, not a query argument.
				'labels'    => [],
			],
			'quiet_hours' => 'inherit',
		];
	}

	/** The all-inherit route. */
	public static function route_defaults(): array {
		return [
			'enabled'        => 'inherit',
			'receivers_mode' => 'inherit',
			'receivers'      => [],
			'template_mode'  => 'inherit',
			'template'       => [ '_' => '' ],
			'subject'        => [ '_' => '' ],
			'buttons_mode'   => 'inherit',
			'buttons'        => [],
			'options'        => [],
		];
	}

	/**
	 * Fill defaults, drop unknown top-level keys, sanitise.
	 *
	 * @param array $raw Untrusted config.
	 */
	public static function normalize( array $raw ): array {
		$out = self::defaults();

		$out['link']        = absint( $raw['link'] ?? 0 );
		$out['enabled']     = self::one_of( $raw['enabled'] ?? '', [ 'inherit', 'on', 'off' ] );
		$out['quiet_hours'] = self::one_of( $raw['quiet_hours'] ?? '', [ 'inherit', 'off' ] );

		foreach ( self::assoc( $raw['channels'] ?? [] ) as $id => $route ) {
			$id = self::slug( $id );
			if ( $id !== '' && is_array( $route ) ) {
				$out['channels'][ $id ] = self::normalize_route( $id, $route );
			}
		}

		$submitter                         = self::assoc( $raw['submitter'] ?? [] );
		$out['submitter']['enabled']       = Text::bool( $submitter['enabled'] ?? false );
		$out['submitter']['consent_field'] = self::line( $submitter['consent_field'] ?? '' );
		foreach ( self::assoc( $submitter['channels'] ?? [] ) as $id => $entry ) {
			$id = self::slug( $id );
			if ( $id === '' || ! is_array( $entry ) ) {
				continue;
			}
			$out['submitter']['channels'][ $id ] = [
				'enabled'  => Text::bool( $entry['enabled'] ?? false ),
				'field'    => self::line( $entry['field'] ?? '' ),
				'template' => FieldSanitizer::localized( $entry['template'] ?? '', 'template' ),
				'subject'  => FieldSanitizer::localized( $entry['subject'] ?? '', 'text' ),
			];
		}

		$out['rules'] = FieldSanitizer::rules( $raw['rules'] ?? [] );

		$policy = self::assoc( $raw['attachments'] ?? [] );
		$mode   = self::one_of( $policy['mode'] ?? '', [ 'inherit', 'off', 'link', 'send', 'both' ] );
		if ( $mode !== 'inherit' ) {
			$out['attachments'] = [
				'mode'          => $mode,
				'types'         => self::one_of( $policy['types'] ?? '', [ 'all', 'images', 'custom' ] ),
				'extensions'    => self::extensions( $policy['extensions'] ?? [] ),
				'max_mb'        => max( 1, min( 2048, (int) ( $policy['max_mb'] ?? 20 ) ) ),
				'max_files'     => max( 1, min( 100, (int) ( $policy['max_files'] ?? 10 ) ) ),
				'keep_copy'     => Text::bool( $policy['keep_copy'] ?? true ),
				'link_ttl_days' => max( 0, min( 3650, (int) ( $policy['link_ttl_days'] ?? 30 ) ) ),
			];
		}

		$fields = self::assoc( $raw['fields'] ?? [] );
		foreach ( [ 'phone', 'email', 'name', 'country' ] as $key ) {
			$out['fields'][ $key ] = self::line( $fields[ $key ] ?? '' );
		}
		foreach ( [ 'sensitive', 'exclude' ] as $key ) {
			$out['fields'][ $key ] = self::lines( $fields[ $key ] ?? [] );
		}
		foreach ( self::assoc( $fields['labels'] ?? [] ) as $id => $label ) {
			$id = self::line( $id );
			if ( $id !== '' ) {
				$out['fields']['labels'][ $id ] = FieldSanitizer::localized( $label, 'text' );
			}
		}

		return $out;
	}

	/**
	 * @param int    $form_id  Row id.
	 * @param string $form_key Form key.
	 * @param array  $data     Raw or normalised config.
	 */
	public function __construct( int $form_id, string $form_key, array $data ) {
		$this->form_id  = $form_id;
		$this->form_key = $form_key;
		$this->data     = self::normalize( $data );
	}

	/**
	 * Read a dot path inside the config.
	 *
	 * @param string $path    Dot path.
	 * @param mixed  $default Returned when the path is unknown.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$node = $this->data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return $default;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/** One of inherit, on, off. */
	public function enabled(): string {
		return (string) $this->data['enabled'];
	}

	/**
	 * Complete route array of a channel.
	 *
	 * @param string $channel Channel id.
	 */
	public function route( string $channel ): array {
		$route = $this->data['channels'][ $channel ] ?? [];
		return array_merge( self::route_defaults(), is_array( $route ) ? $route : [] );
	}

	/** The normalised config array. */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Sanitise one route.
	 *
	 * @param string $channel Channel id.
	 * @param array  $route   Untrusted route.
	 */
	private static function normalize_route( string $channel, array $route ): array {
		return [
			'enabled'        => self::one_of( $route['enabled'] ?? '', [ 'inherit', 'on', 'off' ] ),
			'receivers_mode' => self::one_of( $route['receivers_mode'] ?? '', [ 'inherit', 'append', 'replace' ] ),
			'receivers'      => FieldSanitizer::receivers( $route['receivers'] ?? [] ),
			'template_mode'  => self::one_of( $route['template_mode'] ?? '', [ 'inherit', 'custom' ] ),
			'template'       => FieldSanitizer::localized( $route['template'] ?? '', 'template' ),
			'subject'        => FieldSanitizer::localized( $route['subject'] ?? '', 'text' ),
			'buttons_mode'   => self::one_of( $route['buttons_mode'] ?? '', [ 'inherit', 'append', 'replace', 'none' ] ),
			'buttons'        => FieldSanitizer::buttons( $route['buttons'] ?? [] ),
			'options'        => FieldSanitizer::options( $channel, $route['options'] ?? [] ),
		];
	}

	/**
	 * First entry of $allowed is the fallback.
	 *
	 * @param mixed    $value   Untrusted value.
	 * @param string[] $allowed Accepted values.
	 */
	private static function one_of( $value, array $allowed ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * @param mixed $value Untrusted value.
	 */
	private static function assoc( $value ): array {
		return is_array( $value ) ? $value : [];
	}

	/**
	 * @param mixed $value Untrusted value.
	 */
	private static function line( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * @param mixed $value Untrusted list.
	 * @return string[]
	 */
	private static function lines( $value ): array {
		$list = array_map( [ self::class, 'line' ], is_array( $value ) ? array_values( $value ) : [] );
		return array_values( array_unique( array_filter( $list, 'strlen' ) ) );
	}

	/**
	 * @param mixed $value Untrusted list of extensions.
	 * @return string[]
	 */
	private static function extensions( $value ): array {
		$list = [];
		foreach ( self::lines( $value ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );
			if ( $ext !== '' ) {
				$list[] = $ext;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Channel id, '' when invalid.
	 *
	 * @param mixed $value Untrusted key.
	 */
	private static function slug( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[a-z0-9_]+$/', $value ) ? $value : '';
	}
}
