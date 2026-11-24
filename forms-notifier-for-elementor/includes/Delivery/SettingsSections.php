<?php
/**
 * Settings sections.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Registry;

defined( 'ABSPATH' ) || exit;

/** Failure alerts and digest tabs. */
final class SettingsSections {

	/**
	 * Filter fnfe_settings_sections.
	 *
	 * @param array $sections Sections.
	 */
	public static function add( $sections ): array {
		return array_merge( is_array( $sections ) ? $sections : [], self::alerts(), self::digest() );
	}

	/** Failure alerts tab. */
	private static function alerts(): array {
		$on = [ self::is_on( 'alerts.enabled' ) ];
		return [
			[
				'id'       => 'alerts_main',
				'tab'      => 'alerts',
				'title'    => __( 'Failure alerts', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'Tells you when a message could not be delivered, through channels of your choice.', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'   => 'alerts.enabled',
						'type'  => 'toggle',
						'label' => __( 'Send failure alerts', 'forms-notifier-for-elementor' ),
					],
					[
						'key'     => 'alerts.when',
						'type'    => 'radio',
						'label'   => __( 'When', 'forms-notifier-for-elementor' ),
						'options' => [
							'final' => __( 'After the last attempt failed', 'forms-notifier-for-elementor' ),
							'first' => __( 'As soon as the first attempt fails', 'forms-notifier-for-elementor' ),
						],
						'show_if' => $on,
					],
					[
						'key'     => 'alerts.max_per_hour',
						'type'    => 'number',
						'label'   => __( 'Most alerts per hour', 'forms-notifier-for-elementor' ),
						'desc'    => __( 'Further failures are counted and mentioned in the next alert.', 'forms-notifier-for-elementor' ),
						'attrs'   => [
							'min'  => 1,
							'max'  => 100,
							'step' => 1,
						],
						'width'   => 'half',
						'show_if' => $on,
					],
				],
			],
			[
				'id'       => 'alerts_channels',
				'tab'      => 'alerts',
				'title'    => __( 'Who is told', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'Use a channel other than the ones you watch: a broken channel cannot report itself.', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => self::channel_fields( 'alerts', $on ),
			],
			[
				'id'        => 'alerts_template',
				'tab'       => 'alerts',
				'title'     => __( 'Message', 'forms-notifier-for-elementor' ),
				'desc'      => __( 'Leave empty to use the built-in text.', 'forms-notifier-for-elementor' ),
				'priority'  => 30,
				'collapsed' => true,
				'fields'    => [ self::template( 'alerts.template', 'alert', $on ) ],
			],
		];
	}

	/** Digest tab. */
	private static function digest(): array {
		global $wp_locale;

		$on       = [ self::is_on( 'digest.enabled' ) ];
		$weekdays = [];
		for ( $day = 0; $day < 7; $day++ ) {
			$weekdays[] = [
				'value' => (string) $day,
				'label' => $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday( $day ) : (string) $day,
			];
		}

		return [
			[
				'id'       => 'digest_main',
				'tab'      => 'digest',
				'title'    => __( 'Digest', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'A short report about submissions, deliveries and problems.', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					[
						'key'   => 'digest.enabled',
						'type'  => 'toggle',
						'label' => __( 'Send a digest', 'forms-notifier-for-elementor' ),
					],
					[
						'key'     => 'digest.frequency',
						'type'    => 'select',
						'label'   => __( 'How often', 'forms-notifier-for-elementor' ),
						'options' => [
							'daily'  => __( 'Every day', 'forms-notifier-for-elementor' ),
							'weekly' => __( 'Every week', 'forms-notifier-for-elementor' ),
						],
						'width'   => 'third',
						'show_if' => $on,
					],
					[
						'key'      => 'digest.weekday',
						'type'     => 'select',
						'label'    => __( 'Day of the week', 'forms-notifier-for-elementor' ),
						'options'  => $weekdays,
						'sanitize' => 'int',
						'width'    => 'third',
						'show_if'  => [
							self::is_on( 'digest.enabled' ),
							[
								'key'   => 'digest.frequency',
								'op'    => 'is',
								'value' => 'weekly',
							],
						],
					],
					[
						'key'     => 'digest.time',
						'type'    => 'time',
						'label'   => __( 'Time of day', 'forms-notifier-for-elementor' ),
						'desc'    => __( 'In the timezone of the site.', 'forms-notifier-for-elementor' ),
						'width'   => 'third',
						'show_if' => $on,
					],
					[
						'key'     => 'digest.skip_empty',
						'type'    => 'toggle',
						'label'   => __( 'Skip the digest when nothing happened', 'forms-notifier-for-elementor' ),
						'show_if' => $on,
					],
				],
			],
			[
				'id'       => 'digest_channels',
				'tab'      => 'digest',
				'title'    => __( 'Who gets it', 'forms-notifier-for-elementor' ),
				'priority' => 20,
				'fields'   => self::channel_fields( 'digest', $on ),
			],
			[
				'id'        => 'digest_template',
				'tab'       => 'digest',
				'title'     => __( 'Message', 'forms-notifier-for-elementor' ),
				'desc'      => __( 'Leave empty to use the built-in text.', 'forms-notifier-for-elementor' ),
				'priority'  => 30,
				'collapsed' => true,
				'fields'    => [ self::template( 'digest.template', 'digest', $on ) ],
			],
		];
	}

	/**
	 * Switch and receivers of every channel.
	 *
	 * @param string $root alerts | digest.
	 * @param array  $on   Condition of the tab.
	 */
	private static function channel_fields( string $root, array $on ): array {
		$fields = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$key      = "$root.channels.$id";
			$fields[] = [
				'key'     => "$key.enabled",
				'type'    => 'toggle',
				'label'   => $channel->label(),
				'default' => false,
				'show_if' => $on,
			];
			if ( ( $channel->capabilities()['receivers'] ?? 'list' ) !== 'none' ) {
				$fields[] = [
					'key'     => "$key.receivers",
					'type'    => 'receivers',
					/* translators: %1$s: channel name */
					'label'   => sprintf( __( 'Receivers on %1$s', 'forms-notifier-for-elementor' ), $channel->label() ),
					'channel' => $id,
					'default' => [],
					'show_if' => array_merge( $on, [ self::is_on( "$key.enabled" ) ] ),
				];
			}
		}
		return $fields;
	}

	/**
	 * Template field.
	 *
	 * @param string $key     Key.
	 * @param string $purpose Purpose.
	 * @param array  $on      Condition of the tab.
	 */
	private static function template( string $key, string $purpose, array $on ): array {
		return [
			'key'       => $key,
			'type'      => 'template',
			'label'     => __( 'Text', 'forms-notifier-for-elementor' ),
			'localized' => true,
			'purpose'   => $purpose,
			'show_if'   => $on,
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
