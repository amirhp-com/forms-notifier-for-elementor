<?php
/**
 * Pattern text.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms;

use AmirhpCom\FormsNotifier\Core\Text;

defined( 'ABSPATH' ) || exit;

/**
 * One text that carries a gateway pattern (template) and, optionally, the normal text for gateways without patterns:
 *
 *   pattern:590428
 *   pattern@smsir:123456
 *   0:{field:name}
 *   code:{submission_id}
 *   ---
 *   Thank you {field:name}, your request #{submission_id} was received.
 *
 * A text whose first line is something else is a normal message. Only RAW templates are parsed, never rendered text:
 * submitted values must not be able to turn a message into a pattern.
 */
final class PatternText {

	private const HEADER = 'pattern|pcode|patterncode|bodyid|template';

	/**
	 * Parse the RAW template.
	 *
	 * @param string $template Template.
	 * @return array{code:string,vars:array<int|string,string>,text:string,lang:string,codes:array<string,string>}|null null = normal SMS
	 */
	public static function parse( string $template ): ?array {
		$template = (string) preg_replace( '/^[\x{FEFF}\x{200B}-\x{200F}\s]+/u', '', $template );
		$lines    = Text::lines( $template );
		// One word after the separator: "pattern: your design is ready" stays a normal message, so does "template:{tag}".
		if ( ! preg_match( '/^(?:' . self::HEADER . ')\s*[:=](?!\/\/)\s*([^\s{}]+)\s*$/iu', (string) array_shift( $lines ), $m ) ) {
			return null;
		}

		$out = [
			'code'  => Text::en_digits( $m[1] ),
			'vars'  => [],
			'text'  => '',
			'lang'  => '',
			'codes' => [],
		];
		foreach ( $lines as $i => $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^-{3,}$/', $line ) ) {
				$out['text'] = trim( implode( "\n", array_slice( $lines, $i + 1 ) ) );
				break;
			}
			if ( preg_match( '/^(?:' . self::HEADER . ')@([a-z0-9_]+)\s*[:=]\s*([^\s{}]+)$/iu', $line, $m ) ) {
				$out['codes'][ strtolower( $m[1] ) ] = Text::en_digits( $m[2] );
			} elseif ( preg_match( '/^lang\s*[:=]\s*([A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*)$/i', $line, $m ) ) {
				$out['lang'] = $m[1];
			} elseif ( preg_match( '/^(?:\{([A-Za-z0-9_.\-]+|[۰-۹٠-٩]+)\}|%([A-Za-z0-9_.\-]+|[۰-۹٠-٩]+)%|([A-Za-z0-9_.\-]+|[۰-۹٠-٩]+))\s*[:=](?!\/\/)\s*(.*)$/u', $line, $m ) ) {
				// name:value, the name may be written {name} or %name% as provider panels show it.
				$out['vars'][ Text::en_digits( $m[1] . $m[2] . $m[3] ) ] = $m[4];
			} else {
				$out['vars'][] = $line;
			}
		}
		return $out;
	}

	/**
	 * Build pattern text from parts.
	 *
	 * @param string $code          Pattern code.
	 * @param array  $vars          Variables.
	 * @param string $fallback_text Normal text.
	 */
	public static function compose( string $code, array $vars, string $fallback_text = '' ): string {
		$lines = [ 'pattern:' . trim( $code ) ];
		foreach ( $vars as $name => $value ) {
			$name    = strtolower( (string) $name ) === 'lang' ? '{' . $name . '}' : $name;
			$value   = trim( (string) preg_replace( '/\s*\R\s*/u', ' ', (string) $value ) );
			$lines[] = $name . ':' . ( strncmp( $value, '//', 2 ) === 0 ? ' ' : '' ) . $value;
		}
		if ( trim( $fallback_text ) !== '' ) {
			$lines[] = '---';
			$lines[] = trim( $fallback_text );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Variables as an ordered list: numbered ones by number, others as written.
	 *
	 * @param array $vars Variables.
	 * @return string[]
	 */
	public static function values( array $vars ): array {
		if ( self::is_positional( $vars ) ) {
			ksort( $vars );
		}
		return array_map(
			static function ( $value ): string {
				return is_scalar( $value ) ? (string) $value : '';
			},
			array_values( $vars )
		);
	}

	/**
	 * Every variable is numbered.
	 *
	 * @param array $vars Variables.
	 */
	public static function is_positional( array $vars ): bool {
		return ! array_filter( array_keys( $vars ), 'is_string' );
	}
}
