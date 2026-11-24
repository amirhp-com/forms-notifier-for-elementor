<?php
/**
 * Kavenegar.
 *
 * https://kavenegar.com/rest.html
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

/** Kavenegar: normal SMS and verify lookup as pattern. */
final class Kavenegar extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'kavenegar';
	}

	/** Name. */
	public function label(): string {
		return 'Kavenegar';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://kavenegar.com/';
	}

	private const SLOTS = [ 'token', 'token2', 'token3', 'token10', 'token20' ];

	/** Spaces a slot may hold. */
	private const SPACES = [
		'token10' => 5,
		'token20' => 8,
	];

	/** Status codes a retry cannot fix; 418 (credit) and 409 (busy) are left out. */
	private const PERMANENT = [ 400, 401, 402, 403, 404, 405, 406, 407, 411, 412, 413, 414, 415, 416, 417, 419, 420, 422, 424, 426, 428, 431, 432 ];

	/** Fields. */
	public function fields(): array {
		return [
			$this->api_key_field(),
			$this->sender_field( '', __( 'Empty uses the default line of the account.', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'Pattern code is the template name. Variables are token, token2, token3, token10 and token20; other names fill the free places in order. Spaces in token, token2 and token3 become half-spaces, because Kavenegar does not accept spaces there. Values are cut to 100 characters.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r = $this->post_form( $this->url( 'account/info' ), [] );
		return $this->balance( $r, (int) $this->dig( $r['json'], 'return.status' ) === 200 ? $this->dig( $r['json'], 'entries.remaincredit' ) : null, __( 'Rial', 'forms-notifier-for-elementor' ), $this->text_at( $r['json'], 'return.message' ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = [
			'receptor' => Phone::iran_local( $to ),
			'message'  => $text,
		];
		if ( $this->option( 'sender' ) !== '' ) {
			$body['sender'] = $this->option( 'sender' );
		}
		return $this->result( $this->post_form( $this->url( 'sms/send' ), $body ) );
	}

	/** Verify lookup. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$tokens = $this->tokens( $vars );
		if ( ( $tokens['token'] ?? '' ) === '' ) {
			return $this->fail( __( 'A Kavenegar template needs a value for token.', 'forms-notifier-for-elementor' ), true );
		}
		$body = [
			'receptor' => Phone::iran_local( $to ),
			'template' => $code,
		];
		return $this->result( $this->post_form( $this->url( 'verify/lookup' ), $body + $tokens ) );
	}

	/**
	 * Variables by slot.
	 *
	 * @param array $vars Variables.
	 * @return array<string,string>
	 */
	private function tokens( array $vars ): array {
		$out   = [];
		$other = [];
		if ( PatternText::is_positional( $vars ) ) {
			ksort( $vars );
		}
		foreach ( $this->named( $vars ) as $name => $value ) {
			$slot = strtolower( (string) $name );
			if ( in_array( $slot, self::SLOTS, true ) ) {
				$out[ $slot ] = $value;
			} else {
				$other[] = $value;
			}
		}
		foreach ( self::SLOTS as $slot ) {
			if ( ! isset( $out[ $slot ] ) && $other ) {
				$out[ $slot ] = array_shift( $other );
			}
		}
		foreach ( $out as $slot => $value ) {
			$words = explode( ' ', mb_substr( $value, 0, 100 ) );
			$free  = array_slice( $words, 0, ( self::SPACES[ $slot ] ?? 0 ) + 1 );
			$rest  = array_slice( $words, count( $free ) );
			// Further words are joined with a half-space (U+200C).
			$out[ $slot ] = implode( "\u{200C}", array_merge( [ implode( ' ', $free ) ], $rest ) );
		}
		return $out;
	}

	/**
	 * Method URL.
	 *
	 * @param string $method Method.
	 */
	private function url( string $method ): string {
		return 'https://api.kavenegar.com/v1/' . rawurlencode( $this->secret( 'api_key' ) ) . '/' . $method . '.json';
	}

	/**
	 * Result.
	 *
	 * @param array $r Response.
	 */
	private function result( array $r ): SendResult {
		$status = (int) $this->dig( $r['json'], 'return.status' );
		return $this->outcome(
			$r,
			$status === 200,
			$this->text_at( $r['json'], 'entries.0.messageid' ),
			$status === 200 ? '' : trim( ( $status ? $status . ' ' : '' ) . $this->text_at( $r['json'], 'return.message' ) ),
			$status === 0 ? null : in_array( $status, self::PERMANENT, true )
		);
	}
}
