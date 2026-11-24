<?php
/**
 * IPPanel Edge.
 *
 * https://ippanelcom.github.io/Edge-Document/docs/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** IPPanel Edge API; also serves many resellers of the same panel. */
final class IpPanel extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'ippanel';
	}

	/** Name. */
	public function label(): string {
		return 'IPPanel';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://ippanel.com/';
	}

	private const BASE = 'https://edge.ippanel.com/v1/api';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( '', __( 'With the country code, for example +983000505.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Name the variables as they are named in the pattern of your panel. Line breaks in values become spaces.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', self::BASE . '/payment/credit/mine', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'data.credit' ), __( 'Rial', 'forms-notifier-for-elementor' ), $this->text_at( $r['json'], 'meta.message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call(
			[
				'sending_type' => 'webservice',
				'from_number'  => $this->from(),
				'message'      => $text,
				'params'       => [ 'recipients' => [ $to ] ],
			]
		);
	}

	/** Pattern; one recipient per request. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			[
				'sending_type' => 'pattern',
				'from_number'  => $this->from(),
				'code'         => $code,
				'recipients'   => [ $to ],
				'params'       => (object) $this->named( $vars ),
			]
		);
	}

	/** Sender in E.164. */
	private function from(): string {
		$sender = Phone::clean( $this->option( 'sender' ) );
		if ( strncmp( $sender, '+', 1 ) === 0 ) {
			return $sender;
		}
		return '+' . ( strncmp( $sender, '98', 2 ) === 0 ? $sender : '98' . ltrim( $sender, '0' ) );
	}

	/** The key is sent as it is, without "Bearer". */
	private function headers(): array {
		return [
			'Authorization' => $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * Send.
	 *
	 * @param array $body Body.
	 */
	private function call( array $body ): SendResult {
		$r = $this->post_json( self::BASE . '/send', $body, $this->headers() );
		return $this->outcome( $r, $this->dig( $r['json'], 'meta.status' ) === true, $this->text_at( $r['json'], 'data.message_outbox_ids.0' ), $this->first_text( $r['json'], [ 'meta.message', 'meta.errors' ] ) );
	}
}
