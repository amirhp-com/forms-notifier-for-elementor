<?php
/**
 * Persian WooCommerce SMS plugin.
 *
 * Public method PWSMS()->send_sms() of https://wordpress.org/plugins/persian-woocommerce-sms/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Passthrough;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Hands messages to the gateway that is set up in the Persian WooCommerce SMS plugin. */
final class PersianWooSms extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'pwsms';
	}

	/** Name. */
	public function label(): string {
		return __( 'Persian WooCommerce SMS plugin', 'forms-notifier-for-elementor' );
	}

	/** Region. */
	public function region(): string {
		return 'passthrough';
	}

	/** The plugin accepts Iranian numbers only. */
	public function countries(): array {
		return [ 'IR' ];
	}

	/** Only some of its gateways read pattern text, so the admin says so. */
	public function supports_pattern(): bool {
		return Text::bool( $this->setting( 'pattern', false ) );
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->note( __( 'Uses the gateway that is set up in the Persian WooCommerce SMS plugin.', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'pattern',
				'toggle',
				__( 'The gateway of that plugin understands patterns', 'forms-notifier-for-elementor' ),
				[
					'default' => false,
					'desc'    => __( 'Leave it off if you are not sure: a gateway without patterns would send the pattern code to the receiver. The normal text is used while it is off. The character ~ is removed from values.', 'forms-notifier-for-elementor' ),
				]
			),
		];
	}

	/** The plugin is active. */
	public function is_configured(): bool {
		return function_exists( 'PWSMS' ) && is_object( \PWSMS() ) && is_callable( [ \PWSMS(), 'send_sms' ] );
	}

	/** Problems. */
	public function problems(): array {
		return $this->is_configured() ? [] : [ __( 'The Persian WooCommerce SMS plugin is not active.', 'forms-notifier-for-elementor' ) ];
	}

	/** Status. */
	public function status(): ?string {
		return $this->is_configured() ? __( 'The Persian WooCommerce SMS plugin is active.', 'forms-notifier-for-elementor' ) : null;
	}

	/** Normal text, which must not be read as a pattern by its gateways: an invisible half-space breaks the keyword. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$text = (string) preg_replace( '/^(pattern|pcode|patterncode)(?=:)/i', "$1\u{200C}", $text );
		$text = (string) preg_replace( '/(template)(?==)/i', "$1\u{200C}", $text );
		return $this->call( $to, $text );
	}

	/** Pattern as "pattern:CODE" and one variable per line. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$vars = array_map(
			static function ( string $value ): string {
				return str_replace( '~', ' ', $value );
			},
			$this->named( $vars )
		);
		return $this->call( $to, PatternText::compose( $code, $vars ) );
	}

	/**
	 * Call the plugin.
	 *
	 * @param string $to   E.164.
	 * @param string $text Text.
	 */
	private function call( string $to, string $text ): SendResult {
		$result = \PWSMS()->send_sms(
			[
				'mobile'  => Phone::iran_local( $to ),
				'message' => $text,
				'type'    => 0,
			]
		);
		if ( $result === true ) {
			return $this->ok();
		}
		$error = is_scalar( $result ) ? $this->short( (string) $result ) : '';
		return $this->fail( $error === '' ? __( 'Persian WooCommerce SMS could not send the message.', 'forms-notifier-for-elementor' ) : $error );
	}
}
