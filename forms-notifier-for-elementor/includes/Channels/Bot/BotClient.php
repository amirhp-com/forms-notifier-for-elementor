<?php
/**
 * Bot API client.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Client for the Telegram Bot API and the APIs modelled on it (Bale, Soroush Plus).
 *
 * The token is part of the request path by design of these APIs: it is never logged and it is removed from every error.
 */
class BotClient {

	public const MAX_TEXT     = 4096;
	public const MAX_CAPTION  = 1024;
	public const MAX_CALLBACK = 64;
	public const MAX_GROUP    = 10;

	/** Largest photo in bytes; larger images are sent as documents. */
	public const MAX_PHOTO = 10485760;

	private const BASES = [
		'telegram' => 'https://api.telegram.org',
		'bale'     => 'https://tapi.bale.ai',
		'soroush'  => 'https://api.splus.ir',
	];

	/** What a platform understands beyond plain text, inline keyboards and uploads. */
	private const FEATURES = [
		'telegram' => [ 'parse_mode', 'preview', 'silent', 'protect', 'topics', 'reply_parameters', 'photo_group', 'document_group', 'secret_token', 'allowed_updates', 'menu_button', 'commands' ],
		'bale'     => [ 'reply_to', 'photo_group', 'commands' ],
		'soroush'  => [ 'parse_mode' ],
	];

	/** Captions may be longer on some platforms. */
	private const CAPTIONS = [ 'bale' => 4096 ];

	/** @var array<string,self> */
	private static $instances = [];

	/** @var string */
	private $platform;

	/** @var string */
	private $token;

	/** @var string|null */
	private $base;

	/**
	 * $token/$base override settings (unsaved values, tests).
	 *
	 * @param string      $platform 'telegram' | 'bale' | 'soroush'.
	 * @param string|null $token    Bot token.
	 * @param string|null $base     API base.
	 */
	public function __construct( string $platform, ?string $token = null, ?string $base = null ) {
		$this->platform = isset( self::BASES[ $platform ] ) ? $platform : 'telegram';
		$this->token    = trim( $token ?? Settings::secret( "channels.{$this->platform}.token" ) );
		$this->base     = $base;
		Logger::scrub( $this->token );
	}

	/**
	 * Cached instance from settings. Unsaved values (overlay) get their own instance.
	 *
	 * @param string $platform Platform.
	 */
	public static function for_platform( string $platform ): self {
		$client = new self( $platform );
		$key    = get_current_blog_id() . '|' . $platform . '|' . md5( $client->token . '|' . $client->base() );
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = $client;
		}
		return self::$instances[ $key ];
	}

	/**
	 * Public API of a platform.
	 *
	 * @param string $platform Platform.
	 */
	public static function default_base( string $platform ): string {
		return self::BASES[ $platform ] ?? self::BASES['telegram'];
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
		return $this->token !== '';
	}

	/** Numeric bot id: the part of the token before the colon, '' when the token has another form. */
	public function bot_id(): string {
		return preg_match( '/^(\d+):/', $this->token, $m ) ? $m[1] : '';
	}

	/**
	 * Platform feature.
	 *
	 * @param string $feature Entry of FEATURES.
	 */
	public function supports( string $feature ): bool {
		return in_array( $feature, self::FEATURES[ $this->platform ], true );
	}

	/** Longest caption. */
	public function max_caption(): int {
		return self::CAPTIONS[ $this->platform ] ?? self::MAX_CAPTION;
	}

	/** Effective API base without trailing slash. */
	public function base(): string {
		$base = trim( $this->base ?? Settings::str( "channels.{$this->platform}.api_base" ) );
		$base = $base === '' ? self::default_base( $this->platform ) : $base;
		return untrailingslashit( (string) apply_filters( 'fnfe_bot_api_base', $base, $this->platform ) );
	}

	/** Requests go somewhere else than to the public API. */
	public function has_custom_base(): bool {
		return $this->base() !== self::default_base( $this->platform );
	}

	/** Why the API base cannot be used, '' when it can. */
	public function base_error(): string {
		$base = $this->base();
		if ( preg_match( '~^https://[^/\s?#@]+(/[^\s?#]*)?$~i', $base ) ) {
			return '';
		}
		if ( preg_match( '~^http://[^/\s?#@]+(/[^\s?#]*)?$~i', $base ) ) {
			return Settings::bool( 'advanced.allow_private_hosts' )
				? ''
				: __( 'The API base URL must start with https://. A plain http:// address works only when private hosts are allowed under Advanced.', 'forms-notifier-for-elementor' );
		}
		return __( 'The API base URL is not valid.', 'forms-notifier-for-elementor' );
	}

	/** Upload limit in bytes. Only a self-hosted API server accepts more than 50 MB. */
	public function max_upload_bytes(): int {
		$mb = Settings::int( "channels.{$this->platform}.max_upload_mb", 50 );
		$mb = max( 1, min( $this->has_custom_base() && $this->platform === 'telegram' ? 2000 : 50, $mb ) );
		return (int) apply_filters( 'fnfe_bot_max_upload_bytes', $mb * MB_IN_BYTES, $this->platform );
	}

	/**
	 * Call one Bot API method: POST {base}/bot{token}/{method}. Arrays are JSON encoded. $files triggers multipart.
	 *
	 * @param string $method  Method.
	 * @param array  $params  Parameters; null and '' are left out.
	 * @param array  $files   field => [ 'path' =>, 'name' =>, 'mime' => ].
	 * @param int    $timeout Seconds.
	 * @return array{ok:bool,result:mixed,error:string,code:int,retry_after:int,migrate_to:string}
	 *         code = Bot API error_code or HTTP status, 0 = transport failure. The token never appears in 'error'.
	 */
	public function call( string $method, array $params = [], array $files = [], int $timeout = 0 ): array {
		if ( $this->token === '' ) {
			return self::failure( __( 'The bot token is not set.', 'forms-notifier-for-elementor' ), 401 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9:_-]+$/', $this->token ) ) {
			return self::failure( __( 'The bot token is not valid.', 'forms-notifier-for-elementor' ), 401 );
		}
		if ( ! preg_match( '/^[A-Za-z]+$/', $method ) ) {
			return self::failure( __( 'Unknown API method.', 'forms-notifier-for-elementor' ), 400 );
		}
		$problem = $this->base_error();
		if ( $problem !== '' ) {
			return self::failure( $problem, 400 );
		}

		$params = array_filter(
			$params,
			static function ( $value ): bool {
				return $value !== null && $value !== '';
			}
		);
		$args   = [
			'timeout'     => $timeout,
			'redirection' => 0, // The token must not travel to another host.
			'scrub'       => [ $this->token, rawurlencode( $this->token ) ],
		];
		if ( $files ) {
			$problem = $this->files_error( $files );
			if ( $problem !== '' ) {
				return self::failure( $problem, 413 );
			}
			list( $body, $type ) = Http::multipart( $params, $files );

			$args['body']    = $body;
			$args['headers'] = [ 'Content-Type' => $type ];
			$args['timeout'] = max( 90, $timeout );
		} else {
			$args['json'] = $params;
		}

		$response = Http::post( $this->base() . '/bot' . $this->token . '/' . $method, $args );
		unset( $args, $body );

		$json = is_array( $response['json'] ) ? $response['json'] : null;
		if ( $json !== null && ! empty( $json['ok'] ) ) {
			return [
				'ok'          => true,
				'result'      => $json['result'] ?? true,
				'error'       => '',
				'code'        => $response['code'],
				'retry_after' => 0,
				'migrate_to'  => '',
			];
		}

		if ( $json !== null && array_key_exists( 'ok', $json ) ) {
			$code        = is_numeric( $json['error_code'] ?? null ) ? (int) $json['error_code'] : $response['code'];
			$description = is_scalar( $json['description'] ?? null ) ? sanitize_text_field( (string) $json['description'] ) : '';
			$out         = self::failure( $description === '' ? __( 'The bot API refused the request.', 'forms-notifier-for-elementor' ) : $description, $code );
			$parameters  = is_array( $json['parameters'] ?? null ) ? $json['parameters'] : [];

			$out['retry_after'] = is_numeric( $parameters['retry_after'] ?? null ) ? max( 0, (int) $parameters['retry_after'] ) : 0;
			$migrate            = is_scalar( $parameters['migrate_to_chat_id'] ?? null ) ? (string) $parameters['migrate_to_chat_id'] : '';
			$out['migrate_to']  = preg_match( '/^-?\d{1,20}$/', $migrate ) ? $migrate : '';
		} elseif ( $response['code'] === 0 ) {
			$out = self::failure( $response['error'] === '' ? __( 'The bot API could not be reached.', 'forms-notifier-for-elementor' ) : $response['error'], 0 );
		} else {
			$out = self::failure(
				/* translators: %1$d: HTTP status code */
				sprintf( __( 'The bot API sent an unreadable answer (status %1$d). Check the API base URL.', 'forms-notifier-for-elementor' ), $response['code'] ),
				$response['code']
			);
			if ( is_numeric( $response['headers']['retry-after'] ?? null ) ) {
				$out['retry_after'] = max( 0, (int) $response['headers']['retry-after'] );
			}
		}

		$out['error'] = $this->scrub( $out['error'] );
		// A bot that cannot be reached is polled every minute: one entry per minute and cause is enough.
		if ( RateLimit::hit( "bot_log_{$this->platform}_{$method}_{$out['code']}", 1, 5 * MINUTE_IN_SECONDS ) ) {
			Logger::warning(
				'bot',
				$this->platform . ' ' . $method . ' failed',
				[
					'code'  => $out['code'],
					'error' => $out['error'],
				]
			);
		}
		return $out;
	}

	/**
	 * "chat", "@channel", "chat/topic", "chat_topic", t.me/c/123/45 links -> [ 'chat' =>, 'thread' => ] or null.
	 *
	 * @param string $raw Raw address.
	 */
	public static function parse_address( string $raw ): ?array {
		$raw = preg_replace( '~\s*([/_])\s*~', '$1', trim( Text::en_digits( $raw ) ) );
		// Invisible direction marks come along when an id is copied from a right-to-left screen.
		$raw = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $raw );

		if ( preg_match( '~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/c/([1-9]\d{0,19})(?:/(\d{1,15}))?(?:/\d+)?/?(?:\?.*)?$~i', $raw, $m ) ) {
			return self::address( '-100' . $m[1], $m[2] ?? '' );
		}
		if ( preg_match( '~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me|ble\.ir)/(?:s/)?([A-Za-z][A-Za-z0-9_]{3,31})(?:/(\d{1,15})/\d+)?(?:/\d+)?/?(?:\?.*)?$~i', $raw, $m ) ) {
			return self::address( '@' . $m[1], $m[2] ?? '' );
		}
		if ( preg_match( '~^(@[A-Za-z][A-Za-z0-9_]{3,31}|-?[1-9]\d{0,19})(?:[/_](\d{1,15}))?$~', $raw, $m ) ) {
			return self::address( $m[1], $m[2] ?? '' );
		}
		return null;
	}

	/**
	 * Canonical address.
	 *
	 * @param string     $chat   Chat id.
	 * @param string|int $thread Topic id.
	 */
	public static function format_address( string $chat, $thread = '' ): string {
		$thread = (string) $thread;
		return $thread === '' || $thread === '0' ? $chat : $chat . '/' . $thread;
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
	 * Send text (already in the platform format and not longer than MAX_TEXT).
	 *
	 * @param string $address Address.
	 * @param string $text    Text.
	 * @param array  $opts    parse_mode ('HTML'|'MarkdownV2'|''), disable_preview, silent, protect, reply_to (int),
	 *                        reply_markup (array), chat_type (string; Bale topics exist in private chats only).
	 */
	public function send_message( string $address, string $text, array $opts = [] ): array {
		$params = $this->target( $address, $opts );
		if ( ! $params ) {
			return self::failure( __( 'The chat address is not valid.', 'forms-notifier-for-elementor' ), 400 );
		}
		$params['text'] = $text;
		$params        += $this->text_params( $opts ) + $this->delivery_params( $opts );
		if ( ! empty( $opts['reply_markup'] ) ) {
			$params['reply_markup'] = $opts['reply_markup'];
		}
		return $this->call( 'sendMessage', $params );
	}

	/**
	 * One file. Caption <= max_caption() (caller guarantees).
	 *
	 * @param string $address Address.
	 * @param string $kind    document | photo.
	 * @param array  $file    path, name, mime.
	 * @param string $caption Caption.
	 * @param array  $opts    As in send_message().
	 */
	public function send_file( string $address, string $kind, array $file, string $caption = '', array $opts = [] ): array {
		$params = $this->target( $address, $opts );
		if ( ! $params ) {
			return self::failure( __( 'The chat address is not valid.', 'forms-notifier-for-elementor' ), 400 );
		}
		$kind              = $kind === 'photo' ? 'photo' : 'document';
		$params['caption'] = $caption;
		$params           += $this->delivery_params( $opts );
		if ( ! empty( $opts['parse_mode'] ) && $this->supports( 'parse_mode' ) ) {
			$params['parse_mode'] = $opts['parse_mode'];
		}
		if ( ! empty( $opts['reply_markup'] ) ) {
			$params['reply_markup'] = $opts['reply_markup'];
		}
		return $this->call( $kind === 'photo' ? 'sendPhoto' : 'sendDocument', $params, [ $kind => $file ] );
	}

	/**
	 * 2..10 files as an album of documents (or photos).
	 *
	 * @param string $address Address.
	 * @param string $kind    document | photo.
	 * @param array  $files   Files: path, name, mime and optionally caption.
	 * @param string $caption Caption of the first file when the files carry none.
	 * @param array  $opts    As in send_message().
	 */
	public function send_media_group( string $address, string $kind, array $files, string $caption = '', array $opts = [] ): array {
		$params = $this->target( $address, $opts );
		if ( ! $params ) {
			return self::failure( __( 'The chat address is not valid.', 'forms-notifier-for-elementor' ), 400 );
		}
		$files = array_values( $files );
		if ( count( $files ) < 2 || count( $files ) > self::MAX_GROUP ) {
			return self::failure( __( 'An album needs 2 to 10 files.', 'forms-notifier-for-elementor' ), 400 );
		}

		$media   = [];
		$uploads = [];
		foreach ( $files as $i => $file ) {
			$text = (string) ( $file['caption'] ?? ( $i === 0 ? $caption : '' ) );
			$item = [
				'type'  => $kind === 'photo' ? 'photo' : 'document',
				'media' => 'attach://file' . $i,
			];
			if ( $text !== '' ) {
				$item['caption'] = $text;
			}
			$media[]                = $item;
			$uploads[ 'file' . $i ] = $file;
		}
		$params['media'] = $media;
		$params         += $this->delivery_params( $opts );
		return $this->call( 'sendMediaGroup', $params, $uploads );
	}

	/**
	 * Replace a message text.
	 *
	 * @param string $chat_id    Chat id.
	 * @param int    $message_id Message id.
	 * @param string $text       Text.
	 * @param array  $opts       As in send_message().
	 */
	public function edit_message_text( string $chat_id, int $message_id, string $text, array $opts = [] ): array {
		$params = [
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'text'       => $text,
		];
		if ( ! empty( $opts['reply_markup'] ) ) {
			$params['reply_markup'] = $opts['reply_markup'];
		}
		return $this->call( 'editMessageText', $params + $this->text_params( $opts ) );
	}

	/**
	 * Replace a keyboard.
	 *
	 * @param string $chat_id      Chat id.
	 * @param int    $message_id   Message id.
	 * @param array  $reply_markup Reply markup, [] removes the keyboard.
	 */
	public function edit_reply_markup( string $chat_id, int $message_id, array $reply_markup = [] ): array {
		return $this->call(
			'editMessageReplyMarkup',
			[
				'chat_id'      => $chat_id,
				'message_id'   => $message_id,
				'reply_markup' => $reply_markup ? $reply_markup : [ 'inline_keyboard' => [] ],
			]
		);
	}

	/**
	 * Answer a button press.
	 *
	 * @param string $callback_query_id Callback query id.
	 * @param string $text              Text.
	 * @param bool   $alert             Show as a dialog.
	 */
	public function answer_callback( string $callback_query_id, string $text = '', bool $alert = false ): array {
		return $this->call(
			'answerCallbackQuery',
			[
				'callback_query_id' => $callback_query_id,
				'text'              => Text::truncate( $text, 190 ),
				'show_alert'        => $alert ? true : null,
			],
			[],
			10
		);
	}

	/** Bot account. */
	public function get_me(): array {
		return $this->call( 'getMe', [], [], 15 );
	}

	/**
	 * One chat.
	 *
	 * @param string $chat_id Chat id.
	 */
	public function get_chat( string $chat_id ): array {
		return $this->call( 'getChat', [ 'chat_id' => $chat_id ], [], 15 );
	}

	/**
	 * Pending updates.
	 *
	 * @param int   $offset  First update id to return, 0 = all pending.
	 * @param int   $limit   1-100.
	 * @param int   $timeout Long polling seconds.
	 * @param array $allowed Update types.
	 */
	public function get_updates( int $offset = 0, int $limit = 100, int $timeout = 0, array $allowed = [] ): array {
		return $this->call(
			'getUpdates',
			[
				'offset'          => $offset > 0 ? $offset : null,
				'limit'           => max( 1, min( 100, $limit ) ),
				'timeout'         => $timeout > 0 ? $timeout : null,
				'allowed_updates' => $allowed && $this->supports( 'allowed_updates' ) ? array_values( $allowed ) : null,
			],
			[],
			$timeout + 10
		);
	}

	/**
	 * Set the webhook.
	 *
	 * @param string $url          Url.
	 * @param string $secret_token Returned by Telegram in a header of every update.
	 * @param array  $allowed      Update types; always sent explicitly, otherwise the previous list stays active.
	 */
	public function set_webhook( string $url, string $secret_token = '', array $allowed = [] ): array {
		$params = [ 'url' => $url ];
		if ( $this->supports( 'secret_token' ) ) {
			$params['secret_token'] = $secret_token;
		}
		if ( $this->supports( 'allowed_updates' ) ) {
			$params['allowed_updates']      = array_values( $allowed );
			$params['drop_pending_updates'] = false;
			// Updates of a chat must arrive in order.
			$params['max_connections'] = 1;
		}
		return $this->call( 'setWebhook', $params, [], 20 );
	}

	/**
	 * Remove the webhook.
	 *
	 * @param bool $drop_pending Forget waiting updates.
	 */
	public function delete_webhook( bool $drop_pending = false ): array {
		return $this->call( 'deleteWebhook', $drop_pending ? [ 'drop_pending_updates' => true ] : [], [], 15 );
	}

	/** Webhook status. */
	public function get_webhook_info(): array {
		return $this->call( 'getWebhookInfo', [], [], 10 );
	}

	/**
	 * Register commands.
	 *
	 * @param array  $commands      List of [ 'command' =>, 'description' => ].
	 * @param string $language_code Two-letter language, '' = default list.
	 */
	public function set_my_commands( array $commands, string $language_code = '' ): array {
		return $this->call(
			'setMyCommands',
			[
				'commands'      => array_values( $commands ),
				'language_code' => $language_code,
			],
			[],
			15
		);
	}

	/**
	 * Set the menu button.
	 *
	 * @param array  $menu_button Menu button.
	 * @param string $chat_id     Chat id, '' = default of the bot.
	 */
	public function set_menu_button( array $menu_button, string $chat_id = '' ): array {
		return $this->call(
			'setChatMenuButton',
			[
				'chat_id'     => $chat_id,
				'menu_button' => $menu_button,
			],
			[],
			15
		);
	}

	/**
	 * Map a call() error to a SendResult (permanent for 400 chat not found / 403 blocked, temporary for 429/5xx/0).
	 *
	 * @param array $response Output of call().
	 */
	public function to_result( array $response ): SendResult {
		$code = (int) ( $response['code'] ?? 0 );
		if ( ! empty( $response['ok'] ) ) {
			$result            = SendResult::success( $this->platform, '', implode( ',', self::message_ids( $response ) ) );
			$result->http_code = $code;
			return $result;
		}

		$error   = $this->scrub( (string) ( $response['error'] ?? '' ) );
		$migrate = (string) ( $response['migrate_to'] ?? '' );
		if ( $migrate !== '' ) {
			$result = SendResult::failure( $this->platform, $error, false, $error, $code );
			return $result->with_meta( [ 'migrate_to' => $migrate ] );
		}

		$temporary = $code < 400 || $code === 408 || $code === 409 || $code === 429 || $code >= 500;
		$message   = $error;
		if ( $code === 401 ) {
			$message = __( 'The bot token was rejected. Enter a valid token in the channel settings.', 'forms-notifier-for-elementor' );
		} elseif ( $code === 403 && preg_match( '/blocked/i', $error ) ) {
			$message = __( 'The person blocked the bot.', 'forms-notifier-for-elementor' );
		} elseif ( $code === 403 && preg_match( '/kicked|not a member/i', $error ) ) {
			$message = __( 'The bot is not a member of this chat any more.', 'forms-notifier-for-elementor' );
		} elseif ( $code === 400 && preg_match( '/chat not found/i', $error ) ) {
			$message = __( 'Chat not found. Add the bot to the chat, or let the person press Start in the bot first.', 'forms-notifier-for-elementor' );
		} elseif ( $code === 429 ) {
			$message = __( 'The bot sent too many messages. It will try again later.', 'forms-notifier-for-elementor' );
		}

		$result              = SendResult::failure( $this->platform, $message, ! $temporary, $error, $code );
		$result->retry_after = max( 0, (int) ( $response['retry_after'] ?? 0 ) );
		return $result;
	}

	/**
	 * Message ids of a successful answer.
	 *
	 * @param array $response Output of call().
	 * @return int[]
	 */
	public static function message_ids( array $response ): array {
		$result = $response['result'] ?? null;
		if ( ! is_array( $result ) ) {
			return [];
		}
		$out = [];
		foreach ( isset( $result['message_id'] ) ? [ $result ] : $result as $message ) {
			if ( is_array( $message ) && is_numeric( $message['message_id'] ?? null ) ) {
				$out[] = (int) $message['message_id'];
			}
		}
		return $out;
	}

	/**
	 * Remove the token from a text.
	 *
	 * @param string $text Text.
	 */
	public function scrub( string $text ): string {
		return $this->token === '' ? $text : Logger::clean( str_replace( [ $this->token, rawurlencode( $this->token ) ], '***', $text ) );
	}

	/**
	 * Parsed address.
	 *
	 * @param string $chat   Chat.
	 * @param string $thread Topic id.
	 */
	private static function address( string $chat, string $thread ): array {
		$thread = ltrim( $thread, '0' );
		return [
			'chat'   => $chat,
			'thread' => $thread,
		];
	}

	/**
	 * chat_id and message_thread_id of an address, [] when invalid.
	 *
	 * @param string $address Address.
	 * @param array  $opts    Opts.
	 */
	private function target( string $address, array $opts ): array {
		$parsed = self::parse_address( $address );
		if ( ! $parsed ) {
			return [];
		}
		$params = [ 'chat_id' => $parsed['chat'] ];
		// Topic 1 is the General topic of a forum, which is addressed without a thread.
		$has_topic = $parsed['thread'] !== '' && $parsed['thread'] !== '1';
		if ( $has_topic && ( $this->supports( 'topics' ) || ( $this->is_bale() && ( $opts['chat_type'] ?? '' ) === 'private' ) ) ) {
			$params['message_thread_id'] = (int) $parsed['thread'];
		}
		return $params;
	}

	/**
	 * parse_mode and link preview.
	 *
	 * @param array $opts Opts.
	 */
	private function text_params( array $opts ): array {
		$params = [];
		if ( ! empty( $opts['parse_mode'] ) && $this->supports( 'parse_mode' ) ) {
			$params['parse_mode'] = $opts['parse_mode'];
		}
		if ( ! empty( $opts['disable_preview'] ) && $this->supports( 'preview' ) ) {
			$params['link_preview_options'] = [ 'is_disabled' => true ];
		}
		return $params;
	}

	/**
	 * Silent, protected, reply.
	 *
	 * @param array $opts Opts.
	 */
	private function delivery_params( array $opts ): array {
		$params = [];
		if ( ! empty( $opts['silent'] ) && $this->supports( 'silent' ) ) {
			$params['disable_notification'] = true;
		}
		if ( ! empty( $opts['protect'] ) && $this->supports( 'protect' ) ) {
			$params['protect_content'] = true;
		}
		$reply = (int) ( $opts['reply_to'] ?? 0 );
		if ( $reply > 0 && $this->supports( 'reply_parameters' ) ) {
			$params['reply_parameters'] = [
				'message_id'                  => $reply,
				'allow_sending_without_reply' => true,
			];
		} elseif ( $reply > 0 && $this->supports( 'reply_to' ) ) {
			$params['reply_to_message_id'] = $reply;
		}
		return $params;
	}

	/**
	 * Why files cannot be uploaded, '' when they can. The body is built in memory, so the sizes are checked first.
	 *
	 * @param array $files Files.
	 */
	private function files_error( array $files ): string {
		$total = 0;
		foreach ( $files as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
				return __( 'The file cannot be read.', 'forms-notifier-for-elementor' );
			}
			$size = (int) filesize( $path );
			if ( $size > $this->max_upload_bytes() ) {
				/* translators: %1$s: largest size, e.g. 50 MB */
				return sprintf( __( 'The file is larger than %1$s and cannot be uploaded.', 'forms-notifier-for-elementor' ), size_format( $this->max_upload_bytes() ) );
			}
			$total += $size;
		}
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		// The body exists up to three times while it is built and sent.
		if ( $limit > 0 && memory_get_usage( true ) + 3 * $total > $limit ) {
			return __( 'The server does not have enough memory to upload this file.', 'forms-notifier-for-elementor' );
		}
		return '';
	}

	/**
	 * Failure shape.
	 *
	 * @param string $error Error.
	 * @param int    $code  Code.
	 */
	private static function failure( string $error, int $code ): array {
		return [
			'ok'          => false,
			'result'      => null,
			'error'       => $error,
			'code'        => $code,
			'retry_after' => 0,
			'migrate_to'  => '',
		];
	}
}
