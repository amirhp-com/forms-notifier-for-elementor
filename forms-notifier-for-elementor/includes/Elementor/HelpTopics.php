<?php
/**
 * Help topics.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

defined( 'ABSPATH' ) || exit;

/** Topics about forms and files for the help screen. */
final class HelpTopics {

	/** Attach hooks. */
	public static function register(): void {
		add_filter( 'fnfe_help_topics', [ self::class, 'topics' ] );
	}

	/**
	 * Add the topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function topics( $topics ): array {
		$topics = (array) $topics;

		$topics['forms-found'] = [
			'title' => __( 'Which forms are found', 'forms-notifier-for-elementor' ),
			'group' => 'start',
			'body'  => '<p>' . esc_html__( 'The plugin works with the Form widget of Elementor Pro, with its atomic form and with the form of Hello Plus. Forms in pages, posts, popups, headers, footers and other templates are found twice a day, when a document is saved in Elementor, and when you scan on the Forms screen.', 'forms-notifier-for-elementor' ) . '</p>'
				. '<p>' . esc_html__( 'A global widget is one form, no matter how many pages show it. A form inside a translated page uses the settings of the form in the original page until you unlink it.', 'forms-notifier-for-elementor' ) . '</p>'
				. '<p>' . esc_html__( 'Submissions collected by Elementor can be imported on the Submissions screen. Nobody is notified about imported submissions, and nothing is imported twice.', 'forms-notifier-for-elementor' ) . '</p>',
		];

		$topics['uploaded-files'] = [
			'title' => __( 'Uploaded files', 'forms-notifier-for-elementor' ),
			'group' => 'start',
			'body'  => '<p>' . esc_html__( 'Files are copied into a protected folder under names nobody can guess. Links in messages are signed, can expire, and always go through the plugin.', 'forms-notifier-for-elementor' ) . '</p>'
				. '<p>' . esc_html__( 'Choose in the settings, per channel, per form or in the Elementor editor whether files are sent, linked, both, or left out. A file that is too large for a channel becomes a link.', 'forms-notifier-for-elementor' ) . '</p>'
				. '<p>' . esc_html__( 'The folder is closed with an .htaccess file, which only Apache and LiteSpeed read. Site Health (check "File storage") asks your web server whether the folder is really closed. On nginx, deny access to the folder fnfe-files in your uploads directory, or move the folder out of the public folder with the constant FNFE_FILES_DIR in wp-config.php.', 'forms-notifier-for-elementor' ) . '</p>',
		];
		return $topics;
	}
}
