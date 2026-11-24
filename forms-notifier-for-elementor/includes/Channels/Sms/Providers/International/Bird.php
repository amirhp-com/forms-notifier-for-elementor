<?php
/**
 * Bird SMS.
 *
 * https://bird.com/docs/guides/sms/sending-sms
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Bird SMS API. */
final class Bird extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'bird';
	}

	/** Name. */
	public function label(): string {
		return 'Bird';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://app.bird.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'API key', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Starts with bk_ and the region.', 'forms-notifier-for-elementor' ) ] ),
			$this->field(
				'region',
				'select',
				__( 'Region', 'forms-notifier-for-elementor' ),
				[
					'default' => 'eu1',
					'options' => [
						'eu1' => __( 'Europe (eu1)', 'forms-notifier-for-elementor' ),
						'us1' => __( 'United States (us1)', 'forms-notifier-for-elementor' ),
					],
				]
			),
			$this->sender_field( __( 'Sender name or number', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key', 'sender' ];
	}

	/** Normal text. The documentation lists the category without all of its values; "transactional" fits notifications. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$region = $this->option( 'region' ) === 'us1' ? 'us1' : 'eu1';
		if ( preg_match( '/^bk_(us1|eu1)_/', $this->secret( 'api_key' ), $m ) ) {
			$region = $m[1];
		}
		$r  = $this->post_json(
			'https://' . $region . '.platform.bird.com/v1/sms/messages',
			[
				'to'       => $to,
				'from'     => $this->option( 'sender' ),
				'text'     => $text,
				'category' => 'transactional',
			],
			[ 'Authorization' => 'Bearer ' . $this->secret( 'api_key' ) ]
		);
		$id = $this->text_at( $r['json'], 'id' );
		return $this->outcome( $r, $id !== '', $id, $this->first_text( $r['json'], [ 'error.message', 'error.name', 'error.code' ] ) );
	}
}
