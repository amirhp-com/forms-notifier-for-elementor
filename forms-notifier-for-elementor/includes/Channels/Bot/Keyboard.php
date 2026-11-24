<?php
/**
 * Inline keyboards.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/** Rendered buttons -> inline keyboard rows a platform accepts. One bad button fails the whole API call, so it is dropped. */
final class Keyboard {

	private const MAX_PER_ROW = 8;
	private const MAX_TOTAL   = 100;
	private const MAX_TEXT    = 64;
	private const MAX_COPY    = 256;
	private const MAX_URL     = 2048;

	/** Names that never resolve on the public internet. */
	private const PRIVATE_SUFFIXES = [ 'local', 'localhost', 'localdomain', 'test', 'invalid', 'example', 'internal', 'intranet', 'lan', 'home', 'corp' ];

	/**
	 * Rendered Message buttons -> inline_keyboard rows valid for the platform and chat type.
	 *
	 * @param array  $buttons   Rows of [ text, type, value, style ]; a flat list is one button per row.
	 * @param string $platform  telegram | bale | soroush.
	 * @param string $chat_type private|group|supergroup|channel|'' (unknown: treated as group).
	 * @param array  $context   submission_id (int), purpose, and to override settings: bot_username, short_name,
	 *                          miniapp (bool), webapp_as_link (bool).
	 * @return array rows of button arrays, [] when nothing is left
	 */
	public static function build( array $buttons, string $platform, string $chat_type, array $context = [] ): array {
		$rows  = [];
		$total = 0;
		foreach ( $buttons as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line = [];
			foreach ( isset( $row['type'] ) ? [ $row ] : $row as $button ) {
				$built = is_array( $button ) && $total < self::MAX_TOTAL ? self::button( $button, $platform, $chat_type, $context ) : null;
				if ( $built ) {
					$line[] = $built;
					++$total;
				}
			}
			foreach ( array_chunk( $line, self::MAX_PER_ROW ) as $chunk ) {
				$rows[] = $chunk;
			}
		}
		return $rows;
	}

	/**
	 * callback_data "fnfe:{action}:{submission_id}:{sig}" (<= 64 bytes), '' when it cannot be built.
	 *
	 * @param string $action        Action id.
	 * @param int    $submission_id Submission id.
	 */
	public static function callback_data( string $action, int $submission_id ): string {
		if ( ! preg_match( '/^[a-z0-9_-]{1,24}$/', $action ) || $submission_id <= 0 ) {
			return '';
		}
		$data = 'fnfe:' . $action . ':' . $submission_id . ':' . self::signature( $action, $submission_id );
		return strlen( $data ) <= BotClient::MAX_CALLBACK ? $data : '';
	}

	/**
	 * Read callback_data.
	 *
	 * @param string $data callback_data of a button.
	 * @return array{action:string,submission_id:int}|null null when malformed or the signature is invalid
	 */
	public static function parse_callback( string $data ): ?array {
		if ( strlen( $data ) > BotClient::MAX_CALLBACK || ! preg_match( '/^fnfe:([a-z0-9_-]{1,24}):([1-9]\d{0,17}):([0-9a-f]{16})$/D', $data, $m ) ) {
			return null;
		}
		if ( ! hash_equals( self::signature( $m[1], (int) $m[2] ), $m[3] ) ) {
			return null;
		}
		return [
			'action'        => $m[1],
			'submission_id' => (int) $m[2],
		];
	}

	/**
	 * The messenger can open this address: http(s) and a host that is not local or private.
	 *
	 * @param string $url URL.
	 */
	public static function is_public_url( string $url ): bool {
		if ( strlen( $url ) > self::MAX_URL || ! preg_match( '~^https?://[^\s<>"\x00-\x1f]+$~i', $url ) ) {
			return false;
		}
		return self::is_public_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Host that is not local or private by its name or address.
	 *
	 * @param string $host Host.
	 */
	public static function is_public_host( string $host ): bool {
		$host = rtrim( strtolower( trim( $host, '[]' ) ), '.' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		$parts = explode( '.', $host );
		return count( $parts ) > 1 && ! in_array( '', $parts, true ) && ! in_array( end( $parts ), self::PRIVATE_SUFFIXES, true );
	}

	/**
	 * Link that opens the mini app from any chat, '' when the bot name is unknown.
	 *
	 * @param string $platform telegram | bale.
	 * @param string $param    Start parameter.
	 * @param array  $context  See build().
	 */
	public static function direct_link( string $platform, string $param = '', array $context = [] ): string {
		$bot = (string) ( $context['bot_username'] ?? State::get( "bot.$platform.me.username", '' ) );
		$bot = ltrim( $bot, '@' );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $bot ) ) {
			return '';
		}
		$short = (string) ( $context['short_name'] ?? Settings::str( "channels.$platform.miniapp_short_name" ) );
		$short = preg_match( '/^[A-Za-z0-9_]{1,64}$/', $short ) ? '/' . $short : '';
		return ( $platform === 'bale' ? 'https://ble.ir/' : 'https://t.me/' ) . $bot . $short . '?startapp=' . rawurlencode( $param );
	}

	/**
	 * Start parameter of a mini app address: its startapp argument, or the one that matches its path.
	 *
	 * @param string $url Mini app address.
	 */
	public static function start_param( string $url ): string {
		if ( preg_match( '/[?&](?:startapp|tgWebAppStartParam)=([A-Za-z0-9_-]{1,64})(?:[&#]|$)/', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '~#/(submission|form)s?/(\d{1,18})~', $url, $m ) ) {
			return ( $m[1] === 'form' ? 'form_' : 'sub_' ) . $m[2];
		}
		return '';
	}

	/**
	 * One button, null when the platform would refuse it.
	 *
	 * @param array  $button    Rendered button.
	 * @param string $platform  Platform.
	 * @param string $chat_type Chat type.
	 * @param array  $context   Context.
	 */
	private static function button( array $button, string $platform, string $chat_type, array $context ): ?array {
		$text = self::text( $button['text'] ?? '' );
		if ( $text === '' ) {
			return null;
		}
		$type  = (string) ( $button['type'] ?? '' );
		$value = trim( (string) ( $button['value'] ?? '' ) );

		if ( $type === 'submission' ) {
			$type = self::miniapp_enabled( $context ) && strncmp( $value, 'https://', 8 ) === 0 && self::start_param( $value ) !== '' ? 'web_app' : 'url';
		}

		$action = null;
		switch ( $type ) {
			case 'url':
				$action = self::url_action( $value, $platform );
				break;
			case 'callback':
				// Soroush Plus updates are not read, so nobody would answer the button.
				$action = $platform === 'soroush' ? null : self::callback_action( $value, $context );
				break;
			case 'web_app':
				$action = self::web_app_action( $value, $platform, $chat_type, $context );
				break;
			case 'copy':
				$action = $value === '' || $platform === 'soroush' ? null : [ 'copy_text' => [ 'text' => Text::truncate( $value, self::MAX_COPY ) ] ];
				break;
		}
		if ( ! $action ) {
			return null;
		}

		$built = array_merge( [ 'text' => $text ], $action );
		if ( $platform !== 'soroush' && in_array( $button['style'] ?? '', [ 'primary', 'success', 'danger' ], true ) ) {
			$built['style'] = $button['style'];
		}
		return $built;
	}

	/**
	 * Link button.
	 *
	 * @param string $value    URL.
	 * @param string $platform Platform.
	 */
	private static function url_action( string $value, string $platform ): ?array {
		if ( $platform === 'telegram' && preg_match( '~^tg://[A-Za-z0-9_?=&./%+-]{1,500}$~i', $value ) ) {
			return [ 'url' => $value ];
		}
		$url = esc_url_raw( $value, [ 'http', 'https' ] );
		return self::is_public_url( $url ) ? [ 'url' => $url ] : null;
	}

	/**
	 * Action button. Only notifications of stored submissions have one.
	 *
	 * @param string $value   Action id, or complete callback data.
	 * @param array  $context Context.
	 */
	private static function callback_action( string $value, array $context ): ?array {
		if ( ! in_array( $context['purpose'] ?? 'admin', [ '', 'admin' ], true ) ) {
			return null;
		}
		$data = self::parse_callback( $value ) ? $value : self::callback_data( sanitize_key( $value ), (int) ( $context['submission_id'] ?? 0 ) );
		return $data === '' ? null : [ 'callback_data' => $data ];
	}

	/**
	 * Mini app button. Telegram allows web_app only in private chats; elsewhere the direct link is used.
	 *
	 * @param string $value     Mini app address.
	 * @param string $platform  Platform.
	 * @param string $chat_type Chat type.
	 * @param array  $context   Context.
	 */
	private static function web_app_action( string $value, string $platform, string $chat_type, array $context ): ?array {
		if ( ! in_array( $platform, [ 'telegram', 'bale' ], true ) || ! self::miniapp_enabled( $context ) ) {
			return null;
		}
		$as_link = $platform === 'bale'
			? (bool) ( $context['webapp_as_link'] ?? Settings::bool( 'channels.bale.webapp_as_link' ) )
			: $chat_type !== 'private';
		if ( $as_link ) {
			$link = self::direct_link( $platform, self::start_param( $value ), $context );
			return $link === '' ? null : [ 'url' => $link ];
		}
		$url = esc_url_raw( $value, [ 'https' ] );
		return strncmp( $url, 'https://', 8 ) === 0 && self::is_public_url( $url ) ? [ 'web_app' => [ 'url' => $url ] ] : null;
	}

	/**
	 * The mini app is switched on.
	 *
	 * @param array $context Context.
	 */
	private static function miniapp_enabled( array $context ): bool {
		return (bool) ( $context['miniapp'] ?? Settings::bool( 'miniapp.enabled' ) );
	}

	/**
	 * Button label: one line without tags, 1..64 characters.
	 *
	 * @param mixed $text Text.
	 */
	private static function text( $text ): string {
		$text = is_scalar( $text ) ? wp_strip_all_tags( (string) $text ) : '';
		return Text::truncate( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), self::MAX_TEXT );
	}

	/**
	 * Short signature of an action on a submission.
	 *
	 * @param string $action        Action.
	 * @param int    $submission_id Submission id.
	 */
	private static function signature( string $action, int $submission_id ): string {
		return substr( Crypto::sign( $action . '|' . $submission_id, 'callback' ), 0, 16 );
	}
}
