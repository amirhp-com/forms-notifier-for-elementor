<?php
/**
 * Recipient.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

defined( 'ABSPATH' ) || exit;

/** Who receives a message. */
final class Recipient {

	/** @var string */
	public string $channel = '';

	/** @var string normalised by ChannelInterface::normalize_address() */
	public string $address = '';

	/** @var string label for logs */
	public string $name = '';

	/** @var string language to render in ('' = decided by Resolver) */
	public string $lang = '';

	/** @var string admin|submitter|alert|digest|test */
	public string $role = 'admin';

	/** @var array receiver id ('rid'), chat title, thread, anything the channel stored on the receiver */
	public array $meta = [];

	/**
	 * @param string $channel Channel id.
	 * @param string $address Normalised address.
	 * @param string $name    Label.
	 * @param string $role    Role.
	 */
	public function __construct( string $channel, string $address, string $name = '', string $role = 'admin' ) {
		$this->channel = $channel;
		$this->address = $address;
		$this->name    = $name;
		$this->role    = $role;
	}

	/** JSON-safe array. */
	public function to_array(): array {
		return [
			'channel' => $this->channel,
			'address' => $this->address,
			'name'    => $this->name,
			'lang'    => $this->lang,
			'role'    => $this->role,
			'meta'    => $this->meta,
		];
	}

	/**
	 * @param array $a Output of to_array().
	 */
	public static function from_array( array $a ): self {
		$r       = new self(
			(string) ( $a['channel'] ?? '' ),
			(string) ( $a['address'] ?? '' ),
			(string) ( $a['name'] ?? '' ),
			(string) ( $a['role'] ?? 'admin' )
		);
		$r->lang = (string) ( $a['lang'] ?? '' );
		$r->meta = isset( $a['meta'] ) && is_array( $a['meta'] ) ? $a['meta'] : [];
		return $r;
	}
}
