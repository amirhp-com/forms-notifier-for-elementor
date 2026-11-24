<?php
/**
 * Module contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

defined( 'ABSPATH' ) || exit;

/** A part of the plugin that wires its own hooks. */
interface ModuleInterface {

	/** Attach hooks. Called once on plugins_loaded; do not translate strings here. */
	public function register(): void;
}
