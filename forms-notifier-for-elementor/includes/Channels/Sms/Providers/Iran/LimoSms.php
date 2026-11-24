<?php
/**
 * LimoSMS.
 *
 * https://api.limosms.com/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** LimoSMS. */
final class LimoSms extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'limosms';
	}

	/** Name. */
	public function label(): string {
		return 'LimoSMS';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://limosms.com/';
	}

	private const BASE = 'https://api.limosms.com/api/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( '', __( 'Needed for normal texts.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the pattern id. Variables are sent in order; names are ignored.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r     = $this->post_json( self::BASE . 'getcurrentcredit', [], $this->headers() );
		$value = null;
		foreach ( [ 'Result', 'Credit', 'result', 'credit' ] as $key ) {
			$value = $value ?? $this->dig( $r['json'], $key );
		}
		return $this->balance( $r, $value, '', $this->first_text( $r['json'], [ 'Message', 'message' ] ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		if ( $this->option( 'sender' ) === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->call(
			'sendsms',
			[
				'Message'            => $text,
				'SenderNumber'       => $this->option( 'sender' ),
				'MobileNumber'       => [ Phone::iran_local( $to ) ],
				'SendToBlocksNumber' => true,
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			'sendpatternmessage',
			[
				'OtpId'        => $code,
				'ReplaceToken' => $this->listed( $vars ),
				'MobileNumber' => Phone::iran_local( $to ),
			]
		);
	}

	/** Headers. */
	private function headers(): array {
		return [
			'ApiKey' => $this->secret( 'api_key' ),
			'Accept' => 'application/json',
		];
	}

	/**
	 * Send. Authentication errors are plain text.
	 *
	 * @param string $method Method.
	 * @param array  $body   Body.
	 */
	private function call( string $method, array $body ): SendResult {
		$r  = $this->post_json( self::BASE . $method, $body, $this->headers() );
		$ok = $this->dig( $r['json'], 'Success' ) === true || $this->dig( $r['json'], 'success' ) === true;
		return $this->outcome( $r, $ok, $this->first_text( $r['json'], [ 'MessageId.0', 'messageId.0', 'MessageId' ] ), $this->first_text( $r['json'], [ 'Message', 'message' ] ) );
	}
}
