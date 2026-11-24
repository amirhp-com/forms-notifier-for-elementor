<?php
/**
 * Custom HTTP gateway.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Channels\Sms\CustomRequest;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Any WhatsApp gateway with an HTTP API; files become links in the text. */
final class CustomHttp extends AbstractWhatsAppProvider {

	use CustomRequest;

	private const BODY = [
		'json'  => '{"to":"{to}","text":"{text}"}',
		'form'  => 'to={to}&text={text}',
		'query' => 'to={to}&text={text}',
	];

	/** Id. */
	public function id(): string {
		return 'custom';
	}

	/** Name. */
	public function label(): string {
		return __( 'Custom HTTP gateway', 'forms-notifier-for-elementor' );
	}

	/** Region. */
	public function region(): string {
		return 'any';
	}

	/** Fields. */
	public function fields(): array {
		return $this->request_fields( [ '{to}', '{to_local}', '{to_digits}', '{text}', '{token}' ] );
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = $this->option( 'body' );
		if ( $body === '' ) {
			$body = self::BODY[ $this->option( 'format' ) ] ?? self::BODY['json'];
		}
		return $this->request( $body, $this->number_values( $to ) + [ 'text' => $text ] );
	}
}
