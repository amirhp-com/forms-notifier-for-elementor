<?php
/**
 * WhatsApp channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Channels\Sms\ChannelTest;
use AmirhpCom\FormsNotifier\Channels\Sms\ProviderChain;
use AmirhpCom\FormsNotifier\Channels\Sms\ProviderFields;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Sends through the chosen gateway and, when it fails, through the backup gateway. */
final class WhatsAppChannel extends AbstractChannel {

	/** Id. */
	public function id(): string {
		return 'whatsapp';
	}

	/** Name. */
	public function label(): string {
		return __( 'WhatsApp', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'WhatsApp messages through the Cloud API of Meta or another gateway.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'messenger';
	}

	/** Icon. */
	public function icon(): string {
		return 'chat';
	}

	/** Format. */
	public function format(): string {
		return 'whatsapp';
	}

	/** Capabilities. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'phone',
				'submitter'     => true,
				'attachments'   => true,
				'pattern'       => true,
				'max_length'    => 4096,
			]
		);
	}

	/** Sections. */
	protected function own_sections(): array {
		$providers = [
			'options'        => [ '' => __( 'None', 'forms-notifier-for-elementor' ) ],
			'options_source' => 'whatsapp_providers',
		];
		return [
			[
				'id'       => 'whatsapp_gateways',
				'title'    => __( 'Gateway', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					$this->field( 'provider', 'select', __( 'Gateway', 'forms-notifier-for-elementor' ), $providers ),
					$this->field( 'fallback_provider', 'select', __( 'Backup gateway', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Used when the gateway above fails.', 'forms-notifier-for-elementor' ) ] + $providers ),
				],
			],
			ProviderFields::section( 'whatsapp', __( 'Gateway accounts', 'forms-notifier-for-elementor' ) ),
			[
				'id'        => 'whatsapp_test',
				'title'     => __( 'Test', 'forms-notifier-for-elementor' ),
				'priority'  => 80,
				'collapsed' => true,
				'fields'    => [
					$this->field(
						'test_to',
						'tel',
						__( 'Number', 'forms-notifier-for-elementor' ),
						[
							'desc'  => __( 'Empty uses the first receiver.', 'forms-notifier-for-elementor' ),
							'attrs' => [ 'dir' => 'ltr' ],
						]
					),
					$this->field( 'test_text', 'textarea', __( 'Text or pattern text', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Empty sends a short test sentence.', 'forms-notifier-for-elementor' ) ] ),
					$this->field(
						'test_button',
						'action',
						'',
						[
							'action' => [
								'channel' => 'whatsapp',
								'id'      => 'test_whatsapp',
								'label'   => __( 'Send test message', 'forms-notifier-for-elementor' ),
								'send'    => [ 'test_to', 'test_text', 'receivers', 'provider', 'fallback_provider' ],
							],
						]
					),
				],
			],
		];
	}

	/** One of the two gateways must be usable. */
	public function problems(): array {
		$problems = [];
		foreach ( $this->chain() as $id ) {
			$provider = Registry::provider( 'whatsapp', $id );
			if ( $provider->is_configured() ) {
				return [];
			}
			$problems = array_merge( $problems, $provider->problems() );
		}
		return $problems ? $problems : [ __( 'Choose a WhatsApp gateway.', 'forms-notifier-for-elementor' ) ];
	}

	/**
	 * E.164, '' when invalid.
	 *
	 * @param string $raw     Raw number.
	 * @param array  $context Hints: country.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$country = $context['country'] ?? '';
		return (string) Phone::normalize( $raw, is_scalar( $country ) ? (string) $country : '' );
	}

	/** Admin actions. */
	public function actions(): array {
		$test = function ( array $params, string $provider = '' ): array {
			return ChannelTest::run( $this, $params, $provider );
		};
		return [ 'test_whatsapp' => $test ] + ProviderFields::actions( 'whatsapp', $test );
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		try {
			$to = $this->normalize_address( $this->resolve_address( $message->recipient->address ) );
			if ( $to === '' ) {
				return $this->fail( __( 'The receiver has no valid phone number.', 'forms-notifier-for-elementor' ), true );
			}
			$only  = (string) ( $message->meta['provider'] ?? '' );
			$chain = $only === '' ? $this->chain() : [ $only ];
			if ( ! $chain ) {
				return $this->fail( __( 'No WhatsApp gateway is chosen.', 'forms-notifier-for-elementor' ), true );
			}
			$out = clone $message;
			if ( trim( $out->plain ) === '' && ! $out->pattern ) {
				$out->plain = trim( $out->body );
			}
			if ( trim( $out->body ) === '' ) {
				$out->body = $out->plain === '' ? '' : Registry::formatter( 'whatsapp' )->escape( $out->plain );
			}
			return ProviderChain::run( 'whatsapp', $chain, $to, $out );
		} catch ( \Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/**
	 * Chosen gateways that exist.
	 *
	 * @return string[]
	 */
	private function chain(): array {
		$known = Registry::providers( 'whatsapp' );
		$ids   = [ (string) $this->setting( 'provider' ), (string) $this->setting( 'fallback_provider' ) ];
		return array_values( array_unique( array_intersect( $ids, array_map( 'strval', array_keys( $known ) ) ) ) );
	}
}
