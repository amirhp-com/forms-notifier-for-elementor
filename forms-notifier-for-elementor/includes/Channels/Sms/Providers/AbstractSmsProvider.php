<?php
/**
 * SMS gateway base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers;

use AmirhpCom\FormsNotifier\Channels\AbstractProvider;
use AmirhpCom\FormsNotifier\Channels\Sms\GatewayHelpers;
use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Channels\Sms\Segments;
use AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Shared behaviour of the SMS gateways: a subclass only translates one message into one request. */
abstract class AbstractSmsProvider extends AbstractProvider implements SmsProviderInterface {

	use GatewayHelpers;

	/** Channel id. */
	protected function channel(): string {
		return 'sms';
	}

	/** True when the gateway can send by pattern/template code. */
	public function supports_pattern(): bool {
		return false;
	}

	/** ISO2 country codes, [] = worldwide. */
	public function countries(): array {
		return [];
	}

	/**
	 * Send one SMS.
	 *
	 * @param string  $to      E.164.
	 * @param Message $message Message.
	 */
	public function send( string $to, Message $message ): SendResult {
		if ( ! $this->is_configured() ) {
			return $this->fail( __( 'The gateway is not fully set up.', 'forms-notifier-for-elementor' ), true );
		}
		$countries = $this->countries();
		if ( $countries && ! in_array( Phone::country( $to ), $countries, true ) ) {
			return $this->fail( __( 'This gateway does not send to the country of this number.', 'forms-notifier-for-elementor' ), true );
		}
		$code = trim( (string) ( $message->pattern['code'] ?? '' ) );
		if ( $code !== '' && $this->supports_pattern() ) {
			return $this->send_pattern( $to, $code, (array) ( $message->pattern['vars'] ?? [] ), $message );
		}
		$text = trim( $message->plain );
		if ( $text === '' ) {
			return $this->fail( __( 'There is no normal text for this gateway.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->send_text( $to, $text, $message );
	}

	/**
	 * Send a normal text.
	 *
	 * @param string  $to      E.164.
	 * @param string  $text    Text.
	 * @param Message $message Message.
	 */
	abstract protected function send_text( string $to, string $text, Message $message ): SendResult;

	/**
	 * Send a pattern.
	 *
	 * @param string  $to      E.164.
	 * @param string  $code    Pattern code.
	 * @param array   $vars    Variables, rendered.
	 * @param Message $message Message.
	 */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		unset( $to, $code, $vars, $message );
		return $this->fail( __( 'This gateway cannot send patterns.', 'forms-notifier-for-elementor' ), true );
	}

	/**
	 * Variables as one-line strings by name.
	 *
	 * @param array $vars Variables.
	 * @return array<string,string>
	 */
	protected function named( array $vars ): array {
		$out = [];
		foreach ( $vars as $name => $value ) {
			$out[ (string) $name ] = is_scalar( $value ) ? $this->one_line( (string) $value ) : '';
		}
		return $out;
	}

	/**
	 * Variables as one-line strings in order.
	 *
	 * @param array $vars Variables.
	 * @return string[]
	 */
	protected function listed( array $vars ): array {
		return array_map( [ $this, 'one_line' ], PatternText::values( $vars ) );
	}

	/**
	 * Collapse line breaks and spaces.
	 *
	 * @param string $value Value.
	 */
	protected function one_line( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * The text needs Unicode.
	 *
	 * @param string $text Text.
	 */
	protected function is_unicode( string $text ): bool {
		return ! Segments::is_gsm( $text );
	}

	/**
	 * Digits as an integer, anything else as it is.
	 *
	 * @param string $value Value.
	 * @return int|string
	 */
	protected function maybe_int( string $value ) {
		return preg_match( '/^[1-9]\d{0,17}$/', $value ) ? (int) $value : $value;
	}

	/** 'api_key' secret field. */
	protected function api_key_field(): array {
		return $this->field( 'api_key', 'secret', __( 'API key', 'forms-notifier-for-elementor' ) );
	}

	/**
	 * 'sender' text field.
	 *
	 * @param string $label Label, '' = default.
	 * @param string $desc  Help.
	 */
	protected function sender_field( string $label = '', string $desc = '' ): array {
		return $this->field(
			'sender',
			'text',
			$label === '' ? __( 'Sender line', 'forms-notifier-for-elementor' ) : $label,
			[
				'desc'  => $desc,
				'attrs' => [ 'dir' => 'ltr' ],
			]
		);
	}
}
