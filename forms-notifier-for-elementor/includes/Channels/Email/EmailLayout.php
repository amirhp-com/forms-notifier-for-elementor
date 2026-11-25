<?php
/**
 * Email layout.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Email;

defined( 'ABSPATH' ) || exit;

/** HTML document around an email body: inline styles, right-to-left aware, nothing remote. */
final class EmailLayout {

	private const FONT = 'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Tahoma,Arial,sans-serif';

	/**
	 * Wrap a body.
	 *
	 * @param string $body HTML body (trusted output of EmailFormatter).
	 * @param array  $args layout (branded|plain), subject, site, url, lang, rtl (bool), buttons [ [ text, value ] ].
	 */
	public static function wrap( string $body, array $args ): string {
		$rtl     = ! empty( $args['rtl'] );
		$dir     = $rtl ? 'rtl' : 'ltr';
		$align   = $rtl ? 'right' : 'left';
		$lang    = preg_replace( '/[^A-Za-z0-9-]/', '', str_replace( '_', '-', (string) ( $args['lang'] ?? '' ) ) );
		$subject = esc_html( (string) ( $args['subject'] ?? '' ) );
		$buttons = self::buttons( (array) ( $args['buttons'] ?? [] ) );
		$text    = self::FONT . ';font-size:15px;line-height:1.7;color:#1f2328;text-align:' . $align . ';direction:' . $dir;

		$head = '<!DOCTYPE html><html lang="' . esc_attr( $lang === '' ? 'en' : $lang ) . '" dir="' . $dir . '"><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . $subject . '</title></head>';

		if ( ( $args['layout'] ?? 'branded' ) === 'plain' ) {
			return $head . '<body dir="' . $dir . '" style="margin:0;padding:16px;' . $text . '">' . $body . $buttons . '</body></html>';
		}

		$site = esc_html( (string) ( $args['site'] ?? '' ) );
		$url  = esc_url( (string) ( $args['url'] ?? '' ) );
		$name = $url === '' ? $site : '<a href="' . $url . '" style="color:#57606a;text-decoration:none">' . $site . '</a>';

		return $head . '<body dir="' . $dir . '" style="margin:0;padding:0;background:#f3f4f6">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d8dee4;border-radius:12px">'
			. '<tr><td dir="' . $dir . '" style="padding:16px 24px;border-bottom:1px solid #eaeef2;' . $text . ';font-size:14px;font-weight:600;color:#57606a">' . $name . '</td></tr>'
			. '<tr><td dir="' . $dir . '" style="padding:24px;' . $text . '">' . $body . $buttons . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Link buttons.
	 *
	 * @param array $buttons [ text, value ].
	 */
	private static function buttons( array $buttons ): string {
		$out = '';
		foreach ( $buttons as $button ) {
			$out .= '<a href="' . esc_url( (string) $button['value'] ) . '" style="display:inline-block;margin:16px 4px 0;padding:8px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px">'
				. esc_html( (string) $button['text'] ) . '</a>';
		}
		return $out === '' ? '' : '<div>' . $out . '</div>';
	}
}
