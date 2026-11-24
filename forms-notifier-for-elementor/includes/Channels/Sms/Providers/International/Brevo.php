<?php
/**
 * Brevo transactional SMS.
 *
 * https://developers.brevo.com/reference/send-transac-sms
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Brevo transactional SMS; templates as pattern. */
final class Brevo extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'brevo';
	}

	/** Name. */
	public function label(): string {
		return 'Brevo';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://app.brevo.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ), __( 'At most 11 letters and digits, or 15 digits.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the numeric template id. Name the variables as in the template.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Templates. */
	public function supports_pattern(): bool {
		return true;
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call(
			$to,
			[
				'content'        => $text,
				'unicodeEnabled' => $this->is_unicode( $text ),
			]
		);
	}

	/** Template. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			$to,
			[
				'templateId'     => $this->maybe_int( $code ),
				'params'         => (object) $this->named( $vars ),
				'unicodeEnabled' => true,
			]
		);
	}

	/**
	 * Send.
	 *
	 * @param string $to   E.164.
	 * @param array  $body Body.
	 */
	private function call( string $to, array $body ): SendResult {
		$r  = $this->post_json(
			'https://api.brevo.com/v3/transactionalSMS/sms',
			[
				'sender'    => $this->option( 'sender' ),
				'recipient' => Phone::digits( $to ),
				'type'      => 'transactional',
			] + $body,
			[ 'api-key' => $this->secret( 'api_key' ) ]
		);
		$id = $this->text_at( $r['json'], 'messageId' );
		// 402 is missing credit, which may be topped up.
		return $this->outcome( $r, $id !== '', $id, $this->first_text( $r['json'], [ 'message', 'code' ] ), $r['code'] === 402 ? false : null );
	}
}
