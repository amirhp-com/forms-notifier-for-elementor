<?php
/**
 * Twilio.
 *
 * https://www.twilio.com/docs/messaging/api/message-resource
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Twilio Messages; content templates as pattern. */
final class Twilio extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'twilio';
	}

	/** Name. */
	public function label(): string {
		return 'Twilio';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://console.twilio.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'account_sid', 'text', __( 'Account SID', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'auth_token', 'secret', __( 'Auth token', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'From', 'forms-notifier-for-elementor' ), __( 'A Twilio number, an approved sender name, or a messaging service SID that starts with MG.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is a content SID that starts with HX. Numbered variables are sent as 1, 2, 3; named ones by name.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'account_sid', 'auth_token', 'sender' ];
	}

	/** Content templates. */
	public function supports_pattern(): bool {
		return true;
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', $this->url( 'Balance' ), [ 'headers' => $this->headers() ] );
		return $this->balance( $r, $this->dig( $r['json'], 'balance' ), $this->text_at( $r['json'], 'currency' ), $this->text_at( $r['json'], 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( [ 'To' => $to ] + $this->from() + [ 'Body' => mb_substr( $text, 0, 1600 ) ] );
	}

	/** Content template. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$values = [];
		if ( PatternText::is_positional( $vars ) ) {
			foreach ( $this->listed( $vars ) as $i => $value ) {
				$values[ (string) ( $i + 1 ) ] = $value;
			}
		} else {
			$values = $this->named( $vars );
		}
		return $this->call(
			[ 'To' => $to ] + $this->from() + [
				'ContentSid'       => $code,
				'ContentVariables' => (string) wp_json_encode( (object) $values, JSON_UNESCAPED_UNICODE ),
			]
		);
	}

	/** Sender fields. */
	private function from(): array {
		$sender = $this->option( 'sender' );
		return preg_match( '/^MG[0-9a-f]{32}$/i', $sender ) ? [ 'MessagingServiceSid' => $sender ] : [ 'From' => $sender ];
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => $this->basic( $this->option( 'account_sid' ), $this->secret( 'auth_token' ) ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * URL.
	 *
	 * @param string $resource_name Resource.
	 */
	private function url( string $resource_name ): string {
		return 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $this->option( 'account_sid' ) ) . '/' . $resource_name . '.json';
	}

	/**
	 * Send.
	 *
	 * @param array $body Body.
	 */
	private function call( array $body ): SendResult {
		$r      = $this->post_form( $this->url( 'Messages' ), $body, $this->headers() );
		$sid    = $this->text_at( $r['json'], 'sid' );
		$failed = in_array( $this->dig( $r['json'], 'status' ), [ 'failed', 'undelivered' ], true );
		$error  = trim( $this->first_text( $r['json'], [ 'error_code', 'code' ] ) . ' ' . $this->first_text( $r['json'], [ 'error_message', 'message' ] ) );
		return $this->outcome( $r, $sid !== '' && ! $failed, $sid, $error );
	}
}
