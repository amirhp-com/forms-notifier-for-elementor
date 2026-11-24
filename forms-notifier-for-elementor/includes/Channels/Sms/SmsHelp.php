<?php
/**
 * SMS help.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Core\Registry;

defined( 'ABSPATH' ) || exit;

/** Help topics of the SMS channel. */
final class SmsHelp {

	/**
	 * Filter fnfe_help_topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function topics( $topics ): array {
		$topics = (array) $topics;

		$topics['sms_routing']     = [
			'title' => __( 'SMS gateways and country routing', 'forms-notifier-for-elementor' ),
			'group' => 'sms',
			'body'  => self::paragraphs(
				[
					__( 'Choose a default gateway and, if you like, backup gateways in the SMS channel. When a gateway refuses a message or cannot be reached, the next one is tried at once.', 'forms-notifier-for-elementor' ),
					__( 'Add a route to use another gateway for some countries. The country comes from the dial code of the number. The first route that contains it is used.', 'forms-notifier-for-elementor' ),
					__( 'Numbers are stored in the international form, for example +989120000000. National numbers are completed with the default country of the general settings, or with the country field of the form.', 'forms-notifier-for-elementor' ),
					__( '"Sent" means that the gateway accepted the message. Whether the phone received it is shown in the panel of the gateway.', 'forms-notifier-for-elementor' ),
				]
			),
			'steps' => [],
		];
		$topics['sms_pattern']     = [
			'title' => __( 'Pattern text', 'forms-notifier-for-elementor' ),
			'group' => 'sms',
			'body'  => self::paragraphs(
				[
					__( 'Gateways deliver approved patterns (templates) to every number, also to numbers that block advertising. Write a pattern in the message field like this:', 'forms-notifier-for-elementor' ),
					'<pre>' . esc_html( "pattern:590428\npattern@smsir:123456\n0:{field:name}\ncode:{submission_id}\n---\nThank you {field:name}, your request #{submission_id} was received." ) . '</pre>',
					__( 'The first line holds the pattern code of the first gateway. A line like <code>pattern@smsir:123456</code> holds the code for one other gateway; a backup gateway without a code of its own sends the normal text.', 'forms-notifier-for-elementor' ),
					__( 'Then comes one variable per line: <code>name:value</code>, or only a value, which takes the next position. A line of three or more dashes ends the variables. The text after it is the normal text and may be left out.', 'forms-notifier-for-elementor' ),
					__( 'For WhatsApp, start with <code>template:NAME</code> and add a line like <code>lang:en_US</code> for the language of the approved template.', 'forms-notifier-for-elementor' ),
					__( 'Only the text you write is read as a pattern. What a visitor types never is.', 'forms-notifier-for-elementor' ),
				]
			),
			'steps' => [],
		];
		$topics['sms_credentials'] = [
			'title' => __( 'Where to find SMS gateway credentials', 'forms-notifier-for-elementor' ),
			'group' => 'sms',
			'body'  => self::credentials( 'sms', self::hints() ),
			'steps' => [],
		];
		$topics['sms_custom']      = [
			'title' => __( 'Your own SMS panel: custom HTTP gateway', 'forms-notifier-for-elementor' ),
			'group' => 'sms',
			'body'  => self::paragraphs(
				[
					__( 'Any panel with an HTTP API works: enter the address, choose the method and the format, and write the body with placeholders such as <code>{to}</code>, <code>{to_local}</code>, <code>{text}</code>, <code>{sender}</code> and <code>{token}</code>.', 'forms-notifier-for-elementor' ),
					__( 'In JSON, write placeholders inside quotation marks; special characters are escaped for you. Keep keys and passwords in the API key field, which is stored encrypted.', 'forms-notifier-for-elementor' ),
					__( 'If your panel answers with status 200 even for errors, fill in one of the success checks.', 'forms-notifier-for-elementor' ),
				]
			),
			'steps' => [],
		];
		return $topics;
	}

	/**
	 * List of gateways with a hint and a link.
	 *
	 * @param string $channel Channel id.
	 * @param array  $hints   Provider id => text.
	 */
	public static function credentials( string $channel, array $hints ): string {
		$items = '';
		foreach ( Registry::providers( $channel ) as $id => $provider ) {
			if ( ! isset( $hints[ $id ] ) ) {
				continue;
			}
			$url    = $provider->panel_url();
			$label  = esc_html( $provider->label() );
			$items .= '<li><strong>' . ( $url === '' ? $label : '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>' ) . '</strong>: ' . esc_html( $hints[ $id ] ) . '</li>';
		}
		return '<ul>' . $items . '</ul>';
	}

	/**
	 * Paragraphs.
	 *
	 * @param string[] $texts Texts with limited HTML.
	 */
	public static function paragraphs( array $texts ): string {
		$out = '';
		foreach ( $texts as $text ) {
			$out .= strncmp( $text, '<pre>', 5 ) === 0 ? $text : '<p>' . $text . '</p>';
		}
		return $out;
	}

	/** What each gateway needs. */
	private static function hints(): array {
		$api_key = __( 'Create an API key in the web service or developer section of the panel, and copy the sender line from the list of your lines.', 'forms-notifier-for-elementor' );
		return [
			'kavenegar'   => __( 'Copy the API key from the account settings of the panel. Templates for patterns are in the verification service section.', 'forms-notifier-for-elementor' ),
			'ippanel'     => $api_key,
			'farazsms'    => $api_key,
			'melipayamak' => __( 'Use the username and password of the panel, or the web service password if you set one. The body id of a pattern is in the shared service lines section.', 'forms-notifier-for-elementor' ),
			'smsir'       => __( 'Create an API key in the developers section of the panel. The template id of a pattern is in the fast sending section.', 'forms-notifier-for-elementor' ),
			'ghasedak'    => $api_key,
			'mediana'     => $api_key,
			'amoot'       => __( 'Create a token in the web service section of the panel.', 'forms-notifier-for-elementor' ),
			'limosms'     => $api_key,
			'asanak'      => __( 'Use the web service username and password of the panel.', 'forms-notifier-for-elementor' ),
			'payamresan'  => $api_key,
			'twilio'      => __( 'Account SID and auth token are on the first page of the console. Buy a number or create a messaging service for the sender.', 'forms-notifier-for-elementor' ),
			'vonage'      => __( 'API key and API secret are in the API settings of the dashboard.', 'forms-notifier-for-elementor' ),
			'plivo'       => __( 'Auth ID and auth token are on the first page of the console.', 'forms-notifier-for-elementor' ),
			'bird'        => __( 'Create an access key in the developer settings. Its beginning names the region.', 'forms-notifier-for-elementor' ),
			'clicksend'   => __( 'Username and API key are behind "API credentials" in the dashboard.', 'forms-notifier-for-elementor' ),
			'infobip'     => __( 'The base address and the API key are on the first page of the portal.', 'forms-notifier-for-elementor' ),
			'telnyx'      => __( 'Create a V2 API key in the account settings of the portal.', 'forms-notifier-for-elementor' ),
			'brevo'       => __( 'Create an API key under "SMTP and API" in the account menu.', 'forms-notifier-for-elementor' ),
			'smsto'       => __( 'Create an API key in the API clients section.', 'forms-notifier-for-elementor' ),
			'elks'        => __( 'API username and password are on the account page.', 'forms-notifier-for-elementor' ),
			'smsapi'      => __( 'Create an OAuth token in the API settings of the panel.', 'forms-notifier-for-elementor' ),
			'msg91'       => __( 'Create an auth key in the account menu. The template id is in the SMS section.', 'forms-notifier-for-elementor' ),
			'netgsm'      => __( 'Use the user code and the password of an API sub-user; the message header must be approved.', 'forms-notifier-for-elementor' ),
			'messagebird' => __( 'Use a live access key of the older dashboard.', 'forms-notifier-for-elementor' ),
			'wpsms'       => __( 'Set up the gateway in the WP SMS plugin.', 'forms-notifier-for-elementor' ),
			'pwsms'       => __( 'Set up the gateway in the Persian WooCommerce SMS plugin.', 'forms-notifier-for-elementor' ),
		];
	}
}
