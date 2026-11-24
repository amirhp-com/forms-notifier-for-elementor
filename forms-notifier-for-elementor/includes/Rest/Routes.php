<?php
/**
 * REST routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

defined( 'ABSPATH' ) || exit;

/** Registers the controllers that exist. */
final class Routes {

	public const CONTROLLERS = [
		MetaController::class,
		SettingsController::class,
		ChannelsController::class,
		ToolsController::class,
		FormsController::class,
		SubmissionsController::class,
		FilesController::class,
		TemplateController::class,
		MessagesController::class,
		BotController::class,
		AppController::class,
	];

	/** Runs on rest_api_init. */
	public static function register(): void {
		foreach ( self::CONTROLLERS as $class_name ) {
			if ( class_exists( $class_name ) ) {
				( new $class_name() )->register_routes();
			}
		}
		do_action( 'fnfe_rest_api_init' );
	}
}
