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
use AmirhpCom\FormsNotifier\Core\Settings;
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
			$result  = self::after_timeout( $channel, self::attempt( $provider, $to, $own ) );
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
			if ( ! empty( $result->meta['ambiguous'] ) ) {
				// The gateway may have taken the message: another one must not send it again.
				break;
			}
		}

		if ( ! $failures ) {
			return SendResult::failure( $channel, __( 'No provider is configured for this destination.', 'forms-notifier-for-elementor' ), true );
		}
		if ( count( $failures ) === 1 ) {
			return $failures[0]->with_meta( [ 'chain' => $tried ] );
		}

		$ambiguous = ! empty( end( $failures )->meta['ambiguous'] );
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
		$result              = SendResult::failure( $failures[0]->provider, implode( ' | ', $errors ), $permanent || $ambiguous, implode( "\n", $responses ), $failures[0]->http_code );
		$result->retry_after = $retry;
		$result->with_meta( [ 'chain' => $tried ] );
		return $ambiguous ? $result->with_meta( [ 'ambiguous' => true ] ) : $result;
	}

	/**
	 * The request was sent but no answer came in time: the gateway may have taken the message. By default this is
	 * retried like any other temporary error. With "Retry after a timeout" off it becomes final and unclear, so neither
	 * a retry nor a backup gateway can send the message a second time.
	 *
	 * @param string     $channel Channel id.
	 * @param SendResult $result  Result of one gateway.
	 */
	private static function after_timeout( string $channel, SendResult $result ): SendResult {
		if ( $result->ok || $result->permanent || ! self::is_unclear_timeout( $result ) ) {
			return $result;
		}
		$result->with_meta( [ 'timeout' => true ] );
		if ( Settings::bool( 'channels.' . $channel . '.retry_timeout', true ) ) {
			return $result;
		}
		$result->permanent   = true;
		$result->retry_after = 0;
		$result->error       = trim( $result->error . ' ' . __( 'Not sent again: the gateway may have received the message.', 'forms-notifier-for-elementor' ) );
		return $result->with_meta( [ 'ambiguous' => true ] );
	}

	/**
	 * A transport error that timed out while waiting for the answer. Timeouts while resolving the name or opening the
	 * connection happen before anything was sent and are not unclear.
	 *
	 * @param SendResult $result Failed result.
	 */
	public static function is_unclear_timeout( SendResult $result ): bool {
		if ( $result->http_code !== 0 ) {
			return false;
		}
		$error = $result->error;
		return preg_match( '/timed?[ -]?out/i', $error ) === 1
			&& preg_match( '/\b(connection|connect|resolving|resolve)\b[^.:;]{0,12}\btime(d)?[ -]?out/i', $error ) !== 1;
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
