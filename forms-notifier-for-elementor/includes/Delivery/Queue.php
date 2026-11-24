<?php
/**
 * Queue.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Enqueue, claim, retry, cancel. A row of fnfe_messages is both the queue entry and the log entry. */
final class Queue {

	/** Lock of one run. */
	private const LOCK = 'queue';

	/** Seconds after which a lock of a dead run is taken over. */
	private const LOCK_TTL = 120;

	/** Seconds a claimed row is reserved. */
	private const CLAIM_TTL = 300;

	/** Seconds between two messages to the same chat. */
	private const PACE = 1.0;

	/** Channels with a per-chat limit of about one message per second. */
	private const PACED_CHANNELS = [ 'telegram', 'bale' ];

	/** @var bool */
	private static $kicked = false;

	/** @var array<string,float> chat => time of the last message of this process */
	private static $last_sent = [];

	/**
	 * Insert a queued row for the message.
	 *
	 * @param Message $m     Message.
	 * @param int     $delay Seconds.
	 * @return int row id
	 */
	public static function push( Message $m, int $delay = 0 ): int {
		return MessageRepository::insert(
			array_merge(
				self::row( $m, 'queued' ),
				[ 'next_attempt_gmt' => self::gmt( max( 0, $delay ) ) ]
			)
		);
	}

	/**
	 * Process due rows within the time budget.
	 *
	 * @param int $max         Most rows, 0 = queue.batch.
	 * @param int $time_budget Seconds, 0 = queue.time_budget.
	 * @return array{processed:int,sent:int,failed:int,remaining:int}
	 */
	public static function run( int $max = 0, int $time_budget = 0 ): array {
		$max    = $max > 0 ? $max : max( 1, Settings::int( 'queue.batch', 20 ) );
		$budget = self::budget( $time_budget );
		$totals = self::totals();
		$ttl    = max( self::LOCK_TTL, $budget + 60 );

		if ( ! Lock::acquire( self::LOCK, $ttl ) ) {
			$totals['remaining'] = self::pending();
			return $totals;
		}
		try {
			$deadline = microtime( true ) + $budget;
			while ( $totals['processed'] < $max && microtime( true ) < $deadline ) {
				$rows = MessageRepository::claim( min( 10, $max - $totals['processed'] ), self::CLAIM_TTL );
				if ( ! $rows ) {
					break;
				}
				$waiting = false;
				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) {
						self::release( $row );
					} elseif ( self::pace_left( $row ) > 0 ) {
						self::release( $row, 1 );
						$waiting = true;
					} else {
						self::work( $row, $totals );
					}
				}
				if ( $waiting && microtime( true ) + self::PACE < $deadline ) {
					usleep( (int) ( self::PACE * 1000000 ) );
				}
			}
		} finally {
			Lock::release( self::LOCK );
		}
		$totals['remaining'] = self::pending();
		return $totals;
	}

	/**
	 * Process specific rows now (retry button, inline mode). Due dates are ignored.
	 *
	 * @param int[] $ids Row ids.
	 * @return array{processed:int,sent:int,failed:int,remaining:int}
	 */
	public static function run_ids( array $ids ): array {
		$totals   = self::totals();
		$deadline = microtime( true ) + self::budget( 0 );
		$left     = false;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( microtime( true ) >= $deadline ) {
				$left = true;
				break;
			}
			$row = MessageRepository::take( $id, self::CLAIM_TTL );
			if ( ! $row ) {
				continue;
			}
			$wait = self::pace_left( $row );
			if ( $wait > 0 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
			self::work( $row, $totals );
		}
		if ( $left ) {
			self::kick();
		}
		$totals['remaining'] = self::pending();
		return $totals;
	}

	/**
	 * Any non-sent row -> queued, attempts kept, due now. False when the message cannot be sent again.
	 *
	 * @param int $id Row id.
	 */
	public static function retry( int $id ): bool {
		$row = MessageRepository::find( $id );
		if ( ! $row || $row['status'] === 'sent' || ( $row['status'] === 'sending' && (string) $row['locked_until_gmt'] > self::gmt() ) ) {
			return false;
		}
		$patch = [];
		if ( ! $row['payload'] ) {
			$message = self::rebuild( $row );
			if ( ! $message ) {
				return false;
			}
			$patch = [
				'recipient' => self::shown_address( $message->channel, $message->recipient->address ),
				'subject'   => $message->subject,
				'body'      => self::display_body( $message ),
				'payload'   => $message->to_array(),
			];
		}
		return MessageRepository::update(
			$id,
			$patch + [
				'status'           => 'queued',
				'error'            => '',
				'next_attempt_gmt' => self::gmt(),
				'locked_until_gmt' => null,
				'lock_token'       => '',
				// A finished row gets one more attempt.
				'max_attempts'     => max( $row['max_attempts'], $row['attempts'] + 1 ),
			]
		);
	}

	/**
	 * Queued/retry -> cancelled.
	 *
	 * @param int $id Row id.
	 */
	public static function cancel( int $id ): bool {
		global $wpdb;
		// Compare-and-set: a row that a worker just claimed stays untouched.
		$done = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', next_attempt_gmt = NULL, updated_gmt = %s WHERE id = %d AND status IN ('queued','retry')",
				MessageRepository::table(),
				self::gmt(),
				$id
			)
		);
		if ( (int) $done !== 1 ) {
			return false;
		}
		$row = MessageRepository::find( $id );
		if ( $row ) {
			Worker::finalize( $row );
		}
		return true;
	}

	/** Waiting rows. */
	public static function pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','retry')", MessageRepository::table() ) );
	}

	/** Seconds the oldest due row has been waiting, 0 when nothing is due. */
	public static function oldest_due_age(): int {
		global $wpdb;
		$oldest = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(next_attempt_gmt) FROM %i WHERE status IN ('queued','retry') AND next_attempt_gmt <= %s", MessageRepository::table(), self::gmt() )
		);
		return $oldest ? max( 0, (int) apply_filters( 'fnfe_now', time() ) - (int) strtotime( $oldest . ' UTC' ) ) : 0;
	}

	/** Ask for a run after the current response (shutdown) and make sure the ticker is scheduled. */
	public static function kick(): void {
		if ( self::$kicked ) {
			return;
		}
		self::$kicked = true;
		Ticker::ensure();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// A command line process ends when its work is done; let the scheduler pick the rows up.
			Ticker::request_run();
			return;
		}
		add_action( 'shutdown', [ self::class, 'on_shutdown' ], 100 );
	}

	/** A run was requested in this request. */
	public static function is_kicked(): bool {
		return self::$kicked;
	}

	/** Shutdown callback: let the visitor go, then work. */
	public static function on_shutdown(): void {
		self::$kicked = false;
		try {
			self::finish_request();
			self::run();
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage() );
		}
	}

	/**
	 * Row of a message.
	 *
	 * @param Message $m      Message.
	 * @param string  $status Status.
	 */
	public static function row( Message $m, string $status ): array {
		$max = (int) apply_filters( 'fnfe_max_attempts', max( 1, Settings::int( 'queue.max_attempts', 5 ) ), $m );
		return [
			'submission_id'   => (int) ( $m->meta['submission_id'] ?? 0 ),
			'form_id'         => (int) ( $m->meta['form_id'] ?? 0 ),
			'channel'         => $m->channel,
			'purpose'         => $m->purpose,
			'recipient'       => self::shown_address( $m->channel, $m->recipient->address ),
			'recipient_label' => $m->recipient->name,
			'lang'            => $m->lang,
			'status'          => $status,
			'max_attempts'    => max( 1, min( 100, $max ) ),
			'subject'         => $m->subject,
			'body'            => self::display_body( $m ),
			'payload'         => $m->to_array(),
			'parent_id'       => (int) ( $m->meta['parent_id'] ?? $m->meta['fallback_of'] ?? 0 ),
		];
	}

	/**
	 * Address as the log may show it: credentials (webhook URLs) are masked.
	 *
	 * @param string $channel Channel id.
	 * @param string $address Address.
	 */
	public static function shown_address( string $channel, string $address ): string {
		$target = Registry::channel( $channel );
		$secret = $target && ( $target->capabilities()['receiver_kind'] ?? '' ) === 'secret';
		return $secret && $address !== '' && $address !== Resolver::IMPLICIT && ! Settings::is_placeholder( $address ) ? '••••••' : $address;
	}

	/**
	 * Message of a row without payload, rendered again from the stored submission; null when that is not possible.
	 *
	 * @param array $row Message row.
	 */
	public static function rebuild( array $row ): ?Message {
		if ( ! in_array( $row['purpose'], [ 'admin', 'submitter' ], true ) ) {
			return null;
		}
		$s = SubmissionRepository::find( (int) $row['submission_id'] );
		if ( ! $s ) {
			return null;
		}
		$form     = FormRepository::config( $s->form_id );
		$delivery = null;
		foreach ( Resolver::plan( $s, $form, [ (string) $row['channel'] ] ) as $planned ) {
			if ( $planned->purpose === $row['purpose'] && self::shown_address( $planned->channel, $planned->recipient->address ) === $row['recipient'] ) {
				$delivery = $planned;
				break;
			}
		}
		if ( ! $delivery ) {
			$channel = Registry::channel( (string) $row['channel'] );
			$address = (string) $row['recipient'];
			if ( $channel && $address !== Resolver::IMPLICIT && ! Settings::is_placeholder( $address ) ) {
				$address = strpos( $address, '••' ) === false ? $channel->normalize_address( $address ) : '';
			}
			if ( ! $channel || $address === '' ) {
				return null;
			}
			$recipient = new Recipient( (string) $row['channel'], $address, (string) $row['recipient_label'], (string) $row['purpose'] );
			$delivery  = new Delivery( (string) $row['channel'], $recipient, (string) $row['purpose'] );

			$delivery->lang     = (string) $row['lang'];
			$delivery->template = Resolver::template( (string) $row['channel'], $form, (string) $row['purpose'] );
		}
		try {
			$message = Dispatcher::build( $delivery, $s, $form );
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage(), [ 'message' => (int) $row['id'] ] );
			return null;
		}
		if ( (int) $row['parent_id'] ) {
			$message->meta['fallback_of'] = (int) $row['parent_id'];
		}
		return $message;
	}

	/**
	 * UTC time some seconds from now.
	 *
	 * @param int $seconds Offset.
	 */
	public static function gmt( int $seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) + $seconds );
	}

	/** Forget the state of this process (tests). */
	public static function reset(): void {
		self::$kicked    = false;
		self::$last_sent = [];
		remove_action( 'shutdown', [ self::class, 'on_shutdown' ], 100 );
	}

	/**
	 * Process one claimed row and count it.
	 *
	 * @param array $row    Row.
	 * @param array $totals Totals, by reference.
	 */
	private static function work( array $row, array &$totals ): void {
		++$totals['processed'];
		try {
			$ok = Worker::process( $row )->ok;
		} catch ( \Throwable $e ) {
			// A listener failed after the row was stored; the run goes on.
			Logger::error( 'queue', $e->getMessage(), [ 'message' => (int) $row['id'] ] );
			$ok = false;
		}
		self::$last_sent[ self::chat( $row ) ] = microtime( true );
		++$totals[ $ok ? 'sent' : 'failed' ];
	}

	/**
	 * Give a claimed row back.
	 *
	 * @param array $row   Row.
	 * @param int   $delay Seconds.
	 */
	private static function release( array $row, int $delay = 0 ): void {
		MessageRepository::update(
			(int) $row['id'],
			[
				'status'           => (int) $row['attempts'] > 0 ? 'retry' : 'queued',
				'lock_token'       => '',
				'locked_until_gmt' => null,
				'next_attempt_gmt' => $delay ? self::gmt( $delay ) : (string) $row['next_attempt_gmt'],
			]
		);
	}

	/**
	 * Seconds to wait before the chat of a row may get another message.
	 *
	 * @param array $row Row.
	 */
	private static function pace_left( array $row ): float {
		if ( ! in_array( $row['channel'], self::PACED_CHANNELS, true ) ) {
			return 0.0;
		}
		$last = self::$last_sent[ self::chat( $row ) ] ?? 0.0;
		return max( 0.0, $last + self::PACE - microtime( true ) );
	}

	/**
	 * Chat of a row: topics share the limit of their group.
	 *
	 * @param array $row Row.
	 */
	private static function chat( array $row ): string {
		return $row['channel'] . ':' . explode( '/', (string) $row['recipient'] )[0];
	}

	/**
	 * Body shown in the log.
	 *
	 * @param Message $m Message.
	 */
	private static function display_body( Message $m ): string {
		if ( $m->body !== '' || ! $m->pattern ) {
			return $m->body === '' ? $m->plain : $m->body;
		}
		$lines = [ 'pattern:' . (string) ( $m->pattern['code'] ?? '' ) ];
		foreach ( (array) ( $m->pattern['vars'] ?? [] ) as $name => $value ) {
			$lines[] = $name . ':' . $value;
		}
		return implode( "\n", $lines ) . ( $m->plain === '' ? '' : "\n---\n" . $m->plain );
	}

	/**
	 * Time budget of a run in seconds.
	 *
	 * @param int $seconds Requested, 0 = setting.
	 */
	private static function budget( int $seconds ): int {
		return max( 1, $seconds > 0 ? $seconds : Settings::int( 'queue.time_budget', 20 ) );
	}

	/** Empty totals. */
	private static function totals(): array {
		return [
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'remaining' => 0,
		];
	}

	/** Send the response to the visitor where the server allows it. */
	private static function finish_request(): void {
		ignore_user_abort( true );
		if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// The response is already out; slow services must not cut a run in half.
			@set_time_limit( self::budget( 0 ) + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Hosts may forbid it; the run then keeps the normal limit.
		}
	}
}
