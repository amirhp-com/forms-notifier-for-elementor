<?php
/**
 * HTML email.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template\Format;

use AmirhpCom\FormsNotifier\Template\Splitter;

defined( 'ABSPATH' ) || exit;

/** HTML body of an email; wrapped by EmailLayout. */
final class EmailFormatter extends AbstractFormatter {

	private const STYLES = [
		'code'       => 'font-family:Consolas,Menlo,monospace;background:#f1f3f5;padding:1px 4px;border-radius:4px',
		'pre'        => 'font-family:Consolas,Menlo,monospace;background:#f1f3f5;padding:12px;border-radius:6px;white-space:pre-wrap;margin:8px 0',
		'blockquote' => 'margin:8px 0;padding:4px 12px;border:solid #d0d7de;border-width:0 3px 0 3px',
	];

	/** Format id. */
	public function id(): string {
		return 'email_html';
	}

	/**
	 * Escape a raw value.
	 *
	 * @param string $value Raw value.
	 */
	public function escape( string $value ): string {
		return str_replace( "\n", "<br>\n", self::html( $value ) );
	}

	/**
	 * Split converted text.
	 *
	 * @param string $converted Converted text.
	 * @param int    $limit     Limit, 0 = limit().
	 * @return string[]
	 */
	public function split( string $converted, int $limit = 0 ): array {
		return Splitter::split_html( $converted, $limit > 0 ? $limit : $this->limit(), [ $this, 'length' ] );
	}

	/**
	 * Escape a raw value where it stands.
	 *
	 * @param string $value Raw value.
	 * @param array  $open  Open elements.
	 */
	protected function value( string $value, array $open ): string {
		return isset( $open['pre'] ) ? self::html( $value ) : $this->escape( $value );
	}

	/**
	 * Apply a mark.
	 *
	 * @param string $tag   Tag.
	 * @param string $inner Converted text.
	 * @param array  $open  Open elements.
	 */
	protected function mark( string $tag, string $inner, array $open ): string {
		if ( $tag === 'spoiler' ) {
			return $inner;
		}
		$style = isset( self::STYLES[ $tag ] ) ? ' style="' . self::STYLES[ $tag ] . '"' : '';
		return '<' . $tag . $style . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * A link.
	 *
	 * @param string $inner Label.
	 * @param string $url   URL.
	 * @param string $plain Plain label.
	 */
	protected function link( string $inner, string $url, string $plain ): string {
		return '<a href="' . self::html( $url ) . '">' . $inner . '</a>';
	}

	/**
	 * Rows of a fields table.
	 *
	 * @param array $rows [ label, pieces, plain value ].
	 * @param array $open Open elements.
	 */
	protected function table( array $rows, array $open ): string {
		$cell = 'padding:8px 10px;border:1px solid #d0d7de;text-align:start;vertical-align:top';
		$out  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:8px 0">';
		foreach ( $rows as $row ) {
			$out .= '<tr><th scope="row" style="' . $cell . ';background:#f6f8fa;font-weight:600;width:30%">' . $this->escape( $row[0] ) . '</th>'
				. '<td style="' . $cell . '">' . $this->pieces( $row[1] ) . '</td></tr>';
		}
		return $out . '</table>';
	}

	/**
	 * HTML entities.
	 *
	 * @param string $text Text.
	 */
	private static function html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
