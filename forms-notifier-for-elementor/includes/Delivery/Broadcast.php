<?php
/**
 * Broadcast.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Channels\Bot\Commands;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** Alerts and digests: one text to the receivers of a channel list, sent at once. */
final class Broadcast {

	/**
	 * Receivers by channel of a settings root (alerts.channels, digest.channels); only channels that can send.
	 *
	 * @param string $root Settings path.
	 * @return array<string,array[]> channel id => Receiver[]
	 */
	public static function targets( string $root ): array {
		$out = [];
		foreach ( Settings::arr( $root ) as $id => $entry ) {
			$id      = (string) $id;
			$channel = is_array( $entry ) && ! empty( $entry['enabled'] ) ? Registry::channel( $id ) : null;
			if ( ! $channel || Dispatcher::problem( $channel ) !== '' ) {
				continue;
			}
			if ( ( $channel->capabilities()['receivers'] ?? 'list' ) === 'none' ) {
				$out[ $id ] = [ [ 'address' => Resolver::IMPLICIT ] ];
				continue;
			}
			$receivers = array_filter(
				(array) ( $entry['receivers'] ?? [] ),
				static function ( $receiver ): bool {
					return is_array( $receiver ) && ! empty( $receiver['enabled'] ) && (string) ( $receiver['address'] ?? '' ) !== '';
				}
			);
			if ( $receivers ) {
				$out[ $id ] = array_values( $receivers );
			}
		}
		return $out;
	}

	/**
	 * Send to every receiver in its own language.
	 *
	 * @param array    $targets Output of targets().
	 * @param string   $purpose alert | digest.
	 * @param callable $content Called inside the language of a receiver:
	 *                          ( string $lang, string $channel ): array{template:string,subject:string,extra:array,data:array}.
	 * @param array    $meta    Message meta.
	 * @return array<int,array{channel:string,recipient:string,result:SendResult}>
	 */
	public static function send( array $targets, string $purpose, callable $content, array $meta = [] ): array {
		$out = [];
		foreach ( $targets as $id => $receivers ) {
			$channel = Registry::channel( (string) $id );
			if ( ! $channel ) {
				continue;
			}
			$seen = [];
			foreach ( $receivers as $receiver ) {
				$address = (string) $receiver['address'];
				if ( isset( $seen[ $address ] ) ) {
					continue;
				}
				$seen[ $address ] = true;
				try {
					$message = self::message( $channel, $receiver, $purpose, $content, $meta );
					$result  = Dispatcher::send_now( $message );
				} catch ( \Throwable $e ) {
					$result = SendResult::failure( (string) $id, $e->getMessage() );
				}
				if ( ! $result->ok ) {
					Logger::warning(
						$purpose,
						$result->error === '' ? 'Sending failed.' : $result->error,
						[
							'channel'   => (string) $id,
							'recipient' => Queue::shown_address( (string) $id, $address ),
						]
					);
				}
				$out[] = [
					'channel'   => (string) $id,
					'recipient' => (string) ( $receiver['label'] ?? '' ) === '' ? Queue::shown_address( (string) $id, $address ) : (string) $receiver['label'],
					'result'    => $result,
				];
			}
		}
		return $out;
	}

	/**
	 * Language of a receiver without one: that of the linked WordPress user, else the default.
	 *
	 * @param string $channel Channel id.
	 * @param string $address Address.
	 */
	private static function user_lang( string $channel, string $address ): string {
		$chat = explode( '/', $address )[0];
		// Only a private chat belongs to one person, whose id it has.
		if ( ! in_array( $channel, [ 'telegram', 'bale' ], true ) || ! preg_match( '/^[1-9]\d{0,19}$/', $chat ) ) {
			return I18n::default_lang();
		}
		return Commands::language( $channel, [ 'id' => $chat ] );
	}

	/**
	 * Rendered message for one receiver.
	 *
	 * @param ChannelInterface $channel  Channel.
	 * @param array            $receiver Receiver.
	 * @param string           $purpose  Purpose.
	 * @param callable         $content  See send().
	 * @param array            $meta     Message meta.
	 */
	private static function message( ChannelInterface $channel, array $receiver, string $purpose, callable $content, array $meta ): Message {
		$lang = (string) ( $receiver['lang'] ?? '' );
		$lang = $lang === '' ? self::user_lang( $channel->id(), (string) $receiver['address'] ) : $lang;

		return I18n::with_language(
			$lang,
			static function () use ( $channel, $receiver, $purpose, $content, $meta, $lang ): Message {
				$id    = $channel->id();
				$parts = $content( $lang, $id );

				$recipient       = new Recipient( $id, (string) $receiver['address'], (string) ( $receiver['label'] ?? '' ), $purpose );
				$recipient->lang = $lang;
				$recipient->meta = array_merge( (array) ( $receiver['meta'] ?? [] ), [ 'rid' => (string) ( $receiver['id'] ?? '' ) ] );

				$context            = Context::bare( (array) $parts['extra'], $lang );
				$context->channel   = $id;
				$context->purpose   = $purpose;
				$context->recipient = $recipient;

				$message          = new Message( $id, $recipient );
				$message->purpose = $purpose;
				$message->lang    = $lang;
				$message->format  = $channel->format();
				$message->meta    = $meta;
				$message->subject = Engine::render_inline( (string) $parts['subject'], $context );
				$message->plain   = Engine::render( (string) $parts['template'], $context, 'text' );
				$message->body    = $message->format === 'json' ? '' : Engine::render( (string) $parts['template'], $context, $message->format );
				$message->data    = [
					'event'  => $purpose,
					'site'   => Dispatcher::site(),
					$purpose => (array) $parts['data'],
					'text'   => $message->plain,
				];
				return $message;
			}
		);
	}
}
