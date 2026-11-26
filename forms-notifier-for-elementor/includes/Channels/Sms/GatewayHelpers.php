<?php
/**
 * Gateway helpers.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Channels\AbstractProvider;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Requests and result mapping shared by the SMS and WhatsApp gateways.
 *
 * Result rules: success only when the documented field of the body says so; 408, 429, 5xx and transport errors may be
 * retried; other 4xx are final. A 2xx body that is not understood is final too, and marked ambiguous unless it clearly
 * refuses the message: it may already be on its way, so neither a retry nor a backup gateway may send it again.
 *
 * @mixin AbstractProvider
 */
trait GatewayHelpers {

	/**
	 * Keys of fields() that must be filled.
	 *
	 * @return string[]
	 */
	protected function needs(): array {
		return [];
	}

	/** Everything needed to send is set. */
	public function is_configured(): bool {
		return ! $this->missing();
	}

	/** Translated problems. */
	public function problems(): array {
		$missing = $this->missing();
		if ( ! $missing ) {
			return [];
		}
		$labels = array_column( $this->fields(), 'label', 'key' );
		$out    = [];
		foreach ( $missing as $key ) {
			$out[] = sprintf(
				/* translators: %1$s: gateway name, %2$s: label of a settings field */
				__( '%1$s: fill in "%2$s".', 'forms-notifier-for-elementor' ),
				$this->label(),
				$labels[ $key ] ?? $key
			);
		}
		return $out;
	}

	/**
	 * Required keys without a value.
	 *
	 * @return string[]
	 */
	private function missing(): array {
		return array_values(
			array_filter(
				$this->needs(),
				function ( string $key ): bool {
					return $this->option( $key ) === '' && $this->secret( $key ) === '';
				}
			)
		);
	}

	/**
	 * Trimmed text setting.
	 *
	 * @param string $key Relative key.
	 */
	protected function option( string $key ): string {
		$value = $this->setting( $key );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Send a request. Redirects are not followed: the headers carry credentials.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   See Http::request().
	 */
	protected function http( string $method, string $url, array $args = [] ): array {
		return Http::request( $method, $url, $args + [ 'redirection' => 0 ] );
	}

	/**
	 * POST JSON.
	 *
	 * @param string $url     URL.
	 * @param array  $json    Body.
	 * @param array  $headers Headers.
	 */
	protected function post_json( string $url, array $json, array $headers = [] ): array {
		return $this->http(
			'POST',
			$url,
			[
				'json'    => $json,
				'headers' => $headers + [ 'Accept' => 'application/json' ],
			]
		);
	}

	/**
	 * POST a form.
	 *
	 * @param string $url     URL.
	 * @param array  $fields  Body.
	 * @param array  $headers Headers.
	 */
	protected function post_form( string $url, array $fields, array $headers = [] ): array {
		return $this->http(
			'POST',
			$url,
			[
				'body'    => http_build_query( $fields, '', '&' ),
				'headers' => $headers + [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
				],
			]
		);
	}

	/**
	 * Basic authentication header; its encoded form is kept out of the log as well.
	 *
	 * @param string $user User name.
	 * @param string $pass Password.
	 */
	protected function basic( string $user, string $pass ): string {
		$header = Http::basic_auth( $user, $pass );
		Logger::scrub( substr( $header, 6 ) );
		return $header;
	}

	/**
	 * Value inside decoded JSON.
	 *
	 * @param mixed  $json Decoded JSON.
	 * @param string $path Dot path; list positions are numbers.
	 * @return mixed null when missing
	 */
	protected function dig( $json, string $path ) {
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $json ) || ! array_key_exists( $key, $json ) ) {
				return null;
			}
			$json = $json[ $key ];
		}
		return $json;
	}

	/**
	 * Scalar of decoded JSON as short plain text.
	 *
	 * @param mixed  $json Decoded JSON.
	 * @param string $path Dot path.
	 */
	protected function text_at( $json, string $path ): string {
		$value = $this->dig( $json, $path );
		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return is_scalar( $value ) && ! is_bool( $value ) ? $this->short( (string) $value ) : '';
	}

	/**
	 * Remote text made safe to show.
	 *
	 * @param string $text Text.
	 */
	protected function short( string $text ): string {
		return Text::truncate( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ), 300 );
	}

	/**
	 * First non-empty text of several paths.
	 *
	 * @param mixed    $json  Decoded JSON.
	 * @param string[] $paths Dot paths.
	 */
	protected function first_text( $json, array $paths ): string {
		foreach ( $paths as $path ) {
			$text = $this->text_at( $json, $path );
			if ( $text !== '' ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * Result of a request.
	 *
	 * @param array     $r         Output of http().
	 * @param bool      $ok        The body reports success.
	 * @param string    $remote_id Remote message id.
	 * @param string    $error     Error text of the gateway.
	 * @param bool|null $permanent Retrying is pointless; null = by HTTP status.
	 * @param bool|null $refused   A 2xx body clearly refuses the message; null = look for the usual signs.
	 */
	protected function outcome( array $r, bool $ok, string $remote_id = '', string $error = '', ?bool $permanent = null, ?bool $refused = null ): SendResult {
		$code = (int) $r['code'];
		if ( $ok && $r['ok'] ) {
			$result            = $this->ok( (string) $r['body'], $remote_id );
			$result->http_code = $code;
			return $result;
		}
		if ( $error === '' ) {
			// Plain-text answers are worth showing, HTML pages are not.
			$body  = trim( (string) $r['body'] );
			$plain = $body !== '' && strlen( $body ) <= 300 && $r['json'] === null && strpos( $body, '<' ) === false ? $this->short( $body ) : '';
			if ( $plain !== '' ) {
				$error = $plain;
			} elseif ( $r['ok'] ) {
				$error = __( 'The gateway gave an answer that could not be understood.', 'forms-notifier-for-elementor' );
			} else {
				$error = $this->from_http( $r )->error;
			}
		}
		$temporary           = $code === 0 || $code === 408 || $code === 429 || $code >= 500;
		$result              = $this->fail( $error, $temporary ? false : ( $permanent ?? true ), (string) $r['body'], $code );
		$result->retry_after = $r['ok'] ? 0 : $this->from_http( $r )->retry_after;
		if ( $r['ok'] && ! ( $refused ?? ( $permanent !== null || self::refuses( $r ) ) ) ) {
			$result->permanent = true;
			$result->with_meta( [ 'ambiguous' => true ] );
		}
		return $result;
	}

	/**
	 * A 2xx answer says that the message was not taken: a false success flag, a failure status, an error field.
	 *
	 * @param array $r Output of http().
	 */
	private static function refuses( array $r ): bool {
		if ( ! is_array( $r['json'] ) ) {
			$body = trim( wp_strip_all_tags( (string) $r['body'] ) );
			return $body !== '' && strlen( $body ) <= 300 && preg_match( '/\b(error|fail(ed|ure)?|invalid|denied|unauthori[sz]ed|forbidden|wrong)\b/i', $body ) === 1;
		}
		return self::refusal_in( $r['json'], 0 );
	}

	/**
	 * Signs of a refusal in decoded JSON, two levels deep.
	 *
	 * @param array $json  Decoded JSON.
	 * @param int   $depth Depth.
	 */
	private static function refusal_in( array $json, int $depth ): bool {
		foreach ( $json as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( in_array( $key, [ 'success', 'issuccess', 'succeed', 'ok', 'sent', 'r_success', 'accepted' ], true ) && in_array( $value, [ false, 'false', 0, '0' ], true ) ) {
				return true;
			}
			if ( in_array( $key, [ 'status', 'type', 'result', 'groupname' ], true ) && is_string( $value ) && preg_match( '/^(error|fail(ed|ure)?|rejected|undeliverable|undelivered|invalid)$/i', $value ) ) {
				return true;
			}
			if ( preg_match( '/^(errors?|error_?(code|message|text|description)|exception)$/', $key ) && ! in_array( $value, [ null, '', [], 0, '0', false ], true ) ) {
				return true;
			}
			if ( is_array( $value ) && $depth < 2 && self::refusal_in( $value, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status line of a balance request.
	 *
	 * @param array  $r     Output of http().
	 * @param mixed  $value Balance, null when the answer has none.
	 * @param string $unit  Unit or currency.
	 * @param string $error Error text of the gateway.
	 */
	protected function balance( array $r, $value, string $unit = '', string $error = '' ): string {
		if ( $r['ok'] && is_numeric( $value ) ) {
			$amount = number_format_i18n( (float) $value, floor( (float) $value ) === (float) $value ? 0 : 2 );
			$line   = sprintf(
				/* translators: %1$s: amount, %2$s: currency or unit, may be empty */
				__( 'Balance: %1$s %2$s', 'forms-notifier-for-elementor' ),
				$amount,
				$this->short( $unit )
			);
			return trim( $line );
		}
		return sprintf(
			/* translators: %1$s: error text */
			__( 'The balance could not be read: %1$s', 'forms-notifier-for-elementor' ),
			$this->outcome( $r, false, '', $error )->error
		);
	}

	/** Notice for a gateway whose documentation did not answer everything. */
	protected function unconfirmed_note(): array {
		return [
			'key'   => 'note_unconfirmed',
			'type'  => 'notice',
			'level' => 'warning',
			'desc'  => __( 'Parts of this gateway\'s request format could not be confirmed with its public documentation. Send a test message before you rely on it.', 'forms-notifier-for-elementor' ),
		];
	}
}
