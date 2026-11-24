<?php
/**
 * Bale.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

defined( 'ABSPATH' ) || exit;

/** Bale bot channel. Bale has no parse mode, preview or silent options. */
final class BaleChannel extends AbstractUpdatesChannel {

	/** Channel id. */
	public function id(): string {
		return 'bale';
	}

	/** Label. */
	public function label(): string {
		return __( 'Bale', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Messages, files and buttons in people, groups and channels of Bale.', 'forms-notifier-for-elementor' );
	}

	/** Format of the body. */
	public function format(): string {
		return 'bale_md';
	}

	/** Where to get a token. */
	protected function token_help(): string {
		return __( 'Open @botfather in Bale, create a bot and copy the token it gives you.', 'forms-notifier-for-elementor' );
	}

	/** How chats are found. */
	protected function discovery_help(): string {
		return __( 'People: they press Start in the bot. Groups: add the bot and send /id once. Channels cannot be found: add the bot as an admin and type the @username or the id of the channel.', 'forms-notifier-for-elementor' );
	}

	/** Sending options. */
	protected function delivery_fields(): array {
		return [];
	}

	/** Mini app options. */
	protected function app_fields(): array {
		return [
			$this->field(
				'miniapp_short_name',
				'text',
				__( 'Mini App short name', 'forms-notifier-for-elementor' ),
				[
					'desc'  => __( 'The short name you gave the app in @botfather, if you made one.', 'forms-notifier-for-elementor' ),
					'attrs' => [
						'dir'       => 'ltr',
						'maxlength' => 64,
					],
				]
			),
			$this->field(
				'webapp_as_link',
				'toggle',
				__( 'Open the Mini App through a link', 'forms-notifier-for-elementor' ),
				[
					'default' => false,
					'desc'    => __( 'For old Bale apps that do not know Mini App buttons.', 'forms-notifier-for-elementor' ),
				]
			),
		];
	}
}
