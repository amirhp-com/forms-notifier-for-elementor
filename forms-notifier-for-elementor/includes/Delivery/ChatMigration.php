<?php
/**
 * Chat migration.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Repository\ChatRepository;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** A group became a supergroup with a new id: update every stored receiver. */
final class ChatMigration {

	/**
	 * Replace a chat id everywhere.
	 *
	 * @param string $platform Channel id.
	 * @param string $old      Old chat id.
	 * @param string $new      New chat id.
	 */
	public static function apply( string $platform, string $old, string $new ): void {
		if ( $old === '' || $new === '' || $old === $new ) {
			return;
		}
		ChatRepository::migrate( $platform, $old, $new );

		foreach ( [ "channels.$platform.receivers", "alerts.channels.$platform.receivers", "digest.channels.$platform.receivers" ] as $path ) {
			$receivers = Settings::arr( $path );
			$changed   = self::receivers( $receivers, $old, $new );
			if ( $changed !== $receivers ) {
				Settings::set( $path, $changed );
			}
		}

		foreach ( self::forms( $old ) as $row ) {
			$config  = $row['config'];
			$changed = $config;
			if ( isset( $changed['channels'][ $platform ]['receivers'] ) ) {
				$changed['channels'][ $platform ]['receivers'] = self::receivers( (array) $changed['channels'][ $platform ]['receivers'], $old, $new );
			}
			foreach ( (array) $changed['rules'] as $i => $rule ) {
				if ( isset( $rule['receivers'][ $platform ] ) ) {
					$changed['rules'][ $i ]['receivers'][ $platform ] = self::receivers( (array) $rule['receivers'][ $platform ], $old, $new );
				}
			}
			if ( $changed !== $config ) {
				FormRepository::save_config( (int) $row['id'], $changed );
			}
		}

		Logger::info(
			'bot',
			'A chat moved to a new id; stored receivers were updated.',
			[
				'platform' => $platform,
				'old'      => $old,
				'new'      => $new,
			]
		);
	}

	/**
	 * Receivers with the new id; topics keep their number.
	 *
	 * @param array  $receivers Receivers.
	 * @param string $old       Old chat id.
	 * @param string $new       New chat id.
	 */
	private static function receivers( array $receivers, string $old, string $new ): array {
		foreach ( $receivers as $i => $receiver ) {
			$parts = explode( '/', is_array( $receiver ) ? (string) ( $receiver['address'] ?? '' ) : '', 2 );
			if ( $parts[0] === $old ) {
				$receivers[ $i ]['address'] = $new . ( isset( $parts[1] ) ? '/' . $parts[1] : '' );
			}
		}
		return $receivers;
	}

	/**
	 * Forms whose config mentions a chat id.
	 *
	 * @param string $chat Chat id.
	 * @return array[]
	 */
	private static function forms( string $chat ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE config LIKE %s', FormRepository::table(), '%' . $wpdb->esc_like( $chat ) . '%' ) );
		return array_filter( array_map( [ FormRepository::class, 'find' ], array_map( 'intval', $ids ) ) );
	}
}
