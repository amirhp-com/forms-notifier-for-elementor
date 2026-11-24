<?php
/**
 * Attachments from uploaded files.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Model\Attachment;

defined( 'ABSPATH' ) || exit;

/** Builds value objects from what a form technology reports. */
final class AttachmentFactory {

	/**
	 * One uploaded file.
	 *
	 * @param string $field_id Field id.
	 * @param string $path     Path on disk.
	 * @param string $url      Public URL, '' when there is none.
	 * @param string $name     Name on the visitor's device, '' = name on disk.
	 */
	public static function make( string $field_id, string $path, string $url = '', string $name = '' ): Attachment {
		$name = sanitize_file_name( wp_basename( str_replace( '\\', '/', $name ) ) );
		$name = $name === '' ? sanitize_file_name( wp_basename( $path === '' ? (string) wp_parse_url( $url, PHP_URL_PATH ) : $path ) ) : $name;
		$safe = $path !== '' && PathGuard::is_safe( $path );
		return Attachment::from_array(
			[
				'field_id' => $field_id,
				'name'     => mb_substr( $name, -180 ),
				'path'     => $path,
				'url'      => esc_url_raw( $url, [ 'http', 'https' ] ),
				'mime'     => $safe ? Storage::mime( $path, $name ) : '',
				'size'     => $safe ? (int) filesize( $path ) : 0,
				'ext'      => strtolower( pathinfo( $path === '' ? $name : $path, PATHINFO_EXTENSION ) ),
			]
		);
	}
}
