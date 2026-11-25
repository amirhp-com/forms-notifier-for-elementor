<?php
/**
 * 360dialog.
 *
 * https://docs.360dialog.com/docs/messaging/message-types/text.md , /docs/messaging/media/upload-retrieve-or-delete-media.md , /docs/resources/templates/sending-template-messages.md
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

defined( 'ABSPATH' ) || exit;

/** 360dialog: the messages of the Cloud API on the host of 360dialog. */
final class Dialog360 extends MetaCloudApi {

	/** Id. */
	public function id(): string {
		return 'dialog360';
	}

	/** Name. */
	public function label(): string {
		return '360dialog';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://hub.360dialog.com/';
	}

	/** Fields. */
	public function fields(): array {
		return array_merge(
			[ $this->field( 'token', 'secret', __( 'API key', 'forms-notifier-for-elementor' ) ) ],
			$this->template_fields()
		);
	}

	/** Required. */
	protected function needs(): array {
		return [ 'token' ];
	}

	/** No account check. */
	public function status(): ?string {
		return null;
	}

	/** Headers. */
	protected function headers(): array {
		return [
			'D360-API-KEY' => $this->secret( 'token' ),
			'Accept'       => 'application/json',
		];
	}

	/**
	 * URL.
	 *
	 * @param string $path Path.
	 */
	protected function url( string $path ): string {
		return 'https://waba-v2.360dialog.io' . $path;
	}
}
