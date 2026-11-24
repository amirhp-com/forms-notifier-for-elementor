<?php
/**
 * Custom HTTP gateway.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Passthrough;

use AmirhpCom\FormsNotifier\Channels\Sms\CustomRequest;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Any SMS panel with an HTTP API. */
final class CustomHttp extends AbstractSmsProvider {

	use CustomRequest;

	private const BODY = [
		'json'  => '{"to":"{to}","text":"{text}"}',
		'form'  => 'to={to}&text={text}',
		'query' => 'to={to}&text={text}',
	];

	/** Id. */
	public function id(): string {
		return 'custom';
	}

	/** Name. */
	public function label(): string {
		return __( 'Custom HTTP gateway', 'forms-notifier-for-elementor' );
	}

	/** Region. */
	public function region(): string {
		return 'passthrough';
	}

	/** Patterns, when a body for them is set. */
	public function supports_pattern(): bool {
		return $this->option( 'pattern_body' ) !== '';
	}

	/** Fields. */
	public function fields(): array {
		return array_merge(
			$this->request_fields( [ '{to}', '{to_local}', '{to_digits}', '{text}', '{sender}', '{token}' ] ),
			[
				$this->sender_field(),
				$this->field(
					'pattern_body',
					'code',
					__( 'Body for patterns', 'forms-notifier-for-elementor' ),
					[
						'desc'        => __( 'Optional. Used for pattern texts, with the further placeholders {pattern_code}, {var:NAME} and {var:0}, {var:1} for the first and second variable. Without it, the normal text is sent.', 'forms-notifier-for-elementor' ),
						'placeholder' => '{"to":"{to}","template":"{pattern_code}","code":"{var:code}"}',
						'attrs'       => [
							'dir'  => 'ltr',
							'rows' => 6,
						],
					]
				),
			]
		);
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$body = $this->option( 'body' );
		if ( $body === '' ) {
			$body = self::BODY[ $this->option( 'format' ) ] ?? self::BODY['json'];
		}
		return $this->request( $body, $this->values( $to, $text ) );
	}

	/** Pattern. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$values = $this->values( $to, trim( $message->plain ) ) + [ 'pattern_code' => $code ];
		foreach ( $this->named( $vars ) as $name => $value ) {
			$values[ 'var:' . $name ] = $value;
		}
		foreach ( $this->listed( $vars ) as $i => $value ) {
			$values += [ 'var:' . $i => $value ];
		}
		return $this->request( $this->option( 'pattern_body' ), $values );
	}

	/**
	 * Placeholder values.
	 *
	 * @param string $to   E.164.
	 * @param string $text Text.
	 */
	private function values( string $to, string $text ): array {
		return $this->number_values( $to ) + [
			'text'   => $text,
			'sender' => $this->option( 'sender' ),
		];
	}
}
