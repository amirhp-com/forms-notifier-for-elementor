<?php
/**
 * Hello Plus form widget.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Hello Plus "ehp-form" widget (hooks checked against Hello Plus 1.7.8).
 *
 * There is no hook after the submit actions, so a submission is captured when it passed validation and processing.
 */
final class HelloPlusFormSource extends AbstractRecordSource {

	/** Own names of types Elementor Pro also has. */
	private const TYPES = [
		'ehp-tel'        => 'tel',
		'ehp-acceptance' => 'acceptance',
	];

	/** Source id. */
	public function id(): string {
		return 'ehp';
	}

	/** Translated display name. */
	public function label(): string {
		return __( 'Hello Plus', 'forms-notifier-for-elementor' );
	}

	/** The form technology is active on this site. */
	public function is_available(): bool {
		return defined( 'HELLOPLUS_VERSION' );
	}

	/** Attach native hooks. */
	public function register(): void {
		add_action( 'hello_plus/forms/process', [ $this, 'on_process' ], PHP_INT_MAX, 2 );
	}

	/**
	 * The fields are valid and processed.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function on_process( $record, $handler ): void {
		if ( is_object( $handler ) && empty( $handler->errors ) ) {
			$this->capture_record( $record, $handler );
		}
	}

	/** Widget type in the element data. */
	protected function widget_type(): string {
		return 'ehp-form';
	}

	/**
	 * Definitions with the shared type names.
	 *
	 * @param array $settings Widget settings.
	 */
	protected function native_definitions( array $settings ): array {
		$out = [];
		foreach ( parent::native_definitions( $settings ) as $row ) {
			if ( is_array( $row ) ) {
				$type              = is_string( $row['field_type'] ?? null ) ? $row['field_type'] : 'text';
				$row['field_type'] = self::TYPES[ $type ] ?? $type;
				$out[]             = $row;
			}
		}
		return $out;
	}

	/**
	 * Hello Plus reads the request with FILTER_SANITIZE_FULL_SPECIAL_CHARS, which encodes special characters once.
	 *
	 * @param string $sent Posted value after the record unslashed it.
	 */
	protected function sent_text( string $sent ): string {
		return html_entity_decode( $sent, ENT_QUOTES, 'UTF-8' );
	}
}
