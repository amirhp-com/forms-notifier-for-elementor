<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Built-in templates. */
final class DefaultTemplates {

	/**
	 * Built-in default template for a format and purpose. Filter: fnfe_default_template.
	 *
	 * @param string $format  Format id.
	 * @param string $purpose Purpose.
	 */
	public static function for_format( string $format, string $purpose ): string {
		switch ( $purpose ) {
			case 'submitter':
				$template = __( 'Thank you. We received your message.', 'forms-notifier-for-elementor' );
				break;
			case 'alert':
				$template = self::alert();
				break;
			case 'digest':
				$template = self::digest();
				break;
			default:
				/* translators: {form_name} is a tag and must not be translated */
				$template = '<b>' . __( 'New submission: {form_name}', 'forms-notifier-for-elementor' ) . "</b>\n\n{all_fields}";
		}
		return (string) apply_filters( 'fnfe_default_template', $template, $format, $purpose );
	}

	/**
	 * Built-in subject.
	 *
	 * @param string $purpose Purpose.
	 */
	public static function subject( string $purpose ): string {
		unset( $purpose );
		/* translators: {form_name} is a tag and must not be translated */
		return __( 'New submission: {form_name}', 'forms-notifier-for-elementor' );
	}

	/** Built-in alert. */
	public static function alert(): string {
		return __( 'Delivery failed', 'forms-notifier-for-elementor' ) . "\n{alert.channel}\n{alert.error}";
	}

	/** Built-in digest. */
	public static function digest(): string {
		return "{site_name}\n{digest.period}";
	}
}
