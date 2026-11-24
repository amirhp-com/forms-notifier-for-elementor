<?php
/**
 * Adapter base for form records.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Contracts\FormSourceInterface;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Shared by the form technologies that hand over a Form_Record and an Ajax_Handler. */
abstract class AbstractRecordSource implements FormSourceInterface {

	/** Sanitisers the form modules apply; while the value still equals their output the text as typed is used. */
	private const NATIVE = [
		'text'     => 'sanitize_text_field',
		'password' => 'sanitize_text_field',
		'hidden'   => 'sanitize_text_field',
		'search'   => 'sanitize_text_field',
		'checkbox' => 'sanitize_text_field',
		'radio'    => 'sanitize_text_field',
		'select'   => 'sanitize_text_field',
		'textarea' => 'sanitize_textarea_field',
		'email'    => 'sanitize_email',
	];

	/**
	 * Records of this request with what is known about them. The record is kept, so its object id stays unique.
	 *
	 * @var array<string,array{source:AbstractRecordSource,record:object,handler:object,files:Attachment[],action:bool,done:bool}>
	 */
	private static $records = [];

	/** @var bool */
	private static $shutdown = false;

	/** Widget type in the element data. */
	abstract protected function widget_type(): string;

	/**
	 * Markers of this technology in stored element data.
	 *
	 * @return string[]
	 */
	public function markers(): array {
		return [ '"widgetType":"' . $this->widget_type() . '"' ];
	}

	/**
	 * Discover forms inside one document's element tree.
	 *
	 * @param array $elements Decoded element data.
	 * @param int   $post_id  Document id.
	 * @return array[]
	 */
	public function find_forms( array $elements, int $post_id ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ( $element['widgetType'] ?? '' ) === $this->widget_type() && is_scalar( $element['id'] ?? null ) ) {
				$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
				$out[]    = [
					'element_id'         => (string) $element['id'],
					'name'               => is_scalar( $settings['form_name'] ?? null ) ? FieldNormalizer::clean( (string) $settings['form_name'] ) : '',
					'fields'             => FieldNormalizer::definitions( $this->native_definitions( $settings ) ),
					'editor'             => EditorValues::from_settings( $settings ),
					'global_template_id' => absint( $element['templateID'] ?? 0 ),
				];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$out = array_merge( $out, $this->find_forms( $element['elements'], $post_id ) );
			}
		}
		return $out;
	}

	/**
	 * Remember the uploads of a record before a later step can delete them.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function remember_files( $record, $handler ): void {
		$this->guarded(
			function () use ( $record, $handler ) {
				if ( ! is_object( $record ) || ! is_object( $handler ) || ! empty( $handler->errors ) ) {
					return;
				}
				$files = $this->uploads( $record );
				if ( ! $files ) {
					return;
				}
				$settings = (array) $record->get( 'form_settings' );
				$draft    = $this->identity( $settings, $handler );
				$form     = FormRepository::config_by_key( $draft->form_key );
				$via      = in_array( EditorValues::ACTION, EditorValues::actions( $settings ), true ) ? 'action' : 'auto';
				if ( ! Capture::is_active( $form, EditorValues::from_settings( $settings ), $via ) ) {
					return;
				}

				$all    = Policy::keeps_copies( $form );
				$attach = $this->attach_fields( $record );
				foreach ( $files as $i => $file ) {
					if ( $all || in_array( $file->field_id, $attach, true ) ) {
						$copy        = Storage::keep( $file );
						$files[ $i ] = $copy ? $copy : $file;
					}
				}

				$entry          = &$this->entry( $record, $handler );
				$entry['files'] = $files;
			}
		);
	}

	/**
	 * Build the draft and hand it to Capture, once per record.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function capture_record( $record, $handler ): void {
		$this->guarded(
			function () use ( $record, $handler ) {
				if ( ! is_object( $record ) || ! is_object( $handler ) ) {
					return;
				}
				$entry = &$this->entry( $record, $handler );
				$key   = $this->event_key( $record );
				if ( $entry['done'] || Capture::seen( $key ) ) {
					return;
				}
				$entry['done'] = true;

				$draft = $this->draft( $record, $handler, $entry );
				// From here on Capture owns the files.
				$entry['files'] = [];
				if ( isset( $draft->meta['action_errors'] ) && ! Settings::bool( 'general.notify_on_action_errors', true ) ) {
					Capture::skip( $draft, 'filtered' );
					return;
				}
				Capture::handle( $draft, $key );
			}
		);
	}

	/**
	 * The submit action ran: remember that, and capture at the end of the request if the final hook never fires.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function mark_action( $record, $handler ): void {
		$this->guarded(
			function () use ( $record, $handler ) {
				if ( is_object( $record ) && is_object( $handler ) ) {
					$entry           = &$this->entry( $record, $handler );
					$entry['action'] = true;
				}
			}
		);
	}

	/** Capture what was left behind, then drop unused copies. Runs on shutdown. */
	public static function on_shutdown(): void {
		foreach ( array_keys( self::$records ) as $key ) {
			$entry = self::$records[ $key ];
			if ( $entry['action'] && ! $entry['done'] ) {
				$entry['source']->capture_record( $entry['record'], $entry['handler'] );
			}
			foreach ( self::$records[ $key ]['files'] as $file ) {
				if ( $file->stored ) {
					Storage::delete( $file );
				}
			}
			self::$records[ $key ]['files'] = [];
		}
	}

	/**
	 * Definitions of the form fields in Elementor's shape.
	 *
	 * @param array $settings Widget settings.
	 */
	protected function native_definitions( array $settings ): array {
		return is_array( $settings['form_fields'] ?? null ) ? $settings['form_fields'] : [];
	}

	/**
	 * Text as the visitor typed it.
	 *
	 * @param string $sent Posted value after the record unslashed it.
	 */
	protected function sent_text( string $sent ): string {
		return $sent;
	}

	/**
	 * Run and never let an error reach the visitor.
	 *
	 * @param callable $callback Work.
	 */
	protected function guarded( callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			Logger::warning( 'capture', sprintf( '%s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
		}
	}

	/**
	 * Event key of a record.
	 *
	 * @param object $record Form_Record.
	 */
	private function event_key( $record ): string {
		return $this->id() . ':' . spl_object_id( $record );
	}

	/**
	 * What is known about a record.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	private function &entry( $record, $handler ): array {
		$key = $this->event_key( $record );
		if ( ! isset( self::$records[ $key ] ) ) {
			self::$records[ $key ] = [
				'source'  => $this,
				'record'  => $record,
				'handler' => $handler,
				'files'   => [],
				'action'  => false,
				'done'    => false,
			];
			if ( ! self::$shutdown ) {
				self::$shutdown = true;
				add_action( 'shutdown', [ self::class, 'on_shutdown' ], 1 );
			}
		}
		return self::$records[ $key ];
	}

	/**
	 * Draft with the identity of the form.
	 *
	 * @param array  $settings Form settings of the record.
	 * @param object $handler  Ajax_Handler.
	 */
	private function identity( array $settings, $handler ): Submission {
		$current = method_exists( $handler, 'get_current_form' ) ? $handler->get_current_form() : [];

		$draft             = new Submission();
		$draft->source     = $this->id();
		$draft->post_id    = absint( $settings['form_post_id'] ?? 0 );
		$draft->element_id = sanitize_text_field( (string) ( $current['id'] ?? $settings['id'] ?? '' ) );
		$draft->form_key   = $draft->post_id . ':' . $draft->element_id;
		$name              = is_scalar( $settings['form_name'] ?? null ) ? trim( FieldNormalizer::clean( (string) $settings['form_name'] ) ) : '';
		/* translators: %1$s: element id of a form without a name */
		$draft->form_name = $name === '' ? sprintf( __( 'Form %1$s', 'forms-notifier-for-elementor' ), $draft->element_id ) : $name;
		return $draft;
	}

	/**
	 * Complete draft.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 * @param array  $entry   What is known about the record.
	 */
	private function draft( $record, $handler, array $entry ): Submission {
		$settings = (array) $record->get( 'form_settings' );
		$draft    = $this->identity( $settings, $handler );

		$draft->files  = $entry['files'] ? $entry['files'] : $this->uploads( $record );
		$draft->fields = $this->native_fields( $record, $settings );

		$action      = $entry['action'] || in_array( EditorValues::ACTION, EditorValues::actions( $settings ), true );
		$draft->meta = array_merge(
			RequestMeta::from_post(),
			[
				'via'    => $action ? 'action' : 'auto',
				'editor' => EditorValues::from_settings( $settings ),
				'form'   => [
					'fields' => FieldNormalizer::definitions( $this->native_definitions( $settings ) ),
					'editor' => EditorValues::from_settings( $settings ),
				],
			]
		);

		if ( isset( $handler->is_success ) && ! $handler->is_success ) {
			$errors = isset( $handler->messages ) && is_array( $handler->messages ) ? $handler->messages : [];
			$errors = array_merge( (array) ( $errors['error'] ?? [] ), (array) ( $errors['admin_error'] ?? [] ) );

			$draft->meta['action_errors'] = array_values( array_unique( array_map( 'sanitize_text_field', array_filter( $errors, 'is_string' ) ) ) );
		}
		if ( in_array( 'save-to-database', EditorValues::actions( $settings ), true ) ) {
			$draft->meta['elementor_submission_id'] = SubmissionImporter::latest_id( $draft->post_id, $draft->element_id );
		}
		return $draft;
	}

	/**
	 * Fields of the record as native fields.
	 *
	 * @param object $record   Form_Record.
	 * @param array  $settings Form settings.
	 */
	private function native_fields( $record, array $settings ): array {
		$sent        = (array) $record->get( 'sent_data' );
		$definitions = [];
		foreach ( $this->native_definitions( $settings ) as $row ) {
			if ( is_array( $row ) && isset( $row['custom_id'] ) ) {
				$definitions[ (string) $row['custom_id'] ] = $row;
			}
		}

		$out = [];
		foreach ( (array) $record->get( 'fields' ) as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id   = (string) ( $field['id'] ?? $key );
			$row  = $definitions[ $id ] ?? [];
			$type = (string) ( $row['field_type'] ?? $field['type'] ?? 'text' );

			$out[ $id ] = [
				'id'          => $id,
				'type'        => $type,
				'label'       => is_scalar( $field['title'] ?? null ) ? (string) $field['title'] : '',
				'placeholder' => is_scalar( $row['placeholder'] ?? null ) ? (string) $row['placeholder'] : '',
				'raw'         => $type === 'upload' ? '' : $this->raw( $field, $sent[ $id ] ?? null ),
				'multiple'    => $type === 'checkbox' || in_array( $row['allow_multiple'] ?? '', [ 'true', 'yes' ], true ),
				'is_file'     => $type === 'upload',
			];
		}
		return $out;
	}

	/**
	 * Reported value: untouched arrays, text as typed unless somebody changed the value after the record was built.
	 *
	 * @param array $field Record field.
	 * @param mixed $sent  Posted value.
	 * @return mixed
	 */
	private function raw( array $field, $sent ) {
		if ( is_array( $sent ) ) {
			array_walk_recursive(
				$sent,
				function ( &$item ) {
					$item = is_string( $item ) ? $this->sent_text( $item ) : $item;
				}
			);
			return $sent;
		}
		$value    = $field['value'] ?? '';
		$sanitize = self::NATIVE[ (string) ( $field['type'] ?? '' ) ] ?? '';
		if ( is_string( $sent ) && $sanitize !== '' && is_string( $value ) && $value === $sanitize( $sent ) ) {
			return $this->sent_text( $sent );
		}
		return is_string( $value ) ? $this->sent_text( $value ) : $value;
	}

	/**
	 * Uploads of the record.
	 *
	 * @param object $record Form_Record.
	 * @return Attachment[]
	 */
	private function uploads( $record ): array {
		$out = [];
		foreach ( (array) $record->get( 'files' ) as $id => $files ) {
			$id = (string) $id;
			foreach ( is_array( $files['path'] ?? null ) ? $files['path'] : [] as $index => $path ) {
				if ( ! is_string( $path ) || $path === '' ) {
					continue;
				}
				$name  = $this->client_name( $id, $index );
				$url   = is_string( $files['url'][ $index ] ?? null ) ? $files['url'][ $index ] : '';
				$out[] = AttachmentFactory::make( $id, $path, $url === 'attached' ? '' : $url, $name );
			}
		}
		return $out;
	}

	/**
	 * Upload fields whose files are deleted after they were mailed.
	 *
	 * @param object $record Form_Record.
	 * @return string[]
	 */
	private function attach_fields( $record ): array {
		$out = [];
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			if ( ( $field['attachment_type'] ?? '' ) === 'attach' ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * File name on the visitor's device.
	 *
	 * @param string     $id    Field id.
	 * @param int|string $index File index.
	 */
	private function client_name( string $id, $index ): string {
		// The public form endpoint has no nonce by design; only a display name is read.
		$name = $_FILES['form_fields'][ $id ][ $index ]['name'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitised by AttachmentFactory; PHP does not slash file names.
		return is_string( $name ) ? $name : '';
	}
}
