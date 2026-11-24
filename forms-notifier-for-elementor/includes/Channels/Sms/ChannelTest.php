<?php
/**
 * Test messages.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** "Send test" of the SMS and WhatsApp channels, with a typed number and an optional text or pattern text. */
final class ChannelTest {

	/**
	 * Send a test and describe every gateway that was tried.
	 *
	 * @param ChannelInterface $channel  Channel.
	 * @param array            $params   Optional 'to' and 'text'; else the test settings of the channel.
	 * @param string           $provider Only this gateway, '' = the normal chain.
	 * @return array ActionResult
	 */
	public static function run( ChannelInterface $channel, array $params, string $provider = '' ): array {
		$id  = $channel->id();
		$raw = self::param( $params, 'to', "channels.$id.test_to" );
		if ( $raw === '' ) {
			foreach ( Settings::arr( "channels.$id.receivers" ) as $receiver ) {
				if ( is_array( $receiver ) && ! empty( $receiver['enabled'] ) ) {
					$raw = (string) ( $receiver['address'] ?? '' );
					break;
				}
			}
		}
		if ( $raw === '' ) {
			return self::result( false, __( 'Type a number or add a receiver first.', 'forms-notifier-for-elementor' ), 'warning' );
		}
		$to = $channel->normalize_address( $raw );
		if ( $to === '' ) {
			return self::result( false, __( 'The number is not valid.', 'forms-notifier-for-elementor' ) );
		}

		$message        = self::message( $channel, $to, self::param( $params, 'text', "channels.$id.test_text" ) );
		$message->meta  = [ 'test' => true ] + ( $provider === '' ? [] : [ 'provider' => $provider ] );
		$result         = Dispatcher::send_now( $message );
		$out            = $result->ok
			? self::result( true, __( 'The gateway accepted the test message.', 'forms-notifier-for-elementor' ), 'success' )
			: self::result( false, $result->error === '' ? __( 'The test message could not be sent.', 'forms-notifier-for-elementor' ) : $result->error );
		$out['data']    = [
			'remote_id' => $result->remote_id,
			'http_code' => $result->http_code,
		];
		$out['columns'] = [
			[
				'key'   => 'provider',
				'label' => __( 'Gateway', 'forms-notifier-for-elementor' ),
			],
			[
				'key'   => 'result',
				'label' => __( 'Result', 'forms-notifier-for-elementor' ),
			],
		];
		$out['rows']    = [];
		foreach ( (array) ( $result->meta['chain'] ?? [] ) as $try ) {
			$gateway       = Registry::provider( $id, (string) $try['provider'] );
			$out['rows'][] = [
				'provider' => $gateway ? $gateway->label() : $try['provider'],
				'result'   => $try['ok'] ? __( 'Accepted', 'forms-notifier-for-elementor' ) : $try['error'],
			];
		}
		return $out;
	}

	/**
	 * Build the message.
	 *
	 * @param ChannelInterface $channel Channel.
	 * @param string           $to      E.164.
	 * @param string           $text    Text or pattern text, may be empty.
	 */
	private static function message( ChannelInterface $channel, string $to, string $text ): Message {
		if ( trim( $text ) === '' ) {
			/* translators: %1$s: site name */
			$text = sprintf( __( 'Test message from %1$s.', 'forms-notifier-for-elementor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}
		$context          = Context::bare();
		$context->channel = $channel->id();
		$context->purpose = 'test';
		$pattern          = PatternText::parse( $text );
		$message          = new Message( $channel->id(), new Recipient( $channel->id(), $to, '', 'test' ) );
		$message->purpose = 'test';
		$message->format  = $channel->format();
		if ( $pattern ) {
			$vars = [];
			foreach ( $pattern['vars'] as $name => $value ) {
				$vars[ $name ] = Engine::render_inline( $value, $context );
			}
			$message->pattern = [
				'code'  => $pattern['code'],
				'vars'  => $vars,
				'lang'  => $pattern['lang'],
				'codes' => $pattern['codes'],
			];

			$text = $pattern['text'];
		}
		if ( $text !== '' ) {
			$message->plain = Engine::render( $text, $context, 'text' );
			$message->body  = Engine::render( $text, $context, $message->format );
		}
		return $message;
	}

	/**
	 * Request parameter or stored value.
	 *
	 * @param array  $params Params.
	 * @param string $key    Param.
	 * @param string $path   Settings path.
	 */
	private static function param( array $params, string $key, string $path ): string {
		$value = isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? sanitize_textarea_field( (string) $params[ $key ] ) : '';
		return $value === '' ? trim( Settings::str( $path ) ) : trim( $value );
	}

	/**
	 * ActionResult.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	private static function result( bool $ok, string $message, string $level = 'danger' ): array {
		return [
			'ok'      => $ok,
			'message' => $message,
			'level'   => $level,
		];
	}
}
