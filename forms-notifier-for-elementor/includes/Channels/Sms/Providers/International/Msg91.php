<?php
/**
 * MSG91 flow.
 *
 * https://docs.msg91.com/sms/send-sms
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\International;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** MSG91: approved templates only. Errors come with status 200. */
final class Msg91 extends AbstractSmsProvider {

	/** Id. */
	public function id(): string {
		return 'msg91';
	}

	/** Name. */
	public function label(): string {
		return 'MSG91';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://control.msg91.com/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'api_key', 'secret', __( 'Auth key', 'forms-notifier-for-elementor' ) ),
			$this->note( __( 'MSG91 sends approved templates only, so use pattern text. Pattern code is the template id; variable names are case sensitive.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'api_key' ];
	}

	/** Templates. */
	public function supports_pattern(): bool {
		return true;
	}

	/** Normal text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->fail( __( 'MSG91 sends approved templates only. Write the message as pattern text.', 'forms-notifier-for-elementor' ), true );
	}

	/** Template. */
	protected function send_pattern( string $to, string $code, array $vars, Message $message ): SendResult {
		$row = [ 'mobiles' => Phone::digits( $to ) ];
		if ( PatternText::is_positional( $vars ) ) {
			// Numbered variables are called VAR1, VAR2 in the panel.
			foreach ( $this->listed( $vars ) as $i => $value ) {
				$row[ 'VAR' . ( $i + 1 ) ] = $value;
			}
		} else {
			// The number always wins over a variable of the same name.
			$row += $this->named( $vars );
		}
		$r  = $this->post_json(
			'https://control.msg91.com/api/v5/flow',
			[
				'template_id' => $code,
				'short_url'   => '0',
				'recipients'  => [ $row ],
			],
			[ 'authkey' => $this->secret( 'api_key' ) ]
		);
		$ok = $this->dig( $r['json'], 'type' ) === 'success';
		return $this->outcome( $r, $ok, $ok ? $this->text_at( $r['json'], 'message' ) : '', $this->text_at( $r['json'], 'message' ) );
	}
}
