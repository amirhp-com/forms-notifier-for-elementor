<?php
/**
 * Twilio WhatsApp.
 *
 * https://www.twilio.com/docs/content/send-templates-created-with-the-content-template-builder
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Twilio: text inside the 24 hour window, content templates, media by link. */
final class TwilioWhatsApp extends AbstractWhatsAppProvider {

	/** Longest text of one message. */
	protected const MAX_TEXT = 1600;

	/** Id. */
	public function id(): string {
		return 'twilio';
	}

	/** Name. */
	public function label(): string {
		return 'Twilio WhatsApp';
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
			$this->field(
				'sender',
				'text',
				__( 'WhatsApp sender number', 'forms-notifier-for-elementor' ),
				[
					'placeholder' => '+15550000000',
					'attrs'       => [ 'dir' => 'ltr' ],
				]
			),
			$this->note( __( 'For messages outside the 24 hour window write pattern text with the content SID that starts with HX. Files are sent by link, so the site must be reachable from the internet.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'account_sid', 'auth_token', 'sender' ];
	}

	/** Templates. */
	public function supports_template(): bool {
		return true;
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call( $to, [ 'Body' => $text ] );
	}

	/** Content template: no body beside it. */
	protected function send_template( string $to, string $code, Message $message ): SendResult {
		$vars   = (array) ( $message->pattern['vars'] ?? [] );
		$values = [];
		if ( PatternText::is_positional( $vars ) ) {
			foreach ( PatternText::values( $vars ) as $i => $value ) {
				$values[ (string) ( $i + 1 ) ] = $value;
			}
		} else {
			$values = $vars;
		}
		return $this->call(
			$to,
			[
				'ContentSid'       => $code,
				'ContentVariables' => (string) wp_json_encode( (object) array_map( 'strval', $values ), JSON_UNESCAPED_UNICODE ),
			]
		);
	}

	/** File by link. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$link = $this->link( $file );
		return $link === '' ? $this->unreadable() : $this->call( $to, [ 'MediaUrl' => $link ] );
	}

	/**
	 * Send.
	 *
	 * @param string $to   E.164.
	 * @param array  $body Body.
	 */
	private function call( string $to, array $body ): SendResult {
		$sender = (string) preg_replace( '/^whatsapp:/i', '', $this->option( 'sender' ) );
		$r      = $this->post_form(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $this->option( 'account_sid' ) ) . '/Messages.json',
			[
				'From' => 'whatsapp:+' . Phone::digits( Phone::clean( $sender ) ),
				'To'   => 'whatsapp:' . $to,
			] + $body,
			[ 'Authorization' => $this->basic( $this->option( 'account_sid' ), $this->secret( 'auth_token' ) ) ]
		);
		$sid    = $this->text_at( $r['json'], 'sid' );
		$failed = in_array( $this->dig( $r['json'], 'status' ), [ 'failed', 'undelivered' ], true );
		$error  = trim( $this->first_text( $r['json'], [ 'error_code', 'code' ] ) . ' ' . $this->first_text( $r['json'], [ 'error_message', 'message' ] ) );
		return $this->outcome( $r, $sid !== '' && ! $failed, $sid, $error );
	}
}
