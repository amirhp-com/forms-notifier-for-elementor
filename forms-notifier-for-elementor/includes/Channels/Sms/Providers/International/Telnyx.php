<?php
/**
 * Telnyx.
 *
 * https://developers.telnyx.com/api-reference/messages/send-a-message
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Telnyx messaging. */
final class Telnyx extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'telnyx';
	}

	/** Name. */
	public function label(): string {
		return 'Telnyx';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://portal.telnyx.com/';
	}

	private const BASE = 'https://api.telnyx.com/v2';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( __( 'From', 'forms-notifier-for-elementor' ), __( 'A number, or a messaging profile id.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', self::BASE . '/balance', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'data.balance' ), $this->text_at( $r['json'], 'data.currency' ), $this->error( $r ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$sender  = $this->option( 'sender' );
		$profile = preg_match( '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $sender ) === 1;
		$r       = $this->post_json(
			self::BASE . '/messages',
			[
				$profile ? 'messaging_profile_id' : 'from' => $sender,
				'to'                                       => $to,
				'text'                                     => $text,
			],
			$this->headers()
		);
		$id      = $this->text_at( $r['json'], 'data.id' );
		return $this->outcome( $r, $id !== '', $id, $this->error( $r ) );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * Error text.
	 *
	 * @param array $r Response.
	 */
	private function error( array $r ): string {
		return trim( $this->text_at( $r['json'], 'errors.0.code' ) . ' ' . $this->first_text( $r['json'], [ 'errors.0.detail', 'errors.0.title' ] ) );
	}
}
