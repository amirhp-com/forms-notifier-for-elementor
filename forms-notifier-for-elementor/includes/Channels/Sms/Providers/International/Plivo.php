<?php
/**
 * Plivo.
 *
 * https://www.plivo.com/docs/messaging/api/messages
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Plivo. Authentication errors are plain text. */
final class Plivo extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'plivo';
	}

	/** Name. */
	public function label(): string {
		return 'Plivo';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://console.plivo.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'auth_id', 'text', __( 'Auth ID', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'auth_token', 'secret', __( 'Auth token', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'auth_id', 'auth_token', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', $this->url( '' ), [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'cash_credits' ), 'USD', $this->text_at( $r['json'], 'error' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r  = $this->post_json(
			$this->url( 'Message/' ),
			[
				'src'  => $this->option( 'sender' ),
				'dst'  => Phone::digits( $to ),
				'text' => $text,
			],
			$this->headers()
		);
		$id = $this->text_at( $r['json'], 'message_uuid.0' );
		return $this->outcome( $r, $id !== '', $id, $this->text_at( $r['json'], 'error' ) );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => $this->basic( $this->option( 'auth_id' ), $this->secret( 'auth_token' ) ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * URL.
	 *
	 * @param string $path Path below the account.
	 */
	private function url( string $path ): string {
		return 'https://api.plivo.com/v1/Account/' . rawurlencode( $this->option( 'auth_id' ) ) . '/' . $path;
	}
}
