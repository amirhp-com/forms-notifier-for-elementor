<?php
/**
 * Green API.
 *
 * https://green-api.com/en/docs/api/sending/SendMessage/ and /SendFileByUrl/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Green API; files by link. A wrong instance answers with an HTML page. */
final class GreenApi extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'greenapi';
	}

	/** Name. */
	public function label(): string {
		return 'Green API';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://console.green-api.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->base_field( 'https://api.green-api.com', __( 'The apiUrl of your instance; empty uses https://api.green-api.com.', 'forms-notifier-for-elementor' ) ),
			$this->field( 'instance', 'text', __( 'idInstance', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'token', 'secret', __( 'apiTokenInstance', 'forms-notifier-for-elementor' ) ),
			$this->account_note(),
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
		return $this->call(
			'sendMessage',
			[
				'chatId'  => $this->chat_id( $to ),
				'message' => $text,
			]
		);
	}

	/** File by link. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$link = $this->link( $file );
		if ( $link === '' ) {
			return $this->unreadable();
		}
		return $this->call(
			'sendFileByUrl',
			[
				'chatId'   => $this->chat_id( $to ),
				'urlFile'  => $link,
				'fileName' => $file->name,
			]
		);
	}

	/**
	 * Send. 466 is a used-up quota.
	 *
	 * @param string $method Method.
	 * @param array  $body   Body.
	 */
	private function call( string $method, array $body ): SendResult {
		$base = $this->base() === '' ? 'https://api.green-api.com' : $this->base();
		$r    = $this->post_json( $base . '/waInstance' . rawurlencode( $this->option( 'instance' ) ) . '/' . $method . '/' . rawurlencode( $this->secret( 'token' ) ), $body );
		$id   = $this->text_at( $r['json'], 'idMessage' );
		return $this->outcome( $r, $id !== '', $id, $this->first_text( $r['json'], [ 'message', 'description', 'error' ] ), $r['code'] === 466 ? false : null );
	}
}
