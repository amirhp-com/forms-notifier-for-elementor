<?php
/**
 * ParsGreen.
 *
 * The public documentation covers SOAP only; the REST paths were confirmed by their error answers, the bodies were not.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** ParsGreen REST API: normal SMS and one-value templates. */
final class ParsGreen extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'parsgreen';
	}

	/** Name. */
	public function label(): string {
		return 'ParsGreen';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://www.parsgreen.com/';
	}

	private const BASE = 'https://sms.parsgreen.ir/Apiv2/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'API key (signature)', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( '', __( 'Empty uses the default line of the account.', 'forms-notifier-for-elementor' ) ),
			$this->field( 'add_name', 'toggle', __( 'Greet the receiver by name in templates', 'forms-notifier-for-elementor' ), [ 'default' => false ] ),
			$this->unconfirmed_note(),
			$this->note( __( 'Pattern code is the template id. A template takes one value: the variable named code, or else the first one.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = [
			'SmsBody' => $text,
			'Mobiles' => [ Phone::iran_local( $to ) ],
		];
		if ( $this->option( 'sender' ) !== '' ) {
			$body['SmsNumber'] = $this->option( 'sender' );
		}
		return $this->call( 'Message/SendSms', $body );
	}

	/** Template. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$value = $this->named( $vars )['code'] ?? ( $this->listed( $vars )[0] ?? '' );
		if ( $value === '' ) {
			return $this->fail( __( 'A ParsGreen template needs a value.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->call(
			'Message/SendOtp',
			[
				'Mobile'     => Phone::iran_local( $to ),
				'SmsCode'    => $value,
				'TemplateId' => $this->maybe_int( $code ),
				'AddName'    => (bool) $this->setting( 'add_name', false ),
			]
		);
	}

	/**
	 * Send.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 */
	private function call( string $path, array $body ): SendResult {
		$r       = $this->post_json( self::BASE . $path, $body, [ 'Authorization' => 'BASIC APIKEY:' . $this->secret( 'api_key' ) ] );
		$success = $this->dig( $r['json'], 'R_Success' );
		$error   = trim( $this->text_at( $r['json'], 'R_Code' ) . ' ' . $this->first_text( $r['json'], [ 'R_Message', 'R_Error' ] ) );
		// An explicit refusal was not sent, so it may be tried again.
		return $this->outcome( $r, $success === true, '', $error, $r['ok'] && $success === false ? false : null );
	}
}
