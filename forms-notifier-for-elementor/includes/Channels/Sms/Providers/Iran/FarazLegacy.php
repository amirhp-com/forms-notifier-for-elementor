<?php
/**
 * IPPanel / FarazSMS legacy API.
 *
 * The old API of ippanel.com; no public documentation is left, the host still answers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Older IPPanel and FarazSMS accounts: normal SMS and patterns with username and password. */
final class FarazLegacy extends AbstractIranProvider {

	/** Id. */
	public function id(): string {
		return 'farazlegacy';
	}

	/** Name. */
	public function label(): string {
		return __( 'IPPanel / FarazSMS (legacy API)', 'forms-notifier-for-elementor' );
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://ippanel.com/';
	}

	private const BASE = 'https://ippanel.com';

	/** Codes a retry cannot fix. */
	private const PERMANENT = [ 1, 2, 3, 4, 100, 101, 102, 103, 104, 105, 962 ];

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'Username', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'Password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( '', __( 'For example 3000505.', 'forms-notifier-for-elementor' ) ),
			$this->unconfirmed_note(),
			$this->note( __( 'Only for older accounts; use IPPanel with an API key where you can. Name the variables as they are named in the pattern. Pattern messages are sent as a request whose address holds the username and the password, because this API knows no other way.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password', 'sender' ];
	}

	/** Balance. */
	public function status(): ?string {
		$r    = $this->post_form(
			self::BASE . '/services.jspd',
			[
				'uname' => $this->option( 'username' ),
				'pass'  => $this->secret( 'password' ),
				'op'    => 'credit',
			]
		);
		$code = $this->text_at( $r['json'], '0' );
		return $this->balance( $r, $code === '0' ? $this->dig( $r['json'], '1' ) : null, '', $this->error( $r ) );
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r    = $this->post_form(
			self::BASE . '/services.jspd',
			[
				'uname'   => $this->option( 'username' ),
				'pass'    => $this->secret( 'password' ),
				'from'    => $this->option( 'sender' ),
				'message' => $text,
				'to'      => (string) wp_json_encode( [ Phone::iran_local( $to ) ] ),
				'op'      => 'send',
			]
		);
		$code = $this->text_at( $r['json'], '0' );
		return $this->result( $r, $code === '0', $this->text_at( $r['json'], '1' ) );
	}

	/** Pattern: a GET request; answers with a list, or on newer panels with the number of the message. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$query = http_build_query(
			[
				'username'     => $this->option( 'username' ),
				'password'     => $this->secret( 'password' ),
				'from'         => $this->option( 'sender' ),
				'to'           => (string) wp_json_encode( [ Phone::iran_local( $to ) ] ),
				'input_data'   => (string) wp_json_encode( (object) $this->named( $vars ), JSON_UNESCAPED_UNICODE ),
				'pattern_code' => $code,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$r     = $this->http( 'GET', self::BASE . '/patterns/pattern?' . $query );
		$id    = is_scalar( $r['json'] ) && preg_match( '/^[1-9]\d*$/', (string) $r['json'] ) ? (string) $r['json'] : '';
		if ( $this->dig( $r['json'], '0' ) === 'sent' ) {
			return $this->result( $r, true, $this->text_at( $r['json'], '1' ) );
		}
		return $this->result( $r, $id !== '', $id );
	}

	/**
	 * Result.
	 *
	 * @param array  $r  Response.
	 * @param bool   $ok Accepted.
	 * @param string $id Message id.
	 */
	private function result( array $r, bool $ok, string $id ): SendResult {
		$code = $this->code( $r );
		return $this->outcome( $r, $ok, $ok ? $id : '', $ok ? '' : $this->error( $r ), $code === null ? null : in_array( $code, self::PERMANENT, true ) );
	}

	/**
	 * Error code of the answer, null when it has none.
	 *
	 * @param array $r Response.
	 */
	private function code( array $r ): ?int {
		$code = is_array( $r['json'] ) ? $this->dig( $r['json'], '0' ) : $r['json'];
		return is_scalar( $code ) && is_numeric( $code ) ? (int) $code : null;
	}

	/**
	 * Error text.
	 *
	 * @param array $r Response.
	 */
	private function error( array $r ): string {
		$code = $this->code( $r );
		if ( $code === null ) {
			return '';
		}
		$names = [
			-1  => __( 'The gateway could not be reached.', 'forms-notifier-for-elementor' ),
			1   => __( 'The text is empty.', 'forms-notifier-for-elementor' ),
			2   => __( 'The account is restricted.', 'forms-notifier-for-elementor' ),
			3   => __( 'The sender line does not belong to the account.', 'forms-notifier-for-elementor' ),
			4   => __( 'There is no receiver.', 'forms-notifier-for-elementor' ),
			5   => __( 'The credit is not enough.', 'forms-notifier-for-elementor' ),
			7   => __( 'The line is not suitable for this message.', 'forms-notifier-for-elementor' ),
			9   => __( 'The line cannot send at this hour. Use a pattern.', 'forms-notifier-for-elementor' ),
			99  => __( 'The operator of the line is down.', 'forms-notifier-for-elementor' ),
			101 => __( 'The username or password is wrong.', 'forms-notifier-for-elementor' ),
		];
		return trim( $code . ' ' . ( $names[ $code ] ?? $this->text_at( $r['json'], '1' ) ) );
	}
}
