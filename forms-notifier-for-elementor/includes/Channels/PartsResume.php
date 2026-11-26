<?php
/**
 * Progress of messages in several parts.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels;

use AmirhpCom\FormsNotifier\Model\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which parts of a queue row were delivered, so the next attempt goes on after them. Messages without a row
 * (tests) are not remembered.
 */
final class PartsResume {

	/** @var string transient name, '' = nothing is remembered */
	private $key = '';

	/** @var string[] remote ids of delivered parts, by position */
	private $ids = [];

	/**
	 * @param Message  $message Message.
	 * @param string[] $parts   Parts.
	 */
	public function __construct( Message $message, array $parts ) {
		$row = (int) ( $message->meta['message_id'] ?? 0 );
		if ( $row <= 0 || count( $parts ) < 2 ) {
			return;
		}
		$this->key = 'fnfe_parts_' . md5( $message->channel . '|' . $row . '|' . $message->recipient->address . '|' . implode( "\0", $parts ) );
		$known     = get_transient( $this->key );
		$this->ids = is_array( $known ) ? array_map( 'strval', array_filter( $known, 'is_scalar' ) ) : [];
	}

	/**
	 * A part was delivered by an earlier attempt.
	 *
	 * @param int $i Position.
	 */
	public function has( int $i ): bool {
		return isset( $this->ids[ $i ] );
	}

	/**
	 * A part was delivered. Stored at once: the process may end before the next part.
	 *
	 * @param int    $i         Position.
	 * @param string $remote_id Remote id.
	 */
	public function add( int $i, string $remote_id ): void {
		$this->ids[ $i ] = $remote_id;
		if ( $this->key !== '' ) {
			set_transient( $this->key, $this->ids, DAY_IN_SECONDS );
		}
	}

	/** Delivered parts. */
	public function count(): int {
		return count( $this->ids );
	}

	/** Remote ids of all parts, comma separated. */
	public function remote_ids(): string {
		ksort( $this->ids );
		return implode( ',', array_filter( $this->ids, 'strlen' ) );
	}

	/** Everything was delivered. */
	public function done(): void {
		if ( $this->key !== '' ) {
			delete_transient( $this->key );
		}
	}
}
