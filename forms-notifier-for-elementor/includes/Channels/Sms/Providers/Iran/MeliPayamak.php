<?php
/**
 * MeliPayamak.
 *
 * https://www.melipayamak.com/api/sendsimplesms2/ and https://www.melipayamak.com/api/sendbybasenumber2/
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** MeliPayamak REST: normal SMS and shared service lines as pattern. */
final class MeliPayamak extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'melipayamak';
	}

	/** Name. */
	public function label(): string {
		return 'MeliPayamak';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://www.melipayamak.com/';
	}

	private const BASE = 'https://rest.payamak-panel.com/api/SendSMS/';

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'Username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'Password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( '', __( 'Needed for normal texts; patterns use the shared service line.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the body id. Variables are sent in order; names are ignored. The character ; separates variables, so it is removed from values.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r  = $this->post_form( self::BASE . 'GetCredit', $this->login() );
		$ok = (int) $this->dig( $r['json'], 'RetStatus' ) === 1;
		return $this->balance( $r, $ok ? $this->dig( $r['json'], 'Value' ) : null, __( 'SMS', 'forms-notifier-for-elementor' ), $this->text_at( $r['json'], 'StrRetStatus' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		if ( $this->option( 'sender' ) === '' ) {
			return $this->fail( __( 'A sender line is needed for normal texts.', 'forms-notifier-for-elementor' ), true );
		}
		return $this->result(
			$this->post_form(
				self::BASE . 'SendSMS',
				$this->login() + [
					'to'      => Phone::iran_local( $to ),
					'from'    => $this->option( 'sender' ),
					'text'    => $text,
					'isFlash' => 'false',
				]
			)
		);
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$values = array_map(
			function ( string $value ): string {
				return $this->one_line( str_replace( [ ';', '؛' ], [ ' ', ' ' ], $value ) );
			},
			$this->listed( $vars )
		);
		return $this->result(
			$this->post_form(
				self::BASE . 'BaseServiceNumber',
				$this->login() + [
					'text'   => implode( ';', $values ),
					'to'     => Phone::iran_local( $to ),
					'bodyId' => $code,
				]
			)
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
	 * Result: the HTTP status is always 200, and Value is a long id or a short error code.
	 *
	 * @param array $r Response.
	 */
	private function result( array $r ): SendResult {
		$value  = $this->text_at( $r['json'], 'Value' );
		$status = $this->dig( $r['json'], 'RetStatus' );
		$ok     = (int) $status === 1 && preg_match( '/^\d{6,}$/', $value ) === 1;
		$error  = '';
		if ( $status !== null && ! $ok ) {
			$names = $this->errors();
			$error = $names[ $value ] ?? trim( $this->text_at( $r['json'], 'StrRetStatus' ) . ' (' . $value . ')' );
		}
		// RetStatus 1 with a short number is a documented error code; with anything else it is not understood.
		return $this->outcome( $r, $ok, $value, $error, $status === null ? null : $value !== '-6', $status !== null && ( (int) $status !== 1 || preg_match( '/^-?\d{1,5}$/', $value ) === 1 ) );
	}

	/** Documented pattern error codes. */
	private function errors(): array {
		return [
			'-1'   => __( 'The account may not use this web service.', 'forms-notifier-for-elementor' ),
			'-2'   => __( 'Only one number may be sent per request.', 'forms-notifier-for-elementor' ),
			'-3'   => __( 'The sender line is not defined.', 'forms-notifier-for-elementor' ),
			'-4'   => __( 'The body id is wrong or not approved.', 'forms-notifier-for-elementor' ),
			'-5'   => __( 'The variables do not match the approved text.', 'forms-notifier-for-elementor' ),
			'-6'   => __( 'Internal error of the gateway.', 'forms-notifier-for-elementor' ),
			'-7'   => __( 'The sender number has a problem.', 'forms-notifier-for-elementor' ),
			'-10'  => __( 'A variable contains a link.', 'forms-notifier-for-elementor' ),
			'-111' => __( 'The IP address of this site is not allowed.', 'forms-notifier-for-elementor' ),
		];
	}
}
