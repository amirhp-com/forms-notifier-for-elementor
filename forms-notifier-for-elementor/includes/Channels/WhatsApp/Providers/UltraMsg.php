<?php
/**
 * UltraMsg.
 *
 * https://docs.ultramsg.com/api/post/messages/chat , /image and /document; the answers are not shown there.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** UltraMsg. */
final class UltraMsg extends AbstractWhatsAppProvider {

	/** The base64 form may have 10 000 000 characters. */
	protected const MAX_FILE = 7400000;

	/** Id. */
	public function id(): string {
		return 'ultramsg';
	}

	/** Name. */
	public function label(): string {
		return 'UltraMsg';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://ultramsg.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'instance', 'text', __( 'Instance ID', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'token', 'secret', __( 'Token', 'forms-notifier-for-elementor' ) ),
			$this->account_note(),
			$this->unconfirmed_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'instance', 'token' ];
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( 'chat', $to, [ 'body' => $text ] );
	}

	/** Image or document, inline. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$data = $this->base64( $file );
		if ( $data === '' ) {
			return $this->unreadable();
		}
		if ( $file->is_image() ) {
			return $this->call(
				'image',
				$to,
				[
					'image'   => $data,
					'caption' => $file->name,
				]
			);
		}
		return $this->call(
			'document',
			$to,
			[
				'filename' => mb_substr( $file->name, 0, 255 ),
				'document' => $data,
				'caption'  => $file->name,
			]
		);
	}

	/**
	 * Send.
	 *
	 * @param string $method Method.
	 * @param string $to     E.164.
	 * @param array  $body   Body.
	 */
	private function call( string $method, string $to, array $body ): SendResult {
		$instance = $this->option( 'instance' );
		$instance = ctype_digit( $instance ) ? 'instance' . $instance : $instance;
		$r        = $this->post_form(
			'https://api.ultramsg.com/' . rawurlencode( $instance ) . '/messages/' . $method,
			[
				'token' => $this->secret( 'token' ),
				'to'    => $to,
			] + $body
		);
		$ok       = in_array( $this->dig( $r['json'], 'sent' ), [ 'true', true ], true );
		return $this->outcome( $r, $ok, $this->text_at( $r['json'], 'id' ), $this->first_text( $r['json'], [ 'error', 'message' ] ) );
	}
}
