<?php
/**
 * Telegram.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

defined( 'ABSPATH' ) || exit;

/** Telegram bot channel. */
final class TelegramChannel extends AbstractUpdatesChannel {

	/** Channel id. */
	public function id(): string {
		return 'telegram';
	}

	/** Label. */
	public function label(): string {
		return __( 'Telegram', 'forms-notifier-for-elementor' );
	}

	/** Description. */
	public function description(): string {
		return __( 'Messages, files and buttons in people, groups, topics and channels of Telegram.', 'forms-notifier-for-elementor' );
	}

	/** Format of the body. */
	public function format(): string {
		return $this->setting( 'parse_mode', 'HTML' ) === 'MarkdownV2' ? 'telegram_md' : 'telegram_html';
	}

	/** Where to get a token. */
	protected function token_help(): string {
		return __( 'Open @BotFather in Telegram, send /newbot and copy the token it gives you.', 'forms-notifier-for-elementor' );
	}

	/** How chats are found. */
	protected function discovery_help(): string {
		return __( 'People: they press Start in the bot. Groups: add the bot and send /id once, in every topic you want to use; a bot that is not an admin only sees commands. Channels: add the bot as an admin and publish one post.', 'forms-notifier-for-elementor' );
	}

	/** Sending options. */
	protected function delivery_fields(): array {
		return [
			$this->field(
				'parse_mode',
				'select',
				__( 'Text format', 'forms-notifier-for-elementor' ),
				[
					'default' => 'HTML',
					'options' => [
						'HTML'       => __( 'HTML (recommended)', 'forms-notifier-for-elementor' ),
						'MarkdownV2' => 'MarkdownV2',
					],
				]
			),
			$this->field( 'disable_preview', 'toggle', __( 'No link previews', 'forms-notifier-for-elementor' ), [ 'default' => true ] ),
			$this->field( 'silent', 'toggle', __( 'Send without sound', 'forms-notifier-for-elementor' ), [ 'default' => false ] ),
			$this->field(
				'protect',
				'toggle',
				__( 'Protect the content', 'forms-notifier-for-elementor' ),
				[
					'default' => false,
					'desc'    => __( 'Messages cannot be forwarded or saved.', 'forms-notifier-for-elementor' ),
				]
			),
		];
	}

	/** Mini app options. */
	protected function app_fields(): array {
		return [
			$this->field(
				'miniapp_short_name',
				'text',
				__( 'Mini App short name', 'forms-notifier-for-elementor' ),
				[
					'desc'  => __( 'The short name you gave the app in @BotFather (/newapp). Without it, buttons in groups open the main app of the bot.', 'forms-notifier-for-elementor' ),
					'attrs' => [
						'dir'       => 'ltr',
						'maxlength' => 64,
					],
				]
			),
			$this->field(
				'menu_button',
				'toggle',
				__( 'Menu button opens the Mini App', 'forms-notifier-for-elementor' ),
				[ 'default' => false ]
			),
		];
	}

	/** Options a form can override. */
	protected function form_options(): array {
		return [
			'silent'          => __( 'Send without sound', 'forms-notifier-for-elementor' ),
			'disable_preview' => __( 'No link previews', 'forms-notifier-for-elementor' ),
			'protect'         => __( 'Protect the content', 'forms-notifier-for-elementor' ),
		];
	}
}
