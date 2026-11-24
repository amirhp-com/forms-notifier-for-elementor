<?php
/**
 * Provider chain.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface;
use AmirhpCom\FormsNotifier\Contracts\WhatsAppProviderInterface;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Tries gateways one after the other; shared by the SMS and the WhatsApp channel. */
final class ProviderChain {

	/**
	 * Send through the first gateway that accepts the message.
	 *
	 * @param string   $channel Channel id.
	 * @param string[] $ids     Ordered provider ids.
	 * @param string   $to      E.164.
	 * @param Message  $message Message.
	 */
	public static function run( string $channel, array $ids, string $to, Message $message ): SendResult {
		$tried    = [];
		$failures = [];
		foreach ( array_values( $ids ) as $i => $id ) {
			$provider = Registry::provider( $channel, $id );
			if ( ! $provider ) {
				continue;
			}
			$own     = self::for_provider( $provider, $message, $i === 0 );
			$result  = self::attempt( $provider, $to, $own );
			$tried[] = [
				'provider' => $id,
				'ok'       => $result->ok,
				'pattern'  => ! empty( $own->pattern['code'] ),
				'error'    => $result->error,
			];
			if ( $result->ok ) {
				return $result->with_meta( [ 'chain' => $tried ] );
			}
			$failures[] = $result;
		}

		if ( ! $failures ) {
			return SendResult::failure( $channel, __( 'No provider is configured for this destination.', 'forms-notifier-for-elementor' ), true );
		}
		if ( count( $failures ) === 1 ) {
			return $failures[0]->with_meta( [ 'chain' => $tried ] );
		}

		$permanent = true;
		$retry     = 0;
		$errors    = [];
		$responses = [];
		foreach ( $failures as $failure ) {
			$errors[]  = $failure->provider . ': ' . $failure->error;
			$permanent = $permanent && $failure->permanent;
			if ( $failure->response !== '' ) {
				$responses[] = $failure->provider . ': ' . $failure->response;
			}
			if ( ! $failure->permanent && $failure->retry_after > 0 ) {
				$retry = $retry === 0 ? $failure->retry_after : min( $retry, $failure->retry_after );
			}
		}
		$result              = SendResult::failure( $failures[0]->provider, implode( ' | ', $errors ), $permanent, implode( "\n", $responses ), $failures[0]->http_code );
		$result->retry_after = $retry;
		return $result->with_meta( [ 'chain' => $tried ] );
	}

	/**
	 * The message as one gateway must get it: with its own pattern code, or as normal text. A code in the first line
	 * of a pattern text belongs to the first gateway; the others need a code of their own.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param Message           $message  Message.
	 * @param bool              $primary  First gateway of the chain.
	 */
	private static function for_provider( ProviderInterface $provider, Message $message, bool $primary ): Message {
		$out = clone $message;
		if ( ! $message->pattern ) {
			return $out;
		}
		$codes    = (array) ( $message->pattern['codes'] ?? [] );
		$code     = (string) ( $codes[ $provider->id() ] ?? ( $primary ? $message->pattern['code'] ?? '' : '' ) );
		$supports = ( $provider instanceof SmsProviderInterface && $provider->supports_pattern() )
			|| ( $provider instanceof WhatsAppProviderInterface && $provider->supports_template() );

		$out->pattern = [];
		if ( $code !== '' && $supports ) {
			$out->pattern         = $message->pattern;
			$out->pattern['code'] = $code;
		}
		return $out;
	}

	/**
	 * One gateway; never throws.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param string            $to       E.164.
	 * @param Message           $message  Message for this gateway.
	 */
	private static function attempt( ProviderInterface $provider, string $to, Message $message ): SendResult {
		$id = $provider->id();
		if ( ! $provider instanceof SmsProviderInterface && ! $provider instanceof WhatsAppProviderInterface ) {
			return SendResult::failure( $id, __( 'This gateway cannot send messages.', 'forms-notifier-for-elementor' ), true );
		}
		try {
			if ( ! $provider->is_configured() ) {
				return SendResult::failure( $id, __( 'The gateway is not fully set up.', 'forms-notifier-for-elementor' ), true );
			}
			if ( ! $message->pattern && $message->plain === '' && ! ( $message->attachments || $message->links ) ) {
				return SendResult::failure( $id, __( 'There is no normal text for this gateway.', 'forms-notifier-for-elementor' ), true );
			}
			return $provider->send( $to, $message );
		} catch ( \Throwable $e ) {
			Logger::error( 'gateway', $e->getMessage(), [ 'provider' => $id ] );
			return SendResult::failure( $id, $e->getMessage() );
		}
	}
}
