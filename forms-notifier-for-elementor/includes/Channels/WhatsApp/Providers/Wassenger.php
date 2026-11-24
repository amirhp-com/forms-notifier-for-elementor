<?php
/**
 * Wassenger.
 *
 * https://wassenger.com/developers
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Wassenger; files by link. */
final class Wassenger extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'wassenger';
	}

	/** Name. */
	public function label(): string {
		return 'Wassenger';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://wassenger.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'API key', 'forms-notifier-for-elementor' ) ),
			$this->account_note(),
			$this->note( __( 'Files are sent by link, so the site must be reachable from the internet.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( $to, [ 'message' => $text ] );
	}

	/** File by link. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$link = $this->link( $file );
		return $link === '' ? $this->unreadable() : $this->call( $to, [ 'media' => [ 'url' => $link ] ] );
	}

	/**
	 * Send.
	 *
	 * @param string $to   E.164.
	 * @param array  $body Body.
	 */
	private function call( string $to, array $body ): SendResult {
		$r  = $this->post_json( 'https://api.wassenger.com/v1/messages', [ 'phone' => $to ] + $body, [ 'Token' => $this->secret( 'api_key' ) ] );
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '', $id, trim( $this->text_at( $r['json'], 'errorCode' ) . ' ' . $this->text_at( $r['json'], 'message' ) ) );
	}
}
