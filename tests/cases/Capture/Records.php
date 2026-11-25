<?php
/**
 * Helpers of the capture tests.
 *
 * @package FormsNotifier
 */

// phpcs:disable

namespace AmirhpCom\FormsNotifier\Tests\Capture;

use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Elementor\ProFormSource;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Tests\Db;

/** Stands in for Elementor Pro's Form_Record. */
final class FakeRecord {

	public $data;

	public function __construct( array $sent, array $settings, array $files = [] ) {
		$fields = [];
		foreach ( $settings['form_fields'] as $row ) {
			$raw   = $sent[ $row['custom_id'] ] ?? '';
			$value = is_array( $raw ) ? implode( ', ', array_filter( $raw, 'is_scalar' ) ) : $raw;
			switch ( $row['field_type'] ) {
				case 'text': case 'password': case 'hidden': case 'search': case 'checkbox': case 'radio': case 'select':
					$value = sanitize_text_field( $value );
					break;
				case 'url':
					$value = esc_url_raw( $value );
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				case 'email':
					$value = sanitize_email( $value );
					break;
			}
			$fields[ $row['custom_id'] ] = [
				'id'              => $row['custom_id'],
				'type'            => $row['field_type'],
				'title'           => $row['field_label'] ?? '',
				'value'           => $value,
				'raw_value'       => $raw,
				'required'        => false,
				'attachment_type' => $row['attachment_type'] ?? '',
			];
		}
		$this->data = [ 'sent_data' => $sent, 'form_settings' => $settings, 'fields' => $fields, 'files' => $files, 'meta' => [] ];
	}

	public function get( $key ) {
		return $this->data[ $key ] ?? null;
	}
}

/** Stands in for Elementor Pro's Ajax_Handler. */
final class FakeHandler {

	public $is_success = true;
	public $errors     = [];
	public $messages   = [ 'success' => [], 'error' => [], 'admin_error' => [] ];
	private $form;

	public function __construct( array $form ) {
		$this->form = $form;
	}

	public function get_current_form() {
		return $this->form;
	}
}

trait Records {

	/** Skipped drafts: [ reason, draft ]. */
	protected $skipped = [];

	/** Captured submissions. */
	protected $captured = [];

	protected function listen(): void {
		$this->filter( 'fnfe_submission_skipped', function ( $draft, $reason ) { $this->skipped[] = $reason; }, 10, 2 );
		$this->filter( 'fnfe_submission_captured', function ( $s ) { $this->captured[] = $s; }, 1, 1 );
	}

	protected function row( string $id, string $type = 'text', array $more = [] ): array {
		return array_merge( [ 'custom_id' => $id, 'field_type' => $type, 'field_label' => ucfirst( $id ) ], $more );
	}

	protected function settings( array $rows, array $more = [] ): array {
		return array_merge( [
			'id'             => 'abc1234',
			'form_post_id'   => 45,
			'edit_post_id'   => 45,
			'form_name'      => 'Contact',
			'form_fields'    => $rows,
			'submit_actions' => [],
		], $more );
	}

	/** Run the hooks of one native submit event. */
	protected function submit( array $sent, array $settings, array $files = [], array $options = [] ): ?Submission {
		$source  = $options['source'] ?? new ProFormSource();
		$record  = new FakeRecord( $sent, $settings, $files );
		$handler = new FakeHandler( [ 'id' => $options['element'] ?? 'abc1234' ] );
		$source->on_process( $record, $handler );
		if ( isset( $options['after_process'] ) ) {
			$options['after_process']( $record, $handler );
		}
		if ( in_array( 'fnfe', $settings['submit_actions'], true ) ) {
			$source->mark_action( $record, $handler );
		}
		$source->on_new_record( $record, $handler );
		$source->on_new_record( $record, $handler );
		return $this->latest();
	}

	protected function latest(): ?Submission {
		return SubmissionRepository::query( [ 'per_page' => 1 ] )['items'][0] ?? null;
	}

	protected function total(): int {
		return SubmissionRepository::count();
	}

	/** File in the folder Elementor uses. */
	protected function upload( string $ext = 'pdf', string $content = 'fake pdf' ): string {
		$dir = Db::uploads() . '/elementor/forms';
		wp_mkdir_p( $dir );
		$path = $dir . '/' . uniqid( '', true ) . '.' . $ext;
		file_put_contents( $path, $content );
		return $path;
	}

	protected function png(): string {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	}

	/** Everything the plugin stored, as one text. */
	protected function dump(): string {
		global $wpdb;
		$out = '';
		foreach ( DbSchema::tables() as $table ) {
			$out .= wp_json_encode( $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ), JSON_UNESCAPED_UNICODE );
		}
		return $out . wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%fnfe%'", ARRAY_A ) );
	}

	/** Files below the storage folder that are not protection files. */
	protected function stored(): array {
		$dir = Db::uploads() . '/fnfe-files';
		$out = [];
		if ( is_dir( $dir ) ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( ! in_array( $file->getFilename(), [ 'index.php', '.htaccess' ], true ) ) {
					$out[] = $file->getPathname();
				}
			}
		}
		return $out;
	}

	/** Form element of a document. */
	protected function element( string $id, array $rows, array $settings = [], string $widget = 'form' ): array {
		return [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $widget,
			'settings'   => array_merge( [ 'form_name' => 'Form ' . $id, 'form_fields' => $rows ], $settings ),
			'elements'   => [],
		];
	}

	/** Post with Elementor data; deleted by the caller. */
	protected function document( array $elements, array $post = [] ): int {
		$id = wp_insert_post( array_merge( [ 'post_title' => 'Scan test', 'post_type' => 'page', 'post_status' => 'private' ], $post ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'sec0001', 'elType' => 'container', 'settings' => [], 'elements' => $elements ] ] ) ) );
		return $id;
	}
}
