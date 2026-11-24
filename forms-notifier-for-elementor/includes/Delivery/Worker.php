<?php
/**
 * Worker.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Sends one claimed row and stores the outcome. */
final class Worker {

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

		MessageRepository::update( $id, [ 'attempts' => $attempt ] );
		$row['attempts']             = $attempt;
		$message->meta['message_id'] = $id;
		$message->meta['attempt']    = $attempt;

		$result = self::deliver( $message );
		if ( ! $result->ok ) {
			$moved = self::migrate( $message, $result );
			if ( $moved !== '' ) {
				$row['recipient']            = $moved;
				$message->recipient->address = $moved;
				MessageRepository::update(
					$id,
					[
						'recipient' => $moved,
						'payload'   => $message->to_array(),
					]
				);
				$result = self::deliver( $message );
			}
		}
		return self::store( $row, $result, $message );
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
		if ( ! $message && is_array( $row['payload'] ) && $row['payload'] ) {
			$message = Message::from_array( $row['payload'] );
		}
		if ( ! Settings::bool( 'privacy.log_bodies', true ) ) {
			MessageRepository::update(
				(int) $row['id'],
				[
					'body'    => null,
					'payload' => null,
				]
			);
		}
		if ( (int) $row['submission_id'] ) {
			if ( $row['purpose'] === 'admin' ) {
				self::update_submission( (int) $row['submission_id'] );
			}
		} elseif ( $message ) {
			Cleanup::release_files( $message->attachments );
		}
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
		MessageRepository::update( $id, $patch );

		$stored = MessageRepository::find( $id );
		if ( ! $stored ) {
			return $result;
		}
		if ( $result->ok ) {
			do_action( 'fnfe_message_sent', $stored, $result );
		} else {
			do_action( 'fnfe_message_failed', $stored, $result, $final );
			Alerts::on_failure( $stored, $result, $final );
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
	 * Message of a row.
	 *
	 * @param array $row Row.
	 */
	private static function message( array $row ): ?Message {
		if ( ! $row['payload'] ) {
			return Queue::rebuild( $row );
		}
		$message          = Message::from_array( $row['payload'] );
		$message->channel = (string) $row['channel'];
		return $message;
	}

	/**
	 * Follow a chat that moved to another id.
	 *
	 * @param Message    $message Message.
	 * @param SendResult $result  Failed result.
	 * @return string new address, '' when nothing moved
	 */
	private static function migrate( Message $message, SendResult $result ): string {
		$new = $result->meta['migrate_to_chat_id'] ?? $result->meta['migrate_to'] ?? '';
		$new = is_scalar( $new ) ? (string) $new : '';
		if ( ! preg_match( '/^-?\d{3,20}$/', $new ) ) {
			return '';
		}
		$parts = explode( '/', $message->recipient->address, 2 );
		if ( $parts[0] === $new || $parts[0] === '' ) {
			return '';
		}
		ChatMigration::apply( $message->channel, $parts[0], $new );
		return $new . ( isset( $parts[1] ) ? '/' . $parts[1] : '' );
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
		if ( $row['purpose'] !== 'admin' || ! $sid || $target === '' || $target === $row['channel'] ) {
			return;
		}
		if ( $message && ! empty( $message->meta['fallback_of'] ) ) {
			return;
		}
		if ( Dispatcher::problem( Registry::channel( $target ) ) !== '' ) {
			return;
		}

		$lock = 'fallback_' . $sid . '_' . $target;
		if ( ! Lock::acquire( $lock, 120 ) ) {
			return;
		}
		try {
			$reached = [];
			foreach ( MessageRepository::for_submission( $sid ) as $other ) {
				if ( $other['channel'] !== $target || $other['purpose'] !== 'admin' ) {
					continue;
				}
				if ( $other['parent_id'] ) {
					return;
				}
				if ( ! in_array( $other['status'], [ 'skipped', 'cancelled' ], true ) ) {
					$reached[ $other['recipient'] ] = true;
				}
			}
			$s = SubmissionRepository::find( $sid );
			if ( ! $s ) {
				return;
			}
			$form = FormRepository::config( $s->form_id );
			foreach ( Resolver::fallback( $target, $s, $form ) as $delivery ) {
				// Receivers that already have this submission on that channel are not told twice.
				if ( isset( $reached[ Queue::shown_address( $target, $delivery->recipient->address ) ] ) ) {
					continue;
				}
				Dispatcher::enqueue( $delivery, $s, $form, QuietHours::delay( $target, $form ), [ 'fallback_of' => (int) $row['id'] ] );
			}
		} finally {
			Lock::release( $lock );
		}
	}
}
