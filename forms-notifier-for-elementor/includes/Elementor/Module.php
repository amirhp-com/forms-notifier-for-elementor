<?php
/**
 * Elementor wiring.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Capture\FileCleanup;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Registry;

defined( 'ABSPATH' ) || exit;

/** Attaches the form adapters, the scanner, the editor assets and the file cleanup. */
final class Module implements ModuleInterface {

	/** @var array<string,bool> */
	private static $registered = [];

	/** Attach hooks. Runs on plugins_loaded, before Elementor registers its forms module. */
	public function register(): void {
		self::register_sources();
		// Sources other plugins registered in the meantime.
		add_action( 'init', [ self::class, 'register_sources' ], 2 );

		EditorAssets::register();
		FileCleanup::register();

		add_action( Cron::SCAN, [ self::class, 'scan' ] );
		add_action( 'elementor/document/after_save', [ FormScanner::class, 'on_document_saved' ] );
		add_action( 'transition_post_status', [ FormScanner::class, 'on_post_status' ], 10, 3 );
		add_action( 'deleted_post', [ FormScanner::class, 'on_post_deleted' ] );
	}

	/** Let every source attach its hooks, each one once. */
	public static function register_sources(): void {
		foreach ( Registry::form_sources() as $id => $source ) {
			if ( empty( self::$registered[ $id ] ) ) {
				self::$registered[ $id ] = true;
				$source->register();
			}
		}
	}

	/** Cron: continue or start a scan. The first event is due seconds after activation. */
	public static function scan(): void {
		$result = FormScanner::run( 10 );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cron::SCAN );
		}
	}
}
