<?php
/**
 * Inline button actions.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Mark a submission as read, done or spam, or retry its failed messages, from a button under the notification. */
final class Callbacks {

	private const CHECK = '✓ ';

	/** Built-in and custom actions: id => [ label, status? ]. Filter: fnfe_callback_actions. */
	public static function actions(): array {
		return (array) apply_filters(
			'fnfe_callback_actions',
			[
				'read'  => [
					'label'  => __( 'Mark as read', 'forms-notifier-for-elementor' ),
					'status' => 'read',
				],
				'done'  => [
					'label'  => __( 'Mark as done', 'forms-notifier-for-elementor' ),
					'status' => 'done',
				],
				'spam'  => [
					'label'  => __( 'Mark as spam', 'forms-notifier-for-elementor' ),
					'status' => 'spam',
				],
				'retry' => [
					'label' => __( 'Send failed messages again', 'forms-notifier-for-elementor' ),
				],
			]
		);
	}

	/**
	 * Handle a button press. It is always answered, otherwise the button keeps spinning in the messenger.
	 *
	 * @param string $platform Platform.
	 * @param array  $query    Untrusted callback_query.
	 */
	public static function handle( string $platform, array $query ): void {
		$id = $query['id'] ?? '';
		$id = is_int( $id ) || is_string( $id ) ? (string) $id : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $id ) ) {
			return;
		}
		$from  = is_array( $query['from'] ?? null ) ? $query['from'] : [];
		$reply = [ '', false ];
		try {
			$reply = Commands::in_language(
				$platform,
				$from,
				static function () use ( $platform, $query, $from ): array {
					return self::apply( $platform, $query, $from );
				}
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'bot', $platform . ' button failed: ' . $e->getMessage() );
			$reply = [ __( 'Something went wrong.', 'forms-notifier-for-elementor' ), true ];
		} finally {
			BotClient::for_platform( $platform )->answer_callback( $id, $reply[0], $reply[1] );
		}

		if ( ! empty( $reply[2] ) ) {
			self::mark( $platform, $query, Linking::display_name( $from ) );
		}
	}

	/**
	 * Run the action.
	 *
	 * @param string $platform Platform.
	 * @param array  $query    callback_query.
	 * @param array  $from     Messenger user.
	 * @return array{0:string,1:bool,2?:bool} [ answer, show as dialog, mark the button ]
	 */
	private static function apply( string $platform, array $query, array $from ): array {
		$data   = is_string( $query['data'] ?? null ) ? $query['data'] : '';
		$parsed = Keyboard::parse_callback( $data );
		if ( ! $parsed ) {
			return [ __( 'This button is not valid any more.', 'forms-notifier-for-elementor' ), true ];
		}
		if ( ! self::authorized( $platform, $query, $from ) ) {
			return [ __( 'You are not allowed to use this button.', 'forms-notifier-for-elementor' ), true ];
		}

		$action     = $parsed['action'];
		$submission = SubmissionRepository::find( $parsed['submission_id'] );
		if ( ! $submission ) {
			return [ __( 'This submission does not exist any more.', 'forms-notifier-for-elementor' ), true ];
		}

		$definition = self::actions()[ $action ] ?? null;
		$status     = is_array( $definition ) ? (string) ( $definition['status'] ?? '' ) : '';
		if ( in_array( $status, [ 'new', 'read', 'done', 'spam', 'trash' ], true ) ) {
			$old = $submission->status;
			if ( $old !== $status ) {
				if ( ! SubmissionRepository::set_status( $submission->id, $status ) ) {
					return [ __( 'The change could not be saved.', 'forms-notifier-for-elementor' ), true ];
				}
				do_action( 'fnfe_submission_status_changed', $submission->id, $status, $old, 'bot' );
			}
			return [ self::confirmation( $status ), false, true ];
		}

		if ( $action === 'retry' && is_array( $definition ) ) {
			return self::retry( $submission->id );
		}

		do_action( 'fnfe_bot_callback', $action, $submission->id, $query, $platform );
		return [ is_array( $definition ) ? (string) ( $definition['label'] ?? '' ) : '', false, is_array( $definition ) ];
	}

	/**
	 * Who may press: admins with the permission "act", or everybody in a chat that is a receiver.
	 *
	 * @param string $platform Platform.
	 * @param array  $query    callback_query.
	 * @param array  $from     Messenger user.
	 */
	private static function authorized( string $platform, array $query, array $from ): bool {
		$uid = Linking::uid( $from['id'] ?? '' );
		if ( $uid === '' ) {
			return false;
		}
		if ( Linking::can( $platform, $uid, 'act' ) ) {
			return true;
		}
		if ( Settings::str( "channels.$platform.callback_access", 'admins' ) !== 'chat' ) {
			return false;
		}
		$chat = $query['message']['chat'] ?? null;
		$id   = is_array( $chat ) ? $chat['id'] ?? '' : '';
		if ( ! is_int( $id ) && ! is_string( $id ) ) {
			return false;
		}
		$name = is_string( $chat['username'] ?? null ) ? $chat['username'] : '';
		return (string) $id !== '' && ChatDiscovery::is_receiver( $platform, (string) $id, $name );
	}

	/**
	 * Send the failed messages of a submission again.
	 *
	 * @param int $submission_id Submission.
	 */
	private static function retry( int $submission_id ): array {
		$ids = [];
		foreach ( MessageRepository::for_submission( $submission_id ) as $row ) {
			if ( in_array( $row['status'], [ 'failed', 'retry' ], true ) && Queue::retry( (int) $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( ! $ids ) {
			return [ __( 'There is nothing to send again.', 'forms-notifier-for-elementor' ), false ];
		}
		Queue::run_ids( $ids );
		return [
			sprintf(
				/* translators: %1$s: number of messages */
				_n( '%1$s message is sent again.', '%1$s messages are sent again.', count( $ids ), 'forms-notifier-for-elementor' ),
				number_format_i18n( count( $ids ) )
			),
			false,
			true,
		];
	}

	/**
	 * Short answer to a status change.
	 *
	 * @param string $status Status.
	 */
	private static function confirmation( string $status ): string {
		switch ( $status ) {
			case 'read':
				return __( 'Marked as read.', 'forms-notifier-for-elementor' );
			case 'done':
				return __( 'Marked as done.', 'forms-notifier-for-elementor' );
			case 'spam':
				return __( 'Marked as spam.', 'forms-notifier-for-elementor' );
			case 'trash':
				return __( 'Moved to the trash.', 'forms-notifier-for-elementor' );
		}
		return __( 'Marked as new.', 'forms-notifier-for-elementor' );
	}

	/**
	 * Show on the keyboard what was pressed and by whom.
	 *
	 * @param string $platform Platform.
	 * @param array  $query    callback_query.
	 * @param string $name     Who pressed.
	 */
	private static function mark( string $platform, array $query, string $name ): void {
		$message = is_array( $query['message'] ?? null ) ? $query['message'] : [];
		$rows    = $message['reply_markup']['inline_keyboard'] ?? null;
		$chat    = $message['chat']['id'] ?? '';
		if ( ! is_array( $rows ) || ! is_numeric( $message['message_id'] ?? null ) || ! ( is_int( $chat ) || is_string( $chat ) ) || (string) $chat === '' ) {
			return;
		}
		$changed = false;
		foreach ( $rows as $i => $row ) {
			foreach ( is_array( $row ) ? $row : [] as $j => $button ) {
				if ( ! is_array( $button ) || ! is_string( $button['text'] ?? null ) || ! is_string( $button['callback_data'] ?? null ) ) {
					continue;
				}
				$text = self::unmarked( $button['text'] );
				if ( $button['callback_data'] === $query['data'] ) {
					$suffix = $name === '' ? '' : ' · ' . Text::truncate( str_replace( '·', '', $name ), 24 );
					$text   = self::CHECK . Text::truncate( $text, 62 - mb_strlen( $suffix ) ) . $suffix;
				}
				$changed                  = $changed || $text !== $button['text'];
				$rows[ $i ][ $j ]['text'] = $text;
			}
		}
		if ( $changed ) {
			BotClient::for_platform( $platform )->edit_reply_markup( (string) $chat, (int) $message['message_id'], [ 'inline_keyboard' => $rows ] );
		}
	}

	/**
	 * Label before it was marked.
	 *
	 * @param string $text Label.
	 */
	private static function unmarked( string $text ): string {
		if ( strncmp( $text, self::CHECK, strlen( self::CHECK ) ) !== 0 ) {
			return $text;
		}
		return (string) preg_replace( '/ · [^·]*$/u', '', substr( $text, strlen( self::CHECK ) ) );
	}
}
