<?php
/**
 * Settings schema.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;

defined( 'ABSPATH' ) || exit;

/** Registry of tabs, sections and fields; the only source of truth for what /settings accepts. */
final class Schema {

	public const VALUE_TYPES = [ 'text', 'textarea', 'number', 'url', 'email', 'tel', 'time', 'toggle', 'select', 'radio', 'multiselect', 'checkboxes', 'secret', 'code', 'template', 'receivers', 'buttons', 'rules', 'repeater', 'keyvalue', 'tags' ];

	/** Types whose value is an array. */
	private const LIST_TYPES = [ 'multiselect', 'checkboxes', 'receivers', 'buttons', 'rules', 'repeater', 'keyvalue', 'tags' ];

	/** First segments of absolute paths. */
	private const ROOTS = [ 'general', 'channels', 'attachments', 'queue', 'alerts', 'digest', 'miniapp', 'privacy', 'advanced' ];

	/** @var array<string,array> */
	private static $sections = [];

	/** @var array<string,array> */
	private static $fields = [];

	/** @var bool */
	private static $building = false;

	/** Top-level tabs: id => [ 'label' =>, 'icon' => ]. Filter: fnfe_settings_tabs. */
	public static function tabs(): array {
		return (array) apply_filters(
			'fnfe_settings_tabs',
			[
				'general'     => [
					'label' => __( 'General', 'forms-notifier-for-elementor' ),
					'icon'  => 'settings',
				],
				'attachments' => [
					'label' => __( 'Attachments', 'forms-notifier-for-elementor' ),
					'icon'  => 'paperclip',
				],
				'queue'       => [
					'label' => __( 'Queue and retries', 'forms-notifier-for-elementor' ),
					'icon'  => 'queue',
				],
				'alerts'      => [
					'label' => __( 'Failure alerts', 'forms-notifier-for-elementor' ),
					'icon'  => 'alert',
				],
				'digest'      => [
					'label' => __( 'Digest', 'forms-notifier-for-elementor' ),
					'icon'  => 'calendar',
				],
				'miniapp'     => [
					'label' => __( 'Mini App', 'forms-notifier-for-elementor' ),
					'icon'  => 'app',
				],
				'privacy'     => [
					'label' => __( 'Privacy and data', 'forms-notifier-for-elementor' ),
					'icon'  => 'shield',
				],
				'advanced'    => [
					'label' => __( 'Advanced', 'forms-notifier-for-elementor' ),
					'icon'  => 'code',
				],
			]
		);
	}

	/** All normalised sections (settings + channels), sorted by tab then priority. Filter: fnfe_settings_sections. */
	public static function sections(): array {
		$key = self::cache_key();
		if ( isset( self::$sections[ $key ] ) ) {
			return self::$sections[ $key ];
		}
		if ( self::$building ) {
			return [];
		}

		self::$building = true;
		try {
			$sections = self::core_sections();
			foreach ( Registry::channels() as $id => $channel ) {
				foreach ( self::channel_sections( $channel ) as $section ) {
					$sections[] = self::normalize_section( $section, "channels.$id.", $id );
				}
			}
			$sections = (array) apply_filters( 'fnfe_settings_sections', $sections );

			$unique = [];
			foreach ( $sections as $section ) {
				if ( is_array( $section ) ) {
					$section = self::normalize_section( $section );
					// A section registered later under the same id replaces the earlier one.
					$unique[ $section['id'] ] = $section;
				}
			}
			$sections = self::sort( array_values( $unique ) );
		} finally {
			self::$building = false;
		}

		if ( did_action( 'init' ) > 0 ) {
			self::$sections[ $key ] = $sections;
		}
		return $sections;
	}

	/**
	 * Sections of one tab.
	 *
	 * @param string $tab Tab id or channel id.
	 */
	public static function sections_for( string $tab ): array {
		return array_values(
			array_filter(
				self::sections(),
				static function ( array $section ) use ( $tab ): bool {
					return $section['tab'] === $tab;
				}
			)
		);
	}

	/** Flat map absolute key => normalised field (value types only). */
	public static function fields(): array {
		$key = self::cache_key();
		if ( isset( self::$fields[ $key ] ) ) {
			return self::$fields[ $key ];
		}
		$fields = [];
		foreach ( self::sections() as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( in_array( $field['type'], self::VALUE_TYPES, true ) && $field['key'] !== '' && ! isset( $fields[ $field['key'] ] ) ) {
					$fields[ $field['key'] ] = $field;
				}
			}
		}
		if ( isset( self::$sections[ $key ] ) ) {
			self::$fields[ $key ] = $fields;
		}
		return $fields;
	}

	/**
	 * One field.
	 *
	 * @param string $key Absolute key.
	 */
	public static function field( string $key ): ?array {
		return self::fields()[ $key ] ?? null;
	}

	/**
	 * The path belongs to the secret store: a secret field or a secret sub-field of a repeater row.
	 *
	 * @param string $key Absolute key.
	 */
	public static function is_secret( string $key ): bool {
		$field = self::field( $key );
		if ( $field ) {
			return $field['type'] === 'secret';
		}
		return self::row_secret( $key ) !== null;
	}

	/**
	 * Parts of a repeater secret path "{repeater key}.{row id}.{sub key}", null when it is not one.
	 *
	 * @param string $key Absolute key.
	 * @return array{repeater:string,row:string,field:array}|null
	 */
	public static function row_secret( string $key ): ?array {
		$parts = explode( '.', $key );
		if ( count( $parts ) < 3 ) {
			return null;
		}
		$sub      = array_pop( $parts );
		$row      = array_pop( $parts );
		$repeater = self::field( implode( '.', $parts ) );
		if ( ! $repeater || $repeater['type'] !== 'repeater' || ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $row ) ) {
			return null;
		}
		foreach ( $repeater['fields'] as $field ) {
			if ( $field['key'] === $sub && $field['type'] === 'secret' ) {
				return [
					'repeater' => $repeater['key'],
					'row'      => $row,
					'field'    => $field,
				];
			}
		}
		return null;
	}

	/**
	 * Normalise one field.
	 *
	 * @param array  $field  Raw field.
	 * @param string $prefix Makes relative keys absolute, e.g. "channels.sms.".
	 */
	public static function normalize_field( array $field, string $prefix = '' ): array {
		$field = array_merge(
			[
				'key'            => '',
				'type'           => 'text',
				'label'          => '',
				'desc'           => '',
				'placeholder'    => '',
				'default'        => null,
				'options'        => [],
				'options_source' => '',
				'width'          => 'full',
				'required'       => false,
				'localized'      => false,
				'show_if'        => [],
				'attrs'          => [],
				'sanitize'       => '',
				'fields'         => [],
				'item_label'     => '',
				'action'         => [],
				'steps'          => [],
				'channel'        => '',
				'purpose'        => 'admin',
				'level'          => 'info',
				'constant'       => '',
			],
			$field
		);

		$field['key']       = self::absolute( (string) $field['key'], $prefix, [ 'channels' ] );
		$field['type']      = (string) $field['type'];
		$field['required']  = (bool) $field['required'];
		$field['localized'] = (bool) $field['localized'] && in_array( $field['type'], [ 'text', 'textarea', 'template' ], true );
		$field['default']   = self::default_value( $field );

		$conditions = [];
		foreach ( (array) $field['show_if'] as $condition ) {
			if ( is_array( $condition ) && isset( $condition['key'] ) ) {
				$conditions[] = [
					'key'   => self::absolute( (string) $condition['key'], $prefix ),
					'op'    => (string) ( $condition['op'] ?? 'is' ),
					'value' => $condition['value'] ?? '',
				];
			}
		}
		$field['show_if'] = $conditions;

		if ( $field['action'] ) {
			$field['action'] = array_merge(
				[
					'channel' => '',
					'id'      => '',
					'label'   => '',
					'send'    => [],
					'confirm' => '',
				],
				(array) $field['action']
			);

			$field['action']['send'] = array_map(
				static function ( $key ) use ( $prefix ): string {
					return self::absolute( (string) $key, $prefix );
				},
				array_values( (array) $field['action']['send'] )
			);
		}

		if ( $field['type'] === 'repeater' ) {
			$sub_fields = [];
			foreach ( (array) $field['fields'] as $sub ) {
				if ( is_array( $sub ) ) {
					$sub_fields[] = self::normalize_field( $sub );
				}
			}
			$field['fields'] = $sub_fields;
		}

		if ( $field['type'] === 'secret' && $field['key'] !== '' ) {
			$field['constant'] = Settings::secret_constant( $field['key'] );
		}
		return $field;
	}

	/** Forget the built schema. */
	public static function flush(): void {
		self::$sections = [];
		self::$fields   = [];
	}

	/** The schema is being built right now. */
	public static function is_building(): bool {
		return self::$building;
	}

	/** Sections depend on the site and on the language of their labels. */
	private static function cache_key(): string {
		return get_current_blog_id() . '|' . determine_locale();
	}

	/**
	 * Sections declared by a channel; a broken third-party schema must not break the panel.
	 *
	 * @param ChannelInterface $channel Channel.
	 */
	private static function channel_sections( ChannelInterface $channel ): array {
		try {
			return array_filter( $channel->settings_schema(), 'is_array' );
		} catch ( \Throwable $e ) {
			Logger::error( 'schema', sprintf( 'Schema of channel "%s": %s', $channel->id(), $e->getMessage() ) );
			return [];
		}
	}

	/**
	 * Complete a section.
	 *
	 * @param array  $section Raw section.
	 * @param string $prefix  Key prefix.
	 * @param string $tab     Default tab.
	 */
	private static function normalize_section( array $section, string $prefix = '', string $tab = 'general' ): array {
		$section = array_merge(
			[
				'id'        => '',
				'tab'       => $tab,
				'title'     => '',
				'desc'      => '',
				'priority'  => 10,
				'collapsed' => false,
				'fields'    => [],
			],
			$section
		);

		$fields = [];
		foreach ( (array) $section['fields'] as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = self::normalize_field( $field, $prefix );
			}
		}
		$section['fields'] = $fields;
		if ( $section['id'] === '' ) {
			$section['id'] = $section['tab'] . '_' . md5( $section['title'] . wp_json_encode( array_column( $fields, 'key' ) ) );
		}
		return $section;
	}

	/**
	 * Absolute dot path of a key.
	 *
	 * @param string   $key    Key.
	 * @param string   $prefix Prefix.
	 * @param string[] $roots  First segments that mark a key as absolute already.
	 */
	private static function absolute( string $key, string $prefix, array $roots = self::ROOTS ): string {
		if ( $key === '' || $prefix === '' || strncmp( $key, $prefix, strlen( $prefix ) ) === 0 ) {
			return $key;
		}
		$dot = strpos( $key, '.' );
		if ( $dot !== false && in_array( substr( $key, 0, $dot ), $roots, true ) ) {
			return $key;
		}
		return $prefix . $key;
	}

	/**
	 * Default of a field in the shape of its type.
	 *
	 * @param array $field Field.
	 * @return mixed
	 */
	private static function default_value( array $field ) {
		$default = $field['default'];
		if ( $field['localized'] ) {
			return is_array( $default ) ? $default + [ '_' => '' ] : [ '_' => (string) $default ];
		}
		if ( $field['type'] === 'toggle' ) {
			return Text::bool( $default );
		}
		if ( in_array( $field['type'], self::LIST_TYPES, true ) ) {
			return is_array( $default ) ? $default : [];
		}
		if ( $field['type'] === 'number' ) {
			return is_numeric( $default ) ? $default + 0 : 0;
		}
		return is_scalar( $default ) || is_array( $default ) ? $default : '';
	}

	/**
	 * Sort by tab (settings tabs, then channels in registration order), then priority; stable.
	 *
	 * @param array $sections Sections.
	 */
	private static function sort( array $sections ): array {
		$order = array_flip( array_merge( array_keys( self::tabs() ), Registry::channel_ids() ) );
		$rows  = [];
		foreach ( $sections as $i => $section ) {
			$rows[] = [ $order[ $section['tab'] ] ?? PHP_INT_MAX, (int) $section['priority'], $i, $section ];
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return [ $a[0], $a[1], $a[2] ] <=> [ $b[0], $b[1], $b[2] ];
			}
		);
		return array_column( $rows, 3 );
	}

	/** Sections of the general settings. Alerts, digest and mini app are registered by their own modules. */
	private static function core_sections(): array {
		return array_merge( self::general_sections(), self::attachment_sections(), self::queue_sections(), self::privacy_sections(), self::advanced_sections() );
	}

	/** General tab. */
	private static function general_sections(): array {
		$languages = [];
		foreach ( I18n::languages() as $code => $language ) {
			$languages[ $code ] = $language['name'];
		}

		return [
			[
				'id'       => 'general_capture',
				'tab'      => 'general',
				'title'    => __( 'Which forms notify', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'     => 'general.capture_mode',
						'type'    => 'radio',
						'label'   => __( 'Forms', 'forms-notifier-for-elementor' ),
						'options' => [
							[
								'value' => 'all',
								'label' => __( 'All forms', 'forms-notifier-for-elementor' ),
								'desc'  => __( 'Every form sends notifications unless you switch it off.', 'forms-notifier-for-elementor' ),
							],
							[
								'value' => 'selected',
								'label' => __( 'Selected forms', 'forms-notifier-for-elementor' ),
								'desc'  => __( 'Only forms you switch on, here or with the form action in the editor.', 'forms-notifier-for-elementor' ),
							],
						],
					],
					[
						'key'     => 'general.delivery_mode',
						'type'    => 'radio',
						'label'   => __( 'When to send', 'forms-notifier-for-elementor' ),
						'options' => [
							[
								'value' => 'background',
								'label' => __( 'In the background', 'forms-notifier-for-elementor' ),
								'desc'  => __( 'The visitor does not wait for your channels. Recommended.', 'forms-notifier-for-elementor' ),
							],
							[
								'value' => 'inline',
								'label' => __( 'Immediately', 'forms-notifier-for-elementor' ),
								'desc'  => __( 'Messages are sent before the form answers. Use it when background tasks do not run on your host.', 'forms-notifier-for-elementor' ),
							],
						],
					],
					[
						'key'   => 'general.notify_on_action_errors',
						'type'  => 'toggle',
						'label' => __( 'Notify even when another form action failed', 'forms-notifier-for-elementor' ),
					],
					[
						'key'   => 'general.duplicate_window',
						'type'  => 'number',
						'label' => __( 'Ignore repeated submissions (seconds)', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'The same content from the same address within this time is ignored. 0 switches the check off.', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 0,
							'max'  => 86400,
							'step' => 1,
						],
						'width' => 'half',
					],
					[
						'key'   => 'general.skip_types',
						'type'  => 'tags',
						'label' => __( 'Field types to leave out', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Fields of these types are never saved or sent.', 'forms-notifier-for-elementor' ),
					],
				],
			],
			[
				'id'       => 'general_data',
				'tab'      => 'general',
				'title'    => __( 'What is saved', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => [
					[
						'key'   => 'general.store_submissions',
						'type'  => 'toggle',
						'label' => __( 'Save submissions on this site', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Off: messages are forwarded and nothing is kept. The submissions list and the mini app stay empty.', 'forms-notifier-for-elementor' ),
					],
					[
						'key'     => 'general.store_ip',
						'type'    => 'select',
						'label'   => __( 'Visitor IP address', 'forms-notifier-for-elementor' ),
						'options' => [
							'full'      => __( 'Save', 'forms-notifier-for-elementor' ),
							'anonymize' => __( 'Save anonymized', 'forms-notifier-for-elementor' ),
							'none'      => __( 'Do not save', 'forms-notifier-for-elementor' ),
						],
						'width'   => 'half',
					],
					[
						'key'   => 'general.store_user_agent',
						'type'  => 'toggle',
						'label' => __( 'Save the browser name', 'forms-notifier-for-elementor' ),
						'width' => 'half',
					],
					[
						'key'   => 'general.normalize_digits',
						'type'  => 'toggle',
						'label' => __( 'Convert Persian and Arabic digits in phone, number and date fields', 'forms-notifier-for-elementor' ),
					],
				],
			],
			[
				'id'       => 'general_language',
				'tab'      => 'general',
				'title'    => __( 'Language, dates and phone numbers', 'forms-notifier-for-elementor' ),
				'priority' => 30,
				'fields'   => [
					[
						'key'     => 'general.admin_lang',
						'type'    => 'select',
						'label'   => __( 'Language of messages for you', 'forms-notifier-for-elementor' ),
						'options' => [
							'site'       => __( 'Site language', 'forms-notifier-for-elementor' ),
							'submission' => __( 'Language of the submission', 'forms-notifier-for-elementor' ),
						] + $languages,
						'width'   => 'half',
					],
					[
						'key'     => 'general.calendar',
						'type'    => 'select',
						'label'   => __( 'Calendar', 'forms-notifier-for-elementor' ),
						'options' => [
							'auto'      => __( 'Automatic', 'forms-notifier-for-elementor' ),
							'gregorian' => __( 'Gregorian', 'forms-notifier-for-elementor' ),
							'jalali'    => __( 'Jalali', 'forms-notifier-for-elementor' ),
						],
						'width'   => 'half',
					],
					[
						'key'            => 'general.default_country',
						'type'           => 'select',
						'label'          => __( 'Country of phone numbers without a country code', 'forms-notifier-for-elementor' ),
						'options_source' => 'countries',
						'width'          => 'half',
					],
				],
			],
			[
				'id'        => 'general_quiet_hours',
				'tab'       => 'general',
				'title'     => __( 'Quiet hours', 'forms-notifier-for-elementor' ),
				'desc'      => __( 'Messages for you wait until quiet hours end. Replies to visitors and alerts are never delayed.', 'forms-notifier-for-elementor' ),
				'priority'  => 40,
				'collapsed' => true,
				'fields'    => [
					[
						'key'   => 'general.quiet_hours.enabled',
						'type'  => 'toggle',
						'label' => __( 'Use quiet hours', 'forms-notifier-for-elementor' ),
					],
					[
						'key'     => 'general.quiet_hours.from',
						'type'    => 'time',
						'label'   => _x( 'From', 'time of day', 'forms-notifier-for-elementor' ),
						'width'   => 'half',
						'show_if' => [ self::is_on( 'general.quiet_hours.enabled' ) ],
					],
					[
						'key'     => 'general.quiet_hours.to',
						'type'    => 'time',
						'label'   => _x( 'To', 'time of day', 'forms-notifier-for-elementor' ),
						'width'   => 'half',
						'show_if' => [ self::is_on( 'general.quiet_hours.enabled' ) ],
					],
					[
						'key'            => 'general.quiet_hours.channels',
						'type'           => 'multiselect',
						'label'          => __( 'Channels', 'forms-notifier-for-elementor' ),
						'options_source' => 'channels',
						'show_if'        => [ self::is_on( 'general.quiet_hours.enabled' ) ],
					],
				],
			],
		];
	}

	/** Attachments tab. */
	private static function attachment_sections(): array {
		$modes = [
			'off'  => __( 'Do not include files', 'forms-notifier-for-elementor' ),
			'link' => __( 'Send download links', 'forms-notifier-for-elementor' ),
			'send' => __( 'Send the files', 'forms-notifier-for-elementor' ),
			'both' => __( 'Send the files and the links', 'forms-notifier-for-elementor' ),
		];

		$per_channel = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$capabilities = $channel->capabilities();
			if ( empty( $capabilities['attachments'] ) && empty( $capabilities['links'] ) ) {
				continue;
			}
			$per_channel[] = [
				'key'     => 'attachments.channels.' . $id,
				'type'    => 'select',
				'label'   => $channel->label(),
				'default' => 'inherit',
				'options' => [ 'inherit' => __( 'Same as above', 'forms-notifier-for-elementor' ) ] + $modes,
				'width'   => 'third',
			];
		}

		$active = [
			'key'   => 'attachments.mode',
			'op'    => 'is_not',
			'value' => 'off',
		];

		$sections = [
			[
				'id'       => 'attachments_policy',
				'tab'      => 'attachments',
				'title'    => __( 'Uploaded files', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'     => 'attachments.mode',
						'type'    => 'select',
						'label'   => __( 'What to do with uploaded files', 'forms-notifier-for-elementor' ),
						'options' => $modes,
					],
					[
						'key'     => 'attachments.types',
						'type'    => 'select',
						'label'   => __( 'File types', 'forms-notifier-for-elementor' ),
						'options' => [
							'all'    => __( 'All safe types', 'forms-notifier-for-elementor' ),
							'images' => __( 'Images only', 'forms-notifier-for-elementor' ),
							'custom' => __( 'Selected extensions', 'forms-notifier-for-elementor' ),
						],
						'width'   => 'half',
						'show_if' => [ $active ],
					],
					[
						'key'     => 'attachments.extensions',
						'type'    => 'tags',
						'label'   => __( 'Allowed extensions', 'forms-notifier-for-elementor' ),
						'width'   => 'half',
						'show_if' => [
							$active,
							[
								'key'   => 'attachments.types',
								'op'    => 'is',
								'value' => 'custom',
							],
						],
					],
					[
						'key'     => 'attachments.max_mb',
						'type'    => 'number',
						'label'   => __( 'Largest file to send (MB)', 'forms-notifier-for-elementor' ),
						'desc'    => __( 'Larger files become links when the channel supports them.', 'forms-notifier-for-elementor' ),
						'attrs'   => [
							'min'  => 1,
							'max'  => 2048,
							'step' => 1,
						],
						'width'   => 'half',
						'show_if' => [ $active ],
					],
					[
						'key'     => 'attachments.max_files',
						'type'    => 'number',
						'label'   => __( 'Most files per message', 'forms-notifier-for-elementor' ),
						'attrs'   => [
							'min'  => 1,
							'max'  => 100,
							'step' => 1,
						],
						'width'   => 'half',
						'show_if' => [ $active ],
					],
				],
			],
			[
				'id'       => 'attachments_storage',
				'tab'      => 'attachments',
				'title'    => __( 'Storage and links', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => [
					[
						'key'   => 'attachments.keep_copy',
						'type'  => 'toggle',
						'label' => __( 'Keep a protected copy of uploaded files', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Needed for private download links and for forms that delete files after emailing them.', 'forms-notifier-for-elementor' ),
					],
					[
						'key'   => 'attachments.link_ttl_days',
						'type'  => 'number',
						'label' => __( 'Download links expire after (days)', 'forms-notifier-for-elementor' ),
						'desc'  => __( '0 means links never expire.', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
						'width' => 'half',
					],
				],
			],
		];

		if ( $per_channel ) {
			$sections[] = [
				'id'        => 'attachments_channels',
				'tab'       => 'attachments',
				'title'     => __( 'Different rule per channel', 'forms-notifier-for-elementor' ),
				'priority'  => 30,
				'collapsed' => true,
				'fields'    => $per_channel,
			];
		}
		return $sections;
	}

	/** Queue tab. */
	private static function queue_sections(): array {
		return [
			[
				'id'       => 'queue_retries',
				'tab'      => 'queue',
				'title'    => __( 'Retries', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'   => 'queue.max_attempts',
						'type'  => 'number',
						'label' => __( 'Attempts per message', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 1,
							'max'  => 20,
							'step' => 1,
						],
						'width' => 'half',
					],
					[
						'key'      => 'queue.backoff',
						'type'     => 'tags',
						'label'    => __( 'Wait before each retry (seconds)', 'forms-notifier-for-elementor' ),
						'desc'     => __( 'One number per retry. The last one repeats.', 'forms-notifier-for-elementor' ),
						'sanitize' => 'int',
						'width'    => 'half',
					],
				],
			],
			[
				'id'        => 'queue_runner',
				'tab'       => 'queue',
				'title'     => __( 'Background runner', 'forms-notifier-for-elementor' ),
				'priority'  => 20,
				'collapsed' => true,
				'fields'    => [
					[
						'key'     => 'queue.ticker',
						'type'    => 'select',
						'label'   => __( 'Run with', 'forms-notifier-for-elementor' ),
						'options' => [
							'auto'             => __( 'Automatic', 'forms-notifier-for-elementor' ),
							'cron'             => 'WP-Cron',
							'action_scheduler' => 'Action Scheduler',
						],
						'width'   => 'third',
					],
					[
						'key'   => 'queue.batch',
						'type'  => 'number',
						'label' => __( 'Messages per run', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 1,
							'max'  => 500,
							'step' => 1,
						],
						'width' => 'third',
					],
					[
						'key'   => 'queue.time_budget',
						'type'  => 'number',
						'label' => __( 'Seconds per run', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 5,
							'max'  => 120,
							'step' => 1,
						],
						'width' => 'third',
					],
				],
			],
		];
	}

	/** Privacy tab. */
	private static function privacy_sections(): array {
		$days = [
			'min'  => 0,
			'max'  => 3650,
			'step' => 1,
		];
		return [
			[
				'id'       => 'privacy_retention',
				'tab'      => 'privacy',
				'title'    => __( 'How long data is kept (days)', 'forms-notifier-for-elementor' ),
				'desc'     => __( '0 keeps the data until you delete it.', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'   => 'privacy.retention_submissions_days',
						'type'  => 'number',
						'label' => __( 'Submissions', 'forms-notifier-for-elementor' ),
						'attrs' => $days,
						'width' => 'half',
					],
					[
						'key'   => 'privacy.retention_files_days',
						'type'  => 'number',
						'label' => __( 'Uploaded files', 'forms-notifier-for-elementor' ),
						'attrs' => $days,
						'width' => 'half',
					],
					[
						'key'   => 'privacy.retention_messages_days',
						'type'  => 'number',
						'label' => __( 'Message log', 'forms-notifier-for-elementor' ),
						'attrs' => $days,
						'width' => 'half',
					],
					[
						'key'   => 'privacy.retention_log_days',
						'type'  => 'number',
						'label' => __( 'System log', 'forms-notifier-for-elementor' ),
						'attrs' => $days,
						'width' => 'half',
					],
				],
			],
			[
				'id'       => 'privacy_data',
				'tab'      => 'privacy',
				'title'    => __( 'Data', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => [
					[
						'key'   => 'privacy.log_bodies',
						'type'  => 'toggle',
						'label' => __( 'Keep message texts in the log', 'forms-notifier-for-elementor' ),
					],
					[
						'key'   => 'privacy.delete_on_uninstall',
						'type'  => 'toggle',
						'label' => __( 'Delete all data when the plugin is deleted', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Settings, submissions, files and logs are removed for good.', 'forms-notifier-for-elementor' ),
					],
				],
			],
		];
	}

	/** Advanced tab. */
	private static function advanced_sections(): array {
		return [
			[
				'id'       => 'advanced_http',
				'tab'      => 'advanced',
				'title'    => __( 'Connections', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'   => 'advanced.http_timeout',
						'type'  => 'number',
						'label' => __( 'Request timeout (seconds)', 'forms-notifier-for-elementor' ),
						'attrs' => [
							'min'  => 3,
							'max'  => 120,
							'step' => 1,
						],
						'width' => 'half',
					],
					[
						'key'   => 'advanced.allow_private_hosts',
						'type'  => 'toggle',
						'label' => __( 'Allow addresses on private networks', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Only for self-hosted services on the same network, such as n8n or a local API server. Public servers work on any port without this.', 'forms-notifier-for-elementor' ),
					],
					[
						'key'     => 'advanced.proxy_header',
						'type'    => 'select',
						'label'   => __( 'Visitor address behind a proxy', 'forms-notifier-for-elementor' ),
						'desc'    => __( 'Only when the site runs behind a proxy or CDN that sets this header. Otherwise every visitor shares the address of the proxy, in stored submissions and in the limits on sign-ins and downloads. A wrong choice lets visitors fake their address.', 'forms-notifier-for-elementor' ),
						'options' => [
							[
								'value' => '',
								'label' => __( 'Address of the connection (no proxy)', 'forms-notifier-for-elementor' ),
							],
							[
								'value' => 'cf-connecting-ip',
								'label' => 'CF-Connecting-IP (Cloudflare)',
							],
							[
								'value' => 'x-real-ip',
								'label' => 'X-Real-IP',
							],
							[
								'value' => 'x-forwarded-for',
								'label' => 'X-Forwarded-For',
							],
						],
					],
				],
			],
			[
				'id'       => 'advanced_debug',
				'tab'      => 'advanced',
				'title'    => __( 'Troubleshooting', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => [
					[
						'key'   => 'advanced.debug_log',
						'type'  => 'toggle',
						'label' => __( 'Detailed system log', 'forms-notifier-for-elementor' ),
						'desc'  => __( 'Also writes to the WordPress debug log when that is enabled. Switch it off when you are done.', 'forms-notifier-for-elementor' ),
					],
				],
			],
		];
	}

	/**
	 * Condition: a toggle is on.
	 *
	 * @param string $key Absolute key.
	 */
	private static function is_on( string $key ): array {
		return [
			'key'   => $key,
			'op'    => 'truthy',
			'value' => '',
		];
	}
}
