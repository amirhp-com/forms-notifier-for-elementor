<?php
/**
 * Digest.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Lock;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Rest\Controller;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Jalali;

defined( 'ABSPATH' ) || exit;

/** Scheduled summary of the previous day or week. */
final class Digest {

	/** Forms listed. */
	private const TOP_FORMS = 10;

	/** Hourly hook fnfe_digest_tick. */
	public static function tick(): void {
		if ( ! Settings::bool( 'digest.enabled' ) ) {
			return;
		}
		$due = self::due();
		if ( ! self::is_due() ) {
			if ( State::get( 'digest_last', '' ) === '' ) {
				// The first digest is the next one; from now on a moment that passes between two ticks is noticed.
				State::set( 'digest_last', $due->format( 'Y-m-d' ) );
			}
			return;
		}
		if ( ! Lock::acquire( 'digest', 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			// Marked first: a digest that fails half way must not be repeated every hour.
			State::set( 'digest_last', $due->format( 'Y-m-d' ) );
			$data = self::data( $due );
			if ( Settings::bool( 'digest.skip_empty', true ) && self::is_empty( $data ) ) {
				return;
			}
			self::send( $data );
		} catch ( \Throwable $e ) {
			Logger::error( 'digest', $e->getMessage() );
		} finally {
			Lock::release( 'digest' );
		}
	}

	/** Enabled, and the latest moment of the schedule has no digest yet, however long ago it was. */
	public static function is_due(): bool {
		if ( ! Settings::bool( 'digest.enabled' ) ) {
			return false;
		}
		$day  = self::due()->format( 'Y-m-d' );
		$last = (string) State::get( 'digest_last', '' );
		// Before the first digest there is nothing to catch up on, except the digest of today.
		return $last === '' ? $day === Stats::now()->format( 'Y-m-d' ) : $last < $day;
	}

	/** Day of the latest moment of the schedule that has passed, site time. */
	private static function due(): \DateTimeImmutable {
		$time = Settings::str( 'digest.time', '09:00' );
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = '09:00';
		}
		$now  = Stats::now();
		$day  = $now->format( 'H:i' ) < $time ? Stats::today()->modify( '-1 day' ) : Stats::today();
		$back = 0;
		if ( Settings::str( 'digest.frequency', 'daily' ) === 'weekly' ) {
			$back = ( (int) $day->format( 'w' ) - abs( Settings::int( 'digest.weekday', 6 ) ) % 7 + 7 ) % 7;
		}
		return $day->modify( "-$back days" );
	}

	/**
	 * Numbers of the period. Filter: fnfe_digest_data.
	 *
	 * @param \DateTimeImmutable|null $day Day the digest belongs to (the period ends before it), null = today.
	 */
	public static function data( ?\DateTimeImmutable $day = null ): array {
		$weekly = Settings::str( 'digest.frequency', 'daily' ) === 'weekly';
		$days   = $weekly ? 7 : 1;
		$to     = $day ? $day->setTime( 0, 0 ) : Stats::today();
		$from   = $to->modify( "-$days days" );
		$before = $from->modify( "-$days days" );

		$current  = Stats::between( Stats::gmt( $from ), Stats::gmt( $to ) );
		$previous = Stats::between( Stats::gmt( $before ), Stats::gmt( $from ), false );
		$health   = Stats::health();

		return (array) apply_filters(
			'fnfe_digest_data',
			[
				'frequency'   => $weekly ? 'weekly' : 'daily',
				'from'        => $from->format( 'Y-m-d' ),
				'to'          => $to->modify( '-1 day' )->format( 'Y-m-d' ),
				'submissions' => $current['submissions'],
				'sent'        => $current['messages']['sent'],
				'failed'      => $current['messages']['failed'],
				'by_channel'  => $current['by_channel'],
				'by_form'     => array_slice( $current['by_form'], 0, self::TOP_FORMS ),
				'queue'       => Queue::pending(),
				'health'      => $health,
				'previous'    => [
					'submissions' => $previous['submissions'],
					'sent'        => $previous['messages']['sent'],
					'failed'      => $previous['messages']['failed'],
				],
			]
		);
	}

	/**
	 * Nothing happened in the period.
	 *
	 * @param array $data Output of data().
	 */
	public static function is_empty( array $data ): bool {
		return empty( $data['submissions'] ) && empty( $data['failed'] );
	}

	/**
	 * Send now, whether it is due or not.
	 *
	 * @param array|null $data Output of data().
	 * @return array<int,array{channel:string,recipient:string,result:SendResult}>
	 */
	public static function send( ?array $data = null ): array {
		$data    = $data ?? self::data();
		$results = Broadcast::send(
			Broadcast::targets( 'digest.channels' ),
			'digest',
			static function ( string $lang ) use ( $data ): array {
				$template = Settings::text( 'digest.template', $lang );
				return [
					'template' => trim( $template ) === '' ? DefaultTemplates::digest() : $template,
					'subject'  => self::subject( $data ),
					'extra'    => self::tags( $data ),
					'data'     => $data,
				];
			}
		);
		if ( $results ) {
			do_action( 'fnfe_digest_sent', $data );
		}
		return $results;
	}

	/**
	 * Values of the digest tags, in the active language.
	 *
	 * @param array $data Output of data().
	 * @return array<string,string>
	 */
	public static function tags( array $data ): array {
		$forms = [];
		foreach ( (array) ( $data['by_form'] ?? [] ) as $form ) {
			$name    = (string) ( $form['name'] ?? '' );
			$forms[] = sprintf(
				/* translators: %1$s: form name, %2$s: number of submissions */
				__( '%1$s: %2$s', 'forms-notifier-for-elementor' ),
				$name === '' ? __( 'Unnamed form', 'forms-notifier-for-elementor' ) : $name,
				number_format_i18n( (int) ( $form['submissions'] ?? 0 ) )
			);
		}

		$health = [];
		foreach ( (array) ( $data['health'] ?? [] ) as $check ) {
			if ( ( $check['status'] ?? 'good' ) !== 'good' ) {
				$health[] = (string) $check['message'];
			}
		}

		$previous = (array) ( $data['previous'] ?? [] );
		return [
			'digest.title'       => self::subject( $data ),
			'digest.period'      => self::period( $data ),
			'digest.submissions' => self::with_change( (int) ( $data['submissions'] ?? 0 ), (int) ( $previous['submissions'] ?? 0 ) ),
			'digest.sent'        => self::with_change( (int) ( $data['sent'] ?? 0 ), (int) ( $previous['sent'] ?? 0 ) ),
			'digest.failed'      => self::with_change( (int) ( $data['failed'] ?? 0 ), (int) ( $previous['failed'] ?? 0 ) ),
			'digest.forms'       => $forms ? implode( "\n", $forms ) : '-',
			'digest.health'      => $health ? implode( "\n", $health ) : __( 'Everything works.', 'forms-notifier-for-elementor' ),
			'digest.queue'       => number_format_i18n( (int) ( $data['queue'] ?? 0 ) ),
			'digest.url'         => Urls::admin(),
		];
	}

	/**
	 * Subject line.
	 *
	 * @param array $data Output of data().
	 */
	private static function subject( array $data ): string {
		return ( $data['frequency'] ?? '' ) === 'weekly'
			? __( 'Weekly form report', 'forms-notifier-for-elementor' )
			: __( 'Daily form report', 'forms-notifier-for-elementor' );
	}

	/**
	 * Period in words.
	 *
	 * @param array $data Output of data().
	 */
	private static function period( array $data ): string {
		$from = self::day( (string) ( $data['from'] ?? '' ) );
		$to   = self::day( (string) ( $data['to'] ?? '' ) );
		if ( $from === $to ) {
			return $from;
		}
		/* translators: %1$s: first day, %2$s: last day */
		return sprintf( __( '%1$s to %2$s', 'forms-notifier-for-elementor' ), $from, $to );
	}

	/**
	 * A day in the calendar of the active language.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function day( string $date ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( ! $day ) {
			return '';
		}
		if ( Controller::calendar() === 'jalali' && class_exists( Jalali::class ) ) {
			$fa   = strncmp( determine_locale(), 'fa', 2 ) === 0;
			$text = Jalali::format( 'j F Y', $day, $fa );
			return $fa ? Text::fa_digits( $text ) : $text;
		}
		return (string) wp_date( (string) get_option( 'date_format' ), $day->getTimestamp() );
	}

	/**
	 * Number with the change against the previous period.
	 *
	 * @param int $now    Number.
	 * @param int $before Number of the previous period.
	 */
	private static function with_change( int $now, int $before ): string {
		$text = number_format_i18n( $now );
		if ( $before <= 0 || $now === $before ) {
			return $text;
		}
		$percent = (int) round( 100 * ( $now - $before ) / $before );
		if ( $percent === 0 ) {
			return $text;
		}
		/* translators: %1$s: number, %2$s: signed change in percent */
		return sprintf( __( '%1$s (%2$s%%)', 'forms-notifier-for-elementor' ), $text, ( $percent > 0 ? '+' : '-' ) . number_format_i18n( abs( $percent ) ) );
	}
}
