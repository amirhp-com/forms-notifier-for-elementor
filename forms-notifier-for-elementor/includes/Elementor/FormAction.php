<?php
/**
 * Submit action.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Urls;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use ElementorPro\Modules\Forms\Classes\Action_Base;

defined( 'ABSPATH' ) || exit;

/**
 * The "Forms Notifier" action of the Elementor Pro form widget. Loaded only inside the registration hook, where the
 * parent class exists.
 *
 * It sends nothing itself: it adds the per-form controls and marks the record.
 */
final class FormAction extends Action_Base {

	/** Template tags offered as chips. */
	private const TAGS = [ '{all_fields}', '{field:id}', '{form_name}', '{page_title}', '{page_url}', '{date}', '{time}', '{submission_id}', '{user_ip}', '{site_name}' ];

	/** @var ProFormSource */
	private $source;

	/**
	 * @param ProFormSource $source Adapter.
	 */
	public function __construct( ProFormSource $source ) {
		$this->source = $source;
	}

	/** Action name. */
	public function get_name() {
		return EditorValues::ACTION;
	}

	/** Action label. */
	public function get_label() {
		return __( 'Forms Notifier', 'forms-notifier-for-elementor' );
	}

	/**
	 * Mark the record.
	 *
	 * @param object $record       Form_Record.
	 * @param object $ajax_handler Ajax_Handler.
	 */
	public function run( $record, $ajax_handler ) {
		$this->source->mark_action( $record, $ajax_handler );
	}

	/**
	 * Destinations belong to this site and do not travel with a template.
	 *
	 * @param array $element Element data.
	 */
	public function on_export( $element ) {
		if ( is_array( $element ) && isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			foreach ( array_keys( $element['settings'] ) as $key ) {
				if ( strncmp( (string) $key, 'fnfe_receivers_', 15 ) === 0 && $key !== 'fnfe_receivers_mode' ) {
					unset( $element['settings'][ $key ] );
				}
			}
		}
		return $element;
	}

	/**
	 * Controls of the action.
	 *
	 * @param object $widget Form widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_fnfe',
			[
				'label'     => __( 'Forms Notifier', 'forms-notifier-for-elementor' ),
				'tab'       => Controls_Manager::TAB_CONTENT,
				'condition' => [
					'submit_actions' => $this->get_name(),
				],
			]
		);

		$widget->add_control(
			'fnfe_intro',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'These options override the settings of the plugin for this form only. Leave them empty to use the settings of the plugin.', 'forms-notifier-for-elementor' )
					. '<br><a class="fnfe-open-form" href="' . esc_url( Urls::admin( 'forms' ) ) . '" data-fnfe-base="' . esc_url( Urls::admin( 'forms/key' ) ) . '" target="_blank" rel="noopener">'
					. esc_html__( 'Open full settings for this form', 'forms-notifier-for-elementor' ) . '</a>',
				'content_classes' => 'elementor-descriptor fnfe-intro',
			]
		);

		$widget->add_control(
			'fnfe_enabled',
			$this->control(
				[
					'label'   => __( 'Notifications', 'forms-notifier-for-elementor' ),
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => [
						''    => __( 'Follow the site setting', 'forms-notifier-for-elementor' ),
						'yes' => __( 'On for this form', 'forms-notifier-for-elementor' ),
						'no'  => __( 'Off for this form', 'forms-notifier-for-elementor' ),
					],
				]
			)
		);

		$labels    = [];
		$receivers = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$labels[ $id ] = $channel->label();
			$can           = $channel->capabilities();
			if ( ( $can['receivers'] ?? '' ) === 'list' ) {
				$receivers[ $id ] = (string) ( $can['receiver_kind'] ?? '' );
			}
		}

		$widget->add_control(
			'fnfe_channels',
			$this->control(
				[
					'label'       => __( 'Channels', 'forms-notifier-for-elementor' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'default'     => '',
					'options'     => $labels,
					'description' => esc_html__( 'Only these channels notify about this form. Empty: every channel that is switched on.', 'forms-notifier-for-elementor' ),
				]
			)
		);

		foreach ( $receivers as $id => $kind ) {
			$widget->add_control(
				'fnfe_receivers_' . $id,
				$this->control(
					[
						'label'       => sprintf(
							/* translators: %1$s: channel name */
							__( 'Receivers: %1$s', 'forms-notifier-for-elementor' ),
							$labels[ $id ]
						),
						'type'        => Controls_Manager::TEXT,
						'default'     => '',
						'placeholder' => __( 'Addresses, separated by commas', 'forms-notifier-for-elementor' ),
						'description' => in_array( $kind, [ 'phone', 'email' ], true )
							? sprintf(
								/* translators: %1$s: shortcode of a form field */
								esc_html__( 'A form field may be used too: %1$s', 'forms-notifier-for-elementor' ),
								self::chip( '[field id="phone"]' )
							)
							: '',
						'conditions'  => [
							'relation' => 'or',
							'terms'    => [
								// The empty forms come first: "contains" needs a list.
								[
									'name'     => 'fnfe_channels',
									'operator' => '==',
									'value'    => '',
								],
								[
									'name'     => 'fnfe_channels',
									'operator' => '===',
									'value'    => [],
								],
								[
									'name'     => 'fnfe_channels',
									'operator' => 'contains',
									'value'    => $id,
								],
							],
						],
					]
				)
			);
		}

		$widget->add_control(
			'fnfe_receivers_mode',
			$this->control(
				[
					'label'   => __( 'Use these receivers', 'forms-notifier-for-elementor' ),
					'type'    => Controls_Manager::SELECT,
					'default' => 'append',
					'options' => [
						'append'  => __( 'In addition to the configured receivers', 'forms-notifier-for-elementor' ),
						'replace' => __( 'Instead of the configured receivers', 'forms-notifier-for-elementor' ),
					],
				]
			)
		);

		$widget->add_control(
			'fnfe_template',
			$this->control(
				[
					'label'       => __( 'Message template', 'forms-notifier-for-elementor' ),
					'type'        => Controls_Manager::TEXTAREA,
					'default'     => '',
					'rows'        => 6,
					'placeholder' => __( 'Empty: the template of each channel', 'forms-notifier-for-elementor' ),
					'description' => esc_html__( 'Click a tag to copy it:', 'forms-notifier-for-elementor' ) . ' ' . implode( ' ', array_map( [ self::class, 'chip' ], self::TAGS ) ),
				]
			)
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'text',
			$this->control(
				[
					'label'   => __( 'Text', 'forms-notifier-for-elementor' ),
					'type'    => Controls_Manager::TEXT,
					'default' => '',
				]
			)
		);
		$repeater->add_control(
			'type',
			$this->control(
				[
					'label'   => __( 'Type', 'forms-notifier-for-elementor' ),
					'type'    => Controls_Manager::SELECT,
					'default' => 'url',
					'options' => [
						'url'        => __( 'Open a link', 'forms-notifier-for-elementor' ),
						'copy'       => __( 'Copy a text', 'forms-notifier-for-elementor' ),
						'submission' => __( 'Open the submission', 'forms-notifier-for-elementor' ),
					],
				]
			)
		);
		$repeater->add_control(
			'value',
			$this->control(
				[
					'label'       => __( 'Link or text', 'forms-notifier-for-elementor' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'description' => esc_html__( 'Template tags are allowed.', 'forms-notifier-for-elementor' ),
					'condition'   => [
						'type!' => 'submission',
					],
				]
			)
		);
		$widget->add_control(
			'fnfe_buttons',
			$this->control(
				[
					'label'         => __( 'Buttons (Telegram and Bale)', 'forms-notifier-for-elementor' ),
					'type'          => Controls_Manager::REPEATER,
					'fields'        => $repeater->get_controls(),
					'default'       => [],
					'prevent_empty' => false,
					'title_field'   => '{{{ elementor.helpers.sanitize( text ) }}}',
				]
			)
		);

		$widget->add_control(
			'fnfe_attach',
			$this->control(
				[
					'label'   => __( 'Uploaded files', 'forms-notifier-for-elementor' ),
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => [
						''     => __( 'Follow the site setting', 'forms-notifier-for-elementor' ),
						'off'  => __( 'Do not send', 'forms-notifier-for-elementor' ),
						'link' => __( 'Send links', 'forms-notifier-for-elementor' ),
						'send' => __( 'Send the files', 'forms-notifier-for-elementor' ),
						'both' => __( 'Send the files and links', 'forms-notifier-for-elementor' ),
					],
				]
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * Keys every control has.
	 *
	 * @param array $control Control.
	 */
	private function control( array $control ): array {
		return array_merge(
			[
				'label_block' => true,
				'render_type' => 'none',
				'dynamic'     => [
					'active' => false,
				],
			],
			$control
		);
	}

	/**
	 * Tag that is copied on click.
	 *
	 * @param string $tag Tag.
	 */
	private static function chip( string $tag ): string {
		return '<code class="fnfe-chip" data-fnfe-copy="' . esc_attr( $tag ) . '" role="button" tabindex="0">' . esc_html( $tag ) . '</code>';
	}
}
