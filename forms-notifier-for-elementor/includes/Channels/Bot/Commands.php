<?php
/**
 * Bot commands.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/** /start /id /help /forms /last /stats /app. Texts are canonical markup, converted for the platform when sent. */
final class Commands {

	/** Sender of messages that group admins write anonymously. */
	private const ANONYMOUS_ADMIN = '1087968824';

	/** Replies per chat and minute. */
	private const PER_MINUTE = 20;

	/**
	 * All commands. Filter: fnfe_bot_commands.
	 *
	 * A handler gets ( string $args, array $message, string $platform, BotClient $client ) and returns canonical markup,
	 * or [ 'text' => canonical markup, 'buttons' => rows of rendered buttons ], or '' to stay silent.
	 *
	 * @param string $platform Platform.
	 * @return array<string,array{desc:string,admin:bool,handler:callable}>
	 */
	public static function all( string $platform ): array {
		$commands = [
			'start' => [
				'desc'    => __( 'Start the bot', 'forms-notifier-for-elementor' ),
				'admin'   => false,
				'handler' => [ self::class, 'start' ],
			],
			'id'    => [
				'desc'    => __( 'Show the id of this chat', 'forms-notifier-for-elementor' ),
				'admin'   => false,
				'handler' => [ self::class, 'id' ],
			],
			'help'  => [
				'desc'    => __( 'List the commands', 'forms-notifier-for-elementor' ),
				'admin'   => false,
				'handler' => [ self::class, 'help' ],
			],
			'forms' => [
				'desc'    => __( 'List the forms', 'forms-notifier-for-elementor' ),
				'admin'   => true,
				'handler' => [ self::class, 'forms' ],
			],
			'last'  => [
				'desc'    => __( 'Show the latest submissions', 'forms-notifier-for-elementor' ),
				'admin'   => true,
				'handler' => [ self::class, 'last' ],
			],
			'stats' => [
				'desc'    => __( 'Show the numbers of today and the last 7 days', 'forms-notifier-for-elementor' ),
				'admin'   => true,
				'handler' => [ self::class, 'stats' ],
			],
			'app'   => [
				'desc'    => __( 'Open the app', 'forms-notifier-for-elementor' ),
				'admin'   => true,
				'handler' => [ self::class, 'app' ],
			],
		];
		return (array) apply_filters( 'fnfe_bot_commands', $commands, $platform );
	}

	/**
	 * Publish the command list in the messenger. A platform that does not know the method is ignored.
	 *
	 * @param string $platform Platform.
	 */
	public static function register( string $platform ): void {
		$client = BotClient::for_platform( $platform );
		if ( ! $client->supports( 'commands' ) || ! $client->has_token() ) {
			return;
		}
		if ( ! Settings::bool( "channels.$platform.commands", true ) ) {
			if ( State::get( "bot.$platform.commands_set", false ) && $client->call( 'deleteMyCommands', [], [], 15 )['ok'] ) {
				State::set( "bot.$platform.commands_set", false );
			}
			return;
		}
		$list = static function () use ( $platform ): array {
			$out = [];
			foreach ( self::all( $platform ) as $name => $command ) {
				$desc = is_array( $command ) ? Text::truncate( wp_strip_all_tags( (string) ( $command['desc'] ?? '' ) ), 250 ) : '';
				if ( $desc !== '' && preg_match( '/^[a-z0-9_]{1,32}$/', (string) $name ) ) {
					$out[] = [
						'command'     => (string) $name,
						'description' => $desc,
					];
				}
			}
			return $out;
		};

		$default  = I18n::default_lang();
		$response = $client->set_my_commands( I18n::with_language( $default, $list ) );
		State::set( "bot.$platform.commands_set", $response['ok'] );
		if ( ! $response['ok'] || $client->is_bale() ) {
			return;
		}
		foreach ( array_unique( array_merge( [ 'en' ], array_keys( I18n::languages() ) ) ) as $lang ) {
			$lang = (string) $lang;
			if ( $lang !== $default && preg_match( '/^[a-z]{2}$/', $lang ) && self::is_available( $lang ) ) {
				$client->set_my_commands( I18n::with_language( $lang, $list ), $lang );
			}
		}
	}

	/**
	 * Handle a message.
	 *
	 * @param string $platform   Platform.
	 * @param array  $message    Untrusted message.
	 * @param bool   $is_channel Channel post: only /id is answered.
	 */
	public static function handle( string $platform, array $message, bool $is_channel = false ): void {
		$text = is_string( $message['text'] ?? null ) ? $message['text'] : '';
		$chat = is_array( $message['chat'] ?? null ) ? $message['chat'] : [];
		$from = is_array( $message['from'] ?? null ) ? $message['from'] : [];
		$id   = self::chat_id( $chat );
		if ( $id === '' || ! preg_match( '~^/([A-Za-z0-9_]{1,32})(?:@([A-Za-z0-9_]{1,64}))?(?:\s+(.*))?$~su', trim( $text ), $m ) ) {
			return;
		}
		$command = strtolower( $m[1] );
		$me      = (string) State::get( "bot.$platform.me.username", '' );
		if ( ( $m[2] ?? '' ) !== '' && $me !== '' && strcasecmp( $m[2], $me ) !== 0 ) {
			return; // Meant for another bot.
		}
		if ( ! empty( $from['is_bot'] ) && Linking::uid( $from['id'] ?? '' ) !== self::ANONYMOUS_ADMIN ) {
			return;
		}
		if ( $is_channel && $command !== 'id' ) {
			return;
		}
		if ( $command !== 'start' && ! Settings::bool( "channels.$platform.commands", true ) ) {
			return;
		}
		if ( ! RateLimit::hit( "bot_cmd_{$platform}_{$id}", self::PER_MINUTE, MINUTE_IN_SECONDS ) ) {
			return;
		}
		$args = trim( $m[3] ?? '' );

		self::in_language(
			$platform,
			$from,
			static function () use ( $platform, $message, $command, $args ): void {
				self::run( $platform, $message, $command, $args );
			}
		);
	}

	/**
	 * Run a callback in the language of a messenger user: the linked WordPress user's, else the client language when the
	 * plugin is translated to it, else the site language.
	 *
	 * @param string   $platform Platform.
	 * @param array    $from     Messenger user.
	 * @param callable $fn       Callback.
	 * @return mixed
	 */
	public static function in_language( string $platform, array $from, callable $fn ) {
		return I18n::with_language( self::language( $platform, $from ), $fn );
	}

	/**
	 * Language code for a messenger user.
	 *
	 * @param string $platform Platform.
	 * @param array  $from     Messenger user.
	 */
	public static function language( string $platform, array $from ): string {
		$admin = Linking::admin( $platform, Linking::uid( $from['id'] ?? '' ) );
		if ( $admin && $admin['wp_user'] > 0 && get_userdata( $admin['wp_user'] ) ) {
			$locale = get_user_locale( $admin['wp_user'] );
			foreach ( I18n::languages() as $code => $language ) {
				if ( $language['locale'] === $locale ) {
					return (string) $code;
				}
			}
			return strtolower( explode( '_', $locale )[0] );
		}
		$code = is_string( $from['language_code'] ?? null ) ? strtolower( $from['language_code'] ) : '';
		foreach ( [ $code, explode( '-', $code )[0] ] as $candidate ) {
			if ( preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $candidate ) && self::is_available( $candidate ) ) {
				return $candidate;
			}
		}
		return I18n::default_lang();
	}

	/**
	 * /start, also with the payload of a link.
	 *
	 * @param string    $args     Payload.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function start( string $args, array $message, string $platform, BotClient $client ): string {
		unset( $client );
		$chat = (array) $message['chat'];
		if ( strncmp( $args, 'link_', 5 ) === 0 ) {
			return self::link( $args, $message, $platform );
		}
		$text = sprintf(
			/* translators: %1$s: site name */
			__( 'Hello! This bot sends the form notifications of %1$s.', 'forms-notifier-for-elementor' ),
			'<b>' . self::esc( self::site() ) . '</b>'
		);
		return $text . "\n\n" . sprintf(
			/* translators: %1$s: chat id */
			__( 'The id of this chat: %1$s', 'forms-notifier-for-elementor' ),
			'<code>' . self::chat_id( $chat ) . '</code>'
		);
	}

	/**
	 * /id
	 *
	 * @param string    $args     Arguments.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function id( string $args, array $message, string $platform, BotClient $client ): string {
		unset( $args, $platform, $client );
		$chat   = (array) $message['chat'];
		$id     = self::chat_id( $chat );
		$thread = self::thread( $message );
		$types  = [
			'private'    => _x( 'Private chat', 'chat type', 'forms-notifier-for-elementor' ),
			'group'      => _x( 'Group', 'chat type', 'forms-notifier-for-elementor' ),
			'supergroup' => _x( 'Group', 'chat type', 'forms-notifier-for-elementor' ),
			'channel'    => _x( 'Channel', 'chat type', 'forms-notifier-for-elementor' ),
		];
		$type   = is_string( $chat['type'] ?? null ) ? $chat['type'] : '';
		$lines  = [];
		if ( $type !== 'private' && is_string( $chat['title'] ?? null ) ) {
			$lines[] = '<b>' . self::esc( Text::truncate( sanitize_text_field( $chat['title'] ), 120 ) ) . '</b>';
		}
		/* translators: %1$s: chat id */
		$lines[] = sprintf( __( 'Chat id: %1$s', 'forms-notifier-for-elementor' ), '<code>' . $id . '</code>' );
		if ( isset( $types[ $type ] ) ) {
			/* translators: %1$s: type of the chat */
			$lines[] = sprintf( __( 'Type: %1$s', 'forms-notifier-for-elementor' ), $types[ $type ] );
		}
		if ( $thread ) {
			/* translators: %1$s: topic id */
			$lines[] = sprintf( __( 'Topic id: %1$s', 'forms-notifier-for-elementor' ), '<code>' . $thread . '</code>' );
		}
		/* translators: %1$s: address of the chat */
		$lines[] = sprintf( __( 'Receiver address: %1$s', 'forms-notifier-for-elementor' ), '<code>' . BotClient::format_address( $id, $thread ) . '</code>' );
		return implode( "\n", $lines );
	}

	/**
	 * /help
	 *
	 * @param string    $args     Arguments.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function help( string $args, array $message, string $platform, BotClient $client ): string {
		unset( $args, $client );
		$admin = Linking::can( $platform, Linking::uid( $message['from']['id'] ?? '' ) );
		$lines = [ '<b>' . __( 'Commands', 'forms-notifier-for-elementor' ) . '</b>' ];
		foreach ( self::all( $platform ) as $name => $command ) {
			if ( is_array( $command ) && ( $admin || empty( $command['admin'] ) ) ) {
				$lines[] = '/' . $name . ' - ' . self::esc( (string) ( $command['desc'] ?? '' ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * /forms
	 *
	 * @param string    $args     Arguments.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function forms( string $args, array $message, string $platform, BotClient $client ): array {
		unset( $args, $message, $client );
		$forms = FormRepository::query(
			[
				'status'   => 'active',
				'per_page' => 10,
				'orderby'  => 'submissions_count',
				'order'    => 'desc',
			]
		);
		if ( ! $forms['items'] ) {
			return [ 'text' => __( 'No forms have been found yet.', 'forms-notifier-for-elementor' ) ];
		}
		$lines   = [ '<b>' . __( 'Forms', 'forms-notifier-for-elementor' ) . '</b>' ];
		$buttons = [];
		foreach ( $forms['items'] as $form ) {
			$name = $form['name'] === '' ? '#' . $form['id'] : $form['name'];
			/* translators: %1$s: form name, %2$s: number of submissions */
			$lines[] = sprintf( __( '%1$s: %2$s', 'forms-notifier-for-elementor' ), self::esc( $name ), number_format_i18n( $form['submissions_count'] ) );
			if ( Settings::bool( 'miniapp.enabled' ) ) {
				$buttons[] = [ self::app_button( $platform, Text::truncate( $name, 60 ), 'form_' . $form['id'] ) ];
			}
		}
		$more = $forms['total'] - count( $forms['items'] );
		if ( $more > 0 ) {
			/* translators: %1$s: number of forms */
			$lines[] = sprintf( _n( 'and %1$s more form', 'and %1$s more forms', $more, 'forms-notifier-for-elementor' ), number_format_i18n( $more ) );
		}
		return [
			'text'    => implode( "\n", $lines ),
			'buttons' => $buttons,
		];
	}

	/**
	 * /last [n]
	 *
	 * @param string    $args     Number of submissions.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function last( string $args, array $message, string $platform, BotClient $client ): array {
		unset( $message, $client );
		$number = (int) Text::en_digits( $args );
		$number = $number > 0 ? min( 10, $number ) : 5;
		$page   = SubmissionRepository::query(
			[
				'per_page' => $number,
				'status'   => [ 'new', 'read', 'done' ],
			]
		);
		if ( ! $page['items'] ) {
			return [ 'text' => __( 'There are no submissions yet.', 'forms-notifier-for-elementor' ) ];
		}
		$lines   = [ '<b>' . __( 'Latest submissions', 'forms-notifier-for-elementor' ) . '</b>' ];
		$buttons = [];
		foreach ( $page['items'] as $submission ) {
			$summary = Controller::submission_summary( $submission );
			$lines[] = '#' . $submission->id . ' ' . self::esc( $submission->form_name ) . ' · ' . self::esc( $summary['created_local'] )
				. "\n" . self::esc( Text::truncate( $summary['preview'], 100 ) );
			/* translators: %1$d: submission number */
			$label     = sprintf( __( 'Open #%1$d', 'forms-notifier-for-elementor' ), $submission->id );
			$buttons[] = Settings::bool( 'miniapp.enabled' )
				? self::app_button( $platform, $label, 'sub_' . $submission->id )
				: [
					'text'  => $label,
					'type'  => 'url',
					'value' => Urls::submission_admin( $submission->id ),
				];
		}
		return [
			'text'    => implode( "\n\n", $lines ),
			'buttons' => array_chunk( $buttons, 2 ),
		];
	}

	/**
	 * /stats
	 *
	 * @param string    $args     Arguments.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function stats( string $args, array $message, string $platform, BotClient $client ): string {
		unset( $args, $message, $platform, $client );
		$blocks = [];
		$ranges = [
			'today' => __( 'Today', 'forms-notifier-for-elementor' ),
			'7d'    => __( 'Last 7 days', 'forms-notifier-for-elementor' ),
		];
		foreach ( $ranges as $range => $label ) {
			$stats    = Stats::summary( $range );
			$blocks[] = '<b>' . $label . "</b>\n" . sprintf(
				/* translators: %1$s: number of submissions, %2$s: messages sent, %3$s: messages failed */
				__( 'Submissions: %1$s, sent: %2$s, failed: %3$s', 'forms-notifier-for-elementor' ),
				number_format_i18n( (int) ( $stats['submissions'] ?? 0 ) ),
				number_format_i18n( (int) ( $stats['messages']['sent'] ?? 0 ) ),
				number_format_i18n( (int) ( $stats['messages']['failed'] ?? 0 ) )
			);
		}
		/* translators: %1$s: number of messages */
		$blocks[] = sprintf( __( 'Waiting in the queue: %1$s', 'forms-notifier-for-elementor' ), number_format_i18n( Queue::pending() ) );
		return implode( "\n\n", $blocks );
	}

	/**
	 * /app
	 *
	 * @param string    $args     Arguments.
	 * @param array     $message  Message.
	 * @param string    $platform Platform.
	 * @param BotClient $client   Client.
	 */
	public static function app( string $args, array $message, string $platform, BotClient $client ): array {
		unset( $args, $message, $client );
		if ( ! Settings::bool( 'miniapp.enabled' ) ) {
			return [ 'text' => __( 'The app is switched off in the plugin settings.', 'forms-notifier-for-elementor' ) ];
		}
		return [
			'text'    => __( 'Forms and submissions of the site:', 'forms-notifier-for-elementor' ),
			'buttons' => [ [ self::app_button( $platform, __( 'Open the app', 'forms-notifier-for-elementor' ), '' ) ] ],
		];
	}

	/**
	 * Permission checks, then the handler and its reply.
	 *
	 * @param string $platform Platform.
	 * @param array  $message  Message.
	 * @param string $command  Command name.
	 * @param string $args     Arguments.
	 */
	private static function run( string $platform, array $message, string $command, string $args ): void {
		$client     = BotClient::for_platform( $platform );
		$definition = self::all( $platform )[ $command ] ?? null;
		if ( ! is_array( $definition ) || ! is_callable( $definition['handler'] ?? null ) ) {
			do_action( 'fnfe_bot_command', $command, $message, $platform, $client );
			return;
		}

		$chat    = (array) $message['chat'];
		$chat_id = self::chat_id( $chat );
		$address = BotClient::format_address( $chat_id, self::thread( $message ) );
		$private = ( $chat['type'] ?? '' ) === 'private';
		$uid     = Linking::uid( $message['from']['id'] ?? '' );

		if ( ! empty( $definition['admin'] ) ) {
			if ( ! Linking::can( $platform, $uid ) ) {
				if ( RateLimit::hit( "bot_refuse_{$platform}_{$chat_id}", 1, MINUTE_IN_SECONDS ) ) {
					self::reply( $platform, $address, $chat, __( 'Only the admins of this bot can use this command.', 'forms-notifier-for-elementor' ) );
				}
				return;
			}
			$username = is_string( $chat['username'] ?? null ) ? $chat['username'] : '';
			if ( ! $private && ! ChatDiscovery::is_receiver( $platform, $chat_id, $username ) ) {
				self::reply(
					$platform,
					$uid,
					[ 'type' => 'private' ],
					__( 'This group does not receive submissions, so the command is not answered there. Send it to me here.', 'forms-notifier-for-elementor' )
				);
				return;
			}
		}

		$answer = call_user_func( $definition['handler'], $args, $message, $platform, $client );
		$answer = is_array( $answer ) ? $answer : [ 'text' => $answer ];
		$text   = is_string( $answer['text'] ?? null ) ? $answer['text'] : '';
		if ( $text !== '' ) {
			self::reply( $platform, $address, $chat, $text, (array) ( $answer['buttons'] ?? [] ) );
		}
	}

	/**
	 * Use a link code.
	 *
	 * @param string $code     Payload.
	 * @param array  $message  Message.
	 * @param string $platform Platform.
	 */
	private static function link( string $code, array $message, string $platform ): string {
		$from = is_array( $message['from'] ?? null ) ? $message['from'] : [];
		if ( ( $message['chat']['type'] ?? '' ) !== 'private' ) {
			return __( 'Open the link in a private chat with the bot.', 'forms-notifier-for-elementor' );
		}
		$uid  = Linking::uid( $from['id'] ?? '' );
		$user = RateLimit::hit( "bot_link_{$platform}_{$uid}", 10, 10 * MINUTE_IN_SECONDS ) ? Linking::consume( $platform, $code, $from ) : null;
		if ( ! $user ) {
			return __( 'This link is not valid or has expired. Create a new one in the plugin settings.', 'forms-notifier-for-elementor' );
		}
		return sprintf(
			/* translators: %1$s: site name, %2$s: user name */
			__( 'Linked to %1$s as %2$s. Send /help to see the commands.', 'forms-notifier-for-elementor' ),
			'<b>' . self::esc( self::site() ) . '</b>',
			'<b>' . self::esc( wp_specialchars_decode( $user->display_name, ENT_QUOTES ) ) . '</b>'
		);
	}

	/**
	 * Send canonical markup to a chat.
	 *
	 * @param string $platform Platform.
	 * @param string $address  Address.
	 * @param array  $chat     Chat.
	 * @param string $text     Canonical markup.
	 * @param array  $buttons  Rows of rendered buttons.
	 */
	private static function reply( string $platform, string $address, array $chat, string $text, array $buttons = [] ): void {
		$client    = BotClient::for_platform( $platform );
		$formatter = Registry::formatter( $platform === 'bale' ? 'bale_md' : 'telegram_html' );
		$type      = is_string( $chat['type'] ?? null ) ? $chat['type'] : '';
		$keyboard  = Keyboard::build( $buttons, $platform, $type, [ 'purpose' => 'bot' ] );
		$parts     = $formatter->split( $formatter->convert( $text ), BotClient::MAX_TEXT );
		foreach ( array_values( $parts ) as $i => $part ) {
			$opts = [
				'parse_mode'      => 'HTML',
				'disable_preview' => true,
				'chat_type'       => $type,
			];
			if ( $keyboard && $i === count( $parts ) - 1 ) {
				$opts['reply_markup'] = [ 'inline_keyboard' => $keyboard ];
			}
			$response = $client->send_message( $address, $part, $opts );
			if ( ! $response['ok'] && $response['code'] === 400 && isset( $opts['reply_markup'] ) && stripos( $response['error'], 'button' ) !== false ) {
				unset( $opts['reply_markup'] );
				$client->send_message( $address, $part, $opts );
			}
		}
	}

	/**
	 * Button that opens the mini app.
	 *
	 * @param string $platform Platform.
	 * @param string $label    Label.
	 * @param string $param    Start parameter.
	 */
	private static function app_button( string $platform, string $label, string $param ): array {
		$url = Urls::miniapp( $platform );
		return [
			'text'  => $label,
			'type'  => 'web_app',
			'value' => $param === '' ? $url : add_query_arg( 'startapp', $param, $url ),
		];
	}

	/**
	 * The plugin speaks this language.
	 *
	 * @param string $lang Language code.
	 */
	private static function is_available( string $lang ): bool {
		if ( $lang === 'en' || $lang === I18n::default_lang() ) {
			return true;
		}
		$locale = I18n::locale_of( $lang );
		if ( ! in_array( $locale, get_available_languages(), true ) ) {
			return false;
		}
		$file = 'forms-notifier-for-elementor-' . $locale;
		return is_file( FNFE_DIR . 'languages/' . $file . '.mo' ) || is_file( WP_LANG_DIR . '/plugins/' . $file . '.mo' );
	}

	/**
	 * Chat id of a chat object, '' when invalid.
	 *
	 * @param array $chat Chat.
	 */
	private static function chat_id( array $chat ): string {
		$id = $chat['id'] ?? '';
		$id = is_int( $id ) || is_string( $id ) ? (string) $id : '';
		return preg_match( '/^-?[1-9]\d{0,19}$/D', $id ) ? $id : '';
	}

	/**
	 * Topic of a message, 0 when it is in none.
	 *
	 * @param array $message Message.
	 */
	private static function thread( array $message ): int {
		$thread = is_numeric( $message['message_thread_id'] ?? null ) ? (int) $message['message_thread_id'] : 0;
		$topic  = ! empty( $message['is_topic_message'] ) || ( $message['chat']['type'] ?? '' ) === 'private';
		return $thread > 1 && $topic ? $thread : 0;
	}

	/** Site name. */
	private static function site(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Escape a value for canonical markup.
	 *
	 * @param string $value Value.
	 */
	private static function esc( string $value ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $value );
	}
}
