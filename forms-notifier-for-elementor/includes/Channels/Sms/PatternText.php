<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

defined( 'ABSPATH' ) || exit;

/** "pattern:CODE" text syntax: everything is a normal SMS for now. */
final class PatternText {

	/**
	 * Parse the RAW template.
	 *
	 * @param string $template Template.
	 * @return array{code:string,vars:array<int|string,string>,text:string}|null null = normal SMS
	 */
	public static function parse( string $template ): ?array {
		unset( $template );
		return null;
	}

	/**
	 * Build pattern text from parts.
	 *
	 * @param string $code          Pattern code.
	 * @param array  $vars          Variables.
	 * @param string $fallback_text Normal text.
	 */
	public static function compose( string $code, array $vars, string $fallback_text = '' ): string {
		$lines = [ 'pattern: ' . $code ];
		foreach ( $vars as $name => $value ) {
			$lines[] = $name . ' = ' . $value;
		}
		if ( $fallback_text !== '' ) {
			$lines[] = '---';
			$lines[] = $fallback_text;
		}
		return implode( "\n", $lines );
	}
}
