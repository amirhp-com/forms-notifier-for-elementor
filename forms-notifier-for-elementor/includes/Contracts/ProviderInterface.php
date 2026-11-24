<?php
/**
 * Gateway contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	/** Unique slug, [a-z0-9_]+. */
	public function id(): string;

	/** Translated display name. */
	public function label(): string;

	/** 'iran' | 'international' | 'passthrough' | 'any' */
	public function region(): string;

	/** Field arrays with RELATIVE keys, stored at channels.{channel}.providers.{id}.{key}. */
	public function fields(): array;

	/** Everything needed to send is set. */
	public function is_configured(): bool;

	/** Translated problems, [] when configured. */
	public function problems(): array;

	/** Account status / balance line for the UI, null = not supported. May do one HTTP call (cached 5 min by the caller). */
	public function status(): ?string;

	/** URL of the provider's panel/docs for the help link, '' if none. */
	public function panel_url(): string;
}
