<?php
/**
 * Scheduled events.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Hook names and (un)scheduling. Handlers are attached by modules. */
final class Cron {

	/** Every minute (schedule fnfe_minute). */
	public const QUEUE = 'fnfe_queue_tick';

	/** Every minute, scheduled only while a bot is in polling mode. */
	public const POLL = 'fnfe_bot_poll';

	/** Daily. */
	public const CLEANUP = 'fnfe_cleanup';

	/** Hourly; Digest decides if it is due. */
	public const DIGEST = 'fnfe_digest_tick';

	/** Twice daily. */
	public const SCAN = 'fnfe_scan_forms';

	/** Add the one minute schedule. Recurring events are ensured on init. */
	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- One minute is needed by the queue.
	}

	/**
	 * Filter cron_schedules.
	 *
	 * @param array $schedules Known schedules.
	 */
	public static function schedules( $schedules ): array {
		$schedules                = is_array( $schedules ) ? $schedules : [];
		$schedules['fnfe_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			// Other plugins may ask for the schedules before translations can be loaded.
			'display'  => did_action( 'init' ) ? __( 'Every minute (Forms Notifier)', 'forms-notifier-for-elementor' ) : 'Every minute (Forms Notifier)',
		];
		return $schedules;
	}

	/** Schedule the recurring events that must always exist. */
	public static function ensure_events(): void {
		if ( self::uses_action_scheduler() ) {
			self::clear( self::QUEUE );
		} else {
			self::ensure( self::QUEUE, 'fnfe_minute' );
		}
		self::ensure( self::CLEANUP, 'daily' );
		self::ensure( self::DIGEST, 'hourly' );
		self::ensure( self::SCAN, 'twicedaily' );
	}

	/** The queue ticker runs on Action Scheduler (queue.ticker). */
	public static function uses_action_scheduler(): bool {
		$ticker = Settings::str( 'queue.ticker', 'auto' );
		return $ticker !== 'cron' && function_exists( 'as_schedule_recurring_action' );
	}

	/**
	 * Schedule a recurring event unless it exists.
	 *
	 * @param string $hook       Hook name.
	 * @param string $recurrence Schedule name.
	 */
	public static function ensure( string $hook, string $recurrence ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 10, $recurrence, $hook );
		}
	}

	/**
	 * Remove every event of a hook.
	 *
	 * @param string $hook Hook name.
	 */
	public static function clear( string $hook ): void {
		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Remove everything the plugin scheduled, Action Scheduler included. */
	public static function clear_all(): void {
		foreach ( [ self::QUEUE, self::POLL, self::CLEANUP, self::DIGEST, self::SCAN, 'fnfe_queue_run' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'fnfe' );
		}
	}
}
