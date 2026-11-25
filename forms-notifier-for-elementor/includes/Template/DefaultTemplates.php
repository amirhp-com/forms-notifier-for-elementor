<?php
/**
 * Built-in templates.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

defined( 'ABSPATH' ) || exit;

/** Built-in templates, translated in the current locale. */
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
				/* translators: keep {if name} {name}{/if} exactly as it is; it adds the name of the person when it is known */
				$template = __( 'Thank you{if name} {name}{/if}. We received your message.', 'forms-notifier-for-elementor' );
				break;
			case 'alert':
				$template = self::alert();
				break;
			case 'digest':
				$template = self::digest();
				break;
			case 'test':
				/* translators: {site_name} is a tag and must not be translated */
				$template = __( 'Test message from {site_name}. This channel works.', 'forms-notifier-for-elementor' );
				break;
			default:
				$template = $format === 'text' ? self::short() : self::full();
		}
		return (string) apply_filters( 'fnfe_default_template', $template, $format, $purpose );
	}

	/**
	 * Built-in subject.
	 *
	 * @param string $purpose Purpose.
	 */
	public static function subject( string $purpose ): string {
		switch ( $purpose ) {
			case 'submitter':
				/* translators: {site_name} is a tag and must not be translated */
				return __( 'We received your message: {site_name}', 'forms-notifier-for-elementor' );
			case 'alert':
				/* translators: {site_name} is a tag and must not be translated */
				return __( 'Delivery failed: {site_name}', 'forms-notifier-for-elementor' );
			case 'digest':
				/* translators: {site_name} and {digest.period} are tags and must not be translated */
				return __( '{site_name}: {digest.period}', 'forms-notifier-for-elementor' );
			case 'test':
				return __( 'Test message', 'forms-notifier-for-elementor' );
		}
		/* translators: {form_name} is a tag and must not be translated */
		return __( 'New submission: {form_name}', 'forms-notifier-for-elementor' );
	}

	/** Built-in alert. */
	public static function alert(): string {
		return '<b>' . __( 'Delivery failed', 'forms-notifier-for-elementor' ) . "</b>\n\n"
			/* translators: {alert.channel} is a tag and must not be translated */
			. __( 'Channel: {alert.channel}', 'forms-notifier-for-elementor' ) . "\n"
			/* translators: {alert.recipient} is a tag and must not be translated */
			. '{if alert.recipient}' . __( 'Receiver: {alert.recipient}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			/* translators: {form_name} is a tag and must not be translated */
			. '{if form_name}' . __( 'Form: {form_name}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			/* translators: {submission_id} is a tag and must not be translated */
			. '{if submission_id}' . __( 'Submission: #{submission_id}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			/* translators: {alert.error} is a tag and must not be translated */
			. __( 'Error: {alert.error}', 'forms-notifier-for-elementor' ) . "\n"
			/* translators: {alert.attempts} is a tag and must not be translated */
			. '{if alert.attempts}' . __( 'Attempts: {alert.attempts}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			. '{if alert.log_url}<a href="{alert.log_url}">' . __( 'Open the log', 'forms-notifier-for-elementor' ) . '</a>{/if}';
	}

	/** Built-in digest. */
	public static function digest(): string {
		return "<b>{site_name}</b>\n{digest.period}\n\n"
			/* translators: {digest.submissions} is a tag and must not be translated */
			. __( 'Submissions: {digest.submissions}', 'forms-notifier-for-elementor' ) . "\n"
			/* translators: {digest.sent} is a tag and must not be translated */
			. __( 'Messages sent: {digest.sent}', 'forms-notifier-for-elementor' ) . "\n"
			/* translators: {digest.failed} is a tag and must not be translated */
			. __( 'Messages failed: {digest.failed}', 'forms-notifier-for-elementor' ) . "\n"
			/* translators: {digest.queue} is a tag and must not be translated */
			. '{if digest.queue}' . __( 'Messages waiting: {digest.queue}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			. "{if digest.forms}\n<b>" . __( 'Forms', 'forms-notifier-for-elementor' ) . "</b>\n{digest.forms}\n{/if}"
			. "{if digest.health}\n<b>" . __( 'Health', 'forms-notifier-for-elementor' ) . "</b>\n{digest.health}\n{/if}";
	}

	/** Complete notification. */
	private static function full(): string {
		/* translators: {form_name} is a tag and must not be translated */
		return '<b>' . __( 'New submission: {form_name}', 'forms-notifier-for-elementor' ) . "</b>\n\n{all_fields}\n\n"
			/* translators: {files} is a tag and must not be translated */
			. '{if files_count > 0}' . __( 'Files: {files}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			/* translators: {page_link} is a tag and must not be translated */
			. '{if page_url}' . __( 'Page: {page_link}', 'forms-notifier-for-elementor' ) . "\n{/if}"
			/* translators: {datetime} is a tag and must not be translated */
			. __( 'Date: {datetime}', 'forms-notifier-for-elementor' );
	}

	/** Short notification for text messages: stays below three parts. */
	private static function short(): string {
		/* translators: keep everything inside { } exactly as it is */
		return __( '{form_name|truncate:40}: new submission{if submission_id} #{submission_id}{/if}', 'forms-notifier-for-elementor' )
			. "\n{all_fields:max=3|truncate:120}";
	}
}
