<?php
/**
 * FarazSMS / IranPayamak.
 *
 * https://farazsms.com/webservice/ (pattern request); the request of normal texts is not confirmed by public documentation.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** FarazSMS on the IranPayamak web service. */
final class FarazSms extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'farazsms';
	}

	/** Name. */
	public function label(): string {
		return 'FarazSMS / IranPayamak';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://farazsms.com/';
	}

	private const BASE = 'https://api.iranpayamak.com/ws/v1';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field(),
			$this->unconfirmed_note(),
			$this->note( __( 'Name the variables as they are named in the pattern of your panel. Line breaks in values become spaces.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r     = $this->http( 'GET', self::BASE . '/account/balance', [ 'headers' => $this->headers() ] );
		$value = $this->dig( $r['json'], 'data' );
		return $this->balance( $r, is_array( $value ) ? $value['balance'] ?? null : $value, '', $this->text_at( $r['json'], 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call(
			'/sms/simple',
			[
				'text'          => $text,
				'line_number'   => $this->option( 'sender' ),
				'recipients'    => [ Phone::iran_local( $to ) ],
				'number_format' => 'english',
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			'/sms/pattern',
			[
				'code'          => $code,
				'attributes'    => (object) $this->named( $vars ),
				'recipient'     => Phone::iran_local( $to ),
				'line_number'   => $this->option( 'sender' ),
				'number_format' => 'english',
			]
		);
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Api-Key' => $this->secret( 'api_key' ),
			'Accept'  => 'application/json',
		];
	}

	/**
	 * Send.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 */
	private function call( string $path, array $body ): SendResult {
		$r = $this->post_json( self::BASE . $path, $body, $this->headers() );
		return $this->outcome( $r, $this->dig( $r['json'], 'status' ) === 'success', $this->first_text( $r['json'], [ 'data.id', 'data.message_id' ] ), $this->text_at( $r['json'], 'message' ) );
	}
}
