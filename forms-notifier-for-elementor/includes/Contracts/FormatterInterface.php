<?php
/**
 * Formatter contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

defined( 'ABSPATH' ) || exit;

interface FormatterInterface {

	/** Format id. */
	public function id(): string;

	/**
	 * Escape a raw VALUE so it is inert in this format.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string;

	/**
	 * Convert canonical markup (values inside are canonical-escaped) into this format.
	 *
	 * @param string $canonical Canonical markup.
	 */
	public function convert( string $canonical ): string;

	/** Hard per-message limit in characters (UTF-16 code units for Telegram), 0 = unlimited. */
	public function limit(): int;

	/**
	 * Split converted text into parts <= limit, never breaking an entity; [] for empty input.
	 *
	 * @param string $converted Converted text.
	 * @param int    $limit     Limit, 0 = limit().
	 * @return string[]
	 */
	public function split( string $converted, int $limit = 0 ): array;

	/**
	 * Length as the remote API counts it.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int;
}
