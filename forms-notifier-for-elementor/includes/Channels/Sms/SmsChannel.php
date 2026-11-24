<?php
/**
 * SMS channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Routes by country, sends patterns or normal text and tries the backup gateways. */
final class SmsChannel extends AbstractChannel {

	/** Id. */
	public function id(): string {
		return 'sms';
	}

	/** Name. */
	public function label(): string {
		return __( 'SMS', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Text messages through Iranian and international gateways, with a gateway per country and backup gateways.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'sms';
	}

	/** Icon. */
	public function icon(): string {
		return 'phone';
	}

	/** Capabilities. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'phone',
				'submitter'     => true,
				'pattern'       => true,
			]
		);
	}

	/** Sections. */
	protected function own_sections(): array {
		$providers = [
			'options'        => [ '' => __( 'None', 'forms-notifier-for-elementor' ) ],
			'options_source' => 'sms_providers',
		];
		return [
			[
				'id'       => 'sms_routing',
				'title'    => __( 'Gateways and routing', 'forms-notifier-for-elementor' ),
				'desc'     => __( 'A message goes to the first gateway of its country. When that gateway fails, the next one is tried.', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => [
					$this->field( 'default_provider', 'select', __( 'Default gateway', 'forms-notifier-for-elementor' ), $providers ),
					$this->field(
						'default_fallbacks',
						'multiselect',
						__( 'Backup gateways', 'forms-notifier-for-elementor' ),
						[
							'options_source' => 'sms_providers',
							'desc'           => __( 'Tried in this order.', 'forms-notifier-for-elementor' ),
						]
					),
					$this->field(
						'routes',
						'repeater',
						__( 'Routes by country', 'forms-notifier-for-elementor' ),
						[
							'desc'       => __( 'The first route that contains the country of the number is used; other numbers use the default gateway.', 'forms-notifier-for-elementor' ),
							'item_label' => 'countries',
							'fields'     => [
								[
									'key'            => 'countries',
									'type'           => 'multiselect',
									'label'          => __( 'Countries', 'forms-notifier-for-elementor' ),
									'options_source' => 'countries',
								],
								[
									'key'   => 'provider',
									'type'  => 'select',
									'label' => __( 'Gateway', 'forms-notifier-for-elementor' ),
								] + $providers,
								[
									'key'            => 'fallbacks',
									'type'           => 'multiselect',
									'label'          => __( 'Backup gateways', 'forms-notifier-for-elementor' ),
									'options_source' => 'sms_providers',
								],
							],
						]
					),
				],
			],
			ProviderFields::section( 'sms', __( 'Gateway accounts', 'forms-notifier-for-elementor' ) ),
			[
				'id'       => 'sms_text',
				'title'    => __( 'Text', 'forms-notifier-for-elementor' ),
				'priority' => 65,
				'fields'   => [
					$this->field(
						'max_parts',
						'number',
						__( 'Largest number of SMS parts', 'forms-notifier-for-elementor' ),
						[
							'default' => 0,
							'desc'    => __( '0 means no limit. Longer texts are shortened. One part holds 160 Latin or 70 Persian characters.', 'forms-notifier-for-elementor' ),
							'width'   => 'half',
							'attrs'   => [
								'min' => 0,
								'max' => 20,
							],
						]
					),
					$this->field( 'fa_digits', 'toggle', __( 'Write digits in Persian', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Links and email addresses are left as they are. Patterns are not changed.', 'forms-notifier-for-elementor' ) ] ),
					$this->field( 'append_links', 'toggle', __( 'Add links of uploaded files at the end', 'forms-notifier-for-elementor' ), [ 'desc' => __( 'Links make messages longer and more expensive.', 'forms-notifier-for-elementor' ) ] ),
				],
			],
			[
				'id'        => 'sms_test',
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
								'channel' => 'sms',
								'id'      => 'test_sms',
								'label'   => __( 'Send test SMS', 'forms-notifier-for-elementor' ),
								'send'    => [ 'test_to', 'test_text', 'receivers', 'default_provider', 'default_fallbacks', 'routes', 'max_parts', 'fa_digits' ],
							],
						]
					),
				],
			],
		];
	}

	/** At least one gateway must be usable. */
	public function problems(): array {
		$used     = SmsRouter::used();
		$problems = [];
		foreach ( $used as $id ) {
			$provider = Registry::provider( 'sms', $id );
			if ( ! $provider ) {
				continue;
			}
			if ( $provider->is_configured() ) {
				return [];
			}
			$problems = array_merge( $problems, $provider->problems() );
		}
		return $used ? $problems : [ __( 'Choose an SMS gateway.', 'forms-notifier-for-elementor' ) ];
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
		return [ 'test_sms' => $test ] + ProviderFields::actions( 'sms', $test );
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
			$chain = $only === '' ? SmsRouter::chain( $to, $message ) : [ $only ];
			if ( ! $chain ) {
				return $this->fail( __( 'No SMS provider is configured for this destination.', 'forms-notifier-for-elementor' ), true );
			}
			return ProviderChain::run( 'sms', $chain, $to, $this->prepare( $message ) );
		} catch ( \Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/**
	 * Final normal text.
	 *
	 * @param Message $message Message.
	 */
	private function prepare( Message $message ): Message {
		$out  = clone $message;
		$text = trim( $message->plain );
		if ( $text === '' && ! $message->pattern ) {
			$text = trim( $message->body );
		}
		if ( $text !== '' && $this->bool( 'append_links' ) ) {
			foreach ( $message->links as $link ) {
				$url = (string) ( $link['url'] ?? '' );
				if ( $url !== '' && strpos( $text, $url ) === false ) {
					$text .= "\n" . $url;
				}
			}
		}
		if ( $this->bool( 'fa_digits' ) ) {
			$text = self::persian_digits( $text );
		}
		$out->plain       = Segments::limit( $text, Settings::int( 'channels.sms.max_parts' ) );
		$out->links       = [];
		$out->attachments = [];
		return $out;
	}

	/**
	 * Persian digits outside of links and email addresses.
	 *
	 * @param string $text Text.
	 */
	private static function persian_digits( string $text ): string {
		return (string) preg_replace_callback(
			'~(?:https?://|www\.)\S+|\S+@\S+|\d+~iu',
			static function ( array $m ): string {
				return ctype_digit( $m[0] ) ? Text::fa_digits( $m[0] ) : $m[0];
			},
			$text
		);
	}
}
