<?php
/**
 * WhatsApp module.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp;

use AmirhpCom\FormsNotifier\Channels\Sms\SmsHelp;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;

defined( 'ABSPATH' ) || exit;

/** Registers the built-in WhatsApp gateways and the help topic. */
final class WhatsAppModule implements ModuleInterface {

	/** Attach hooks. */
	public function register(): void {
		add_filter( 'fnfe_whatsapp_providers', [ self::class, 'providers' ], 5 );
		add_filter( 'fnfe_help_topics', [ self::class, 'help' ] );
	}

	/**
	 * Built-in gateways.
	 *
	 * @return array<string,string>
	 */
	public static function provider_classes(): array {
		return [
			'meta'      => Providers\MetaCloudApi::class,
			'dialog360' => Providers\Dialog360::class,
			'twilio'    => Providers\TwilioWhatsApp::class,
			'ultramsg'  => Providers\UltraMsg::class,
			'greenapi'  => Providers\GreenApi::class,
			'waha'      => Providers\Waha::class,
			'evolution' => Providers\EvolutionApi::class,
			'wassenger' => Providers\Wassenger::class,
			'whapi'     => Providers\Whapi::class,
			'custom'    => Providers\CustomHttp::class,
		];
	}

	/**
	 * Filter fnfe_whatsapp_providers.
	 *
	 * @param array $providers Id => class or instance.
	 */
	public static function providers( $providers ): array {
		return (array) $providers + self::provider_classes();
	}

	/**
	 * Filter fnfe_help_topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function help( $topics ): array {
		$topics = (array) $topics;

		$topics['whatsapp'] = [
			'title' => __( 'WhatsApp gateways', 'forms-notifier-for-elementor' ),
			'group' => 'sms',
			'body'  => SmsHelp::paragraphs(
				[
					__( 'The Cloud API of Meta is the official way. Free text reaches a person only within 24 hours after that person wrote to you; at any other time the message must be an approved template. Write it as pattern text that starts with <code>template:NAME</code>.', 'forms-notifier-for-elementor' ),
					__( 'UltraMsg, Green API, WAHA, Evolution API, Wassenger and whapi.cloud drive a normal WhatsApp account. WhatsApp does not allow that for bulk messages and may block the account.', 'forms-notifier-for-elementor' ),
				]
			) . SmsHelp::credentials(
				'whatsapp',
				[
					'meta'      => __( 'In the Meta developer portal open your app, then WhatsApp, API setup: copy the phone number ID. Create a permanent token with a system user.', 'forms-notifier-for-elementor' ),
					'dialog360' => __( 'Create an API key for your number in the 360dialog hub.', 'forms-notifier-for-elementor' ),
					'twilio'    => __( 'Account SID and auth token are on the first page of the console; the sender is your WhatsApp number at Twilio.', 'forms-notifier-for-elementor' ),
					'ultramsg'  => __( 'Instance ID and token are on the page of your instance.', 'forms-notifier-for-elementor' ),
					'greenapi'  => __( 'apiUrl, idInstance and apiTokenInstance are on the page of your instance in the console.', 'forms-notifier-for-elementor' ),
					'waha'      => __( 'Use the address of your WAHA server, its API key and the name of the session.', 'forms-notifier-for-elementor' ),
					'evolution' => __( 'Use the address of your Evolution API server, its API key and the name of the instance.', 'forms-notifier-for-elementor' ),
					'whapi'     => __( 'The token is on the page of your channel in the panel.', 'forms-notifier-for-elementor' ),
					'wassenger' => __( 'Create an API key in the developer section of the console.', 'forms-notifier-for-elementor' ),
				]
			),
			'steps' => [],
		];
		return $topics;
	}
}
