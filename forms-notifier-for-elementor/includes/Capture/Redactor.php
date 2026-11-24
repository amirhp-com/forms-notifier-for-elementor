<?php
/**
 * Redaction.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Model\FormConfig;

defined( 'ABSPATH' ) || exit;

/** Passwords and sensitive fields: only the fact that something was typed is kept. */
final class Redactor {

	/** Replacement of a redacted value. */
	public const MASK = '••••••';

	/**
	 * The field must not be stored or sent in clear.
	 *
	 * @param string          $type Native type.
	 * @param string          $id   Field id.
	 * @param FormConfig|null $form Form config.
	 */
	public static function applies( string $type, string $id, ?FormConfig $form = null ): bool {
		$types = apply_filters( 'fnfe_redact_field_types', [ 'password' ] );
		if ( in_array( $type, is_array( $types ) ? $types : [ 'password' ], true ) ) {
			return true;
		}
		return $form && in_array( $id, (array) $form->get( 'fields.sensitive', [] ), true );
	}

	/**
	 * Masked field.
	 *
	 * @param array $field Field.
	 */
	public static function mask( array $field ): array {
		return array_merge(
			$field,
			[
				'value'    => self::MASK,
				'values'   => [],
				'raw'      => '',
				'redacted' => true,
			]
		);
	}
}
