<?php
/**
 * ClickSend.
 *
 * https://developers.clicksend.com/docs/messaging/sms ; the fields of one message are not confirmed by public documentation.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** ClickSend. Every message of the answer carries its own status. */
final class ClickSend extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'clicksend';
	}

	/** Name. */
	public function label(): string {
		return 'ClickSend';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://dashboard.clicksend.com/';
	}

	private const BASE = 'https://rest.clicksend.com/v3';

	/** Message statuses a retry cannot fix. */
	private const PERMANENT = [ 'INVALID_RECIPIENT', 'INVALID_SENDER_ID', 'COUNTRY_NOT_ENABLED', 'EMPTY_MESSAGE', 'INVALID_FROM', 'INVALID_CREDENTIALS' ];

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'Username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->api_key_field(),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ), __( 'Optional.', 'forms-notifier-for-elementor' ) ),
			$this->unconfirmed_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', self::BASE . '/account', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'data.balance' ), $this->text_at( $r['json'], 'data._currency.currency_name_short' ), $this->text_at( $r['json'], 'response_msg' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$item = [
			'source' => 'wp',
			'to'     => $to,
			'body'   => $text,
		];
		if ( $this->option( 'sender' ) !== '' ) {
			$item['from'] = $this->option( 'sender' );
		}
		$r      = $this->post_json( self::BASE . '/sms/send', [ 'messages' => [ $item ] ], $this->headers() );
		$status = $this->text_at( $r['json'], 'data.messages.0.status' );
		$ok     = $this->dig( $r['json'], 'response_code' ) === 'SUCCESS' && $status === 'SUCCESS';
		$error  = $status !== '' && $status !== 'SUCCESS' ? $status : $this->first_text( $r['json'], [ 'response_msg', 'response_code' ] );
		return $this->outcome( $r, $ok, $this->text_at( $r['json'], 'data.messages.0.message_id' ), $error, $status === '' ? null : in_array( $status, self::PERMANENT, true ) );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => $this->basic( $this->option( 'username' ), $this->secret( 'api_key' ) ),
			'Accept'        => 'application/json',
		];
	}
}
