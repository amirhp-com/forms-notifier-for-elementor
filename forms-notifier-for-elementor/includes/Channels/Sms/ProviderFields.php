<?php
/**
 * Provider settings.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Contracts\SmsProviderInterface;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;

defined( 'ABSPATH' ) || exit;

/** Settings section and admin actions of the gateways behind a channel. */
final class ProviderFields {

	/**
	 * One section: a gateway picker and the fields of the picked gateway.
	 *
	 * @param string $channel Channel id.
	 * @param string $title   Section title.
	 */
	public static function section( string $channel, string $title ): array {
		$picker = 'channels.' . $channel . '.edit_provider';
		$fields = [
			[
				'key'            => $picker,
				'type'           => 'select',
				'label'          => __( 'Gateway to set up', 'forms-notifier-for-elementor' ),
				'desc'           => __( 'Shows the account fields of one gateway. Which gateway is used is chosen above.', 'forms-notifier-for-elementor' ),
				'default'        => '',
				'options'        => [ '' => __( 'Choose a gateway', 'forms-notifier-for-elementor' ) ],
				'options_source' => $channel . '_providers',
			],
		];
		foreach ( Registry::providers( $channel ) as $id => $provider ) {
			try {
				$fields = array_merge( $fields, self::fields( $channel, $id, $provider, $picker ) );
			} catch ( \Throwable $e ) {
				Logger::error( 'gateway', $e->getMessage(), [ 'provider' => $id ] );
			}
		}
		return [
			'id'       => $channel . '_providers',
			'title'    => $title,
			'priority' => 20,
			'fields'   => $fields,
		];
	}

	/**
	 * Actions test_{provider} and status_{provider}.
	 *
	 * @param string   $channel Channel id.
	 * @param callable $test    Callback ( array $params, string $provider_id ): array ActionResult.
	 * @return array<string,callable>
	 */
	public static function actions( string $channel, callable $test ): array {
		$actions = [];
		foreach ( Registry::providers( $channel ) as $id => $provider ) {
			$actions[ 'test_' . $id ]   = static function ( array $params ) use ( $test, $id ): array {
				return $test( $params, $id );
			};
			$actions[ 'status_' . $id ] = static function () use ( $provider ): array {
				return self::status( $provider );
			};
		}
		return $actions;
	}

	/**
	 * Account status as an ActionResult.
	 *
	 * @param ProviderInterface $provider Provider.
	 */
	private static function status( ProviderInterface $provider ): array {
		$problems = $provider->problems();
		if ( $problems ) {
			return [
				'ok'      => false,
				'message' => implode( ' ', $problems ),
				'level'   => 'warning',
			];
		}
		$status = $provider->status();
		return [
			'ok'      => true,
			'message' => $status === null ? __( 'This gateway does not report its status. Send a test message to check it.', 'forms-notifier-for-elementor' ) : Logger::clean( $status ),
			'level'   => 'info',
		];
	}

	/**
	 * Fields of one gateway, shown while it is picked.
	 *
	 * @param string            $channel  Channel id.
	 * @param string            $id       Provider id.
	 * @param ProviderInterface $provider Provider.
	 * @param string            $picker   Key of the picker field.
	 */
	private static function fields( string $channel, string $id, ProviderInterface $provider, string $picker ): array {
		$prefix = 'channels.' . $channel . '.providers.' . $id . '.';
		$shown  = [
			[
				'key'   => $picker,
				'op'    => 'is',
				'value' => $id,
			],
		];
		$fields = [
			[
				'key'     => $prefix . 'heading',
				'type'    => 'heading',
				'label'   => $provider->label(),
				'desc'    => self::intro( $provider ),
				'show_if' => $shown,
			],
		];
		$send   = [ 'channels.' . $channel . '.test_to', 'channels.' . $channel . '.test_text' ];
		foreach ( $provider->fields() as $field ) {
			$field            = Schema::normalize_field( (array) $field, $prefix );
			$field['show_if'] = array_merge( $shown, $field['show_if'] );
			$fields[]         = $field;
			if ( in_array( $field['type'], Schema::VALUE_TYPES, true ) ) {
				$send[] = $field['key'];
			}
		}
		foreach ( [
			'status' => __( 'Check account', 'forms-notifier-for-elementor' ),
			'test'   => __( 'Send test through this gateway', 'forms-notifier-for-elementor' ),
		] as $action => $label ) {
			$fields[] = [
				'key'     => $prefix . 'action_' . $action,
				'type'    => 'action',
				'label'   => '',
				'width'   => 'half',
				'show_if' => $shown,
				'action'  => [
					'channel' => $channel,
					'id'      => $action . '_' . $id,
					'label'   => $label,
					'send'    => $send,
				],
			];
		}
		return $fields;
	}

	/**
	 * Help line of a gateway.
	 *
	 * @param ProviderInterface $provider Provider.
	 */
	private static function intro( ProviderInterface $provider ): string {
		$parts = [];
		if ( $provider instanceof SmsProviderInterface ) {
			$parts[] = $provider->supports_pattern()
				? __( 'Sends normal text and patterns (templates).', 'forms-notifier-for-elementor' )
				: __( 'Sends normal text only.', 'forms-notifier-for-elementor' );
		}
		$url = $provider->panel_url();
		if ( $url !== '' ) {
			$parts[] = sprintf(
				/* translators: %1$s: opening link tag, %2$s: closing link tag */
				__( '%1$sOpen the website of the provider%2$s', 'forms-notifier-for-elementor' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
		}
		return implode( ' ', $parts );
	}
}
