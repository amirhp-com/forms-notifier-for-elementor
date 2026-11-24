<?php
/**
 * Send result.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

use AmirhpCom\FormsNotifier\Core\Logger;

defined( 'ABSPATH' ) || exit;

/** Outcome of one send attempt. */
final class SendResult {

	/** Longest provider response kept. */
	private const MAX_RESPONSE = 2000;

	/** @var bool */
	public bool $ok = false;

	/** @var string channel id or provider id that answered */
	public string $provider = '';

	/** @var string short provider response for the log, secrets scrubbed */
	public string $response = '';

	/** @var string human readable error */
	public string $error = '';

	/** @var bool true = do not retry */
	public bool $permanent = false;

	/** @var int seconds the remote asked us to wait, 0 = use backoff */
	public int $retry_after = 0;

	/** @var string remote message id(s), comma separated */
	public string $remote_id = '';

	/** @var int */
	public int $http_code = 0;

	/** @var array parts sent, provider chain tried, migrate_to_chat_id, etc. */
	public array $meta = [];

	/**
	 * @param string $provider  Who answered.
	 * @param string $response  Provider response.
	 * @param string $remote_id Remote message id.
	 */
	public static function success( string $provider, string $response = '', string $remote_id = '' ): self {
		$r            = new self();
		$r->ok        = true;
		$r->provider  = $provider;
		$r->response  = self::clip( $response );
		$r->remote_id = $remote_id;
		return $r;
	}

	/**
	 * @param string $provider  Who answered.
	 * @param string $error     Human readable error.
	 * @param bool   $permanent Retrying is pointless.
	 * @param string $response  Provider response.
	 * @param int    $http_code HTTP status.
	 */
	public static function failure( string $provider, string $error, bool $permanent = false, string $response = '', int $http_code = 0 ): self {
		$r            = new self();
		$r->provider  = $provider;
		$r->error     = self::clip( $error );
		$r->permanent = $permanent;
		$r->response  = self::clip( $response );
		$r->http_code = $http_code;
		return $r;
	}

	/**
	 * Merge extra data into $meta.
	 *
	 * @param array $meta Extra data.
	 */
	public function with_meta( array $meta ): self {
		$this->meta = array_merge( $this->meta, $meta );
		return $this;
	}

	/** JSON-safe array. */
	public function to_array(): array {
		return [
			'ok'          => $this->ok,
			'provider'    => $this->provider,
			'response'    => $this->response,
			'error'       => $this->error,
			'permanent'   => $this->permanent,
			'retry_after' => $this->retry_after,
			'remote_id'   => $this->remote_id,
			'http_code'   => $this->http_code,
			'meta'        => $this->meta,
		];
	}

	/**
	 * Remove secrets and shorten.
	 *
	 * @param string $text Text to store.
	 */
	private static function clip( string $text ): string {
		return mb_substr( Logger::clean( $text ), 0, self::MAX_RESPONSE );
	}
}
