<?php
/**
 * Asanak.
 *
 * https://asanak.com/api-docs/sms/single
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Asanak REST v2. */
final class Asanak extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'asanak';
	}

	/** Name. */
	public function label(): string {
		return 'Asanak';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://asanak.com/';
	}

	private const BASE = 'https://sms.asanak.ir/webservice/v2rest/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'Username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'Password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( '', __( 'Needed for normal texts.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the template id. Name the variables as in the template.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r     = $this->post_json( self::BASE . 'getcredit', $this->login() );
		$value = $this->dig( $r['json'], 'data' );
		return $this->balance( $r, is_array( $value ) ? reset( $value ) : $value, '', $this->text_at( $r['json'], 'meta.message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		if ( $this->option( 'sender' ) === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->call(
			'sendsms',
			[
				'source'            => $this->option( 'sender' ),
				'destination'       => Phone::iran_local( $to ),
				'message'           => $text,
				'send_to_blacklist' => 0,
			]
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		return $this->call(
			'template',
			[
				'template_id' => $this->maybe_int( $code ),
				'destination' => Phone::iran_local( $to ),
				'parameters'  => (object) $this->named( $vars ),
			]
		);
	}

	/** Credentials. */
	private function login(): array {
		return [
			'username' => $this->option( 'username' ),
			'password' => $this->secret( 'password' ),
		];
	}

	/**
	 * Send.
	 *
	 * @param string $method Method.
	 * @param array  $body   Body.
	 */
	private function call( string $method, array $body ): SendResult {
		$r      = $this->post_json( self::BASE . $method, $this->login() + $body );
		$status = (int) $this->dig( $r['json'], 'meta.status' );
		return $this->outcome( $r, $status === 200, $this->text_at( $r['json'], 'data.0' ), $status === 0 || $status === 200 ? '' : trim( $status . ' ' . $this->text_at( $r['json'], 'meta.message' ) ) );
	}
}
