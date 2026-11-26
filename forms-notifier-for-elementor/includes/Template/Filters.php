<?php
/**
 * Value filters.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Built-in and custom filters of template tags. Input and output are raw values. */
final class Filters {

	private const NAMES = [ 'upper', 'lower', 'title', 'trim', 'truncate', 'default', 'digits', 'nl2space', 'first', 'last', 'join', 'count', 'urlencode', 'date', 'mask', 'phone', 'number', 'strip' ];

	/**
	 * Run a filter chain.
	 *
	 * @param array    $chain   [ [ name, arg ] ].
	 * @param string   $value   Raw value.
	 * @param string[] $values  List form of the value.
	 * @param Context  $context Context.
	 */
	public static function chain( array $chain, string $value, array $values, Context $context ): string {
		foreach ( $chain as $filter ) {
			list( $name, $arg ) = $filter;
			if ( in_array( $name, [ 'first', 'last', 'join', 'count' ], true ) && ! isset( self::custom()[ $name ] ) ) {
				$value = self::from_list( $name, (string) $arg, $values );
			} else {
				$value = self::apply( (string) $name, (string) $arg, $value, $context );
			}
			$values = $value === '' ? [] : [ $value ];
		}
		return $value;
	}

	/**
	 * One filter on a single value. Unknown filters change nothing.
	 *
	 * @param string  $name    Filter name.
	 * @param string  $arg     Argument.
	 * @param string  $value   Raw value.
	 * @param Context $context Context.
	 */
	public static function apply( string $name, string $arg, string $value, Context $context ): string {
		$custom = self::custom();
		if ( isset( $custom[ $name ] ) ) {
			try {
				$result = call_user_func( $custom[ $name ], $value, $arg, $context );
				return is_scalar( $result ) ? Markup::clean_text( (string) $result ) : $value;
			} catch ( \Throwable $e ) {
				Logger::error( 'template', sprintf( 'Filter "%s": %s', $name, $e->getMessage() ) );
				return $value;
			}
		}

		switch ( $name ) {
			case 'upper':
				return Text::upper( $value );
			case 'lower':
				return Text::lower( $value );
			case 'title':
				return Text::title( $value );
			case 'trim':
				return (string) preg_replace( '/^\s+|\s+$/u', '', $value );
			case 'truncate':
				$limit = (int) Text::en_digits( $arg );
				return $limit > 0 ? Text::truncate( $value, $limit ) : $value;
			case 'default':
				return trim( $value ) === '' ? $arg : $value;
			case 'digits':
				return trim( $arg ) === 'fa' ? Text::fa_digits( $value ) : Text::en_digits( $value );
			case 'nl2space':
				return trim( (string) preg_replace( '/\s*\n\s*/u', ' ', $value ) );
			case 'urlencode':
				return rawurlencode( $value );
			case 'date':
				return self::date( $value, $arg, $context );
			case 'mask':
				return self::mask( $value );
			case 'phone':
				$phone = trim( $value ) === '' ? null : Phone::normalize( $value, Tags::country( $context ) );
				return $phone === null ? $value : $phone;
			case 'number':
				return self::number( $value );
			case 'strip':
				return wp_strip_all_tags( $value );
			case 'first':
			case 'last':
			case 'join':
			case 'count':
				return self::from_list( $name, $arg, $value === '' ? [] : [ $value ] );
		}
		return $value;
	}

	/**
	 * The filter exists.
	 *
	 * @param string $name Filter name.
	 */
	public static function known( string $name ): bool {
		return in_array( $name, self::NAMES, true ) || isset( self::custom()[ $name ] );
	}

	/** Filters for the help screen: name with argument => description. */
	public static function catalog(): array {
		return [
			'upper'        => __( 'Capital letters', 'forms-notifier-for-elementor' ),
			'lower'        => __( 'Small letters', 'forms-notifier-for-elementor' ),
			'title'        => __( 'First letter of every word in capitals', 'forms-notifier-for-elementor' ),
			'trim'         => __( 'Remove spaces at both ends', 'forms-notifier-for-elementor' ),
			'truncate:N'   => __( 'Cut to N characters', 'forms-notifier-for-elementor' ),
			'default:TEXT' => __( 'Use TEXT when the value is empty', 'forms-notifier-for-elementor' ),
			'digits:en'    => __( 'English digits; digits:fa for Persian digits', 'forms-notifier-for-elementor' ),
			'nl2space'     => __( 'Put several lines on one line', 'forms-notifier-for-elementor' ),
			'first'        => __( 'First value of a field with several values', 'forms-notifier-for-elementor' ),
			'last'         => __( 'Last value of a field with several values', 'forms-notifier-for-elementor' ),
			'join:SEP'     => __( 'Join several values with SEP', 'forms-notifier-for-elementor' ),
			'count'        => __( 'Number of values', 'forms-notifier-for-elementor' ),
			'urlencode'    => __( 'Encode for use inside a URL', 'forms-notifier-for-elementor' ),
			'date:FORMAT'  => __( 'Read the value as a date and format it', 'forms-notifier-for-elementor' ),
			'mask'         => __( 'Keep the first and last two characters', 'forms-notifier-for-elementor' ),
			'phone'        => __( 'International phone number', 'forms-notifier-for-elementor' ),
			'number'       => __( 'Thousands separators', 'forms-notifier-for-elementor' ),
			'strip'        => __( 'Remove HTML', 'forms-notifier-for-elementor' ),
		];
	}

	/** Custom filters. Filter: fnfe_template_filters. */
	private static function custom(): array {
		$custom = apply_filters( 'fnfe_template_filters', [] );
		return is_array( $custom ) ? array_filter( $custom, 'is_callable' ) : [];
	}

	/**
	 * Filters of multi-value fields.
	 *
	 * @param string   $name   Filter.
	 * @param string   $arg    Argument.
	 * @param string[] $values Values.
	 */
	private static function from_list( string $name, string $arg, array $values ): string {
		$values = array_values( $values );
		switch ( $name ) {
			case 'first':
				return $values ? (string) $values[0] : '';
			case 'last':
				return $values ? (string) end( $values ) : '';
			case 'count':
				return (string) count( $values );
		}
		return implode( $arg === '' ? ', ' : str_replace( '\n', "\n", $arg ), $values );
	}

	/**
	 * Read a date value and format it.
	 *
	 * @param string  $value   Value.
	 * @param string  $format  PHP date format.
	 * @param Context $context Context.
	 */
	private static function date( string $value, string $format, Context $context ): string {
		$text = trim( Text::en_digits( $value ) );
		if ( $text === '' ) {
			return $value;
		}
		$zone = wp_timezone();
		// Jalali years never look like Gregorian ones of a form.
		if ( preg_match( '~^(1[34]\d{2})[/.-](\d{1,2})[/.-](\d{1,2})\b(.*)$~s', $text, $m ) && Jalali::is_valid( (int) $m[1], (int) $m[2], (int) $m[3] ) ) {
			$text = vsprintf( '%04d-%02d-%02d', Jalali::to_gregorian( (int) $m[1], (int) $m[2], (int) $m[3] ) ) . $m[4];
		}
		try {
			$date = ctype_digit( $text ) && strlen( $text ) >= 9
				? ( new \DateTimeImmutable( '@' . $text ) )->setTimezone( $zone )
				: new \DateTimeImmutable( $text, $zone );
		} catch ( \Throwable $e ) {
			return $value;
		}
		return Tags::format_date( $date, $format, $context );
	}

	/**
	 * Keep the first and last two characters.
	 *
	 * @param string $value Value.
	 */
	private static function mask( string $value ): string {
		$length = mb_strlen( $value );
		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}
		return mb_substr( $value, 0, 2 ) . str_repeat( '•', min( 8, $length - 4 ) ) . mb_substr( $value, -2 );
	}

	/**
	 * Thousands separators; text that is not a number stays as it is.
	 *
	 * @param string $value Value.
	 */
	private static function number( string $value ): string {
		$text = str_replace( [ ',', '٬', ' ' ], '', str_replace( '٫', '.', trim( Text::en_digits( $value ) ) ) );
		if ( ! preg_match( '/^[+-]?\d{1,15}(\.\d+)?$/', $text ) ) {
			return $value;
		}
		$parts = explode( '.', $text );
		return number_format( (float) $text, isset( $parts[1] ) ? min( 6, strlen( $parts[1] ) ) : 0, '.', ',' );
	}
}
