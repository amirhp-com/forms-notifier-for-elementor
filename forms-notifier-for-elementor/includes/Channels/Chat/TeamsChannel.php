<?php
/**
 * Microsoft Teams channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Chat;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Template\Extras;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft Teams through the Workflows trigger "When a Teams webhook request is received". Office 365 connectors are
 * retired. Workflow hosts vary, so any https address is accepted except the retired ones.
 *
 * @see https://learn.microsoft.com/en-us/connectors/teams/
 */
final class TeamsChannel extends AbstractWebhookChat {

	/** Characters of one card: the request must stay below 28 KB, a character may take several bytes. */
	protected const LIMIT = 6000;

	/** Text blocks of one card. */
	private const MAX_BLOCKS = 80;

	/** Channel id. */
	public function id(): string {
		return 'teams';
	}

	/** Display name. */
	public function label(): string {
		return 'Microsoft Teams';
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Post cards to Teams channels and chats through a workflow webhook.', 'forms-notifier-for-elementor' );
	}

	/** Icon. */
	public function icon(): string {
		return 'teams';
	}

	/** Buttons are card actions. */
	protected function buttons_as_text(): bool {
		return false;
	}

	/** How to get the address. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'teams_help',
				'title'  => __( 'Webhook address', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field( 'help', 'notice', __( 'In Teams open the menu of the channel, choose Workflows and the template that posts to the channel when a webhook request is received. Allow anyone to trigger it and copy the address. Old connector addresses (webhook.office.com) do not work any more.', 'forms-notifier-for-elementor' ) ),
				],
			],
		];
	}

	/**
	 * URL check.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param string $path   Path.
	 */
	protected function accepts( string $scheme, string $host, string $path ): bool {
		return $scheme === 'https' && strpos( $host, '.' ) !== false && ! preg_match( '/(^|\.)webhook\.office(365)?\.com$/', $host ) && strlen( $path ) > 1;
	}

	/**
	 * Post one part.
	 *
	 * @param string  $url     Webhook URL.
	 * @param string  $text    Text.
	 * @param Message $message Message.
	 * @param bool    $last    Last part.
	 */
	protected function post( string $url, string $text, Message $message, bool $last ): SendResult {
		$rtl     = I18n::is_rtl( $message->lang === '' ? I18n::current() : $message->lang );
		$content = [
			'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
			'type'    => 'AdaptiveCard',
			'version' => '1.4',
			'body'    => self::blocks( $text ),
		];
		if ( $rtl ) {
			$content['rtl'] = true;
		}
		$actions = [];
		foreach ( $last ? Extras::url_buttons( $message, 6 ) : [] as $button ) {
			$actions[] = [
				'type'  => 'Action.OpenUrl',
				'title' => Text::truncate( $button['text'], 80 ),
				'url'   => $button['value'],
			];
		}
		if ( $actions ) {
			$content['actions'] = $actions;
		}

		$response = $this->request(
			$url,
			[
				'type'        => 'message',
				'attachments' => [
					[
						'contentType' => 'application/vnd.microsoft.card.adaptive',
						'contentUrl'  => null,
						'content'     => $content,
					],
				],
			]
		);
		$json     = is_array( $response['json'] ) ? $response['json'] : [];
		return $this->outcome( $response, is_scalar( $json['error']['message'] ?? null ) ? (string) $json['error']['message'] : '' );
	}

	/**
	 * One text block per line: a card does not break lines at a single newline.
	 *
	 * @param string $text Text.
	 */
	private static function blocks( string $text ): array {
		// Code blocks stay in one piece.
		$lines = strpos( $text, '```' ) === false ? explode( "\n", $text ) : [ $text ];
		if ( count( $lines ) > self::MAX_BLOCKS ) {
			$rest  = array_splice( $lines, self::MAX_BLOCKS - 1 );
			$lines = array_merge( $lines, [ implode( "\n\n", $rest ) ] );
		}
		$out   = [];
		$space = false;
		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				$space = true;
				continue;
			}
			$out[] = [
				'type'    => 'TextBlock',
				'text'    => $line,
				'wrap'    => true,
				'spacing' => $space ? 'Medium' : 'None',
			];
			$space = false;
		}
		return $out;
	}
}
