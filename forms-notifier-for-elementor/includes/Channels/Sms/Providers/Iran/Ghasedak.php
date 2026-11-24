<?php
/**
 * Ghasedak.
 *
 * https://ghasedak.me/docs
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Ghasedak gateway API. Keys are read without regard to case: documentation and live answers differ. */
final class Ghasedak extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'ghasedak';
	}

	/** Name. */
	public function label(): string {
		return 'Ghasedak';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://ghasedak.me/';
	}

	private const BASE = 'https://gateway.ghasedak.me/rest/api/v1/WebService';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( __( 'Line number', 'forms-notifier-for-elementor' ), __( 'Needed for normal texts.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the template name. Named variables are sent by name; numbered ones are sent as param1 to param10.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r    = $this->http( 'GET', self::BASE . '/GetAccountInformation', [ 'headers' => $this->headers() ] );
		$json = $this->lower( $r['json'] );
		return $this->balance( $r, $this->dig( $json, 'issuccess' ) === true ? $this->dig( $json, 'data.credit' ) : null, '', $this->text_at( $json, 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		if ( $this->option( 'sender' ) === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->call(
			'/SendSingleSMS',
			[
				'lineNumber'        => $this->option( 'sender' ),
				'receptor'          => Phone::iran_local( $to ),
				'message'           => $text,
				'clientReferenceId' => $this->reference( $message ),
				'udh'               => false,
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$body = [
			'receptors'    => [
				[
					'mobile'            => Phone::iran_local( $to ),
					'clientReferenceId' => $this->reference( $message ),
				],
			],
			'templateName' => $code,
		];
		if ( PatternText::is_positional( $vars ) ) {
			foreach ( array_slice( $this->listed( $vars ), 0, 10 ) as $i => $value ) {
				$body[ 'param' . ( $i + 1 ) ] = $value;
			}
			$body['isVoice'] = false;
			$body['udh']     = false;
			return $this->call( '/SendOtpWithParams', $body );
		}
		$body['inputs'] = [];
		foreach ( $this->named( $vars ) as $name => $value ) {
			$body['inputs'][] = [
				'param' => (string) $name,
				'value' => $value,
			];
		}
		$body['udh'] = false;
		return $this->call( '/SendOtpSMS', $body );
	}

	/** Headers. */
	private function headers(): array {
		return [
			'ApiKey' => $this->secret( 'api_key' ),
			'Accept' => 'application/json',
		];
	}

	/**
	 * Own id of the request.
	 *
	 * @param Message $message Message.
	 */
	private function reference( Message $message ): string {
		$id = (int) ( $message->meta['message_id'] ?? 0 );
		return $id ? (string) $id : Text::uid();
	}

	/**
	 * Lowercase keys at every level.
	 *
	 * @param mixed $json Decoded JSON.
	 * @return mixed
	 */
	private function lower( $json ) {
		return is_array( $json ) ? array_map( [ $this, 'lower' ], array_change_key_case( $json ) ) : $json;
	}

	/**
	 * Send.
	 *
	 * @param string $path Path.
	 * @param array  $body Body.
	 */
	private function call( string $path, array $body ): SendResult {
		$r    = $this->post_json( self::BASE . $path, $body, $this->headers() );
		$json = $this->lower( $r['json'] );
		return $this->outcome( $r, $this->dig( $json, 'issuccess' ) === true, $this->first_text( $json, [ 'data.messageid', 'data.items.0.messageid' ] ), $this->text_at( $json, 'message' ) );
	}
}
