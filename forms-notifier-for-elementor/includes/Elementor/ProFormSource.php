<?php
/**
 * Elementor Pro form widget.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro "form" widget.
 *
 * The hooks are attached whether or not Elementor Pro is loaded yet; they only fire when it is.
 */
final class ProFormSource extends AbstractRecordSource {

	/** Source id. */
	public function id(): string {
		return 'pro';
	}

	/** Translated display name. */
	public function label(): string {
		return _x( 'Form', 'form technology', 'forms-notifier-for-elementor' );
	}

	/** The form technology is active on this site. */
	public function is_available(): bool {
		return class_exists( '\ElementorPro\Plugin' );
	}

	/** Attach native hooks. */
	public function register(): void {
		add_action( 'elementor_pro/forms/actions/register', [ $this, 'register_action' ] );
		add_action( 'elementor_pro/forms/process', [ $this, 'on_process' ], 99, 2 );
		add_action( 'elementor_pro/forms/new_record', [ $this, 'on_new_record' ], 20, 2 );
	}

	/**
	 * Register the submit action. Its parent class exists only here.
	 *
	 * @param object $registrar Form actions registrar.
	 */
	public function register_action( $registrar ): void {
		$this->guarded(
			function () use ( $registrar ) {
				if ( class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) && is_object( $registrar ) && method_exists( $registrar, 'register' ) ) {
					$registrar->register( new FormAction( $this ) );
				}
			}
		);
	}

	/**
	 * Uploads were moved; the e-mail action may delete them soon.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function on_process( $record, $handler ): void {
		$this->remember_files( $record, $handler );
	}

	/**
	 * Every valid submission of every form.
	 *
	 * @param object $record  Form_Record.
	 * @param object $handler Ajax_Handler.
	 */
	public function on_new_record( $record, $handler ): void {
		$this->capture_record( $record, $handler );
	}

	/** Widget type in the element data. */
	protected function widget_type(): string {
		return 'form';
	}
}
