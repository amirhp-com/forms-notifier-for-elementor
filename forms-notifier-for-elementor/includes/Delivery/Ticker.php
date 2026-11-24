<?php
/**
 * Ticker.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** Drives the queue every minute with Action Scheduler or WP-Cron. */
final class Ticker {

	/** Action Scheduler group. */
	public const GROUP = 'fnfe';

	/** One-time run request. */
	public const RUN = 'fnfe_queue_run';

	/** Seconds after which waiting messages count as stuck. */
	public const STUCK = 15 * MINUTE_IN_SECONDS;

	/** Hooks of the ticker. */
	public static function register(): void {
		add_action( Cron::QUEUE, [ self::class, 'tick' ], 10, 0 );
		add_action( self::RUN, [ self::class, 'tick' ], 10, 0 );
		add_action( 'action_scheduler_init', [ self::class, 'maybe_ensure' ], 20, 0 );
		add_action( 'fnfe_settings_saved', [ self::class, 'on_settings' ], 10, 1 );
	}

	/** Every minute: recover stale rows, work, look for trouble. */
	public static function tick(): void {
		try {
			State::set( 'queue_last_tick', (int) apply_filters( 'fnfe_now', time() ) );
			MessageRepository::release_stale();
			Queue::run();
			self::check_stuck();
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage() );
		}
	}

	/** Front-end requests do not pay for the check. */
	public static function maybe_ensure(): void {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::ensure();
		}
	}

	/** Make sure the recurring event of the configured driver exists, and only that one. */
	public static function ensure(): void {
		if ( ! Cron::uses_action_scheduler() ) {
			self::unschedule_action();
			Cron::ensure( Cron::QUEUE, 'fnfe_minute' );
			return;
		}
		if ( ! self::action_scheduler_ready() ) {
			return;
		}
		Cron::clear( Cron::QUEUE );
		if ( ! self::has_action() ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, Cron::QUEUE, [], self::GROUP );
		}
	}

	/** Ask the scheduler for a run as soon as possible. */
	public static function request_run(): void {
		if ( ! wp_next_scheduled( self::RUN ) ) {
			wp_schedule_single_event( time(), self::RUN );
		}
	}

	/** Timestamp of the next tick, 0 when none is scheduled. */
	public static function next_run(): int {
		if ( Cron::uses_action_scheduler() && self::action_scheduler_ready() && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( Cron::QUEUE, [], self::GROUP );
			// True means "running right now".
			return $next === true ? time() : (int) $next;
		}
		return (int) wp_next_scheduled( Cron::QUEUE );
	}

	/** Driver in use: action_scheduler | cron. */
	public static function driver(): string {
		return Cron::uses_action_scheduler() ? 'action_scheduler' : 'cron';
	}

	/**
	 * The driver may have changed.
	 *
	 * @param mixed $paths Changed setting paths.
	 */
	public static function on_settings( $paths ): void {
		if ( in_array( 'queue.ticker', (array) $paths, true ) ) {
			self::ensure();
		}
	}

	/** Tell the admins when messages wait although the ticker runs. */
	private static function check_stuck(): void {
		$age = Queue::oldest_due_age();
		if ( $age <= self::STUCK || ! RateLimit::hit( 'alert_queue_stuck', 1, 6 * HOUR_IN_SECONDS ) ) {
			return;
		}
		Alerts::send(
			__( 'The message queue is stuck', 'forms-notifier-for-elementor' ),
			sprintf(
				/* translators: %1$d: number of minutes */
				_n( 'The oldest message has been waiting for %1$d minute.', 'The oldest message has been waiting for %1$d minutes.', (int) floor( $age / 60 ), 'forms-notifier-for-elementor' ),
				(int) floor( $age / 60 )
			)
		);
	}

	/** Action Scheduler is loaded and its store can be used. */
	private static function action_scheduler_ready(): bool {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return false;
		}
		return ! class_exists( '\ActionScheduler' ) || ! method_exists( '\ActionScheduler', 'is_initialized' ) || \ActionScheduler::is_initialized();
	}

	/** The recurring action exists. */
	private static function has_action(): bool {
		return function_exists( 'as_has_scheduled_action' )
			? (bool) as_has_scheduled_action( Cron::QUEUE, [], self::GROUP )
			: (bool) as_next_scheduled_action( Cron::QUEUE, [], self::GROUP );
	}

	/** Remove the recurring action. */
	private static function unschedule_action(): void {
		if ( self::action_scheduler_ready() && function_exists( 'as_unschedule_all_actions' ) && self::has_action() ) {
			as_unschedule_all_actions( Cron::QUEUE, [], self::GROUP );
		}
	}
}
