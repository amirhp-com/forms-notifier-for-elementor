<?php
/**
 * Amoot SMS.
 *
 * https://hub.amootsoft.com/Payamak/docs/send-simple and https://hub.amootsoft.com/Payamak/docs/send-with-pattern
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Amoot SMS. */
final class Amoot extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'amoot';
	}

	/** Name. */
	public function label(): string {
		return 'Amoot SMS';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://amootsms.com/';
	}

	private const BASE = 'https://portal.amootsms.com/rest/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'Token', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( '', __( 'Empty uses the public line.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the pattern id. Variables are sent in order; names are ignored. Commas separate variables, so they are removed from values.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r  = $this->http( 'GET', self::BASE . 'AccountStatus', [ 'headers' => $this->headers() ] );
		$ok = in_array( $this->dig( $r['json'], 'Status' ), [ 'Success', null ], true );
		return $this->balance( $r, $ok ? $this->dig( $r['json'], 'RemaindCredit' ) : null, '', $this->text_at( $r['json'], 'Status' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$now = new \DateTimeImmutable( '@' . (int) apply_filters( 'fnfe_now', time() ) );
		return $this->call(
			'SendSimple',
			[
				'SendDateTime'   => $now->setTimezone( new \DateTimeZone( 'Asia/Tehran' ) )->format( 'Y-m-d H:i:s' ),
				'SMSMessageText' => $text,
				'LineNumber'     => $this->option( 'sender' ) === '' ? 'public' : $this->option( 'sender' ),
				'Mobiles'        => Phone::iran_bare( $to ),
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$values = array_map(
			function ( string $value ): string {
				return $this->one_line( str_replace( [ ',', '،' ], ' ', $value ) );
			},
			$this->listed( $vars )
		);
		return $this->call(
			'SendWithPattern',
			[
				'Mobile'        => Phone::iran_bare( $to ),
				'PatternCodeID' => $code,
				'PatternValues' => implode( ',', $values ),
			]
		);
	}

	/** The token is sent as it is. */
	private function headers(): array {
		return [
			'Authorization' => $this->secret( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * Send. The HTTP status is always 200: the answer and every item carry their own status.
	 *
	 * @param string $method Method.
	 * @param array  $body   Body.
	 */
	private function call( string $method, array $body ): SendResult {
		$r      = $this->post_form( self::BASE . $method, $body, $this->headers() );
		$status = $this->text_at( $r['json'], 'Status' );
		$item   = $this->text_at( $r['json'], 'Data.0.Status' );
		$ok     = $status === 'Success' && in_array( $item, [ 'Success', '' ], true );
		return $this->outcome( $r, $ok, $this->first_text( $r['json'], [ 'Data.0.MessageID', 'CampaignID' ] ), $status === 'Success' ? $item : $status );
	}
}
