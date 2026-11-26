<?php
/**
 * Worker.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Sends one claimed row and stores the outcome. */
final class Worker {

	/** Seconds one send may take before another process may take the row over. */
	private const SEND_TTL = 900;

	/** Stored instead of an answer the database refuses. */
	private const UNREADABLE = 'The answer could not be stored.';

	/**
	 * Process a claimed row.
	 *
	 * @param array $row Row of MessageRepository in status 'sending'.
	 */
	public static function process( array $row ): SendResult {
		$id      = (int) $row['id'];
		$attempt = (int) $row['attempts'] + 1;

		if ( $attempt > max( 1, (int) $row['max_attempts'] ) ) {
			// Attempts are counted before sending, so a message that kills its process ends here instead of looping.
			return self::store( $row, SendResult::failure( (string) $row['channel'], __( 'Sending was interrupted too often.', 'forms-notifier-for-elementor' ), true ), null );
		}

		$message = self::message( $row );
		if ( ! $message ) {
			return self::store( $row, SendResult::failure( (string) $row['channel'], __( 'The content of this message is no longer stored.', 'forms-notifier-for-elementor' ), true ), null );
		}

		$problem = Dispatcher::problem( Registry::channel( $message->channel ) );
		if ( $problem !== '' ) {
			return self::skip( $row, $message, $problem );
		}

		$counted = MessageRepository::update(
			$id,
			[
				'attempts'         => $attempt,
				'locked_until_gmt' => Queue::gmt( self::SEND_TTL ),
			]
		);
		if ( ! $counted ) {
			// An attempt that cannot be counted could be repeated without end.
			Logger::error( 'queue', 'An attempt could not be stored, so the message was not sent.', [ 'message' => $id ] );
			return SendResult::failure( $message->channel, __( 'Sending was interrupted.', 'forms-notifier-for-elementor' ) );
		}
		$row['attempts']             = $attempt;
		$message->meta['message_id'] = $id;
		$message->meta['attempt']    = $attempt;

		$address = $message->recipient->address;
		$result  = self::send( $message );
		if ( $message->recipient->address !== $address ) {
			$row['recipient']     = $message->recipient->address;
			$payload              = $row['payload'];
			$payload['recipient'] = $message->recipient->to_array();
			MessageRepository::update(
				$id,
				[
					'recipient' => $message->recipient->address,
					'payload'   => $payload,
				]
			);
		}
		return self::store( $row, $result, $message );
	}

	/**
	 * Send, and once more when the chat moved to a new id. The message then holds the new address; stored receivers are
	 * updated by the channel.
	 *
	 * @param Message $m Message.
	 */
	public static function send( Message $m ): SendResult {
		$result = self::deliver( $m );
		$moved  = $result->ok ? '' : self::moved( $m, $result );
		if ( $moved === '' ) {
			return $result;
		}
		$m->recipient->address = $moved;
		return self::deliver( $m );
	}

	/**
	 * Send through the channel; an exception is a temporary failure.
	 *
	 * @param Message $m Message.
	 */
	public static function deliver( Message $m ): SendResult {
		$channel = Registry::channel( $m->channel );
		if ( ! $channel ) {
			return SendResult::failure( $m->channel, __( 'This channel does not exist any more.', 'forms-notifier-for-elementor' ), true );
		}
		try {
			do_action( 'fnfe_before_send', $m );
			$result = $channel->send( $m );
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage(), [ 'channel' => $m->channel ] );
			$result = SendResult::failure( $m->channel, $e->getMessage() );
		}
		try {
			do_action( 'fnfe_after_send', $m, $result );
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage(), [ 'channel' => $m->channel ] );
		}
		return $result;
	}

	/**
	 * Columns that record a result.
	 *
	 * @param SendResult $result Result.
	 */
	public static function outcome( SendResult $result ): array {
		return [
			'provider'         => $result->provider,
			'response'         => $result->response,
			'error'            => $result->ok ? '' : ( $result->error === '' ? __( 'Sending failed.', 'forms-notifier-for-elementor' ) : $result->error ),
			'remote_id'        => $result->remote_id,
			'http_code'        => $result->http_code,
			'sent_gmt'         => $result->ok ? Queue::gmt() : null,
			'lock_token'       => '',
			'locked_until_gmt' => null,
		];
	}

	/**
	 * Recompute the delivery status of a submission from its admin rows.
	 *
	 * @param int $submission_id Submission id.
	 */
	public static function update_submission( int $submission_id ): void {
		if ( $submission_id <= 0 ) {
			return;
		}
		$counts = [];
		foreach ( MessageRepository::for_submission( $submission_id ) as $row ) {
			if ( $row['purpose'] === 'admin' ) {
				$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
			}
		}
		SubmissionRepository::set_delivery_status( $submission_id, self::delivery_status( $counts ) );
	}

	/**
	 * Delivery status for a number of rows per status.
	 *
	 * @param array<string,int> $counts Status => rows.
	 */
	public static function delivery_status( array $counts ): string {
		$pending = ( $counts['queued'] ?? 0 ) + ( $counts['sending'] ?? 0 ) + ( $counts['retry'] ?? 0 );
		$sent    = $counts['sent'] ?? 0;
		$failed  = $counts['failed'] ?? 0;
		if ( $pending ) {
			return 'queued';
		}
		if ( $sent ) {
			return $failed ? 'partial' : 'sent';
		}
		return $failed || ! empty( $counts['skipped'] ) ? 'failed' : 'none';
	}

	/**
	 * Work that follows a final status: submission status, files of unsaved submissions, log privacy.
	 *
	 * @param array        $row     Row as stored.
	 * @param Message|null $message Message when it is known.
	 */
	public static function finalize( array $row, ?Message $message = null ): void {
		$payload = (array) $row['payload'];
		if ( ! $message && $payload ) {
			$message = Message::from_array( $payload );
		}
		if ( ! Settings::bool( 'privacy.log_bodies', true ) ) {
			MessageRepository::update(
				(int) $row['id'],
				[
					'body'    => null,
					'payload' => Queue::reduced( (string) $row['channel'], $payload ),
				]
			);
		} elseif ( isset( $payload['meta']['submission'] ) ) {
			// Only a backup channel needed it.
			unset( $payload['meta']['submission'] );
			MessageRepository::update( (int) $row['id'], [ 'payload' => $payload ] );
		}
		if ( (int) $row['submission_id'] ) {
			if ( $row['purpose'] === 'admin' ) {
				self::update_submission( (int) $row['submission_id'] );
			}
		} elseif ( $message ) {
			$s = self::carried( $message );
			Cleanup::release_files( array_merge( $message->attachments, $s ? $s->files : [] ) );
		}
	}

	/**
	 * Store a result; a text the database refuses must not cost the status, or the message would be sent again.
	 *
	 * @param int   $id    Row id.
	 * @param array $patch Columns.
	 */
	public static function save( int $id, array $patch ): bool {
		if ( MessageRepository::update( $id, $patch ) ) {
			return true;
		}
		Logger::warning( 'queue', self::UNREADABLE, [ 'message' => $id ] );
		$note = [
			'response'  => self::UNREADABLE,
			'error'     => ( $patch['error'] ?? '' ) === '' ? '' : self::UNREADABLE,
			'remote_id' => '',
			'provider'  => '',
		];
		if ( MessageRepository::update( $id, array_merge( $patch, $note ) ) ) {
			return true;
		}
		$status = array_intersect_key( $patch, array_flip( [ 'status', 'sent_gmt', 'next_attempt_gmt', 'lock_token', 'locked_until_gmt' ] ) );
		return MessageRepository::update( $id, $status );
	}

	/**
	 * Store a result: sent, retry or failed.
	 *
	 * @param array        $row     Row.
	 * @param SendResult   $result  Result.
	 * @param Message|null $message Message.
	 */
	private static function store( array $row, SendResult $result, ?Message $message ): SendResult {
		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'];
		$patch    = self::outcome( $result );
		$final    = $result->ok || $result->permanent || $attempts >= max( 1, (int) $row['max_attempts'] );

		if ( $result->ok ) {
			$patch['status'] = 'sent';
		} elseif ( $final ) {
			$patch['status'] = 'failed';
		} else {
			$patch['status']           = 'retry';
			$patch['next_attempt_gmt'] = Queue::gmt( Backoff::delay( $attempts, $row, $result ) );
		}
		if ( $final ) {
			$patch['next_attempt_gmt'] = null;
		}
		if ( ! self::own( $row ) ) {
			Logger::warning( 'queue', 'A message took too long and was taken over by another process.', [ 'message' => $id ] );
			return $result;
		}
		self::save( $id, $patch );

		$stored = MessageRepository::find( $id );
		if ( ! $stored ) {
			return $result;
		}
		if ( $result->ok ) {
			do_action( 'fnfe_message_sent', $stored, $result );
		} else {
			do_action( 'fnfe_message_failed', $stored, $result, $final );
			// A process that died on the way reported nothing, so the first stored error counts as the first failure.
			Alerts::on_failure( $stored, $result, $final, (string) $row['error'] === '' );
			if ( $final ) {
				self::fallback( $stored, $message );
			}
		}
		if ( $final ) {
			self::finalize( $stored, $message );
		}
		return $result;
	}

	/**
	 * Finish a row that must not be sent.
	 *
	 * @param array   $row     Row.
	 * @param Message $message Message.
	 * @param string  $reason  Reason.
	 */
	private static function skip( array $row, Message $message, string $reason ): SendResult {
		MessageRepository::update(
			(int) $row['id'],
			[
				'status'           => 'skipped',
				'error'            => $reason,
				'lock_token'       => '',
				'locked_until_gmt' => null,
				'next_attempt_gmt' => null,
			]
		);
		$stored = MessageRepository::find( (int) $row['id'] );
		if ( $stored ) {
			self::finalize( $stored, $message );
		}
		return SendResult::failure( (string) $row['channel'], $reason, true );
	}

	/**
	 * Make sure the row still belongs to this process. A row that was released but not taken by another process is
	 * taken back: its message was just sent.
	 *
	 * @param array $row Claimed row.
	 */
	private static function own( array $row ): bool {
		global $wpdb;
		if ( (string) $row['lock_token'] === '' ) {
			return true;
		}
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'sending', lock_token = %s, locked_until_gmt = %s WHERE id = %d AND ( ( status = 'sending' AND lock_token = %s ) OR ( status IN ('queued','retry') AND lock_token = '' ) )",
				MessageRepository::table(),
				bin2hex( random_bytes( 16 ) ),
				Queue::gmt( MINUTE_IN_SECONDS ),
				(int) $row['id'],
				(string) $row['lock_token']
			)
		);
		// Not zero rows but an error: the result must not get lost.
		return $done === false || (int) $done === 1;
	}

	/**
	 * Message of a row.
	 *
	 * @param array $row Row.
	 */
	private static function message( array $row ): ?Message {
		if ( Queue::is_reduced( (array) $row['payload'] ) ) {
			return Queue::rebuild( $row );
		}
		$message          = Message::from_array( $row['payload'] );
		$message->channel = (string) $row['channel'];
		return $message;
	}

	/**
	 * Address of a chat that moved to another id.
	 *
	 * @param Message    $message Message.
	 * @param SendResult $result  Failed result.
	 * @return string new address, '' when nothing moved
	 */
	private static function moved( Message $message, SendResult $result ): string {
		$new    = $result->meta['migrate_to'] ?? '';
		$new    = is_scalar( $new ) ? (string) $new : '';
		$parsed = BotClient::parse_address( $message->recipient->address );
		if ( ! preg_match( '/^-?\d{3,20}$/D', $new ) || ! $parsed || $parsed['chat'] === $new ) {
			return '';
		}
		return BotClient::format_address( $new, $parsed['thread'] );
	}

	/**
	 * Unsaved submission that travels with a message.
	 *
	 * @param Message $message Message.
	 */
	private static function carried( Message $message ): ?Submission {
		$data = $message->meta['submission'] ?? null;
		return is_array( $data ) && $data ? Submission::from_array( $data ) : null;
	}

	/**
	 * Rows of a submission on one channel. Unsaved submissions are recognised by their uuid.
	 *
	 * @param int    $submission_id Submission id, 0 = unsaved.
	 * @param string $uuid          Uuid.
	 * @param string $channel       Channel id.
	 * @return array[]
	 */
	private static function rows_on( int $submission_id, string $uuid, string $channel ): array {
		global $wpdb;
		if ( $submission_id ) {
			$rows = MessageRepository::for_submission( $submission_id );
		} else {
			$ids  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE submission_id = 0 AND channel = %s AND purpose = 'admin' AND payload LIKE %s ORDER BY id ASC",
					MessageRepository::table(),
					$channel,
					'%' . $wpdb->esc_like( '"uuid":"' . $uuid . '"' ) . '%'
				)
			);
			$rows = array_filter( array_map( [ MessageRepository::class, 'find' ], array_map( 'intval', $ids ) ) );
		}
		return array_filter(
			$rows,
			static function ( array $row ) use ( $channel ): bool {
				return $row['channel'] === $channel && $row['purpose'] === 'admin';
			}
		);
	}

	/**
	 * Send through the fallback channel after a final failure, once per submission and channel.
	 *
	 * @param array        $row     Failed row.
	 * @param Message|null $message Message.
	 */
	private static function fallback( array $row, ?Message $message ): void {
		$sid    = (int) $row['submission_id'];
		$target = Settings::str( 'channels.' . $row['channel'] . '.fallback' );
		if ( $row['purpose'] !== 'admin' || $target === '' || $target === $row['channel'] ) {
			return;
		}
		// A fallback has no fallback. The message may be unknown here, the parent row is not.
		if ( (int) $row['parent_id'] || ( $message && ! empty( $message->meta['fallback_of'] ) ) ) {
			return;
		}
		$s    = $sid || ! $message ? null : self::carried( $message );
		$uuid = $s ? $s->uuid : '';
		if ( ! $sid && $uuid === '' ) {
			return;
		}
		if ( Dispatcher::problem( Registry::channel( $target ) ) !== '' ) {
			return;
		}

		$lock = 'fallback_' . ( $sid ? $sid : $uuid ) . '_' . $target;
		if ( ! Lock::acquire( $lock, 120 ) ) {
			return;
		}
		try {
			$reached = [];
			foreach ( self::rows_on( $sid, $uuid, $target ) as $other ) {
				if ( $other['parent_id'] ) {
					return;
				}
				if ( ! in_array( $other['status'], [ 'skipped', 'cancelled' ], true ) ) {
					$reached[ (string) ( $other['payload']['recipient']['address'] ?? $other['recipient'] ) ] = true;
				}
			}
			$s = $s ?? SubmissionRepository::find( $sid );
			if ( ! $s ) {
				return;
			}
			$form = FormRepository::config( $s->form_id );
			foreach ( Resolver::fallback( $target, $s, $form ) as $delivery ) {
				// Receivers that already have this submission on that channel are not told twice.
				$address = $delivery->recipient->address;
				if ( isset( $reached[ $address ] ) || isset( $reached[ Queue::shown_address( $target, $address ) ] ) ) {
					continue;
				}
				Dispatcher::enqueue( $delivery, $s, $form, QuietHours::delay( $target, $form ), [ 'fallback_of' => (int) $row['id'] ] );
			}
		} finally {
			Lock::release( $lock );
		}
	}
}
