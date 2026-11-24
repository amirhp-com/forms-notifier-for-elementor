<?php
/**
 * Provider base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels;

use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Shared provider behaviour. */
abstract class AbstractProvider implements ProviderInterface {

	/** 'sms' | 'whatsapp' | third party channel id */
	abstract protected function channel(): string;

	/** Region of the gateway. */
	public function region(): string {
		return 'any';
	}

	/** Translated problems. */
	public function problems(): array {
		if ( $this->is_configured() ) {
			return [];
		}
		return [
			sprintf(
				/* translators: %1$s: gateway name */
				__( '%1$s is not fully set up.', 'forms-notifier-for-elementor' ),
				$this->label()
			),
		];
	}

	/** Account status line, null = not supported. */
	public function status(): ?string {
		return null;
	}

	/** Panel or docs URL. */
	public function panel_url(): string {
		return '';
	}

	/** "channels.{channel}.providers.{id}" */
	protected function base_key(): string {
		return 'channels.' . $this->channel() . '.providers.' . $this->id();
	}

	/**
	 * Provider setting.
	 *
	 * @param string $key     Relative key.
	 * @param mixed  $default Returned when unknown.
	 * @return mixed
	 */
	protected function setting( string $key, $default = '' ) {
		return Settings::get( $this->base_key() . '.' . $key, $default );
	}

	/**
	 * Provider secret.
	 *
	 * @param string $key Relative key.
	 */
	protected function secret( string $key ): string {
		return Settings::secret( $this->base_key() . '.' . $key );
	}

	/**
	 * Success result.
	 *
	 * @param string $response  Provider response.
	 * @param string $remote_id Remote message id.
	 */
	protected function ok( string $response = '', string $remote_id = '' ): SendResult {
		return SendResult::success( $this->id(), $response, $remote_id );
	}

	/**
	 * Failure result.
	 *
	 * @param string $error     Error.
	 * @param bool   $permanent Retrying is pointless.
	 * @param string $response  Provider response.
	 * @param int    $http_code HTTP status.
	 */
	protected function fail( string $error, bool $permanent = false, string $response = '', int $http_code = 0 ): SendResult {
		return SendResult::failure( $this->id(), $error, $permanent, $response, $http_code );
	}

	/**
	 * Standard mapping of an Http response.
	 *
	 * @param array  $response Output of Http::request().
	 * @param string $ok_note  Logged instead of the body on success.
	 */
	protected function from_http( array $response, string $ok_note = '' ): SendResult {
		return AbstractChannel::map_http( $this->id(), $response, $ok_note );
	}

	/**
	 * Build a field array.
	 *
	 * @param string $key   Relative key.
	 * @param string $type  Field type.
	 * @param string $label Translated label.
	 * @param array  $extra Other field keys.
	 */
	protected function field( string $key, string $type, string $label, array $extra = [] ): array {
		return array_merge(
			[
				'key'   => $key,
				'type'  => $type,
				'label' => $label,
			],
			$extra
		);
	}

	/**
	 * A 'notice' field.
	 *
	 * @param string $text Translated text.
	 */
	protected function note( string $text ): array {
		return [
			'key'  => 'note_' . substr( md5( $text ), 0, 8 ),
			'type' => 'notice',
			'desc' => $text,
		];
	}

	/**
	 * Pattern vars as an ordered list (token1/token2 style APIs).
	 *
	 * @param Message $m Message.
	 * @return string[]
	 */
	protected function pattern_values( Message $m ): array {
		return array_map( [ self::class, 'scalar' ], array_values( (array) ( $m->pattern['vars'] ?? [] ) ) );
	}

	/**
	 * Pattern vars as name => value (named-variable APIs); numeric keys are kept as "0","1"...
	 *
	 * @param Message $m Message.
	 * @return array<string,string>
	 */
	protected function pattern_map( Message $m ): array {
		$out = [];
		foreach ( (array) ( $m->pattern['vars'] ?? [] ) as $name => $value ) {
			$out[ (string) $name ] = self::scalar( $value );
		}
		return $out;
	}

	/**
	 * String of a variable.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
