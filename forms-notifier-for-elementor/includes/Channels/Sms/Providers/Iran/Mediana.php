<?php
/**
 * Mediana.
 *
 * https://developer.mediana.ir/spec/en
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Mediana API. */
final class Mediana extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'mediana';
	}

	/** Name. */
	public function label(): string {
		return 'Mediana';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://mediana.ir/';
	}

	private const BASE = 'https://api.mediana.ir/sms/v1';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( '', __( 'Empty lets Mediana choose a line by the message type.', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'type',
				'select',
				__( 'Message type', 'forms-notifier-for-elementor' ),
				[
					'default' => 'Informational',
					'options' => [
						'Informational'          => __( 'Informational', 'forms-notifier-for-elementor' ),
						'PromotionalToCustomers' => __( 'Promotional, to customers', 'forms-notifier-for-elementor' ),
						'PromotionalAll'         => __( 'Promotional, to everyone', 'forms-notifier-for-elementor' ),
					],
				]
			),
			$this->note( __( 'Name the variables as they are named in the pattern of your panel. Line breaks in values become spaces.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', self::BASE . '/account/balance', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'data.balance' ), '', $this->text_at( $r['json'], 'meta.errorMessage' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call(
			'/send/sms',
			$this->line() + [
				'recipients'  => [ Phone::iran_local( $to ) ],
				'messageText' => $text,
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			'/send/pattern',
			$this->line() + [
				'recipients'  => [ Phone::iran_local( $to ) ],
				'patternCode' => $code,
				'parameters'  => (object) $this->named( $vars ),
			]
		);
	}

	/** Sender line or message type. */
	private function line(): array {
		$sender = $this->option( 'sender' );
		$type   = $this->option( 'type' );
		return $sender !== '' ? [ 'sendingNumber' => $sender ] : [ 'type' => $type === '' ? 'Informational' : $type ];
	}

	/** Headers. */
	private function headers(): array {
		return [
			'X-API-KEY' => $this->secret( 'api_key' ),
			'Accept'    => 'application/json',
		];
	}

	/**
	 * Send.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 */
	private function call( string $path, array $body ): SendResult {
		$r     = $this->post_json( self::BASE . $path, $body, $this->headers() );
		$error = trim( $this->text_at( $r['json'], 'meta.code' ) . ' ' . $this->text_at( $r['json'], 'meta.errorMessage' ) );
		$ok    = $this->dig( $r['json'], 'data.succeed' ) === true;
		// 1042 is missing credit.
		return $this->outcome( $r, $ok, $this->text_at( $r['json'], 'data.requestId' ), $error === 'OK' ? '' : $error, strpos( $error, '1042' ) === 0 ? false : null );
	}
}
