<?php
/**
 * Chat discovery.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Chats, topics, membership changes and migrations, learned from updates (no Bot API method lists chats or topics).
 * Everything in an update is untrusted.
 */
final class ChatDiscovery {

	private const TYPES = [ 'private', 'group', 'supergroup', 'channel' ];

	/** @var int new rows of this request */
	private static $found = 0;

	/** New chats and topics seen in this request. */
	public static function found(): int {
		return self::$found;
	}

	/**
	 * Learn from one update.
	 *
	 * @param string $platform Platform.
	 * @param array  $update   Update.
	 */
	public static function handle( string $platform, array $update ): void {
		if ( is_array( $update['my_chat_member'] ?? null ) ) {
			self::membership( $platform, $update['my_chat_member'] );
		}
		foreach ( [ 'message', 'channel_post' ] as $kind ) {
			if ( is_array( $update[ $kind ] ?? null ) ) {
				self::message( $platform, $update[ $kind ] );
			}
		}
	}

	/**
	 * A group became a supergroup: move its rows and rewrite every stored receiver.
	 *
	 * @param string $platform Platform.
	 * @param string $old      Old chat id.
	 * @param string $new      New chat id.
	 */
	public static function migrate( string $platform, string $old, string $new ): void {
		if ( ! self::is_id( $old ) || ! self::is_id( $new ) || $old === $new ) {
			return;
		}
		ChatRepository::migrate( $platform, $old, $new );

		$changes = [];
		foreach ( [ "channels.$platform.receivers", "alerts.channels.$platform.receivers", "digest.channels.$platform.receivers" ] as $path ) {
			$receivers = Settings::arr( $path );
			$rewritten = self::rewrite( $receivers, $old, $new );
			if ( $rewritten !== $receivers ) {
				$changes[ $path ] = $rewritten;
			}
		}
		if ( $changes ) {
			Settings::set_many( $changes );
		}

		if ( strpos( FormRepository::raw_configs_containing( $old ), $old ) === false ) {
			return;
		}
		foreach ( FormRepository::export_rows() as $row ) {
			$config = $row['config'];
			if ( isset( $config['channels'][ $platform ]['receivers'] ) ) {
				$config['channels'][ $platform ]['receivers'] = self::rewrite( $config['channels'][ $platform ]['receivers'], $old, $new );
			}
			foreach ( $config['rules'] as $i => $rule ) {
				if ( isset( $rule['receivers'][ $platform ] ) ) {
					$config['rules'][ $i ]['receivers'][ $platform ] = self::rewrite( $rule['receivers'][ $platform ], $old, $new );
				}
			}
			$form = $config === $row['config'] ? null : FormRepository::find_by_key( $row['form_key'] );
			if ( $form ) {
				FormRepository::save_config( $form['id'], $config );
			}
		}
	}

	/**
	 * The chat is a receiver: site-wide, of a form, of a rule, of alerts or of the digest.
	 *
	 * @param string $platform Platform.
	 * @param string $chat_id  Chat id.
	 * @param string $username Public name of the chat.
	 */
	public static function is_receiver( string $platform, string $chat_id, string $username = '' ): bool {
		$names = [ $chat_id ];
		if ( $username !== '' ) {
			$names[] = '@' . strtolower( $username );
		}
		foreach ( [ 'channels', 'alerts.channels', 'digest.channels' ] as $root ) {
			if ( self::contains( Settings::arr( "$root.$platform.receivers" ), $names ) ) {
				return true;
			}
		}

		$needles = array_filter( [ $chat_id, $username ], 'strlen' );
		$stored  = false;
		foreach ( $needles as $needle ) {
			$stored = $stored || FormRepository::raw_configs_containing( $needle ) !== '';
		}
		if ( ! $stored ) {
			return false;
		}
		foreach ( FormRepository::export_rows() as $row ) {
			if ( self::contains( $row['config']['channels'][ $platform ]['receivers'] ?? [], $names ) ) {
				return true;
			}
			foreach ( $row['config']['rules'] as $rule ) {
				if ( self::contains( $rule['receivers'][ $platform ] ?? [], $names ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Type of a chat: from the chat table, else from the address where that is certain, '' when unknown.
	 *
	 * @param string $platform Platform.
	 * @param string $chat     Chat part of an address.
	 */
	public static function chat_type( string $platform, string $chat ): string {
		$row = self::is_id( $chat ) ? ChatRepository::find_chat( $platform, $chat ) : null;
		if ( $row && $row['type'] !== '' ) {
			return $row['type'];
		}
		// On Telegram a positive id is always a person.
		return $platform === 'telegram' && preg_match( '/^[1-9]\d*$/', $chat ) ? 'private' : '';
	}

	/**
	 * The messenger refused the chat for good.
	 *
	 * @param string $platform Platform.
	 * @param string $chat     Chat part of an address.
	 */
	public static function mark_lost( string $platform, string $chat ): void {
		$row = self::is_id( $chat ) ? ChatRepository::find_chat( $platform, $chat ) : null;
		if ( $row && $row['status'] === 'active' ) {
			ChatRepository::upsert( $platform, $chat, 0, [ 'status' => 'kicked' ] );
		}
	}

	/**
	 * Row in the shape of the REST API.
	 *
	 * @param array $row Row of ChatRepository.
	 */
	public static function client_row( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'chat_id'    => (string) $row['chat_id'],
			'thread_id'  => (int) $row['thread_id'],
			'type'       => (string) $row['type'],
			'title'      => self::title( $row ),
			'username'   => (string) $row['username'],
			'topic_name' => self::topic_name( $row ),
			'is_forum'   => (bool) $row['is_forum'],
			'status'     => (string) $row['status'],
			'bot_role'   => (string) $row['bot_role'],
			'address'    => BotClient::format_address( (string) $row['chat_id'], $row['thread_id'] ),
			'last_seen'  => str_replace( ' ', 'T', (string) $row['last_seen_gmt'] ) . 'Z',
		];
	}

	/**
	 * Rows of the receivers picker: chats with their topics below.
	 *
	 * @param string $platform Platform.
	 * @param array  $params   search, type, page.
	 */
	public static function options( string $platform, array $params ): array {
		$search = is_scalar( $params['search'] ?? null ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$type   = is_scalar( $params['type'] ?? null ) ? (string) $params['type'] : '';
		$types  = $type === 'group' ? [ 'group', 'supergroup' ] : $type;
		$page   = ChatRepository::query(
			[
				'platform'    => $platform,
				'type'        => in_array( $type, self::TYPES, true ) ? $types : '',
				'status'      => [ 'active', 'closed' ],
				'search'      => $search,
				'with_topics' => $search !== '',
				'page'        => max( 1, (int) ( $params['page'] ?? 1 ) ),
				'per_page'    => 50,
			]
		);
		$rows   = [];
		foreach ( $page['items'] as $row ) {
			$rows[] = self::option( $row );
			if ( $search === '' ) {
				foreach ( ChatRepository::topics( $platform, $row['chat_id'] ) as $topic ) {
					if ( in_array( $topic['status'], [ 'active', 'closed' ], true ) ) {
						$rows[] = self::option( $topic );
					}
				}
			}
		}
		return [
			'rows'  => $rows,
			'total' => (int) $page['total'],
			'page'  => (int) $page['page'],
		];
	}

	/**
	 * Display title of a chat row.
	 *
	 * @param array $row Row.
	 */
	public static function title( array $row ): string {
		$custom = $row['meta']['custom_title'] ?? '';
		return is_string( $custom ) && $custom !== '' ? $custom : (string) $row['title'];
	}

	/**
	 * Display name of a topic row.
	 *
	 * @param array $row Row.
	 */
	public static function topic_name( array $row ): string {
		if ( ! (int) $row['thread_id'] ) {
			return '';
		}
		$custom = $row['meta']['custom_topic'] ?? '';
		$name   = is_string( $custom ) && $custom !== '' ? $custom : (string) $row['topic_name'];
		/* translators: %1$d: number of a forum topic */
		return $name === '' ? sprintf( __( 'Topic %1$d', 'forms-notifier-for-elementor' ), $row['thread_id'] ) : $name;
	}

	/**
	 * Message or channel post.
	 *
	 * @param string $platform Platform.
	 * @param array  $message  Message.
	 */
	private static function message( string $platform, array $message ): void {
		$chat = is_array( $message['chat'] ?? null ) ? $message['chat'] : [];
		$id   = self::id( $chat['id'] ?? '' );
		if ( $id === '' ) {
			return;
		}

		$new = self::id( $message['migrate_to_chat_id'] ?? '' );
		if ( $new !== '' ) {
			self::migrate( $platform, $id, $new );
			return;
		}
		$old = self::id( $message['migrate_from_chat_id'] ?? '' );
		if ( $old !== '' ) {
			self::migrate( $platform, $old, $id );
		}

		$data = self::chat_data( $chat );
		if ( self::is_bot( $platform, $message['left_chat_member'] ?? null ) ) {
			$data['status']   = 'left';
			$data['bot_role'] = '';
		} else {
			$data['status'] = 'active';
			foreach ( is_array( $message['new_chat_members'] ?? null ) ? $message['new_chat_members'] : [] as $member ) {
				if ( self::is_bot( $platform, $member ) ) {
					$data['bot_role'] = 'member';
				}
			}
		}
		if ( $data['type'] === 'private' && is_array( $message['from'] ?? null ) && empty( $message['from']['is_bot'] ) ) {
			$data['user_id'] = self::id( $message['from']['id'] ?? '' );
			$lang            = $message['from']['language_code'] ?? '';
			if ( is_string( $lang ) && preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $lang ) ) {
				$data['lang'] = strtolower( $lang );
			}
		}
		self::store( $platform, $id, 0, $data );
		self::topic( $platform, $id, $data, $message );
	}

	/**
	 * Topic of a message.
	 *
	 * @param string $platform Platform.
	 * @param string $chat_id  Chat id.
	 * @param array  $chat     Data of the chat row.
	 * @param array  $message  Message.
	 */
	private static function topic( string $platform, string $chat_id, array $chat, array $message ): void {
		$thread = is_numeric( $message['message_thread_id'] ?? null ) ? (int) $message['message_thread_id'] : 0;
		// Replies in ordinary supergroups carry a thread id too; only topic messages count. Bale has topics in private chats only.
		$is_topic = ! empty( $message['is_topic_message'] ) || ( $platform === 'bale' && $chat['type'] === 'private' );
		if ( $thread <= 1 || ! $is_topic ) {
			return;
		}

		$data = [
			'type'   => $chat['type'],
			'title'  => $chat['title'],
			'status' => 'active',
		];
		$name = '';
		foreach ( [ 'forum_topic_edited', 'forum_topic_created' ] as $event ) {
			$name = $name === '' ? self::line( $message[ $event ]['name'] ?? '', 191 ) : $name;
		}
		$known = ChatRepository::find_chat( $platform, $chat_id, $thread );
		if ( $name === '' && ( ! $known || $known['topic_name'] === '' ) ) {
			$name = self::line( $message['reply_to_message']['forum_topic_created']['name'] ?? '', 191 );
		}
		if ( $name !== '' ) {
			$data['topic_name'] = $name;
		}
		if ( isset( $message['forum_topic_closed'] ) ) {
			$data['status'] = 'closed';
		} elseif ( $known && $known['status'] === 'closed' && ! isset( $message['forum_topic_reopened'] ) ) {
			$data['status'] = 'closed'; // Admins may still write in a closed topic.
		}
		self::store( $platform, $chat_id, $thread, $data );
	}

	/**
	 * The bot was added, promoted, removed or blocked.
	 *
	 * @param string $platform Platform.
	 * @param array  $event    ChatMemberUpdated.
	 */
	private static function membership( string $platform, array $event ): void {
		$chat   = is_array( $event['chat'] ?? null ) ? $event['chat'] : [];
		$id     = self::id( $chat['id'] ?? '' );
		$member = is_array( $event['new_chat_member'] ?? null ) ? $event['new_chat_member'] : [];
		if ( $id === '' ) {
			return;
		}
		$data = self::chat_data( $chat );
		switch ( $member['status'] ?? '' ) {
			case 'creator':
			case 'administrator':
				$data['status']   = 'active';
				$data['bot_role'] = 'administrator';
				break;
			case 'member':
				$data['status']   = 'active';
				$data['bot_role'] = 'member';
				break;
			case 'restricted':
				$data['status']   = empty( $member['is_member'] ) ? 'left' : 'active';
				$data['bot_role'] = empty( $member['is_member'] ) ? '' : 'member';
				break;
			case 'left':
				$data['status']   = 'left';
				$data['bot_role'] = '';
				break;
			case 'kicked':
				$data['status']   = 'kicked';
				$data['bot_role'] = '';
				break;
			default:
				return;
		}
		if ( $data['type'] === 'private' ) {
			$data['user_id'] = $id;
		}
		self::store( $platform, $id, 0, $data );
	}

	/**
	 * Save a row and announce it.
	 *
	 * @param string $platform Platform.
	 * @param string $chat_id  Chat id.
	 * @param int    $thread   Topic id.
	 * @param array  $data     Data.
	 */
	private static function store( string $platform, string $chat_id, int $thread, array $data ): void {
		$is_new = ChatRepository::find_chat( $platform, $chat_id, $thread ) === null;
		$id     = ChatRepository::upsert( $platform, $chat_id, $thread, $data );
		if ( ! $id ) {
			return;
		}
		self::$found += $is_new ? 1 : 0;
		do_action( 'fnfe_chat_discovered', (array) ChatRepository::find( $id ), $is_new );
	}

	/**
	 * Columns known from a Chat object.
	 *
	 * @param array $chat Chat.
	 */
	private static function chat_data( array $chat ): array {
		$title = self::line( $chat['title'] ?? '', 191 );
		if ( $title === '' ) {
			$title = trim( self::line( $chat['first_name'] ?? '', 90 ) . ' ' . self::line( $chat['last_name'] ?? '', 90 ) );
		}
		$username = is_string( $chat['username'] ?? null ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', $chat['username'] ) ? $chat['username'] : '';
		return [
			'type'     => in_array( $chat['type'] ?? '', self::TYPES, true ) ? $chat['type'] : '',
			'title'    => $title === '' ? $username : $title,
			'username' => $username,
			'is_forum' => ! empty( $chat['is_forum'] ),
		];
	}

	/**
	 * Row of the receivers picker.
	 *
	 * @param array $row Row.
	 */
	private static function option( array $row ): array {
		$groups = [
			'private'    => __( 'People', 'forms-notifier-for-elementor' ),
			'group'      => __( 'Groups', 'forms-notifier-for-elementor' ),
			'supergroup' => __( 'Groups', 'forms-notifier-for-elementor' ),
			'channel'    => __( 'Channels', 'forms-notifier-for-elementor' ),
		];
		$title  = self::title( $row );
		$topic  = self::topic_name( $row );
		return [
			'address' => BotClient::format_address( $row['chat_id'], $row['thread_id'] ),
			'label'   => $topic === '' ? $title : $title . ' › ' . $topic,
			'group'   => $groups[ $row['type'] ] ?? __( 'Other chats', 'forms-notifier-for-elementor' ),
			'hint'    => $row['username'] === '' ? BotClient::format_address( $row['chat_id'], $row['thread_id'] ) : '@' . $row['username'],
			'meta'    => [
				'type'  => $row['type'],
				'title' => $title,
				'topic' => $topic,
				'forum' => $row['is_forum'],
			],
		];
	}

	/**
	 * Receivers with the chat part replaced.
	 *
	 * @param array  $receivers Receivers.
	 * @param string $old       Old chat id.
	 * @param string $new       New chat id.
	 */
	private static function rewrite( array $receivers, string $old, string $new ): array {
		foreach ( $receivers as $i => $receiver ) {
			$parsed = is_array( $receiver ) ? BotClient::parse_address( (string) ( $receiver['address'] ?? '' ) ) : null;
			if ( $parsed && $parsed['chat'] === $old ) {
				$receivers[ $i ]['address'] = BotClient::format_address( $new, $parsed['thread'] );
			}
		}
		return $receivers;
	}

	/**
	 * One of the chats is in a list of receivers.
	 *
	 * @param array    $receivers Receivers.
	 * @param string[] $names     Chat parts of addresses, lowercase.
	 */
	private static function contains( array $receivers, array $names ): bool {
		foreach ( $receivers as $receiver ) {
			$parsed = is_array( $receiver ) && ! empty( $receiver['enabled'] ) ? BotClient::parse_address( (string) ( $receiver['address'] ?? '' ) ) : null;
			if ( $parsed && in_array( strtolower( $parsed['chat'] ), $names, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A user object is this bot.
	 *
	 * @param string $platform Platform.
	 * @param mixed  $user     User.
	 */
	private static function is_bot( string $platform, $user ): bool {
		$id = is_array( $user ) ? self::id( $user['id'] ?? '' ) : '';
		if ( $id === '' ) {
			return false;
		}
		$me = (string) State::get( "bot.$platform.me.id", '' );
		return $id === ( $me === '' ? BotClient::for_platform( $platform )->bot_id() : $me );
	}

	/**
	 * Chat or user id as a string, '' when it is none.
	 *
	 * @param mixed $value Value.
	 */
	private static function id( $value ): string {
		return ( is_int( $value ) || is_string( $value ) ) && self::is_id( (string) $value ) ? (string) $value : '';
	}

	/**
	 * Numeric id.
	 *
	 * @param string $value Value.
	 */
	private static function is_id( string $value ): bool {
		return (bool) preg_match( '/^-?[1-9]\d{0,19}$/D', $value );
	}

	/**
	 * One line of clean text.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Longest result.
	 */
	private static function line( $value, int $length ): string {
		return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, $length ) : '';
	}
}
