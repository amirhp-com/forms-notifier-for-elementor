<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** Queue: sends inline. */
final class Queue {

	/**
	 * Insert a row for the message and send it.
	 *
	 * @param Message $m     Message.
	 * @param int     $delay Seconds.
	 * @return int row id
	 */
	public static function push( Message $m, int $delay = 0 ): int {
		unset( $delay );
		$id = MessageRepository::insert( self::row( $m, 'sending' ) );
		self::finish( $id, self::deliver( $m ) );
		return $id;
	}

	/**
	 * Process due rows within the time budget.
	 *
	 * @param int $max         Most rows.
	 * @param int $time_budget Seconds.
	 * @return array{processed:int,sent:int,failed:int,remaining:int}
	 */
	public static function run( int $max = 0, int $time_budget = 0 ): array {
		unset( $max, $time_budget );
		return [
			'processed' => 0,
			'sent'      => 0,
			'failed'    => 0,
			'remaining' => self::pending(),
		];
	}

	/**
	 * Process specific rows now.
	 *
	 * @param int[] $ids Row ids.
	 */
	public static function run_ids( array $ids ): array {
		unset( $ids );
		return self::run();
	}

	/**
	 * Any non-sent row -> queued.
	 *
	 * @param int $id Row id.
	 */
	public static function retry( int $id ): bool {
		unset( $id );
		return false;
	}

	/**
	 * Queued/retry -> cancelled.
	 *
	 * @param int $id Row id.
	 */
	public static function cancel( int $id ): bool {
		unset( $id );
		return false;
	}

	/** Waiting rows. */
	public static function pending(): int {
		$counts = MessageRepository::counts();
		return ( $counts['queued'] ?? 0 ) + ( $counts['retry'] ?? 0 );
	}

	/** Ask for a run after the current response. */
	public static function kick(): void {
	}

	/**
	 * Row of a message.
	 *
	 * @param Message $m      Message.
	 * @param string  $status Status.
	 */
	public static function row( Message $m, string $status ): array {
		return [
			'submission_id'   => (int) ( $m->meta['submission_id'] ?? 0 ),
			'form_id'         => (int) ( $m->meta['form_id'] ?? 0 ),
			'channel'         => $m->channel,
			'purpose'         => $m->purpose,
			'recipient'       => $m->recipient->address,
			'recipient_label' => $m->recipient->name,
			'lang'            => $m->lang,
			'status'          => $status,
			'max_attempts'    => Settings::int( 'queue.max_attempts', 5 ),
			'subject'         => $m->subject,
			'body'            => $m->body,
			'payload'         => $m->to_array(),
		];
	}

	/**
	 * Send through the channel.
	 *
	 * @param Message $m Message.
	 */
	public static function deliver( Message $m ): SendResult {
		$channel = Registry::channel( $m->channel );
		if ( ! $channel ) {
			return SendResult::failure( $m->channel, __( 'Unknown channel.', 'forms-notifier-for-elementor' ), true );
		}
		do_action( 'fnfe_before_send', $m );
		try {
			$result = $channel->send( $m );
		} catch ( \Throwable $e ) {
			$result = SendResult::failure( $m->channel, $e->getMessage() );
		}
		do_action( 'fnfe_after_send', $m, $result );
		return $result;
	}

	/**
	 * Store the outcome.
	 *
	 * @param int        $id     Row id.
	 * @param SendResult $result Result.
	 */
	public static function finish( int $id, SendResult $result ): void {
		MessageRepository::update(
			$id,
			[
				'status'    => $result->ok ? 'sent' : 'failed',
				'attempts'  => 1,
				'provider'  => $result->provider,
				'response'  => $result->response,
				'error'     => $result->error,
				'remote_id' => $result->remote_id,
				'http_code' => $result->http_code,
				'sent_gmt'  => $result->ok ? gmdate( 'Y-m-d H:i:s' ) : null,
			]
		);
	}
}
