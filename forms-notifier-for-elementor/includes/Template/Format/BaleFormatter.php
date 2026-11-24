<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Contracts\FormatterInterface;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** Bale markdown. */
final class BaleFormatter implements FormatterInterface {

	/** Format id. */
	public function id(): string {
		return 'bale_md';
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return $value;
	}

	/**
	 * Convert canonical markup.
	 *
	 * @param string $canonical Canonical markup.
	 */
	public function convert( string $canonical ): string {
		return str_replace( [ '<b>', '</b>' ], '*', $canonical );
	}

	/** Hard limit. */
	public function limit(): int {
		return 4096;
	}

	/**
	 * Split converted text.
	 *
	 * @param string $converted Converted text.
	 * @param int    $limit     Limit, 0 = limit().
	 * @return string[]
	 */
	public function split( string $converted, int $limit = 0 ): array {
		return Splitter::split_plain( $converted, $limit > 0 ? $limit : $this->limit() );
	}

	/**
	 * Length as the remote API counts it.
	 *
	 * @param string $converted Converted text.
	 */
	public function length( string $converted ): int {
		return Text::utf16_length( $converted );
	}
}
