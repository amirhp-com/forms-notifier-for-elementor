<?php
/**
 * SMS.to.
 *
 * https://developers.sms.to/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** SMS.to. */
final class SmsTo extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'smsto';
	}

	/** Name. */
	public function label(): string {
		return 'SMS.to';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://app.sms.to/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', 'https://auth.sms.to/api/balance', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'balance' ), $this->text_at( $r['json'], 'currency' ), $this->text_at( $r['json'], 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = [
			'message' => $text,
			'to'      => $to,
		];
		if ( $this->option( 'sender' ) !== '' ) {
			$body['sender_id'] = $this->option( 'sender' );
		}
		$r = $this->post_json( 'https://api.sms.to/sms/send', $body, $this->headers() );
		return $this->outcome( $r, $this->dig( $r['json'], 'success' ) === true, $this->text_at( $r['json'], 'message_id' ), $this->text_at( $r['json'], 'message' ) );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}
}
