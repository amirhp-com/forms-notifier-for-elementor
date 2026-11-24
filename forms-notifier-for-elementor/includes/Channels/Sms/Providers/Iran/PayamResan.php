<?php
/**
 * Payam-resan (sms-webservice.com).
 *
 * https://docs.payam-resan.com/sms-webservice/reference/send/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Payam-resan web service V3. */
final class PayamResan extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'payamresan';
	}

	/** Name. */
	public function label(): string {
		return 'Payam-resan';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://payam-resan.com/';
	}

	private const BASE = 'https://api.sms-webservice.com/api/V3/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( '', __( 'Needed for normal texts.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the template key. Variables are sent in order as p1 to p10; names are ignored.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->post_json( self::BASE . 'AccountInfo', [ 'ApiKey' => $this->secret( 'api_key' ) ] );
		return $this->balance( $r, $this->dig( $r['json'], 'Success' ) === true ? $this->dig( $r['json'], 'Result.Credit' ) : null, '', $this->text_at( $r['json'], 'Error' ) );
	}

	/** Normal text. This method of the gateway accepts GET only. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		if ( $this->option( 'sender' ) === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		$query = http_build_query(
			[
				'ApiKey'     => $this->secret( 'api_key' ),
				'Text'       => $text,
				'Sender'     => $this->option( 'sender' ),
				'Recipients' => Phone::iran_bare( $to ),
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return $this->result( $this->http( 'GET', self::BASE . 'Send?' . $query, [ 'headers' => [ 'Accept' => 'application/json' ] ] ) );
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$body = [
			'ApiKey'      => $this->secret( 'api_key' ),
			'TemplateKey' => $code,
			'Destination' => $this->maybe_int( Phone::iran_bare( $to ) ),
		];
		foreach ( array_slice( $this->listed( $vars ), 0, 10 ) as $i => $value ) {
			$body[ 'p' . ( $i + 1 ) ] = $value;
		}
		return $this->result( $this->post_json( self::BASE . 'SendTokenSingle', $body ) );
	}

	/**
	 * Result: errors come with status 200.
	 *
	 * @param array $r Response.
	 */
	private function result( array $r ): SendResult {
		$error = trim( $this->text_at( $r['json'], 'ErrorCode' ) . ' ' . $this->text_at( $r['json'], 'Error' ) );
		return $this->outcome( $r, $this->dig( $r['json'], 'Success' ) === true, $this->first_text( $r['json'], [ 'Result.0.ID', 'Result.0.Id', 'Result.ID', 'Result.Id' ] ), $error === '0' ? '' : $error );
	}
}
