<?php
/**
 * Vonage SMS API.
 *
 * https://developer.vonage.com/api/v1/developer/api/file/sms?format=json
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Vonage SMS API with the credentials in the Basic header. */
final class Vonage extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'vonage';
	}

	/** Name. */
	public function label(): string {
		return 'Vonage';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://dashboard.nexmo.com/';
	}

	/** Statuses that may go away: 1 throttled, 5 internal error, 9 quota. */
	private const TEMPORARY = [ 1, 5, 9 ];

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'text', __( 'API key', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'api_secret', 'secret', __( 'API secret', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'api_secret', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', 'https://rest.nexmo.com/account/get-balance', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'value' ), 'EUR', $this->first_text( $r['json'], [ 'error-code-label', 'detail', 'title' ] ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = [
			'from' => $this->option( 'sender' ),
			'to'   => Phone::digits( $to ),
			'text' => $text,
		];
		if ( $this->is_unicode( $text ) ) {
			$body['type'] = 'unicode';
		}
		$r      = $this->post_form( 'https://rest.nexmo.com/sms/json', $body, $this->headers() );
		$status = $this->text_at( $r['json'], 'messages.0.status' );
		$error  = trim( $status . ' ' . $this->text_at( $r['json'], 'messages.0.error-text' ) );
		if ( $status === '' ) {
			$error = $this->first_text( $r['json'], [ 'detail', 'title', 'error-code-label' ] );
		}
		return $this->outcome( $r, $status === '0', $this->text_at( $r['json'], 'messages.0.message-id' ), $error, $status === '' ? null : ! in_array( (int) $status, self::TEMPORARY, true ) );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => $this->basic( $this->option( 'api_key' ), $this->secret( 'api_secret' ) ),
			'Accept'        => 'application/json',
		];
	}
}
