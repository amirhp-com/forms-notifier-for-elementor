<?php
/**
 * SMSAPI.
 *
 * https://www.smsapi.com/docs/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** SMSAPI. Errors come with status 200. */
final class SmsApi extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'smsapi';
	}

	/** Name. */
	public function label(): string {
		return 'SMSAPI';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://www.smsapi.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'OAuth token', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'host',
				'select',
				__( 'Service', 'forms-notifier-for-elementor' ),
				[
					'default' => 'api.smsapi.com',
					'options' => [
						'api.smsapi.com' => 'smsapi.com',
						'api.smsapi.pl'  => 'smsapi.pl',
					],
				]
			),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', $this->base() . '/profile', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'points' ), __( 'points', 'forms-notifier-for-elementor' ), $this->text_at( $r['json'], 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = [
			'to'       => Phone::digits( $to ),
			'message'  => $text,
			'encoding' => 'utf-8',
			'format'   => 'json',
		];
		if ( $this->option( 'sender' ) !== '' ) {
			$body['from'] = $this->option( 'sender' );
		}
		$r     = $this->post_form( $this->base() . '/sms.do', $body, $this->headers() );
		$error = $this->text_at( $r['json'], 'error' );
		$id    = $this->text_at( $r['json'], 'list.0.id' );
		return $this->outcome( $r, $error === '' && $id !== '', $id, trim( $error . ' ' . $this->text_at( $r['json'], 'message' ) ) );
	}

	/** Base URL. */
	private function base(): string {
		return 'https://' . ( $this->option( 'host' ) === 'api.smsapi.pl' ? 'api.smsapi.pl' : 'api.smsapi.com' );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}
}
