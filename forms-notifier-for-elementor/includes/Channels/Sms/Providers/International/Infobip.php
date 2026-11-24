<?php
/**
 * Infobip SMS v3.
 *
 * https://api.infobip.com/platform/1/openapi/sms
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Infobip SMS API v3. */
final class Infobip extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'infobip';
	}

	/** Name. */
	public function label(): string {
		return 'Infobip';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://portal.infobip.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field(
				'base',
				'text',
				__( 'API base address', 'forms-notifier-for-elementor' ),
				[
					'placeholder' => 'xxxxx.api.infobip.com',
					'desc'        => __( 'Shown on the home page of your Infobip portal.', 'forms-notifier-for-elementor' ),
					'attrs'       => [ 'dir' => 'ltr' ],
				]
			),
			$this->api_key_field(),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'base', 'api_key', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', $this->url( '/account/1/balance' ), [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'balance' ), $this->text_at( $r['json'], 'currency' ), $this->error( $r ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r      = $this->post_json(
			$this->url( '/sms/3/messages' ),
			[
				'messages' => [
					[
						'sender'       => $this->option( 'sender' ),
						'destinations' => [ [ 'to' => Phone::digits( $to ) ] ],
						'content'      => [ 'text' => $text ],
					],
				],
			],
			$this->headers()
		);
		$id     = $this->text_at( $r['json'], 'messages.0.messageId' );
		$group  = $this->text_at( $r['json'], 'messages.0.status.groupName' );
		$failed = in_array( $group, [ 'REJECTED', 'UNDELIVERABLE', 'EXPIRED' ], true );
		return $this->outcome( $r, $id !== '' && ! $failed, $id, $failed ? trim( $group . ' ' . $this->text_at( $r['json'], 'messages.0.status.description' ) ) : $this->error( $r ) );
	}

	/**
	 * URL; the base may be typed with or without the scheme.
	 *
	 * @param string $path Path.
	 */
	private function url( string $path ): string {
		return 'https://' . trim( (string) preg_replace( '~^https?://~i', '', $this->option( 'base' ) ), '/' ) . $path;
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'App ' . $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * Error text.
	 *
	 * @param array $r Response.
	 */
	private function error( array $r ): string {
		return $this->first_text( $r['json'], [ 'description', 'requestError.serviceException.text', 'errorCode' ] );
	}
}
