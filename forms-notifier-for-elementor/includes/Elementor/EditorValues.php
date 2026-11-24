<?php
/**
 * Editor override values.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Core\FieldSanitizer;

defined( 'ABSPATH' ) || exit;

/** The fnfe_* control values of a form widget, cleaned. Empty values are left out. */
final class EditorValues {

	/** Name of the submit action. */
	public const ACTION = 'fnfe';

	/**
	 * Clean values.
	 *
	 * @param mixed $settings Widget settings.
	 */
	public static function from_settings( $settings ): array {
		$settings = is_array( $settings ) ? $settings : [];
		$out      = [];

		// Without the action the controls are hidden and Elementor does not apply them either.
		if ( ! in_array( self::ACTION, self::actions( $settings ), true ) ) {
			return $out;
		}

		$choices = [
			'fnfe_enabled'        => [ 'yes', 'no' ],
			'fnfe_receivers_mode' => [ 'append', 'replace' ],
			'fnfe_attach'         => [ 'off', 'link', 'send', 'both' ],
		];
		foreach ( $choices as $key => $allowed ) {
			if ( in_array( $settings[ $key ] ?? '', $allowed, true ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}

		$channels = array_filter( array_filter( (array) ( $settings['fnfe_channels'] ?? [] ), 'is_string' ), [ self::class, 'is_slug' ] );
		if ( $channels ) {
			$out['fnfe_channels'] = array_values( array_unique( $channels ) );
		}

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( strncmp( $key, 'fnfe_receivers_', 15 ) !== 0 || $key === 'fnfe_receivers_mode' || ! is_string( $value ) || ! self::is_slug( substr( $key, 15 ) ) ) {
				continue;
			}
			$lines = array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n,]+/', $value ) ), 'strlen' );
			if ( $lines ) {
				$out[ $key ] = implode( "\n", $lines );
			}
		}

		$template = is_string( $settings['fnfe_template'] ?? null ) ? trim( $settings['fnfe_template'] ) : '';
		if ( $template !== '' ) {
			$out['fnfe_template'] = FieldSanitizer::template( $template );
		}

		// Rows keep Elementor's shape; they become buttons when the message is built.
		$buttons = [];
		foreach ( is_array( $settings['fnfe_buttons'] ?? null ) ? $settings['fnfe_buttons'] : [] as $row ) {
			$text = is_array( $row ) && is_string( $row['text'] ?? null ) ? trim( FieldNormalizer::clean( $row['text'] ) ) : '';
			if ( $text !== '' ) {
				$buttons[] = [
					'_id'   => is_scalar( $row['_id'] ?? null ) ? sanitize_key( (string) $row['_id'] ) : '',
					'text'  => $text,
					'type'  => in_array( $row['type'] ?? '', [ 'url', 'copy', 'submission' ], true ) ? $row['type'] : 'url',
					'value' => is_string( $row['value'] ?? null ) ? trim( FieldNormalizer::clean( $row['value'] ) ) : '',
				];
			}
		}
		if ( $buttons ) {
			$out['fnfe_buttons'] = $buttons;
		}
		return $out;
	}

	/**
	 * Names of the submit actions.
	 *
	 * @param array $settings Widget settings.
	 * @return string[]
	 */
	public static function actions( array $settings ): array {
		return array_values( array_filter( (array) ( $settings['submit_actions'] ?? [] ), 'is_string' ) );
	}

	/**
	 * Channel id.
	 *
	 * @param string $value Value.
	 */
	private static function is_slug( string $value ): bool {
		return (bool) preg_match( '/^[a-z0-9_]+$/', $value );
	}
}
