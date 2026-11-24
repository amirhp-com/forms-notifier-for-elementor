<?php
/**
 * Delivery module.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Hooks: captured submission -> plan -> queue; scheduled work; settings. */
final class Module implements ModuleInterface {

	/** Attach hooks. */
	public function register(): void {
		add_action( 'fnfe_submission_captured', [ self::class, 'on_captured' ], 10, 2 );
		add_action( 'fnfe_submission_deleted', [ Cleanup::class, 'on_submission_deleted' ], 10, 1 );
		add_action( Cron::CLEANUP, [ Cleanup::class, 'daily' ], 10, 0 );
		add_action( Cron::DIGEST, [ Digest::class, 'tick' ], 10, 0 );
		add_filter( 'fnfe_settings_sections', [ SettingsSections::class, 'add' ], 5 );
		Ticker::register();
	}

	/**
	 * Queue the messages of a submission. A problem here must not break the visitor's form.
	 *
	 * @param Submission $s    Submission.
	 * @param FormConfig $form Form config.
	 */
	public static function on_captured( $s, $form ): void {
		if ( ! $s instanceof Submission || ! $form instanceof FormConfig ) {
			return;
		}
		try {
			Dispatcher::dispatch( $s, $form );
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage(), [ 'submission' => $s->id ] );
		}
	}
}
