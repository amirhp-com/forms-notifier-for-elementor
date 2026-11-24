<?php
/**
 * SMS.ir.
 *
 * https://sms.ir/rest-api/ (v1)
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** SMS.ir web service v1. */
final class SmsIr extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'smsir';
	}

	/** Name. */
	public function label(): string {
		return 'SMS.ir';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://sms.ir/';
	}

	private const BASE = 'https://api.sms.ir/v1';

	/** Codes that may go away: 0 (system error), 20 (rate), 102 (credit). */
	private const TEMPORARY = [ 0, 20, 102 ];

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( __( 'Line number', 'forms-notifier-for-elementor' ), __( 'Needed for normal texts.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the template id. Name the variables as in the template. Values are cut to 25 characters, the limit of SMS.ir.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->http( 'GET', self::BASE . '/credit', [ 'headers' => $this->headers() ] );
		return $this->balance( $r, (int) $this->dig( $r['json'], 'status' ) === 1 ? $this->dig( $r['json'], 'data' ) : null, __( 'SMS', 'forms-notifier-for-elementor' ), $this->text_at( $r['json'], 'message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$line = Phone::clean( $this->option( 'sender' ) );
		if ( $line === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		$r   = $this->post_json(
			self::BASE . '/send/bulk',
			[
				'lineNumber'   => $this->maybe_int( $line ),
				'messageText'  => $text,
				'mobiles'      => [ Phone::iran_local( $to ) ],
				'sendDateTime' => null,
			],
			$this->headers()
		);
		$ids = $this->dig( $r['json'], 'data.messageIds' );
		if ( (int) $this->dig( $r['json'], 'status' ) === 1 && is_array( $ids ) && array_key_exists( 0, $ids ) && ! $ids[0] ) {
			return $this->outcome( $r, false, '', $ids[0] === null ? __( 'The gateway could not send to this number.', 'forms-notifier-for-elementor' ) : __( 'This number is on the blacklist of the operator.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->result( $r, 'data.messageIds.0' );
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$parameters = [];
		foreach ( $this->named( $vars ) as $name => $value ) {
			$parameters[] = [
				'name'  => (string) $name,
				'value' => mb_substr( $value, 0, 25 ),
			];
		}
		return $this->result(
			$this->post_json(
				self::BASE . '/send/verify',
				[
					'mobile'     => Phone::iran_bare( $to ),
					'templateId' => $this->maybe_int( $code ),
					'parameters' => $parameters,
				],
				$this->headers()
			),
			'data.messageId'
		);
	}

	/** Headers. */
	private function headers(): array {
		return [
			'X-API-KEY' => $this->secret( 'api_key' ),
			'Accept'    => 'application/json',
		];
	}

	/**
	 * Result.
	 *
	 * @param array  $r  Response.
	 * @param string $id Path of the id.
	 */
	private function result( array $r, string $id ): SendResult {
		$status = $this->dig( $r['json'], 'status' );
		$ok     = is_numeric( $status ) && (int) $status === 1;
		$error  = $ok || ! is_numeric( $status ) ? '' : trim( $status . ' ' . $this->text_at( $r['json'], 'message' ) );
		return $this->outcome( $r, $ok, $this->text_at( $r['json'], $id ), $error, is_numeric( $status ) ? ! in_array( (int) $status, self::TEMPORARY, true ) : null );
	}
}
