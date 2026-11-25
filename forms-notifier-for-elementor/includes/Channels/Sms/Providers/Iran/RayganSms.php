<?php
/**
 * RayganSMS.
 *
 * https://raygansms.com/page/20/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** RayganSMS URL API: normal SMS only. */
final class RayganSms extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'raygansms';
	}

	/** Name. */
	public function label(): string {
		return 'RayganSMS';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://raygansms.com/';
	}

	/** The API has no patterns. */
	public function supports_pattern(): bool {
		return false;
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'Username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'Password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field(),
			$this->note( __( 'The web service must be switched on for your account: ask the support of RayganSMS. This API has no patterns.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password', 'sender' ];
	}

	/** Normal text. The answer is a number: above 2000 it is the id of the message, below it an error code. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r     = $this->post_form(
			'https://raygansms.com/SendMessageWithPost.ashx',
			[
				'Username'    => $this->option( 'username' ),
				'Password'    => $this->secret( 'password' ),
				'PhoneNumber' => $this->option( 'sender' ),
				'MessageBody' => $text,
				'RecNumber'   => Phone::iran_local( $to ),
				'Smsclass'    => '1',
			]
		);
		$body  = trim( (string) $r['body'] );
		$value = preg_match( '/^-?\d{1,18}$/', $body ) ? (int) $body : 0;
		$error = '';
		if ( $value === 8 ) {
			$error = __( '8: the web service is not switched on for this account.', 'forms-notifier-for-elementor' );
		} elseif ( $value !== 0 || $body === '0' ) {
			/* translators: %1$s: error code */
			$error = sprintf( __( 'The gateway answered with error code %1$s.', 'forms-notifier-for-elementor' ), $body );
		}
		return $this->outcome( $r, $value > 2000, $value > 2000 ? $body : '', $error );
	}
}
