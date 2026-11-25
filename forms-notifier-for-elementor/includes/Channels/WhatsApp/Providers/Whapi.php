<?php
/**
 * whapi.cloud.
 *
 * https://whapi.readme.io/reference/sendmessagetext ; the success answer is not confirmed there.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** whapi.cloud: text only, files become links. */
final class Whapi extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'whapi';
	}

	/** Name. */
	public function label(): string {
		return 'whapi.cloud';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://whapi.cloud/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'token', 'secret', __( 'Token', 'forms-notifier-for-elementor' ) ),
			$this->account_note(),
			$this->unconfirmed_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'token' ];
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r = $this->post_json(
			'https://gate.whapi.cloud/messages/text',
			[
				'to'   => Phone::digits( $to ),
				'body' => $text,
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'token' ) ]
		);
		return $this->outcome( $r, $this->dig( $r['json'], 'sent' ) === true, $this->text_at( $r['json'], 'message.id' ), $this->first_text( $r['json'], [ 'error.message', 'error.details', 'error', 'message' ] ) );
	}
}
