<?php
/**
 * Update routing.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

defined( 'ABSPATH' ) || exit;

/** Routes one update to discovery, commands and callbacks. */
final class UpdateHandler {

	/**
	 * Handle an update.
	 *
	 * @param string $platform Platform.
	 * @param array  $update   Untrusted update.
	 */
	public static function handle( string $platform, array $update ): void {
		ChatDiscovery::handle( $platform, $update );

		foreach ( [ 'message', 'channel_post' ] as $kind ) {
			if ( is_array( $update[ $kind ] ?? null ) ) {
				Commands::handle( $platform, $update[ $kind ], $kind === 'channel_post' );
			}
		}
		if ( is_array( $update['callback_query'] ?? null ) ) {
			Callbacks::handle( $platform, $update['callback_query'] );
		}
	}
}
