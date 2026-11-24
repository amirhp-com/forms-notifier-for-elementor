<?php
/**
 * Soroush Plus.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

defined( 'ABSPATH' ) || exit;

/** Soroush Plus bot API, a subset of Telegram's: text, link buttons, files. No topics, no mini app, no discovery. */
final class SoroushChannel extends AbstractBotChannel {

	/** Channel id. */
	public function id(): string {
		return 'soroush';
	}

	/** Label. */
	public function label(): string {
		return __( 'Soroush Plus', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Messages, files and link buttons through a Soroush Plus bot.', 'forms-notifier-for-elementor' );
	}

	/** Icon key. */
	public function icon(): string {
		return 'chat';
	}

	/** Format of the body. */
	public function format(): string {
		return 'telegram_html';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'text',
				'buttons'       => [ 'url' ],
				'discover'      => false,
			]
		);
	}

	/**
	 * Numeric id or @username. Topics do not exist.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$address = parent::normalize_address( preg_replace( '~^(?:https?://)?splus\.ir/~i', '@', trim( $raw ) ), $context );
		return explode( '/', $address )[0];
	}

	/** Connection and files. */
	protected function own_sections(): array {
		return [
			[
				'id'       => 'soroush_connection',
				'title'    => __( 'Connection', 'forms-notifier-for-elementor' ),
				'priority' => 10,
				'fields'   => array_merge(
					$this->connection_fields( __( 'Create a bot with the bot builder of Soroush Plus and copy its token.', 'forms-notifier-for-elementor' ) ),
					[
						$this->base_field(),
						$this->field(
							'address_help',
							'notice',
							'',
							[
								'desc'  => __( 'Receivers: the numeric chat id or the @username of a channel in which the bot is an admin.', 'forms-notifier-for-elementor' ),
								'level' => 'info',
							]
						),
					]
				),
			],
			[
				'id'       => 'soroush_delivery',
				'title'    => __( 'Sending', 'forms-notifier-for-elementor' ),
				'priority' => 30,
				'fields'   => $this->file_fields(),
			],
		];
	}

	/** Admin actions. */
	public function actions(): array {
		return [ 'test_connection' => [ $this, 'action_test_connection' ] ];
	}
}
