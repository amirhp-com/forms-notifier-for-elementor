<?php
/**
 * NetGSM.
 *
 * Field names from the SDK of the vendor (npm package @netgsm/sms); the answer was seen for errors only.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** NetGSM REST v2, Turkish numbers. */
final class NetGsm extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'netgsm';
	}

	/** Name. */
	public function label(): string {
		return 'NetGSM';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://www.netgsm.com.tr/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'username', 'text', __( 'User code', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'password', 'secret', __( 'API password', 'forms-notifier-for-elementor' ) ),
			$this->sender_field( __( 'Message header', 'forms-notifier-for-elementor' ) ),
			$this->unconfirmed_note(),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'username', 'password', 'sender' ];
	}

	/** Countries. */
	public function countries(): array {
		return [ 'TR' ];
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		$r    = $this->post_json(
			'https://api.netgsm.com.tr/sms/rest/v2/send',
			[
				'msgheader' => $this->option( 'sender' ),
				'encoding'  => 'TR',
				'messages'  => [
					[
						'msg' => $text,
						'no'  => substr( Phone::digits( $to ), 2 ),
					],
				],
			],
			[ 'Authorization' => $this->basic( $this->option( 'username' ), $this->secret( 'password' ) ) ]
		);
		$code = $this->text_at( $r['json'], 'code' );
		$id   = $this->text_at( $r['json'], 'jobid' );
		return $this->outcome( $r, $code === '00' && $id !== '', $id, $code === '00' ? '' : trim( $code . ' ' . $this->text_at( $r['json'], 'description' ) ) );
	}
}
