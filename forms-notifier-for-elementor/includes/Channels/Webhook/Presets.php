<?php
/**
 * Webhook presets.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Webhook;

defined( 'ABSPATH' ) || exit;

/** Presets pre-fill and explain; every endpoint stays fully editable. */
final class Presets {

	/** Preset id => [ label, shape (standard|flat|ifttt), placeholder, help ]. */
	public static function all(): array {
		return [
			'custom' => [
				'label'       => __( 'Any webhook', 'forms-notifier-for-elementor' ),
				'shape'       => 'standard',
				'placeholder' => 'https://example.com/hook',
				'help'        => __( 'Sends the standard JSON. Everything can be changed below.', 'forms-notifier-for-elementor' ),
			],
			'n8n'    => [
				'label'       => 'n8n',
				'shape'       => 'standard',
				'placeholder' => 'https://n8n.example.com/webhook/…',
				'help'        => __( 'Add a Webhook node, choose POST and copy its Production URL. The Test URL only works while the editor is listening. For an n8n on your own network, allow private hosts in the advanced settings.', 'forms-notifier-for-elementor' ),
			],
			'make'   => [
				'label'       => 'Make',
				'shape'       => 'standard',
				'placeholder' => 'https://hook.eu1.make.com/…',
				'help'        => __( 'Add a Webhooks module of type Custom webhook and copy its address. Run the scenario once and press the test button here, so Make learns the structure.', 'forms-notifier-for-elementor' ),
			],
			'zapier' => [
				'label'       => 'Zapier',
				'shape'       => 'flat',
				'placeholder' => 'https://hooks.zapier.com/hooks/catch/…',
				'help'        => __( 'Choose the trigger Webhooks by Zapier, event Catch Hook, and copy its address. Fields are sent as flat keys, which are easy to map.', 'forms-notifier-for-elementor' ),
			],
			'pabbly' => [
				'label'       => 'Pabbly Connect',
				'shape'       => 'flat',
				'placeholder' => 'https://connect.pabbly.com/workflow/sendwebhookdata/…',
				'help'        => __( 'Choose the trigger Webhook and copy its address. Press the test button here while Pabbly waits for data.', 'forms-notifier-for-elementor' ),
			],
			'ifttt'  => [
				'label'       => 'IFTTT',
				'shape'       => 'ifttt',
				'placeholder' => 'https://maker.ifttt.com/trigger/{event}/with/key/{key}',
				'help'        => __( 'Use the Webhooks service with "Receive a web request". value1 is the form name, value2 all fields, value3 the page address.', 'forms-notifier-for-elementor' ),
			],
		];
	}

	/** Preset id => label. */
	public static function labels(): array {
		return array_map(
			static function ( array $preset ): string {
				return $preset['label'];
			},
			self::all()
		);
	}

	/**
	 * JSON shape of a preset.
	 *
	 * @param string $preset Preset id.
	 */
	public static function shape( string $preset ): string {
		return in_array( $preset, [ 'zapier', 'pabbly' ], true ) ? 'flat' : ( $preset === 'ifttt' ? 'ifttt' : 'standard' );
	}
}
