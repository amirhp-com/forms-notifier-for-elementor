<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Template engine: supports {field:id}, {all_fields}, {form_name}, {site_name} and extra tags only. */
final class Engine {

	/**
	 * Render a template to a channel format.
	 *
	 * @param string|array $template Canonical markup; string or localized text.
	 * @param Context      $context  Context.
	 * @param string       $format   Target format id.
	 * @return string rendered text, NOT split
	 */
	public static function render( $template, Context $context, string $format = 'text' ): string {
		$formatter = Registry::formatter( $format );
		$source    = Settings::localize( $template, $context->lang );
		$parts     = preg_split( '/(\{[a-z0-9_.]+(?::[^{}|]*)?\})/', $source, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out       = '';
		foreach ( (array) $parts as $part ) {
			if ( preg_match( '/^\{([a-z0-9_.]+)(?::([^{}|]*))?\}$/', $part, $m ) ) {
				$out .= $formatter->escape( self::tag( $m[1], $m[2] ?? '', $context ) );
			} else {
				$out .= $formatter->convert( $part );
			}
		}
		return $out;
	}

	/**
	 * Render a one-line value: tags resolved, no markup, plain.
	 *
	 * @param string|array $template  Template.
	 * @param Context      $context   Context.
	 * @param bool         $urlencode Encode every inserted value.
	 */
	public static function render_inline( $template, Context $context, bool $urlencode = false ): string {
		$source = wp_strip_all_tags( Settings::localize( $template, $context->lang ) );
		$text   = preg_replace_callback(
			'/\{([a-z0-9_.]+)(?::([^{}|]*))?\}/',
			static function ( array $m ) use ( $context, $urlencode ): string {
				$value = self::tag( $m[1], $m[2] ?? '', $context );
				return $urlencode ? rawurlencode( $value ) : $value;
			},
			$source
		);
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Resolve one tag to its raw value ('' when unknown).
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Tag argument.
	 * @param Context $context Context.
	 */
	public static function tag( string $name, string $arg, Context $context ): string {
		if ( isset( $context->extra[ $name ] ) && is_scalar( $context->extra[ $name ] ) ) {
			return (string) $context->extra[ $name ];
		}
		switch ( $name ) {
			case 'field':
				return $context->submission->value( $arg );
			case 'form_name':
				return $context->submission->form_name;
			case 'site_name':
				return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			case 'all_fields':
				$lines = [];
				foreach ( $context->submission->fields as $field ) {
					if ( (string) ( $field['value'] ?? '' ) !== '' && empty( $field['hidden'] ) ) {
						$lines[] = $field['label'] . ': ' . $field['value'];
					}
				}
				return implode( "\n", $lines );
		}
		return '';
	}

	/**
	 * Syntax check for the editor.
	 *
	 * @param string $template Template.
	 * @return array[] [ 'line' => int, 'message' => string ]
	 */
	public static function validate( string $template ): array {
		unset( $template );
		return [];
	}

	/**
	 * Sample submission for previews.
	 *
	 * @param int    $form_id Form row id, 0 = generic sample.
	 * @param string $lang    Language.
	 */
	public static function sample_submission( int $form_id = 0, string $lang = '' ): Submission {
		return Submission::from_array(
			[
				'form_id'   => $form_id,
				'form_key'  => 'api:sample',
				'form_name' => __( 'Sample form', 'forms-notifier-for-elementor' ),
				'lang'      => $lang,
				'fields'    => [
					'name'  => [
						'type'   => 'text',
						'label'  => __( 'Name', 'forms-notifier-for-elementor' ),
						'value'  => 'Sara Example',
						'values' => [ 'Sara Example' ],
						'raw'    => 'Sara Example',
					],
					'email' => [
						'type'   => 'email',
						'label'  => __( 'Email', 'forms-notifier-for-elementor' ),
						'value'  => 'sara@example.com',
						'values' => [ 'sara@example.com' ],
						'raw'    => 'sara@example.com',
					],
				],
				'meta'      => [ 'via' => 'test' ],
			]
		);
	}
}
