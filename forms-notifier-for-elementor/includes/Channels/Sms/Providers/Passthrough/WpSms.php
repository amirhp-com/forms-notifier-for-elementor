<?php
/**
 * WP SMS plugin.
 *
 * Public function wp_sms_send() of https://wordpress.org/plugins/wp-sms/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Passthrough;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Hands messages to the gateway that is set up in the WP SMS plugin. */
final class WpSms extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'wpsms';
	}

	/** Name. */
	public function label(): string {
		return __( 'WP SMS plugin', 'forms-notifier-for-elementor' );
	}

	/** Region. */
	public function region(): string {
		return 'passthrough';
	}

	/** Templates of the gateway in WP SMS. */
	public function supports_pattern(): bool {
		return true;
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->note( __( 'Uses the gateway that is set up in the WP SMS plugin. Pattern code is the template id of that gateway and only works with gateways that have templates; the normal text is sent along with it. The character | is replaced in texts, because WP SMS reads what follows it as a template id. When WP SMS sends in the background, "sent" means that WP SMS took the message.', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'local_numbers',
				'toggle',
				__( 'Send Iranian numbers as 0912...', 'forms-notifier-for-elementor' ),
				[ 'default' => true ]
			),
		];
	}

	/** The plugin is active. */
	public function is_configured(): bool {
		return function_exists( 'wp_sms_send' );
	}

	/** Problems. */
	public function problems(): array {
		return $this->is_configured() ? [] : [ __( 'The WP SMS plugin is not active.', 'forms-notifier-for-elementor' ) ];
	}

	/** Status. */
	public function status(): ?string {
		return $this->is_configured() ? __( 'The WP SMS plugin is active.', 'forms-notifier-for-elementor' ) : null;
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( $to, str_replace( '|', '/', $text ), [] );
	}

	/** Template: "text|template id" and the variables. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$vars = $this->named( $vars );
		$text = trim( $message->plain ) === '' ? implode( ' ', $vars ) : trim( $message->plain );
		return $this->call( $to, str_replace( '|', '/', $text ) . '|' . str_replace( '|', '', $code ), $vars );
	}

	/**
	 * Call the plugin.
	 *
	 * @param string $to   E.164.
	 * @param string $text Text.
	 * @param array  $vars Template variables.
	 */
	private function call( string $to, string $text, array $vars ): SendResult {
		$number = Phone::is_iran( $to ) && $this->setting( 'local_numbers', true ) ? Phone::iran_local( $to ) : $to;
		$result = \wp_sms_send( [ $number ], $text, false, null, [], $vars );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $this->short( (string) $result->get_error_message() ) );
		}
		if ( ! $result ) {
			return $this->fail( __( 'WP SMS could not send the message. Look into its outbox.', 'forms-notifier-for-elementor' ) );
		}
		return $this->ok( is_scalar( $result ) ? $this->short( (string) $result ) : (string) wp_json_encode( $result ) );
	}
}
