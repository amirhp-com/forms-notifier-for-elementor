<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Bot API client for telegram/bale: no request is made yet. */
class BotClient {

	public const MAX_TEXT     = 4096;
	public const MAX_CAPTION  = 1024;
	public const MAX_CALLBACK = 64;
	public const MAX_GROUP    = 10;

	/** @var string */
	private $platform;

	/** @var string|null */
	private $token;

	/** @var string|null */
	private $base;

	/**
	 * $token/$base override settings (unsaved values, tests).
	 *
	 * @param string      $platform 'telegram' | 'bale'.
	 * @param string|null $token    Bot token.
	 * @param string|null $base     API base.
	 */
	public function __construct( string $platform, ?string $token = null, ?string $base = null ) {
		$this->platform = $platform;
		$this->token    = $token;
		$this->base     = $base;
	}

	/**
	 * Instance from settings.
	 *
	 * @param string $platform Platform.
	 */
	public static function for_platform( string $platform ): self {
		return new self( $platform );
	}

	/**
	 * Public API of a platform.
	 *
	 * @param string $platform Platform.
	 */
	public static function default_base( string $platform ): string {
		return $platform === 'bale' ? 'https://tapi.bale.ai' : 'https://api.telegram.org';
	}

	/** Platform id. */
	public function platform(): string {
		return $this->platform;
	}

	/** Bale. */
	public function is_bale(): bool {
		return $this->platform === 'bale';
	}

	/** A token is available. */
	public function has_token(): bool {
		return ( $this->token ?? Settings::secret( "channels.{$this->platform}.token" ) ) !== '';
	}

	/** Effective API base without trailing slash. */
	public function base(): string {
		$base = $this->base ?? Settings::str( "channels.{$this->platform}.api_base" );
		$base = $base === '' ? self::default_base( $this->platform ) : $base;
		return untrailingslashit( (string) apply_filters( 'fnfe_bot_api_base', $base, $this->platform ) );
	}

	/** Upload limit. */
	public function max_upload_bytes(): int {
		return (int) apply_filters( 'fnfe_bot_max_upload_bytes', 50 * MB_IN_BYTES, $this->platform );
	}

	/**
	 * Call one Bot API method.
	 *
	 * @param string $method  Method.
	 * @param array  $params  Parameters.
	 * @param array  $files   field => [ 'path' =>, 'name' =>, 'mime' => ].
	 * @param int    $timeout Seconds.
	 * @return array{ok:bool,result:mixed,error:string,code:int,retry_after:int,migrate_to:string}
	 */
	public function call( string $method, array $params = [], array $files = [], int $timeout = 0 ): array {
		unset( $method, $params, $files, $timeout );
		return [
			'ok'          => false,
			'result'      => null,
			'error'       => __( 'Not available yet.', 'forms-notifier-for-elementor' ),
			'code'        => 0,
			'retry_after' => 0,
			'migrate_to'  => '',
		];
	}

	/**
	 * "chat", "@channel", "chat/topic", "chat_topic" -> [ 'chat' =>, 'thread' => ] or null.
	 *
	 * @param string $raw Raw address.
	 */
	public static function parse_address( string $raw ): ?array {
		$raw = trim( Text::en_digits( $raw ) );
		if ( ! preg_match( '~^(@[A-Za-z][A-Za-z0-9_]{3,}|-?\d+)(?:[/_](\d+))?$~', $raw, $m ) ) {
			return null;
		}
		return [
			'chat'   => $m[1],
			'thread' => $m[2] ?? '',
		];
	}

	/**
	 * Canonical address.
	 *
	 * @param string     $chat   Chat id.
	 * @param string|int $thread Topic id.
	 */
	public static function format_address( string $chat, $thread = '' ): string {
		return (string) $thread === '' || (string) $thread === '0' ? $chat : $chat . '/' . $thread;
	}

	/**
	 * Canonical address, '' when invalid.
	 *
	 * @param string $raw Raw address.
	 */
	public static function normalize_address( string $raw ): string {
		$parsed = self::parse_address( $raw );
		return $parsed ? self::format_address( $parsed['chat'], $parsed['thread'] ) : '';
	}

	/**
	 * Send text.
	 *
	 * @param string $address Address.
	 * @param string $text    Text.
	 * @param array  $opts    Opts.
	 */
	public function send_message( string $address, string $text, array $opts = [] ): array {
		unset( $address, $text, $opts );
		return $this->call( '' );
	}

	/**
	 * Send one file.
	 *
	 * @param string $address Address.
	 * @param string $kind    Kind.
	 * @param array  $file    File.
	 * @param string $caption Caption.
	 * @param array  $opts    Opts.
	 */
	public function send_file( string $address, string $kind, array $file, string $caption = '', array $opts = [] ): array {
		unset( $address, $kind, $file, $caption, $opts );
		return $this->call( '' );
	}

	/**
	 * Send 2..10 files as an album.
	 *
	 * @param string $address Address.
	 * @param string $kind    Kind.
	 * @param array  $files   Files.
	 * @param string $caption Caption.
	 * @param array  $opts    Opts.
	 */
	public function send_media_group( string $address, string $kind, array $files, string $caption = '', array $opts = [] ): array {
		unset( $address, $kind, $files, $caption, $opts );
		return $this->call( '' );
	}

	/**
	 * Replace a message text.
	 *
	 * @param string $chat_id    Chat id.
	 * @param int    $message_id Message id.
	 * @param string $text       Text.
	 * @param array  $opts       Opts.
	 */
	public function edit_message_text( string $chat_id, int $message_id, string $text, array $opts = [] ): array {
		unset( $chat_id, $message_id, $text, $opts );
		return $this->call( '' );
	}

	/**
	 * Replace a keyboard.
	 *
	 * @param string $chat_id      Chat id.
	 * @param int    $message_id   Message id.
	 * @param array  $reply_markup Reply markup.
	 */
	public function edit_reply_markup( string $chat_id, int $message_id, array $reply_markup = [] ): array {
		unset( $chat_id, $message_id, $reply_markup );
		return $this->call( '' );
	}

	/**
	 * Answer a button press.
	 *
	 * @param string $callback_query_id Callback query id.
	 * @param string $text              Text.
	 * @param bool   $alert             Alert.
	 */
	public function answer_callback( string $callback_query_id, string $text = '', bool $alert = false ): array {
		unset( $callback_query_id, $text, $alert );
		return $this->call( '' );
	}

	/** Bot account. */
	public function get_me(): array {
		return $this->call( '' );
	}

	/**
	 * One chat.
	 *
	 * @param string $chat_id Chat id.
	 */
	public function get_chat( string $chat_id ): array {
		unset( $chat_id );
		return $this->call( '' );
	}

	/**
	 * Pending updates.
	 *
	 * @param int   $offset  Offset.
	 * @param int   $limit   Limit.
	 * @param int   $timeout Timeout.
	 * @param array $allowed Allowed.
	 */
	public function get_updates( int $offset = 0, int $limit = 100, int $timeout = 0, array $allowed = [] ): array {
		unset( $offset, $limit, $timeout, $allowed );
		return $this->call( '' );
	}

	/**
	 * Set the webhook.
	 *
	 * @param string $url          Url.
	 * @param string $secret_token Secret token.
	 * @param array  $allowed      Allowed.
	 */
	public function set_webhook( string $url, string $secret_token = '', array $allowed = [] ): array {
		unset( $url, $secret_token, $allowed );
		return $this->call( '' );
	}

	/**
	 * Remove the webhook.
	 *
	 * @param bool $drop_pending Drop pending.
	 */
	public function delete_webhook( bool $drop_pending = false ): array {
		unset( $drop_pending );
		return $this->call( '' );
	}

	/** Webhook status. */
	public function get_webhook_info(): array {
		return $this->call( '' );
	}

	/**
	 * Register commands.
	 *
	 * @param array  $commands      Commands.
	 * @param string $language_code Language code.
	 */
	public function set_my_commands( array $commands, string $language_code = '' ): array {
		unset( $commands, $language_code );
		return $this->call( '' );
	}

	/**
	 * Set the menu button.
	 *
	 * @param array  $menu_button Menu button.
	 * @param string $chat_id     Chat id.
	 */
	public function set_menu_button( array $menu_button, string $chat_id = '' ): array {
		unset( $menu_button, $chat_id );
		return $this->call( '' );
	}

	/**
	 * Map a call() error to a SendResult.
	 *
	 * @param array $response Output of call().
	 */
	public function to_result( array $response ): SendResult {
		if ( ! empty( $response['ok'] ) ) {
			return SendResult::success( $this->platform );
		}
		$code   = (int) ( $response['code'] ?? 0 );
		$result = SendResult::failure( $this->platform, (string) ( $response['error'] ?? '' ), $code >= 400 && $code < 500 && $code !== 429, '', $code );

		$result->retry_after = (int) ( $response['retry_after'] ?? 0 );
		return $result;
	}
}
