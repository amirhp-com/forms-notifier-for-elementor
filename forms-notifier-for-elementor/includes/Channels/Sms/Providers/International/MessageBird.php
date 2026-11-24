<?php
/**
 * MessageBird legacy API.
 *
 * https://developers.messagebird.com/api/sms-messaging/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** MessageBird legacy SMS API; new accounts use Bird. */
final class MessageBird extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'messagebird';
	}

	/** Name. */
	public function label(): string {
		return __( 'MessageBird (legacy)', 'forms-notifier-for-elementor' );
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://bird.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'Access key', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'This older API is expected to be switched off at the end of 2026. Use Bird for new setups.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', 'https://rest.messagebird.com/balance', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'amount' ), $this->text_at( $r['json'], 'type' ), $this->text_at( $r['json'], 'errors.0.description' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r     = $this->post_form(
			'https://rest.messagebird.com/messages',
			[
				'originator' => $this->option( 'sender' ),
				'recipients' => Phone::digits( $to ),
				'body'       => $text,
				'datacoding' => 'auto',
			],
			$this->headers()
		);
		$id    = $this->text_at( $r['json'], 'id' );
		$error = $this->text_at( $r['json'], 'errors.0.description' );
		return $this->outcome( $r, $id !== '' && $error === '', $id, $error );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'AccessKey ' . $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}
}
