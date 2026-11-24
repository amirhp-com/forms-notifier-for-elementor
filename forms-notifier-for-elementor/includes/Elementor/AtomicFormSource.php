<?php
/**
 * Elementor Pro atomic form.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Contracts\FormSourceInterface;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Best effort adapter: atomic forms fire no hook after a submit, so every registered action is wrapped and the first
 * call that knows everything captures. Without the classes it needs the adapter does nothing.
 */
final class AtomicFormSource implements FormSourceInterface {

	private const RUNNER = '\ElementorPro\Modules\AtomicForm\Actions\Action_Runner';

	private const BASE = '\ElementorPro\Modules\AtomicForm\Actions\Action_Base';

	private const FORM = 'e-form';

	/** Action that saves Elementor's own submission; it runs first. */
	private const COLLECT = 'collect-submissions';

	/** Atomic field widgets and the shared type names. */
	private const TYPES = [
		'e-form-input'        => 'text',
		'e-form-textarea'     => 'textarea',
		'e-form-checkbox'     => 'checkbox',
		'e-form-radio-button' => 'radio',
		'e-form-date-picker'  => 'date',
		'e-form-time-picker'  => 'time',
		'e-form-select'       => 'select',
		'e-form-file-upload'  => 'upload',
	];

	/** Source id. */
	public function id(): string {
		return 'atomic';
	}

	/** Translated display name. */
	public function label(): string {
		return __( 'Atomic form', 'forms-notifier-for-elementor' );
	}

	/** The form technology is active on this site. */
	public function is_available(): bool {
		return class_exists( self::RUNNER ) && class_exists( self::BASE )
			&& method_exists( self::RUNNER, 'get_registered_actions' ) && method_exists( self::RUNNER, 'register_action' )
			&& did_action( 'elementor_pro/atomic_forms/actions/register' ) > 0;
	}

	/** Attach native hooks. */
	public function register(): void {
		add_action( 'elementor_pro/atomic_forms/actions/register', [ $this, 'wrap_actions' ], 999 );
	}

	/**
	 * Markers of this technology in stored element data.
	 *
	 * @return string[]
	 */
	public function markers(): array {
		return [ '"elType":"' . self::FORM . '"', '"widgetType":"' . self::FORM . '"' ];
	}

	/** Wrap every registered action once. */
	public function wrap_actions(): void {
		try {
			if ( ! $this->is_available() ) {
				return;
			}
			$runner = self::RUNNER;
			foreach ( $runner::get_registered_actions() as $action ) {
				if ( is_a( $action, self::BASE ) && ! $action instanceof AtomicActionProxy ) {
					$runner::register_action( new AtomicActionProxy( $action, $this ) );
				}
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'capture', $e->getMessage() );
		}
	}

	/**
	 * An action finished.
	 *
	 * @param string $type      Action type.
	 * @param array  $form_data Form data.
	 * @param array  $context   Context of the runner.
	 * @param array  $result    Outcome of the action.
	 */
	public function after_action( string $type, array $form_data, array $context, array $result ): void {
		try {
			if ( $type === self::COLLECT ) {
				if ( (int) ( $context['pending_actions_count'] ?? 0 ) > 0 ) {
					return;
				}
				if ( ! empty( $result['submissionId'] ) ) {
					$context['submission_id'] = $result['submissionId'];
				}
			}
			$key = 'atomic:' . absint( $context['post_id'] ?? 0 ) . ':' . sanitize_text_field( (string) ( $context['form_id'] ?? '' ) );
			if ( Capture::seen( $key ) ) {
				return;
			}
			$draft = $this->draft( $form_data, $context );
			if ( ( $result['status'] ?? 'success' ) === 'failed' ) {
				$error = is_string( $result['error'] ?? null ) ? sanitize_text_field( $result['error'] ) : '';

				$draft->meta['action_errors'] = $error === '' ? [ $type ] : [ $error ];
				if ( ! Settings::bool( 'general.notify_on_action_errors', true ) ) {
					Capture::skip( $draft, 'filtered' );
					return;
				}
			}
			Capture::handle( $draft, $key );
		} catch ( \Throwable $e ) {
			Logger::warning( 'capture', sprintf( '%s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
		}
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
			$children = is_array( $element['elements'] ?? null ) ? $element['elements'] : [];
			if ( self::kind( $element ) === self::FORM && is_scalar( $element['id'] ?? null ) ) {
				$out[] = [
					'element_id'         => (string) $element['id'],
					'name'               => FieldNormalizer::clean( self::prop( $element, 'form-name' ) ),
					'fields'             => FieldNormalizer::definitions( self::field_rows( $children, self::labels( $children ) ) ),
					'editor'             => [],
					'global_template_id' => 0,
				];
			} elseif ( $children ) {
				$out = array_merge( $out, $this->find_forms( $children, $post_id ) );
			}
		}
		return $out;
	}

	/**
	 * Complete draft.
	 *
	 * @param array $form_data Form data.
	 * @param array $context   Context of the runner.
	 */
	private function draft( array $form_data, array $context ): Submission {
		$draft             = new Submission();
		$draft->source     = $this->id();
		$draft->post_id    = absint( $context['post_id'] ?? 0 );
		$draft->element_id = sanitize_text_field( (string) ( $context['form_id'] ?? '' ) );
		$draft->form_key   = $draft->post_id . ':' . $draft->element_id;

		$row  = FormRepository::find_by_key( $draft->form_key );
		$name = trim( FieldNormalizer::clean( is_scalar( $context['form_name'] ?? null ) ? (string) $context['form_name'] : '' ) );
		if ( $name === '' || $name === $draft->element_id ) {
			/* translators: %1$s: element id of a form without a name */
			$name = $row && $row['name'] !== '' ? $row['name'] : sprintf( __( 'Form %1$s', 'forms-notifier-for-elementor' ), $draft->element_id );
		}
		$draft->form_name = $name;

		$types = [];
		foreach ( $row ? $row['fields'] : [] as $known ) {
			$types[ (string) ( $known['id'] ?? '' ) ] = (string) ( $known['type'] ?? '' );
		}

		$meta  = is_array( $context['field_metadata'] ?? null ) ? $context['field_metadata'] : [];
		$files = is_array( $context['files'] ?? null ) ? $context['files'] : [];
		$facts = [];
		foreach ( $form_data as $id => $value ) {
			$id   = (string) $id;
			$info = is_array( $meta[ $id ] ?? null ) ? $meta[ $id ] : [];
			$type = (string) ( $info['type'] ?? '' );
			// The type comes from the browser. A password must not become readable because it was renamed there.
			if ( ( $types[ $id ] ?? '' ) === 'password' ) {
				$type = 'password';
			} elseif ( $type === '' ) {
				$type = $types[ $id ] ?? 'text';
			}
			if ( isset( $files[ $id ] ) || $type === 'file' ) {
				$type = 'upload';
			}
			$draft->fields[ $id ] = [
				'id'       => $id,
				'type'     => $type,
				'label'    => (string) ( $info['label'] ?? '' ),
				'raw'      => $type === 'upload' ? '' : $value,
				'multiple' => is_array( $value ),
				'is_file'  => $type === 'upload',
			];

			$facts[] = [
				'id'      => $id,
				'type'    => $type,
				'label'   => (string) ( $info['label'] ?? '' ),
				'options' => is_array( $info['options'] ?? null ) ? $info['options'] : [],
			];
		}

		$draft->files = $this->files( $files, $form_data, $draft->form_key );
		$draft->meta  = array_merge(
			RequestMeta::build(
				is_string( $context['referrer'] ?? null ) ? $context['referrer'] : '',
				is_string( $context['referer_title'] ?? null ) ? sanitize_text_field( $context['referer_title'] ) : '',
				$draft->post_id
			),
			[
				'via'    => 'auto',
				'editor' => [],
				'form'   => [ 'fields' => FieldNormalizer::definitions( $facts ) ],
			]
		);
		if ( ! empty( $context['submission_id'] ) ) {
			$draft->meta['elementor_submission_id'] = absint( $context['submission_id'] );
		}
		return $draft;
	}

	/**
	 * Uploaded files, copied when the policy says so.
	 *
	 * @param array  $files     Field id => paths.
	 * @param array  $form_data Field id => URLs.
	 * @param string $form_key  Form key.
	 * @return Attachment[]
	 */
	private function files( array $files, array $form_data, string $form_key ): array {
		$form = FormRepository::config_by_key( $form_key );
		if ( ! $files || ! Capture::is_active( $form, [], 'auto' ) ) {
			return [];
		}
		$keep = Policy::keeps_copies( $form );
		$out  = [];
		foreach ( $files as $id => $paths ) {
			$names = self::client_names( (string) $id );
			foreach ( array_values( (array) $paths ) as $i => $path ) {
				if ( ! is_string( $path ) ) {
					continue;
				}
				$url  = is_array( $form_data[ $id ] ?? null ) && is_string( $form_data[ $id ][ $i ] ?? null ) ? $form_data[ $id ][ $i ] : '';
				$file = AttachmentFactory::make( (string) $id, $path, $url, $names[ $i ] ?? '' );
				$copy = $keep ? Storage::keep( $file ) : null;

				$out[] = $copy ? $copy : $file;
			}
		}
		return $out;
	}

	/**
	 * File names on the visitor's device, in the order of the stored paths.
	 *
	 * @param string $field_id Field id.
	 * @return string[]
	 */
	private static function client_names( string $field_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Elementor verified its nonce before; only display names are read and AttachmentFactory sanitises them.
		$fields = isset( $_POST['form_fields'] ) && is_array( $_POST['form_fields'] ) ? $_POST['form_fields'] : [];
		$out    = [];
		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['id'] ?? null ) || sanitize_text_field( $field['id'] ) !== $field_id ) {
				continue;
			}
			$names = $_FILES['form_fields']['name'][ $index ]['value'] ?? [];
			foreach ( is_array( $names ) ? $names : [] as $i => $name ) {
				if ( is_string( $name ) && $name !== '' && ( $_FILES['form_fields']['error'][ $index ]['value'][ $i ] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
					$out[] = $name;
				}
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Widget or element type.
	 *
	 * @param array $element Element data.
	 */
	private static function kind( array $element ): string {
		$kind = $element['widgetType'] ?? '';
		$kind = is_string( $kind ) && $kind !== '' ? $kind : ( $element['elType'] ?? '' );
		return is_string( $kind ) ? $kind : '';
	}

	/**
	 * Field definitions below a form element.
	 *
	 * @param array $elements Element data.
	 * @param array $labels   CSS id => label text.
	 */
	private static function field_rows( array $elements, array $labels ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$kind = self::kind( $element );
			if ( isset( self::TYPES[ $kind ] ) && is_scalar( $element['id'] ?? null ) ) {
				$type  = $kind === 'e-form-input' ? self::prop( $element, 'type' ) : '';
				$out[] = [
					'id'          => (string) $element['id'],
					'type'        => $type === '' ? self::TYPES[ $kind ] : $type,
					'label'       => $labels[ self::prop( $element, '_cssid' ) ] ?? '',
					'placeholder' => self::prop( $element, 'placeholder' ),
				];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$out = array_merge( $out, self::field_rows( $element['elements'], $labels ) );
			}
		}
		return $out;
	}

	/**
	 * Label widgets below a form element: CSS id of the field => text.
	 *
	 * @param array $elements Element data.
	 * @return array<string,string>
	 */
	private static function labels( array $elements ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( self::kind( $element ) === 'e-form-label' ) {
				$target = self::prop( $element, 'input-id' );
				if ( $target !== '' ) {
					$out[ $target ] = self::prop( $element, 'text' );
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$out += self::labels( $element['elements'] );
			}
		}
		return $out;
	}

	/**
	 * Text of an atomic property: a string, or nested [ '$$type' => ..., 'value' => ... ] wrappers.
	 *
	 * @param array  $element Element data.
	 * @param string $name    Property.
	 */
	private static function prop( array $element, string $name ): string {
		$value = $element['settings'][ $name ] ?? '';
		$depth = 0;
		while ( $depth < 6 && is_array( $value ) ) {
			$value = $value['value'] ?? $value['content'] ?? '';
			++$depth;
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
