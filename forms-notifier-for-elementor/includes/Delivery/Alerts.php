<?php
/**
 * Failure alerts.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;

defined( 'ABSPATH' ) || exit;

/** Tells the admins about failed deliveries through their own channels. Rate limited, coalesced, never recursive. */
final class Alerts {

	/** Seconds in which the same error of the same channel is reported once. */
	private const COALESCE = 10 * MINUTE_IN_SECONDS;

	/** Purposes that never raise an alert. */
	private const SILENT = [ 'alert', 'test', 'digest' ];

	/** @var bool */
	private static $sending = false;

	/**
	 * Called by the worker when a row fails.
	 *
	 * @param array      $message_row Row.
	 * @param SendResult $result      Result.
	 * @param bool       $final       No more attempts.
	 * @param bool|null  $first       First failure of the row, null = by the number of attempts.
	 */
	public static function on_failure( array $message_row, SendResult $result, bool $final, ?bool $first = null ): void {
		if ( self::$sending || ! Settings::bool( 'alerts.enabled' ) || in_array( $message_row['purpose'] ?? '', self::SILENT, true ) ) {
			return;
		}
		$first = $first ?? (int) ( $message_row['attempts'] ?? 0 ) <= 1;
		if ( ! ( Settings::str( 'alerts.when', 'final' ) === 'first' ? $first : $final ) ) {
			return;
		}
		self::guarded(
			static function () use ( $message_row, $result ): void {
				self::report( $message_row, $result );
			}
		);
	}

	/**
	 * Send a custom alert text through the alert channels.
	 *
	 * @param string $title   Title.
	 * @param string $text    Text.
	 * @param array  $context Extra data: channel (id that has the problem), url (link for more information).
	 */
	public static function send( string $title, string $text, array $context = [] ): void {
		if ( self::$sending || ! Settings::bool( 'alerts.enabled' ) ) {
			return;
		}
		self::guarded(
			static function () use ( $title, $text, $context ): void {
				$targets = self::targets( (string) ( $context['channel'] ?? '' ) );
				if ( ! $targets || ! self::allowed( $title . '|' . $text ) ) {
					return;
				}
				self::custom( $targets, $title, $text, $context );
			}
		);
	}

	/**
	 * Send a test alert, limits and the on/off switch ignored.
	 *
	 * @return array<int,array{channel:string,recipient:string,result:SendResult}>
	 */
	public static function test(): array {
		return self::custom(
			self::targets( '' ),
			__( 'Test alert', 'forms-notifier-for-elementor' ),
			__( 'Alerts about failed deliveries will arrive here.', 'forms-notifier-for-elementor' ),
			[]
		);
	}

	/**
	 * Report a failed row.
	 *
	 * @param array      $row    Row.
	 * @param SendResult $result Result.
	 */
	private static function report( array $row, SendResult $result ): void {
		$channel = (string) $row['channel'];
		$targets = self::targets( $channel );
		$error   = (string) $row['error'] === '' ? $result->error : (string) $row['error'];
		// Numbers differ between otherwise equal errors (ids, seconds to wait).
		if ( ! $targets || ! self::allowed( $channel . '|' . preg_replace( '/\d+/', '#', $error ) ) ) {
			return;
		}
		$form       = (int) $row['form_id'] ? FormRepository::find( (int) $row['form_id'] ) : null;
		$suppressed = self::take_suppressed();
		$content    = static function ( string $lang, string $target ) use ( $row, $result, $error, $form, $suppressed ): array {
			unset( $target );
			$failed   = Registry::channel( (string) $row['channel'] );
			$label    = (string) $row['recipient_label'] === '' ? (string) $row['recipient'] : (string) $row['recipient_label'];
			$template = Settings::text( 'alerts.template', $lang );
			$template = trim( $template ) === '' ? DefaultTemplates::alert() : $template;
			$template = (string) apply_filters( 'fnfe_alert_message', $template, $row, $result ) . self::suppressed_note( $suppressed );
			$data     = [
				'title'         => __( 'Delivery failed', 'forms-notifier-for-elementor' ),
				'channel'       => $failed ? $failed->label() : (string) $row['channel'],
				'recipient'     => $label,
				'form'          => $form ? (string) $form['name'] : '',
				'submission_id' => (int) $row['submission_id'],
				'error'         => $error,
				'attempts'      => (int) $row['attempts'],
				'log_url'       => Urls::admin( 'log/' . (int) $row['id'] ),
			];
			return [
				'template' => $template,
				'subject'  => $data['title'] . ': ' . self::inert( $data['channel'] ),
				'extra'    => [
					'alert.title'     => $data['title'],
					'alert.channel'   => $data['channel'],
					'alert.recipient' => $data['recipient'],
					'alert.error'     => $data['error'],
					'alert.attempts'  => Text::local_digits( (string) $data['attempts'], determine_locale() ),
					'alert.log_url'   => $data['log_url'],
					'form_name'       => $data['form'],
					'form_id'         => (string) (int) $row['form_id'],
					'submission_id'   => $data['submission_id'] ? (string) $data['submission_id'] : '',
				],
				'data'     => $data,
			];
		};
		Broadcast::send(
			$targets,
			'alert',
			$content,
			[
				'parent_id' => (int) $row['id'],
				'form_id'   => (int) $row['form_id'],
			]
		);
	}

	/**
	 * Send a plain alert.
	 *
	 * @param array  $targets Targets.
	 * @param string $title   Title.
	 * @param string $text    Text.
	 * @param array  $context Extra data.
	 */
	private static function custom( array $targets, string $title, string $text, array $context ): array {
		$suppressed = self::take_suppressed();
		return Broadcast::send(
			$targets,
			'alert',
			static function () use ( $title, $text, $context, $suppressed ): array {
				$url = (string) ( $context['url'] ?? Urls::admin() );
				return [
					// Title and text are values: they may hold braces or markup of remote services.
					'template' => "<b>{alert.title}</b>\n\n{alert.text}\n\n{alert.log_url}" . self::suppressed_note( $suppressed ),
					'subject'  => '{alert.title}',
					'extra'    => [
						'alert.title'   => Logger::clean( $title ),
						'alert.text'    => Logger::clean( $text ),
						'alert.error'   => Logger::clean( $text ),
						'alert.log_url' => $url,
					],
					'data'     => [
						'title' => Logger::clean( $title ),
						'text'  => Logger::clean( $text ),
						'url'   => $url,
					],
				];
			}
		);
	}

	/**
	 * Alert receivers; the failed channel is left out when another one can deliver.
	 *
	 * @param string $failed Channel with the problem.
	 */
	private static function targets( string $failed ): array {
		$targets = Broadcast::targets( 'alerts.channels' );
		if ( $failed !== '' && count( $targets ) > 1 ) {
			unset( $targets[ $failed ] );
		}
		return $targets;
	}

	/**
	 * Coalescing and the hourly limit; suppressed alerts are counted.
	 *
	 * @param string $fingerprint What the alert is about.
	 */
	private static function allowed( string $fingerprint ): bool {
		$max = max( 1, Settings::int( 'alerts.max_per_hour', 10 ) );
		if ( RateLimit::hit( 'alert_' . md5( $fingerprint ), 1, self::COALESCE ) && RateLimit::hit( 'alerts', $max, HOUR_IN_SECONDS ) ) {
			return true;
		}
		State::update(
			'alerts_suppressed',
			static function ( $count ): int {
				return (int) $count + 1;
			},
			0
		);
		return false;
	}

	/** Number of suppressed alerts since the last one; resets the counter. */
	private static function take_suppressed(): int {
		$taken = 0;
		if ( State::get( 'alerts_suppressed', 0 ) ) {
			State::update(
				'alerts_suppressed',
				static function ( $count ) use ( &$taken ): int {
					$taken = (int) $count;
					return 0;
				},
				0
			);
		}
		return $taken;
	}

	/**
	 * Last line about suppressed alerts.
	 *
	 * @param int $count Number.
	 */
	private static function suppressed_note( int $count ): string {
		if ( $count <= 0 ) {
			return '';
		}
		$note = sprintf(
			/* translators: %1$d: number of failures */
			_n( 'And %1$d more failure was not reported individually.', 'And %1$d more failures were not reported individually.', $count, 'forms-notifier-for-elementor' ),
			$count
		);
		return "\n\n" . Text::local_digits( $note, determine_locale() );
	}

	/**
	 * Text without template braces.
	 *
	 * @param string $text Text.
	 */
	private static function inert( string $text ): string {
		return Text::truncate( str_replace( [ '{', '}' ], '', $text ), 100 );
	}

	/**
	 * Run once, without recursion and without breaking the caller.
	 *
	 * @param callable $callback Callback.
	 */
	private static function guarded( callable $callback ): void {
		self::$sending = true;
		try {
			$callback();
		} catch ( \Throwable $e ) {
			Logger::error( 'alerts', $e->getMessage() );
		} finally {
			self::$sending = false;
		}
	}
}
