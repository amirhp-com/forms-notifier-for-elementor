<?php
/**
 * WAHA.
 *
 * https://waha.devlike.pro/docs/how-to/send-messages/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** WAHA on your own server. */
final class Waha extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'waha';
	}

	/** Name. */
	public function label(): string {
		return 'WAHA';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://waha.devlike.pro/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->base_field( 'https://waha.example.com', __( 'For a server in a private network also allow private hosts in the advanced settings.', 'forms-notifier-for-elementor' ) ),
			$this->field( 'api_key', 'secret', __( 'API key', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Optional when the server has none.', 'forms-notifier-for-elementor' ) ] ),
			$this->field(
				'session',
				'text',
				__( 'Session', 'forms-notifier-for-elementor' ),
				[
					'default' => 'default',
					'attrs'   => [ 'dir' => 'ltr' ],
				]
			),
			$this->account_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'base' ];
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( 'sendText', $to, [ 'text' => $text ] );
	}

	/** Image or file, inline. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$data = $this->base64( $file );
		if ( $data === '' ) {
			return $this->unreadable();
		}
		return $this->call(
			$file->is_image() ? 'sendImage' : 'sendFile',
			$to,
			[
				'file' => [
					'mimetype' => $file->mime === '' ? 'application/octet-stream' : $file->mime,
					'filename' => $file->name,
					'data'     => $data,
				],
			]
		);
	}

	/**
	 * Send. The documentation shows no answer; a 2xx answer counts as accepted.
	 *
	 * @param string $method Method.
	 * @param string $to     E.164.
	 * @param array  $body   Body.
	 */
	private function call( string $method, string $to, array $body ): SendResult {
		$session = $this->option( 'session' );
		$headers = $this->secret( 'api_key' ) === '' ? [] : [ 'X-Api-Key' => $this->secret( 'api_key' ) ];
		$r       = $this->post_json(
			$this->base() . '/api/' . $method,
			[
				'session' => $session === '' ? 'default' : $session,
				'chatId'  => $this->chat_id( $to ),
			] + $body,
			$headers
		);
		return $this->outcome( $r, is_array( $r['json'] ) && empty( $r['json']['error'] ), $this->first_text( $r['json'], [ 'id._serialized', 'key.id', 'id' ] ), $this->first_text( $r['json'], [ 'message', 'error' ] ) );
	}
}
