<?php
/**
 * Additions to a rendered body.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Model\Message;

defined( 'ABSPATH' ) || exit;

/** File links and link buttons as text, for channels that cannot show them in another way. */
final class Extras {

	/**
	 * Body with the file links the template did not show and, optionally, the link buttons.
	 *
	 * @param Message $m       Message.
	 * @param bool    $buttons Add link buttons as lines.
	 */
	public static function compose( Message $m, bool $buttons = false ): string {
		$blocks = [ rtrim( $m->body ) ];
		$links  = self::links( self::missing_links( $m->body, $m->links ), $m->format );
		if ( $links !== '' ) {
			$blocks[] = $links;
		}
		if ( $buttons ) {
			$lines = self::button_lines( $m );
			if ( $lines !== '' ) {
				$blocks[] = $lines;
			}
		}
		$glue = $m->format === 'email_html' ? "<br>\n<br>\n" : "\n\n";
		return implode( $glue, array_filter( $blocks, 'strlen' ) );
	}

	/**
	 * File links that are not in the body yet. Signed links differ from call to call, so files are compared by id.
	 *
	 * @param string $body  Rendered body.
	 * @param array  $links [ [ name, url, size ] ].
	 */
	public static function missing_links( string $body, array $links ): array {
		$out = [];
		foreach ( $links as $link ) {
			$url = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';
			if ( $url === '' ) {
				continue;
			}
			$found = preg_match( '~fnfe(?:/|%2F)v1(?:/|%2F)file(?:/|%2F)(\d+)(?!\d)~i', $url, $m )
				? preg_match( '~fnfe(?:/|%2F)v1(?:/|%2F)file(?:/|%2F)' . $m[1] . '(?!\d)~i', $body )
				: strpos( $body, $url ) !== false || strpos( $body, str_replace( '&', '&amp;', $url ) ) !== false;
			if ( ! $found ) {
				$out[] = $link;
			}
		}
		return $out;
	}

	/**
	 * File links as lines.
	 *
	 * @param array  $links  Links.
	 * @param string $format Format.
	 */
	public static function links( array $links, string $format ): string {
		$lines = [];
		foreach ( $links as $link ) {
			$lines[] = self::anchor( (string) $link['url'], (string) ( $link['name'] ?? '' ) );
		}
		if ( ! $lines ) {
			return '';
		}
		return Registry::formatter( $format )->convert( '<b>' . Markup::escape( __( 'Files', 'forms-notifier-for-elementor' ) ) . "</b>\n" . implode( "\n", $lines ) );
	}

	/**
	 * Link buttons as lines.
	 *
	 * @param Message $m Message.
	 */
	public static function button_lines( Message $m ): string {
		$lines = [];
		foreach ( self::url_buttons( $m ) as $button ) {
			$lines[] = self::anchor( $button['value'], $button['text'] );
		}
		return $lines ? Registry::formatter( $m->format )->convert( implode( "\n", $lines ) ) : '';
	}

	/**
	 * Flat list of the link buttons of a message with a valid http(s) address.
	 *
	 * @param Message $m   Message.
	 * @param int     $max Longest list, 0 = any.
	 * @return array[] [ text, value ]
	 */
	public static function url_buttons( Message $m, int $max = 0 ): array {
		$out = [];
		foreach ( $m->buttons as $row ) {
			foreach ( is_array( $row ) ? $row : [] as $button ) {
				if ( ! is_array( $button ) || ( $button['type'] ?? '' ) !== 'url' ) {
					continue;
				}
				$url  = Markup::url( (string) ( $button['value'] ?? '' ) );
				$text = trim( (string) ( $button['text'] ?? '' ) );
				if ( $text !== '' && preg_match( '~^https?://~i', $url ) ) {
					$out[] = [
						'text'  => $text,
						'value' => $url,
					];
				}
			}
		}
		return $max > 0 ? array_slice( $out, 0, $max ) : $out;
	}

	/**
	 * Canonical link.
	 *
	 * @param string $url  Address.
	 * @param string $text Text.
	 */
	private static function anchor( string $url, string $text ): string {
		return '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . Markup::escape( $text === '' ? $url : $text ) . '</a>';
	}
}
