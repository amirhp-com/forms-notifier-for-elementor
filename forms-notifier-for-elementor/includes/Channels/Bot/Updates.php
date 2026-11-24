<?php
/**
 * Receiving bot updates.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Alerts;
use AmirhpCom\FormsNotifier\MiniApp\MenuButton;

defined( 'ABSPATH' ) || exit;

/** Webhook and polling ingest, mode selection, de-duplication. State lives under bot.{platform}. */
final class Updates {

	public const PLATFORMS = [ 'telegram', 'bale' ];

	public const MODES = [ 'auto', 'webhook', 'polling', 'off' ];

	/** Largest webhook body in bytes. */
	public const MAX_BODY = 1048576;

	private const LOCK_TTL   = 30;
	private const POLL_CALLS = 5;
	private const POLL_TIME  = 15;

	/** Update ids remembered to recognise repeats. */
	private const SEEN = 200;

	/** After a week without updates Telegram starts again at a random id. */
	private const IDLE_RESET = 6 * DAY_IN_SECONDS;

	/** @var array[] updates waiting for the end of the request: [ platform, update ] */
	private static $pending = [];

	/** @var bool connect() is running */
	private static $connecting = false;

	/**
	 * Check the secrets of a webhook request. Bale sends no header: its path secret is the only protection.
	 *
	 * @param string $platform Platform.
	 * @param string $secret   Path segment.
	 * @param string $header   X-Telegram-Bot-Api-Secret-Token.
	 */
	public static function verify( string $platform, string $secret, string $header ): bool {
		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			return false;
		}
		$expected = State::get( "bot.$platform.webhook_secret", '' );
		if ( ! is_string( $expected ) || strlen( $expected ) !== 32 || ! hash_equals( $expected, $secret ) ) {
			return false;
		}
		if ( $platform !== 'telegram' ) {
			return true;
		}
		$expected = State::get( "bot.$platform.header_secret", '' );
		return is_string( $expected ) && strlen( $expected ) === 48 && hash_equals( $expected, $header );
	}

	/**
	 * Accept a webhook update; it is processed after the response was sent.
	 *
	 * @param string $platform Platform.
	 * @param string $body     Raw request body.
	 */
	public static function receive( string $platform, string $body ): void {
		if ( strlen( $body ) > self::MAX_BODY ) {
			Logger::warning( 'bot', $platform . ' update ignored: too large', [ 'bytes' => strlen( $body ) ] );
			return;
		}
		$update = json_decode( $body, true, 64 );
		if ( ! is_array( $update ) || ! is_int( $update['update_id'] ?? null ) ) {
			Logger::warning( 'bot', $platform . ' update ignored: not an update' );
			return;
		}
		if ( self::effective_mode( $platform ) === 'off' ) {
			return;
		}
		if ( ! self::accept( $platform, $update['update_id'] ) ) {
			return;
		}
		if ( ! self::$pending ) {
			add_action( 'shutdown', [ self::class, 'run_pending' ], 1 );
		}
		self::$pending[] = [ $platform, $update ];
	}

	/** Process the accepted updates of this request. */
	public static function run_pending(): void {
		if ( ! self::$pending ) {
			return;
		}
		self::finish_response();
		$pending       = self::$pending;
		self::$pending = [];
		foreach ( $pending as list( $platform, $update ) ) {
			self::process( $platform, $update );
		}
	}

	/** During shutdown: let the client go before slow work starts. */
	public static function finish_response(): void {
		if ( ! doing_action( 'shutdown' ) ) {
			return;
		}
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Each update is processed once: true for a new id, which is remembered.
	 *
	 * @param string $platform  Platform.
	 * @param int    $update_id Update id.
	 */
	public static function accept( string $platform, int $update_id ): bool {
		$locked = false;
		for ( $i = 0; $i < 20 && ! $locked; $i++ ) {
			$locked = Lock::acquire( 'bot_' . $platform, self::LOCK_TTL );
			if ( ! $locked ) {
				usleep( 50000 );
			}
		}
		// A busy lock must not lose the update: it is then checked without the lock.
		$new = self::remember( $platform, $update_id );
		if ( $locked ) {
			Lock::release( 'bot_' . $platform );
		}
		return $new;
	}

	/**
	 * Run the handlers of one update.
	 *
	 * @param string $platform Platform.
	 * @param array  $update   Update.
	 */
	public static function process( string $platform, array $update ): void {
		try {
			do_action( 'fnfe_bot_update', $platform, $update );
			UpdateHandler::handle( $platform, $update );
		} catch ( \Throwable $e ) {
			Logger::error(
				'bot',
				$platform . ' update failed: ' . $e->getMessage(),
				[ 'update_id' => (int) ( $update['update_id'] ?? 0 ) ]
			);
		}
	}

	/** Cron: poll every platform in polling mode. */
	public static function poll_all(): void {
		foreach ( self::PLATFORMS as $platform ) {
			if ( self::effective_mode( $platform ) === 'polling' ) {
				self::poll( $platform );
			}
		}
	}

	/**
	 * Fetch and process pending updates.
	 *
	 * @param string $platform Platform.
	 * @return array{ok:bool,updates:int,chats:int,message:string}
	 */
	public static function poll( string $platform ): array {
		$out    = [
			'ok'      => false,
			'updates' => 0,
			'chats'   => 0,
			'message' => '',
		];
		$client = BotClient::for_platform( $platform );
		if ( ! $client->has_token() ) {
			$out['message'] = __( 'The bot token is not set.', 'forms-notifier-for-elementor' );
			return $out;
		}
		if ( self::effective_mode( $platform ) === 'webhook' ) {
			$out['ok']      = true;
			$out['message'] = __( 'The bot receives messages through its webhook. New chats appear in the list by themselves.', 'forms-notifier-for-elementor' );
			return $out;
		}
		if ( ! Lock::acquire( 'bot_poll_' . $platform, 2 * MINUTE_IN_SECONDS ) ) {
			$out['message'] = __( 'Another check is running. Try again in a moment.', 'forms-notifier-for-elementor' );
			return $out;
		}

		try {
			self::check_bot( $platform, $client );
			if ( ! State::get( "bot.$platform.webhook_cleared", false ) ) {
				$deleted = $client->delete_webhook();
				if ( ! $deleted['ok'] ) {
					$out['message'] = $deleted['error'];
					return $out;
				}
				State::set( "bot.$platform.webhook_cleared", true );
			}

			$found = ChatDiscovery::found();
			$until = time() + self::POLL_TIME;
			for ( $i = 0; $i < self::POLL_CALLS; $i++ ) {
				if ( time() >= $until ) {
					break;
				}
				$response = $client->get_updates( (int) State::get( "bot.$platform.offset", 0 ), 100, 0, self::allowed( $platform ) );
				if ( ! $response['ok'] ) {
					$out['message'] = $response['error'];
					if ( $response['code'] === 409 ) {
						State::set( "bot.$platform.webhook_cleared", false );
					}
					return $out;
				}
				$updates = is_array( $response['result'] ) ? $response['result'] : [];
				foreach ( $updates as $update ) {
					if ( ! is_array( $update ) || ! is_int( $update['update_id'] ?? null ) ) {
						continue;
					}
					// The offset moves first: an update that breaks its handler is not fetched forever.
					State::set( "bot.$platform.offset", $update['update_id'] + 1 );
					if ( self::accept( $platform, $update['update_id'] ) ) {
						self::process( $platform, $update );
						++$out['updates'];
					}
				}
				if ( count( $updates ) < 100 ) {
					break;
				}
			}
			$out['ok']    = true;
			$out['chats'] = ChatDiscovery::found() - $found;
		} finally {
			State::set( "bot.$platform.last_poll", self::now() );
			Lock::release( 'bot_poll_' . $platform );
		}
		return $out;
	}

	/**
	 * Can the messenger reach this site?
	 *
	 * @param string $platform Platform.
	 * @return array{0:bool,1:string} [ possible, reason ]
	 */
	public static function can_webhook( string $platform ): array {
		$url   = Urls::rest( "bot/$platform/hook/x" );
		$parts = (array) wp_parse_url( $url );
		if ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' ) {
			return [ false, __( 'A webhook needs HTTPS. The site address does not start with https://.', 'forms-notifier-for-elementor' ) ];
		}
		if ( ! Keyboard::is_public_host( (string) ( $parts['host'] ?? '' ) ) ) {
			return [ false, __( 'The messenger cannot reach this site: its address is local or private.', 'forms-notifier-for-elementor' ) ];
		}
		$ports = $platform === 'bale' ? [ 443, 88 ] : [ 443, 80, 88, 8443 ];
		if ( ! in_array( (int) ( $parts['port'] ?? 443 ), $ports, true ) ) {
			return [
				false,
				/* translators: %1$s: list of port numbers */
				sprintf( __( 'The messenger only calls these ports: %1$s.', 'forms-notifier-for-elementor' ), implode( ', ', $ports ) ),
			];
		}
		return [ true, __( 'The site has a public HTTPS address.', 'forms-notifier-for-elementor' ) ];
	}

	/**
	 * Mode in use: webhook | polling | off.
	 *
	 * @param string $platform Platform.
	 */
	public static function effective_mode( string $platform ): string {
		$mode = State::get( "bot.$platform.mode_effective", '' );
		return in_array( $mode, [ 'webhook', 'polling' ], true ) ? $mode : 'off';
	}

	/**
	 * Connect the bot: checks the token, then sets or removes the webhook. Secrets are rotated.
	 *
	 * @param string $platform Platform.
	 * @param string $mode     auto | webhook | polling | off.
	 * @return string error, '' on success
	 */
	public static function connect( string $platform, string $mode ): string {
		if ( self::$connecting ) {
			return '';
		}
		self::$connecting = true;
		try {
			$client = BotClient::for_platform( $platform );
			$error  = self::identify( $platform, $client );
			if ( $error !== '' ) {
				return $error;
			}

			list( $possible, $reason ) = self::can_webhook( $platform );

			if ( $mode === 'webhook' && ! $possible ) {
				return $reason;
			}
			if ( $mode === 'webhook' || ( $mode === 'auto' && $possible ) ) {
				$error = self::start_webhook( $platform, $client );
				if ( $error === '' ) {
					$reason = $mode === 'auto' ? $reason : '';
					self::finish( $platform, 'webhook', $reason );
					return '';
				}
				if ( $mode === 'webhook' ) {
					return $error;
				}
				/* translators: %1$s: error of the messenger */
				$reason = sprintf( __( 'The webhook could not be set: %1$s', 'forms-notifier-for-elementor' ), $error );
			}

			$deleted = $client->delete_webhook();
			State::set( "bot.$platform.webhook_cleared", $deleted['ok'] );
			if ( ! $deleted['ok'] ) {
				return $deleted['error'];
			}
			self::finish( $platform, $mode === 'off' ? 'off' : 'polling', $mode === 'auto' ? $reason : '' );
			return '';
		} finally {
			self::$connecting = false;
		}
	}

	/** connect() is running: setting changes it makes must not start it again. */
	public static function is_connecting(): bool {
		return self::$connecting;
	}

	/**
	 * Read the bot account and store it.
	 *
	 * @param string         $platform Platform.
	 * @param BotClient|null $client   Client.
	 * @return string error, '' on success
	 */
	public static function identify( string $platform, ?BotClient $client = null ): string {
		$client = $client ?? BotClient::for_platform( $platform );
		$me     = $client->get_me();
		if ( ! $me['ok'] ) {
			self::flag_token( $platform, $me['code'] );
			return $client->to_result( $me )->error;
		}
		self::check_bot( $platform, $client );
		State::set( "bot.$platform.me", self::me( $me['result'] ) );
		State::delete( "bot.$platform.token_error" );
		return '';
	}

	/**
	 * Remember that the token was rejected.
	 *
	 * @param string $platform Platform.
	 * @param int    $code     Error code of the API.
	 */
	public static function flag_token( string $platform, int $code ): void {
		if ( $code === 401 ) {
			State::set( "bot.$platform.token_error", self::now() );
		}
	}

	/**
	 * Status object of the REST API.
	 *
	 * @param string $platform Platform.
	 * @param bool   $live     Ask the messenger.
	 */
	public static function status( string $platform, bool $live = true ): array {
		$client     = BotClient::for_platform( $platform );
		$configured = $client->has_token();
		$me         = State::get( "bot.$platform.me", [] );
		$me         = is_array( $me ) ? $me : [];
		$error      = '';

		if ( $live && $configured && ( ! $me || (string) State::get( "bot.$platform.bot_id", '' ) !== $client->bot_id() ) ) {
			$error = self::identify( $platform, $client );
			$me    = (array) State::get( "bot.$platform.me", [] );
		}

		$mode = self::effective_mode( $platform );

		list( $possible, $reason ) = self::can_webhook( $platform );

		$stored  = (string) State::get( "bot.$platform.mode_reason", '' );
		$webhook = [
			'url'             => $mode === 'webhook' ? self::masked( (string) State::get( "bot.$platform.webhook_url", '' ) ) : '',
			'set'             => false,
			'pending'         => 0,
			'last_error'      => '',
			'last_error_date' => null,
		];
		if ( $live && $configured && $error === '' && $mode === 'webhook' ) {
			$info = $client->get_webhook_info();
			if ( $info['ok'] && is_array( $info['result'] ) ) {
				$webhook = array_merge( $webhook, self::webhook_info( $platform, $info['result'] ) );
			} else {
				$error = $info['error'];
			}
		}

		return [
			'configured'  => $configured,
			'me'          => [
				'id'       => (string) ( $me['id'] ?? '' ),
				'username' => (string) ( $me['username'] ?? '' ),
				'name'     => (string) ( $me['name'] ?? '' ),
			],
			'mode'        => $mode,
			'webhook'     => $webhook,
			'polling'     => [
				'last_run' => self::iso( (int) State::get( "bot.$platform.last_poll", 0 ) ),
				'offset'   => (int) State::get( "bot.$platform.offset", 0 ),
			],
			'can_webhook' => $possible,
			'reason'      => $stored !== '' && $mode !== 'off' ? $stored : $reason,
			'miniapp_url' => Settings::bool( 'miniapp.enabled' ) ? Urls::miniapp( $platform ) : '',
			'error'       => $error,
		];
	}

	/** Schedule polling while a platform needs it. */
	public static function sync_schedule(): void {
		$needed = false;
		foreach ( self::PLATFORMS as $platform ) {
			$needed = $needed || self::effective_mode( $platform ) === 'polling';
		}
		if ( $needed ) {
			Cron::ensure( Cron::POLL, 'fnfe_minute' );
		} else {
			Cron::clear( Cron::POLL );
		}
	}

	/** Hourly: is the webhook still set and does it work? Sets it again when it was removed. */
	public static function check_webhooks(): void {
		foreach ( self::PLATFORMS as $platform ) {
			if ( self::effective_mode( $platform ) !== 'webhook' || ! Settings::bool( "channels.$platform.enabled" ) ) {
				continue;
			}
			$client = BotClient::for_platform( $platform );
			$info   = $client->has_token() ? $client->get_webhook_info() : null;
			if ( ! $info || ! $info['ok'] || ! is_array( $info['result'] ) ) {
				continue;
			}
			$state = self::webhook_info( $platform, $info['result'] );
			if ( ! $state['set'] ) {
				$error = self::start_webhook( $platform, $client );
				self::alert(
					$platform,
					$error === ''
						? __( 'The webhook of the bot had been removed or replaced. It was set again.', 'forms-notifier-for-elementor' )
						/* translators: %1$s: error of the messenger */
						: sprintf( __( 'The webhook of the bot was removed and could not be set again: %1$s', 'forms-notifier-for-elementor' ), $error )
				);
			} elseif ( $state['last_error'] !== '' && $state['pending'] > 0 && (int) ( $info['result']['last_error_date'] ?? 0 ) > self::now() - HOUR_IN_SECONDS ) {
				self::alert(
					$platform,
					/* translators: %1$s: error of the messenger */
					sprintf( __( 'The messenger cannot deliver updates to this site: %1$s', 'forms-notifier-for-elementor' ), $state['last_error'] )
				);
			}
		}
	}

	/**
	 * Update types to ask for. Filter: fnfe_bot_allowed_updates.
	 *
	 * @param string $platform Platform.
	 * @return string[]
	 */
	public static function allowed( string $platform ): array {
		$types = $platform === 'bale'
			? [ 'message', 'callback_query' ]
			: [ 'message', 'channel_post', 'my_chat_member', 'callback_query' ];
		$types = apply_filters( 'fnfe_bot_allowed_updates', $types, $platform );
		return array_values( array_filter( (array) $types, 'is_string' ) );
	}

	/**
	 * Set the webhook with fresh secrets; the old ones stay in use when the messenger refuses.
	 *
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 * @return string error, '' on success
	 */
	private static function start_webhook( string $platform, BotClient $client ): string {
		$old = [
			'webhook_secret' => State::get( "bot.$platform.webhook_secret", '' ),
			'header_secret'  => State::get( "bot.$platform.header_secret", '' ),
		];
		State::set( "bot.$platform.webhook_secret", Crypto::token( 16 ) );
		State::set( "bot.$platform.header_secret", Crypto::token( 24 ) );

		$url      = Urls::bot_webhook( $platform );
		$response = $client->set_webhook(
			$url,
			(string) State::get( "bot.$platform.header_secret", '' ),
			self::allowed( $platform )
		);
		if ( ! $response['ok'] ) {
			foreach ( $old as $key => $value ) {
				State::set( "bot.$platform.$key", $value );
			}
			return $response['error'];
		}
		State::set( "bot.$platform.webhook_url", $url );
		State::set( "bot.$platform.webhook_cleared", false );
		return '';
	}

	/**
	 * Store the mode, then everything that depends on it.
	 *
	 * @param string $platform Platform.
	 * @param string $mode     webhook | polling | off.
	 * @param string $reason   Why auto chose it.
	 */
	private static function finish( string $platform, string $mode, string $reason ): void {
		State::set( "bot.$platform.mode_effective", $mode );
		State::set( "bot.$platform.mode_reason", $reason );
		self::sync_schedule();
		if ( $mode !== 'off' ) {
			Commands::register( $platform );
			if ( $platform === 'telegram' && class_exists( MenuButton::class ) ) {
				MenuButton::sync();
			}
		}
		Logger::info( 'bot', $platform . ' connected', [ 'mode' => $mode ] );
	}

	/**
	 * Another bot than before: its ids start from scratch.
	 *
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	private static function check_bot( string $platform, BotClient $client ): void {
		$known = (string) State::get( "bot.$platform.bot_id", '' );
		$bot   = $client->bot_id();
		if ( $known === $bot ) {
			return;
		}
		foreach ( [ 'offset', 'last_update', 'last_update_at', 'seen', 'webhook_cleared', 'me', 'commands_set' ] as $key ) {
			State::delete( "bot.$platform.$key" );
		}
		State::set( "bot.$platform.bot_id", $bot );
		if ( $platform === 'telegram' ) {
			State::delete( 'miniapp_menu' ); // The menu button of the new bot was never set.
		}
	}

	/**
	 * Remember an update id; false when it was seen before or is older than what was seen.
	 *
	 * @param string $platform  Platform.
	 * @param int    $update_id Update id.
	 */
	private static function remember( string $platform, int $update_id ): bool {
		// Another request may have written since this one read the option.
		wp_cache_delete( State::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$bot  = State::get( "bot.$platform", [] );
		$bot  = is_array( $bot ) ? $bot : [];
		$seen = array_map( 'intval', (array) ( $bot['seen'] ?? [] ) );
		if ( in_array( $update_id, $seen, true ) ) {
			return false;
		}
		$last = (int) ( $bot['last_update'] ?? 0 );
		$idle = self::now() - (int) ( $bot['last_update_at'] ?? 0 );
		if ( $last > 0 && $update_id <= $last ) {
			if ( $idle > self::IDLE_RESET ) {
				$seen = [];
			} elseif ( count( $seen ) < 2 || $update_id < min( $seen ) ) {
				return false;
			}
			// Otherwise: parallel requests that arrived in the wrong order.
		}
		$seen[] = $update_id;

		$bot['seen']           = array_slice( $seen, -self::SEEN );
		$bot['last_update']    = $idle > self::IDLE_RESET ? $update_id : max( $last, $update_id );
		$bot['last_update_at'] = self::now();
		State::set( "bot.$platform", $bot );
		return true;
	}

	/**
	 * Webhook part of the status.
	 *
	 * @param string $platform Platform.
	 * @param array  $info     WebhookInfo.
	 */
	private static function webhook_info( string $platform, array $info ): array {
		$date = is_numeric( $info['last_error_date'] ?? null ) ? (int) $info['last_error_date'] : 0;
		$url  = is_string( $info['url'] ?? null ) ? $info['url'] : '';
		// Compared with the URL that was set: a multilingual plugin may put a language into rest_url(). The host must
		// still be the one of this site, otherwise the site moved.
		$known = (string) State::get( "bot.$platform.webhook_url", '' );
		$host  = wp_parse_url( Urls::rest(), PHP_URL_HOST );
		return [
			'set'             => $url !== '' && hash_equals( $known, $url ) && wp_parse_url( $url, PHP_URL_HOST ) === $host,
			'pending'         => is_numeric( $info['pending_update_count'] ?? null ) ? (int) $info['pending_update_count'] : 0,
			'last_error'      => is_string( $info['last_error_message'] ?? null ) ? sanitize_text_field( $info['last_error_message'] ) : '',
			'last_error_date' => self::iso( $date ),
		];
	}

	/**
	 * Bot account.
	 *
	 * @param mixed $result Result of getMe.
	 */
	private static function me( $result ): array {
		$result   = is_array( $result ) ? $result : [];
		$username = $result['username'] ?? '';
		return [
			'id'       => Linking::uid( $result['id'] ?? '' ),
			'username' => is_string( $username ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', $username ) ? $username : '',
			'name'     => is_string( $result['first_name'] ?? null ) ? sanitize_text_field( $result['first_name'] ) : '',
		];
	}

	/**
	 * Alert about the bot, at most once a day per platform.
	 *
	 * @param string $platform Platform.
	 * @param string $text     Text.
	 */
	private static function alert( string $platform, string $text ): void {
		Logger::warning( 'bot', $platform . ': ' . $text );
		if ( RateLimit::hit( 'bot_webhook_' . $platform, 1, DAY_IN_SECONDS ) ) {
			Alerts::send(
				/* translators: %1$s: messenger name */
				sprintf( __( 'Problem with the %1$s bot', 'forms-notifier-for-elementor' ), $platform === 'bale' ? __( 'Bale', 'forms-notifier-for-elementor' ) : __( 'Telegram', 'forms-notifier-for-elementor' ) ),
				$text,
				[ 'channel' => $platform ]
			);
		}
	}

	/**
	 * Webhook URL without its secret.
	 *
	 * @param string $url URL.
	 */
	private static function masked( string $url ): string {
		return (string) preg_replace( '~(hook/[0-9a-f]{4})[0-9a-f]{28}~', '$1••••', $url );
	}

	/**
	 * ISO 8601, null for 0.
	 *
	 * @param int $time Timestamp.
	 */
	private static function iso( int $time ): ?string {
		return $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;
	}

	/** Clock. */
	private static function now(): int {
		return (int) apply_filters( 'fnfe_now', time() );
	}
}
