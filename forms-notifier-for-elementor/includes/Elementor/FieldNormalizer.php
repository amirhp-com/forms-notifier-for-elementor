<?php
/**
 * Field normalisation.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Capture\Redactor;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Native field arrays to the submission field shape.
 *
 * A native field is [ id, type, label, raw ] plus optional placeholder, multiple (bool) and is_file (bool). Fields that
 * already have the submission shape pass through unchanged apart from cleaning, so running twice does no harm.
 */
final class FieldNormalizer {

	/** Deepest nesting followed when a value is flattened. */
	private const MAX_DEPTH = 5;

	/** Most items kept of one list value. */
	private const MAX_ITEMS = 500;

	/** Types whose values hold digits only by meaning. */
	private const DIGIT_TYPES = [ 'tel', 'number', 'date', 'time' ];

	/** Characters sanitize_text_field() would damage, and stand-ins that cannot occur in cleaned input. */
	private const PROTECTED = [
		'<' => "\x02",
		'%' => "\x03",
	];

	/**
	 * Normalise every field of a submission in place.
	 *
	 * @param Submission      $s          Submission.
	 * @param FormConfig|null $form       Form config.
	 * @param bool            $keep_lines Keep line breaks in every type, not only in textareas.
	 */
	public static function normalize( Submission $s, ?FormConfig $form = null, bool $keep_lines = false ): void {
		$skip   = self::skip_types();
		$fields = [];
		foreach ( $s->fields as $key => $native ) {
			if ( ! is_array( $native ) ) {
				continue;
			}
			$native['id'] = (string) ( $native['id'] ?? $key );
			if ( $native['id'] === '' || in_array( (string) ( $native['type'] ?? 'text' ), $skip, true ) ) {
				continue;
			}
			$field = self::field( $native, $s, $form, $keep_lines );
			if ( $field === null ) {
				continue;
			}
			$filtered = apply_filters( 'fnfe_field_value', $field, $s );
			if ( is_array( $filtered ) ) {
				$fields[ $field['id'] ] = self::complete( array_merge( $field, $filtered ), $field['id'] );
			}
		}
		$s->fields = $fields;
	}

	/** Field types dropped at capture. Filter: fnfe_skip_field_types. */
	public static function skip_types(): array {
		$types = apply_filters( 'fnfe_skip_field_types', Settings::arr( 'general.skip_types' ) );
		return array_values( array_filter( is_array( $types ) ? $types : [], 'is_string' ) );
	}

	/**
	 * Definitions of a form for the forms table: id, type, label, options.
	 *
	 * @param array $native Elementor "form_fields" rows or rows that already have the target shape.
	 * @return array[]
	 */
	public static function definitions( array $native ): array {
		$skip = self::skip_types();
		$out  = [];
		foreach ( $native as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = sanitize_text_field( self::scalar( $row['custom_id'] ?? $row['id'] ?? '' ) );
			$type = sanitize_text_field( self::scalar( $row['field_type'] ?? $row['type'] ?? 'text' ) );
			if ( $id === '' || in_array( $type, $skip, true ) || isset( $out[ $id ] ) ) {
				continue;
			}
			$item = [
				'id'    => $id,
				'type'  => $type,
				'label' => self::label( self::scalar( $row['field_label'] ?? $row['label'] ?? '' ), self::scalar( $row['placeholder'] ?? '' ), $id ),
			];

			$options = $row['field_options'] ?? $row['options'] ?? null;
			if ( is_string( $options ) ) {
				$options = Text::lines( $options );
			}
			if ( is_array( $options ) ) {
				$options = array_values( array_filter( array_map( 'sanitize_text_field', array_filter( array_map( [ self::class, 'option' ], $options ), 'is_string' ) ), 'strlen' ) );
				if ( $options ) {
					$item['options'] = array_slice( $options, 0, self::MAX_ITEMS );
				}
			}
			$out[ $id ] = $item;
		}
		return array_values( $out );
	}

	/**
	 * Nothing was filled in and nothing was uploaded.
	 *
	 * @param Submission $s Submission.
	 */
	public static function is_empty( Submission $s ): bool {
		if ( $s->files ) {
			return false;
		}
		foreach ( $s->fields as $field ) {
			if ( trim( (string) $field['value'] ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Clean text: no tags, no control characters, valid UTF-8. "a < b" and "100%25" survive as typed.
	 *
	 * @param string $text  Untrusted text.
	 * @param bool   $lines Keep line breaks.
	 */
	public static function clean( string $text, bool $lines = false ): string {
		$text = wp_check_invalid_utf8( $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		// Neither can start a tag; a percent sign is plain text here because nothing is ever URL-decoded.
		$text = (string) preg_replace( '/<(?![a-zA-Z\/!?])/', self::PROTECTED['<'], $text );
		$text = str_replace( '%', self::PROTECTED['%'], $text );
		$text = $lines ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
		return str_replace( array_values( self::PROTECTED ), array_keys( self::PROTECTED ), $text );
	}

	/**
	 * Label with fallbacks.
	 *
	 * @param string $label       Native label, may contain markup.
	 * @param string $placeholder Placeholder.
	 * @param string $id          Field id.
	 */
	public static function label( string $label, string $placeholder, string $id ): string {
		foreach ( [ $label, $placeholder ] as $candidate ) {
			$candidate = trim( self::clean( html_entity_decode( wp_strip_all_tags( $candidate ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( $candidate !== '' ) {
				return $candidate;
			}
		}
		return $id;
	}

	/**
	 * One field, null when it is dropped.
	 *
	 * @param array           $native     Native field.
	 * @param Submission      $s          Submission.
	 * @param FormConfig|null $form       Form config.
	 * @param bool            $keep_lines Keep line breaks in every type.
	 */
	private static function field( array $native, Submission $s, ?FormConfig $form, bool $keep_lines ): ?array {
		$id    = sanitize_text_field( $native['id'] );
		$type  = sanitize_text_field( self::scalar( $native['type'] ?? 'text' ) );
		$type  = $type === '' ? 'text' : $type;
		$files = $s->files_for( $native['id'] );
		$field = self::complete(
			[
				'id'      => $id,
				'type'    => $type,
				'label'   => self::label( self::scalar( $native['label'] ?? '' ), self::scalar( $native['placeholder'] ?? '' ), $id ),
				'hidden'  => ! empty( $native['hidden'] ) || ( $form && in_array( $id, (array) $form->get( 'fields.exclude', [] ), true ) ),
				'is_file' => ! empty( $native['is_file'] ) || $type === 'upload' || $files,
			],
			$id
		);

		$raw = self::raw( $native );
		if ( ! empty( $native['redacted'] ) || Redactor::applies( $type, $id, $form ) ) {
			return empty( $native['redacted'] ) && self::is_blank( $raw ) && ! $files ? null : Redactor::mask( $field );
		}

		if ( $field['is_file'] ) {
			$names = [];
			foreach ( $files as $file ) {
				$names[] = $file->name;
			}
			if ( ! $names ) {
				$names = self::file_names( $raw );
			}
			return self::with_values( $field, $names, $names );
		}

		if ( $type === 'acceptance' ) {
			$accepted        = ! self::is_blank( $raw );
			$field['value']  = $accepted ? __( 'Accepted', 'forms-notifier-for-elementor' ) : '';
			$field['values'] = $accepted ? [ $field['value'] ] : [];
			$field['raw']    = $accepted ? 'on' : '';
			return $field;
		}

		$digits = Settings::bool( 'general.normalize_digits', true ) && self::holds_digits( $type, $id, $form );
		$lines  = $keep_lines || $type === 'textarea';

		if ( is_array( $raw ) ) {
			$list = [];
			self::flatten( $raw, $list );
		} elseif ( is_object( $raw ) ) {
			$list = [ (string) wp_json_encode( $raw ) ];
		} else {
			$list = [ self::scalar( $raw ) ];
		}

		$clean = [];
		foreach ( $list as $item ) {
			$item = $type === 'url' && ! $lines ? self::clean_url( $item ) : self::clean( $item, $lines );
			$item = $digits ? Text::en_digits( $item ) : $item;
			if ( trim( $item ) !== '' ) {
				$clean[] = $item;
			}
		}
		return self::with_values( $field, $clean, is_array( $raw ) || ! empty( $native['multiple'] ) ? $clean : ( $clean[0] ?? '' ) );
	}

	/**
	 * Fill value, values, raw.
	 *
	 * @param array    $field  Field.
	 * @param string[] $values Clean values.
	 * @param mixed    $raw    Clean reported value.
	 */
	private static function with_values( array $field, array $values, $raw ): array {
		$field['value']  = implode( ', ', $values );
		$field['values'] = array_values( $values );
		$field['raw']    = $raw;
		return $field;
	}

	/**
	 * Reported value of a native or already normalised field.
	 *
	 * @param array $native Field.
	 * @return mixed
	 */
	private static function raw( array $native ) {
		foreach ( [ 'raw', 'values', 'value' ] as $key ) {
			if ( isset( $native[ $key ] ) && ! self::is_blank( $native[ $key ] ) ) {
				return $native[ $key ];
			}
		}
		return '';
	}

	/**
	 * Nothing typed.
	 *
	 * @param mixed $raw Reported value.
	 */
	private static function is_blank( $raw ): bool {
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( ! self::is_blank( $item ) ) {
					return false;
				}
			}
			return true;
		}
		return $raw === null || $raw === false || ( is_scalar( $raw ) && trim( (string) $raw ) === '' );
	}

	/**
	 * Scalars of a nested array, in order.
	 *
	 * @param array    $value Value.
	 * @param string[] $out   Result, by reference.
	 * @param int      $depth Depth.
	 */
	private static function flatten( array $value, array &$out, int $depth = 0 ): void {
		foreach ( $value as $item ) {
			if ( count( $out ) >= self::MAX_ITEMS ) {
				return;
			}
			if ( is_array( $item ) ) {
				if ( $depth < self::MAX_DEPTH ) {
					self::flatten( $item, $out, $depth + 1 );
				}
			} else {
				$out[] = is_object( $item ) ? (string) wp_json_encode( $item ) : self::scalar( $item );
			}
		}
	}

	/**
	 * Names behind URLs or paths of an upload field whose files are not known.
	 *
	 * @param mixed $raw Reported value.
	 * @return string[]
	 */
	private static function file_names( $raw ): array {
		$list = [];
		self::flatten( is_array( $raw ) ? $raw : preg_split( '/\s+,\s+|,\s*(?=https?:)/', self::scalar( $raw ) ), $list );
		$out = [];
		foreach ( $list as $item ) {
			$item = trim( (string) preg_replace( '/[?#].*$/', '', $item ) );
			$name = sanitize_file_name( wp_basename( $item ) );
			if ( $name !== '' && $item !== 'attached' ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Persian and Arabic digits become ASCII in this field.
	 *
	 * @param string          $type Type.
	 * @param string          $id   Field id.
	 * @param FormConfig|null $form Form config.
	 */
	private static function holds_digits( string $type, string $id, ?FormConfig $form ): bool {
		if ( in_array( $type, self::DIGIT_TYPES, true ) ) {
			return true;
		}
		return $form && in_array( $id, [ (string) $form->get( 'fields.phone', '' ), (string) $form->get( 'fields.country', '' ) ], true );
	}

	/**
	 * URL as typed, without tags; percent-encoding is kept.
	 *
	 * @param string $url Untrusted URL.
	 */
	private static function clean_url( string $url ): string {
		$clean = esc_url_raw( trim( $url ) );
		return $clean === '' ? self::clean( $url ) : $clean;
	}

	/**
	 * Every key of a field with the right type.
	 *
	 * @param array  $field Field.
	 * @param string $id    Field id.
	 */
	private static function complete( array $field, string $id ): array {
		$field = array_merge(
			[
				'id'       => $id,
				'type'     => 'text',
				'label'    => $id,
				'value'    => '',
				'values'   => [],
				'raw'      => '',
				'is_file'  => false,
				'redacted' => false,
				'hidden'   => false,
			],
			array_intersect_key( $field, array_flip( [ 'type', 'label', 'value', 'values', 'raw', 'is_file', 'redacted', 'hidden' ] ) )
		);

		$field['type']   = self::scalar( $field['type'] );
		$field['label']  = self::scalar( $field['label'] );
		$field['value']  = self::scalar( $field['value'] );
		$field['values'] = array_values( array_map( [ self::class, 'scalar' ], is_array( $field['values'] ) ? $field['values'] : [] ) );
		foreach ( [ 'is_file', 'redacted', 'hidden' ] as $flag ) {
			$field[ $flag ] = (bool) $field[ $flag ];
		}
		return $field;
	}

	/**
	 * Text of one option.
	 *
	 * @param mixed $option Option.
	 */
	private static function option( $option ): string {
		if ( is_array( $option ) ) {
			$option = $option['value'] ?? $option['label'] ?? '';
		}
		return trim( self::scalar( $option ) );
	}

	/**
	 * String of a scalar, '' otherwise.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
