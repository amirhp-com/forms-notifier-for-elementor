<?php
/**
 * Editor assets.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Admin\Assets;

defined( 'ABSPATH' ) || exit;

/** Enqueues editor.js and editor.css in the Elementor editor. */
final class EditorAssets {

	/** Attach hooks. */
	public static function register(): void {
		add_action( 'elementor/editor/after_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/** Enqueue. */
	public static function enqueue(): void {
		Assets::register();
		wp_enqueue_script( 'fnfe-editor' );
		wp_enqueue_style( 'fnfe-editor-css' );
	}
}
