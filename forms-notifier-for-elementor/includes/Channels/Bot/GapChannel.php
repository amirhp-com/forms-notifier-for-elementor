<?php
/**
 * Gap.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Gap bot platform: text. */
final class GapChannel extends AbstractSimpleMessenger {

	private const URL = 'https://api.gap.im/sendMessage';

	/** Channel id. */
	public function id(): string {
		return 'gap';
	}

	/** Label. */
	public function label(): string {
		return __( 'Gap', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Text messages through a Gap bot.', 'forms-notifier-for-elementor' );
	}

	/**
	 * User id, mobile number with country code, or @bot of a broadcast bot.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		unset( $context );
		$raw = preg_replace( '/[\s()-]+/', '', Text::en_digits( $raw ) );
		return preg_match( '/^(\+?\d{3,20}|@[A-Za-z][A-Za-z0-9_]{2,63})$/', (string) $raw ) ? (string) $raw : '';
	}

	/** Where to get the token. */
	protected function token_help(): string {
		return __( 'Create a bot in the developer panel of Gap (my.gap.im) and copy its token.', 'forms-notifier-for-elementor' );
	}

	/** What a receiver address looks like. */
	protected function address_help(): string {
		return __( 'Receivers: the numeric user id, a mobile number such as +989120000000, or @name of a broadcast bot.', 'forms-notifier-for-elementor' );
	}

	/**
	 * Send the text.
	 *
	 * @param Message $message Message.
	 * @param string  $token   Token.
	 */
	protected function deliver( Message $message, string $token ): SendResult {
		$text = $this->text( $message );
		if ( $text === '' ) {
			return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
		}
		$response = Http::post(
			self::URL,
			[
				'body'        => http_build_query(
					[
						'chat_id' => $this->normalize_address( $message->recipient->address ),
						'type'    => 'text',
						'data'    => $text,
					],
					'',
					'&'
				),
				'headers'     => [
					'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
					'token'        => $token,
				],
				'redirection' => 0,
				'scrub'       => [ $token ],
			]
		);
		$json     = is_array( $response['json'] ) ? $response['json'] : [];
		if ( ! $response['ok'] && is_scalar( $json['error'] ?? null ) && (string) $json['error'] !== '' ) {
			$response['error'] = sanitize_text_field( (string) $json['error'] );
		}
		$result = $this->from_http( $response );
		if ( $result->ok && is_scalar( $json['id'] ?? null ) ) {
			$result->remote_id = (string) $json['id'];
		}
		return $result;
	}
}
