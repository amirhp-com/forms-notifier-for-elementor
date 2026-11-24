<?php
/**
 * 46elks.
 *
 * https://46elks.com/docs/send-sms
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** 46elks. Errors are plain text. */
final class FortySixElks extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'elks';
	}

	/** Name. */
	public function label(): string {
		return '46elks';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://46elks.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'API username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'API password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password', 'sender' ];
	}

	/** Balance: the account answers in ten-thousandths of its currency. */
	public function status(): ?string {
		$r     = $this->http( 'GET', 'https://api.46elks.com/a1/me', [ 'headers' => $this->headers() ] );
		$value = $this->dig( $r['json'], 'balance' );
		return $this->balance( $r, is_numeric( $value ) ? $value / 10000 : null, $this->text_at( $r['json'], 'currency' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r  = $this->post_form(
			'https://api.46elks.com/a1/sms',
			[
				'from'    => $this->option( 'sender' ),
				'to'      => $to,
				'message' => $text,
			],
			$this->headers()
		);
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '' && $this->dig( $r['json'], 'status' ) !== 'failed', $id, $this->dig( $r['json'], 'status' ) === 'failed' ? 'failed' : '' );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => $this->basic( $this->option( 'username' ), $this->secret( 'password' ) ),
			'Accept'        => 'application/json',
		];
	}
}
