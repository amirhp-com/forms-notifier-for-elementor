<?php
/**
 * Evolution API v2.
 *
 * https://docs.evolutionfoundation.com.br/evolution-api/send-text-message ; the success answer is not confirmed there.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Evolution API on your own server. */
final class EvolutionApi extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'evolution';
	}

	/** Name. */
	public function label(): string {
		return 'Evolution API';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://github.com/EvolutionAPI/evolution-api';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->base_field( 'https://evolution.example.com', __( 'For a server in a private network also allow private hosts in the advanced settings.', 'forms-notifier-for-elementor' ) ),
			$this->field( 'api_key', 'secret', __( 'API key', 'forms-notifier-for-elementor' ) ),
			$this->field( 'instance', 'text', __( 'Instance name', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->account_note(),
			$this->unconfirmed_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'base', 'api_key', 'instance' ];
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( 'sendText', $to, [ 'text' => $text ] );
	}

	/** Image or document, inline. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$data = $this->base64( $file );
		if ( $data === '' ) {
			return $this->unreadable();
		}
		return $this->call(
			'sendMedia',
			$to,
			[
				'mediatype' => $file->is_image() ? 'image' : 'document',
				'mimetype'  => $file->mime,
				'media'     => $data,
				'fileName'  => $file->name,
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
		$r  = $this->post_json(
			$this->base() . '/message/' . $method . '/' . rawurlencode( $this->option( 'instance' ) ),
			[ 'number' => Phone::digits( $to ) ] + $body,
			[ 'apikey' => $this->secret( 'api_key' ) ]
		);
		$id = $this->text_at( $r['json'], 'key.id' );
		return $this->outcome( $r, $id !== '', $id, $this->first_text( $r['json'], [ 'response.message', 'message', 'error' ] ) );
	}
}
