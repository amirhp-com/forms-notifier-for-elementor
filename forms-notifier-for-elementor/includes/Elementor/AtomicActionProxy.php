<?php
/**
 * Atomic action decorator.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use ElementorPro\Modules\AtomicForm\Actions\Action_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a registered atomic form action. The inner action runs first and its outcome is returned untouched. Loaded only
 * inside the registration hook, where the parent class exists.
 */
final class AtomicActionProxy extends Action_Base {

	/** @var Action_Base */
	private $inner;

	/** @var AtomicFormSource */
	private $source;

	/**
	 * @param Action_Base      $inner  Wrapped action.
	 * @param AtomicFormSource $source Adapter.
	 */
	public function __construct( Action_Base $inner, AtomicFormSource $source ) {
		$this->inner  = $inner;
		$this->source = $source;
	}

	/** Type of the wrapped action. */
	public function get_type(): string {
		return $this->inner->get_type();
	}

	/** The wrapped action. */
	public function inner(): Action_Base {
		return $this->inner;
	}

	/**
	 * Run the wrapped action, then report.
	 *
	 * @param array $form_data       Form data.
	 * @param array $widget_settings Widget settings.
	 * @param array $context         Context.
	 */
	public function execute( array $form_data, array $widget_settings, array $context ): array {
		try {
			$result = $this->inner->execute( $form_data, $widget_settings, $context );
		} catch ( \Throwable $e ) {
			$this->source->after_action( $this->get_type(), $form_data, $context, [ 'status' => 'failed' ] );
			throw $e;
		}
		$this->source->after_action( $this->get_type(), $form_data, $context, $result );
		return $result;
	}
}
