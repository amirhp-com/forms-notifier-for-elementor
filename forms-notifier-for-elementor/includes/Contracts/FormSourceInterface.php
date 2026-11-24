<?php
/**
 * Form source contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

defined( 'ABSPATH' ) || exit;

interface FormSourceInterface {

	/** 'pro' | 'atomic' | 'ehp' | custom */
	public function id(): string;

	/** Translated display name. */
	public function label(): string;

	/** The form technology is active on this site. */
	public function is_available(): bool;

	/** Attach native hooks; must call Capture::handle(). */
	public function register(): void;

	/**
	 * Discover forms inside one Elementor document's element tree.
	 *
	 * @param array $elements Decoded _elementor_data.
	 * @param int   $post_id  Document id.
	 * @return array[] each: [ 'element_id' => string, 'name' => string, 'fields' => array[] (id,type,label,options?),
	 *                 'editor' => array (fnfe_* settings), 'global_template_id' => int ]
	 */
	public function find_forms( array $elements, int $post_id ): array;
}
