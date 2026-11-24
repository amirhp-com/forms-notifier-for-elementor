<?php
/**
 * Counters.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Health\SiteHealth;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Numbers for the dashboard, the digest, the mini app and bot commands. Notifications and replies are counted. */
final class Stats {

	private const RANGES = [
		'today' => 1,
		'7d'    => 7,
		'30d'   => 30,
	];

	/** Days of the series when the range is everything. */
	private const ALL_DAYS = 90;

	/** Most forms listed. */
	private const MAX_FORMS = 50;

	private const EPOCH = '1970-01-01 00:00:00';
	private const LATER = '9999-12-31 23:59:59';

	/**
	 * Numbers for a range.
	 *
	 * @param string $range today | 7d | 30d | all.
	 * @return array{range:string,submissions:int,messages:array{sent:int,failed:int,queued:int},by_channel:array<string,array{sent:int,failed:int}>,by_form:array<int,array{form_id:int,name:string,submissions:int,failed:int}>,series:array<int,array{date:string,submissions:int,sent:int,failed:int}>}
	 */
	public static function summary( string $range = '7d' ): array {
		$range = $range === 'all' || isset( self::RANGES[ $range ] ) ? $range : '7d';
		$days  = self::RANGES[ $range ] ?? 0;
		$today = self::today();
		$from  = $days ? self::gmt( $today->modify( '-' . ( $days - 1 ) . ' days' ) ) : self::EPOCH;

		$data           = self::between( $from, self::LATER );
		$data['range']  = $range;
		$data['series'] = self::series( $days ? $days : self::ALL_DAYS );
		if ( $days ) {
			$before           = $today->modify( '-' . ( 2 * $days - 1 ) . ' days' );
			$previous         = self::between( self::gmt( $before ), $from, false );
			$data['previous'] = [
				'submissions' => $previous['submissions'],
				'sent'        => $previous['messages']['sent'],
				'failed'      => $previous['messages']['failed'],
			];
		}
		return array_merge( [ 'range' => $range ], $data );
	}

	/**
	 * Health overview.
	 *
	 * @return array[] [ 'id' =>, 'label' =>, 'status' => 'good|warning|critical', 'message' =>, 'route' => admin route that helps ]
	 */
	public static function health(): array {
		return class_exists( SiteHealth::class ) ? SiteHealth::checks() : [];
	}

	/**
	 * Numbers between two UTC dates (the end is exclusive).
	 *
	 * @param string $from    'Y-m-d H:i:s' UTC.
	 * @param string $to      'Y-m-d H:i:s' UTC.
	 * @param bool   $by_form Include the per form numbers.
	 */
	public static function between( string $from, string $to, bool $by_form = true ): array {
		global $wpdb;

		$messages   = [
			'sent'   => 0,
			'failed' => 0,
			'queued' => 0,
		];
		$by_channel = [];
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, status, COUNT(*) AS n FROM %i WHERE purpose IN ('admin','submitter') AND created_gmt >= %s AND created_gmt < %s GROUP BY channel, status",
				MessageRepository::table(),
				$from,
				$to
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$kind = self::kind( (string) $row['status'] );
			if ( $kind === '' ) {
				continue;
			}
			$messages[ $kind ] += (int) $row['n'];
			if ( $kind !== 'queued' ) {
				$by_channel[ $row['channel'] ] = $by_channel[ $row['channel'] ] ?? [
					'sent'   => 0,
					'failed' => 0,
				];

				$by_channel[ $row['channel'] ][ $kind ] += (int) $row['n'];
			}
		}
		ksort( $by_channel );

		return [
			'submissions' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE status NOT IN ('spam','trash') AND created_gmt >= %s AND created_gmt < %s",
					SubmissionRepository::table(),
					$from,
					$to
				)
			),
			'messages'    => $messages,
			'by_channel'  => $by_channel,
			'by_form'     => $by_form ? self::by_form( $from, $to ) : [],
		];
	}

	/** Midnight of today in the site timezone. */
	public static function today(): \DateTimeImmutable {
		return self::now()->setTime( 0, 0 );
	}

	/** Now in the site timezone. */
	public static function now(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . (int) apply_filters( 'fnfe_now', time() ) ) )->setTimezone( wp_timezone() );
	}

	/**
	 * UTC date of the database.
	 *
	 * @param \DateTimeImmutable $date Date.
	 */
	public static function gmt( \DateTimeImmutable $date ): string {
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}

	/**
	 * Submissions and failed messages per form.
	 *
	 * @param string $from UTC date.
	 * @param string $to   UTC date.
	 */
	private static function by_form( string $from, string $to ): array {
		global $wpdb;
		$forms = [];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, MAX(form_name) AS name, COUNT(*) AS n FROM %i WHERE status NOT IN ('spam','trash') AND created_gmt >= %s AND created_gmt < %s GROUP BY form_id",
				SubmissionRepository::table(),
				$from,
				$to
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$forms[ (int) $row['form_id'] ] = [
				'form_id'     => (int) $row['form_id'],
				'name'        => (string) $row['name'],
				'submissions' => (int) $row['n'],
				'failed'      => 0,
			];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS n FROM %i WHERE status = 'failed' AND purpose IN ('admin','submitter') AND form_id > 0 AND created_gmt >= %s AND created_gmt < %s GROUP BY form_id",
				MessageRepository::table(),
				$from,
				$to
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$id           = (int) $row['form_id'];
			$forms[ $id ] = $forms[ $id ] ?? [
				'form_id'     => $id,
				'name'        => '',
				'submissions' => 0,
				'failed'      => 0,
			];

			$forms[ $id ]['failed'] = (int) $row['n'];
		}
		usort(
			$forms,
			static function ( array $a, array $b ): int {
				return [ $b['submissions'], $b['failed'], $a['form_id'] ] <=> [ $a['submissions'], $a['failed'], $b['form_id'] ];
			}
		);
		$forms = array_slice( $forms, 0, self::MAX_FORMS );
		foreach ( $forms as $i => $form ) {
			// The current name, also after the form was renamed.
			$row = $form['form_id'] ? FormRepository::find( $form['form_id'] ) : null;
			if ( $row && (string) $row['name'] !== '' ) {
				$forms[ $i ]['name'] = (string) $row['name'];
			}
		}
		return $forms;
	}

	/**
	 * Numbers per local day, oldest first; days without activity are included.
	 *
	 * @param int $days Number of days up to today.
	 */
	private static function series( int $days ): array {
		$start  = self::today()->modify( '-' . ( $days - 1 ) . ' days' );
		$series = [];
		for ( $i = 0; $i < $days; $i++ ) {
			$date            = $start->modify( "+$i days" )->format( 'Y-m-d' );
			$series[ $date ] = [
				'date'        => $date,
				'submissions' => 0,
				'sent'        => 0,
				'failed'      => 0,
			];
		}
		self::bucket( $series, SubmissionRepository::table(), '', 'submissions', self::gmt( $start ) );
		self::bucket( $series, MessageRepository::table(), 'sent', 'sent', self::gmt( $start ) );
		self::bucket( $series, MessageRepository::table(), 'failed', 'failed', self::gmt( $start ) );
		return array_values( $series );
	}

	/**
	 * Add counts per local day. The database groups by ten minutes of UTC (by minute where the offset needs it), so the
	 * site timezone and its clock changes are applied here without database specific functions.
	 *
	 * @param array  $series Series by date, by reference.
	 * @param string $table  Table.
	 * @param string $status Message status, '' = submissions.
	 * @param string $key    Series key.
	 * @param string $from   UTC date.
	 */
	private static function bucket( array &$series, string $table, string $status, string $key, string $from ): void {
		global $wpdb;
		$zone   = wp_timezone();
		$length = self::now()->getOffset() % 600 === 0 ? 15 : 16;
		if ( $status === '' ) {
			$sql = $wpdb->prepare( "SELECT SUBSTR(created_gmt,1,%d) AS slot, COUNT(*) AS n FROM %i WHERE status NOT IN ('spam','trash') AND created_gmt >= %s GROUP BY slot", $length, $table, $from );
		} else {
			$sql = $wpdb->prepare( "SELECT SUBSTR(created_gmt,1,%d) AS slot, COUNT(*) AS n FROM %i WHERE status = %s AND purpose IN ('admin','submitter') AND created_gmt >= %s GROUP BY slot", $length, $table, $status, $from );
		}
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$slot = (string) $row['slot'] . ( $length === 15 ? '0' : '' );
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $slot, new \DateTimeZone( 'UTC' ) );
			$day  = $date ? $date->setTimezone( $zone )->format( 'Y-m-d' ) : '';
			if ( isset( $series[ $day ] ) ) {
				$series[ $day ][ $key ] += (int) $row['n'];
			}
		}
	}

	/**
	 * Counter of a row status, '' = not counted.
	 *
	 * @param string $status Status.
	 */
	private static function kind( string $status ): string {
		if ( $status === 'sent' || $status === 'failed' ) {
			return $status;
		}
		return in_array( $status, [ 'queued', 'retry', 'sending' ], true ) ? 'queued' : '';
	}
}
