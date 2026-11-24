<?php
/**
 * Uploaded file value object.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

defined( 'ABSPATH' ) || exit;

/** One uploaded file of a submission. Immutable after creation by convention. */
final class Attachment {

	/** @var int fnfe_files.id (0 = not stored) */
	public int $id = 0;

	/** @var string form field that produced it */
	public string $field_id = '';

	/** @var string original client file name, sanitised */
	public string $name = '';

	/** @var string absolute local path, '' when the file is gone */
	public string $path = '';

	/** @var string public URL if Elementor exposes one */
	public string $url = '';

	/** @var string mime type, '' if unknown */
	public string $mime = '';

	/** @var int bytes */
	public int $size = 0;

	/** @var string lowercase extension without dot */
	public string $ext = '';

	/** @var bool true when $path is inside the plugin's own storage */
	public bool $stored = false;

	/** Mime and extension both say image. */
	public function is_image(): bool {
		return strncmp( $this->mime, 'image/', 6 ) === 0
			&& in_array( $this->ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true );
	}

	/** The file is on disk and readable. */
	public function exists(): bool {
		return $this->path !== '' && is_file( $this->path ) && is_readable( $this->path );
	}

	/** All public properties. */
	public function to_array(): array {
		return [
			'id'       => $this->id,
			'field_id' => $this->field_id,
			'name'     => $this->name,
			'path'     => $this->path,
			'url'      => $this->url,
			'mime'     => $this->mime,
			'size'     => $this->size,
			'ext'      => $this->ext,
			'stored'   => $this->stored,
		];
	}

	/**
	 * Build from an array; unknown keys are ignored.
	 *
	 * @param array $a Output of to_array() or a database row.
	 */
	public static function from_array( array $a ): self {
		$file           = new self();
		$file->id       = (int) ( $a['id'] ?? 0 );
		$file->field_id = (string) ( $a['field_id'] ?? '' );
		$file->name     = (string) ( $a['name'] ?? '' );
		$file->path     = (string) ( $a['path'] ?? '' );
		$file->url      = (string) ( $a['url'] ?? '' );
		$file->mime     = (string) ( $a['mime'] ?? '' );
		$file->size     = max( 0, (int) ( $a['size'] ?? 0 ) );
		$file->ext      = strtolower( ltrim( (string) ( $a['ext'] ?? '' ), '.' ) );
		$file->stored   = (bool) ( $a['stored'] ?? false );

		if ( $file->ext === '' && $file->name !== '' ) {
			$file->ext = strtolower( pathinfo( $file->name, PATHINFO_EXTENSION ) );
		}
		if ( $file->url === 'attached' ) {
			$file->url = '';
		}
		return $file;
	}
}
