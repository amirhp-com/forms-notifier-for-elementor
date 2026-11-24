<?php
/**
 * Privacy tools.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Privacy;

use AmirhpCom\FormsNotifier\Capture\FileCleanup;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Phone\Phone;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** Personal data exporter and eraser, privacy policy text. */
final class Privacy implements ModuleInterface {

	private const ID = 'forms-notifier-for-elementor';

	/** Submissions per step. */
	private const PER_PAGE = 100;

	/** Messages without a submission per step. */
	private const MAX_MESSAGES = 1000;

	/** Attach hooks. */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
		add_action( 'admin_init', [ self::class, 'policy_content' ] );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Exporters.
	 */
	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : [];

		$exporters[ self::ID ] = [
			'exporter_friendly_name' => __( 'Forms Notifier submissions', 'forms-notifier-for-elementor' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Erasers.
	 */
	public static function erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : [];

		$erasers[ self::ID ] = [
			'eraser_friendly_name' => __( 'Forms Notifier submissions', 'forms-notifier-for-elementor' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * One page of personal data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data:array[],done:bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$email = is_string( $email ) ? trim( $email ) : '';
		$page  = max( 1, (int) $page );
		$ids   = self::ids( $email );
		$data  = [];

		foreach ( array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) {
			$s = SubmissionRepository::find( $id );
			if ( ! $s ) {
				continue;
			}

			$data[] = [
				'group_id'          => 'fnfe-submissions',
				'group_label'       => __( 'Form submissions', 'forms-notifier-for-elementor' ),
				'group_description' => __( 'Forms filled in on this site.', 'forms-notifier-for-elementor' ),
				'item_id'           => 'fnfe-submission-' . $s->id,
				'data'              => self::submission_data( $s ),
			];

			$addresses = self::addresses( $s, $email );
			foreach ( MessageRepository::for_submission( $s->id ) as $row ) {
				if ( in_array( (string) $row['recipient'], $addresses, true ) ) {
					$data[] = self::message_item( $row );
				}
			}
		}
		if ( $page === 1 ) {
			foreach ( self::loose_messages( $email ) as $row ) {
				$data[] = self::message_item( $row );
			}
		}
		return [
			'data' => $data,
			'done' => count( $ids ) <= $page * self::PER_PAGE,
		];
	}

	/**
	 * Erase one page. Erased rows no longer match, so every page starts at the beginning.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed:int,items_retained:int,messages:string[],done:bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		unset( $page );
		$email    = is_string( $email ) ? trim( $email ) : '';
		$ids      = self::ids( $email );
		$removed  = 0;
		$retained = 0;

		foreach ( array_slice( $ids, 0, self::PER_PAGE ) as $id ) {
			if ( FileCleanup::delete_submission( $id ) ) {
				++$removed;
			} else {
				++$retained;
			}
		}
		$loose = self::loose_messages( $email );
		foreach ( $loose as $row ) {
			$removed += MessageRepository::delete( (int) $row['id'] ) ? 1 : 0;
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained
				? [
					sprintf(
						/* translators: %1$d: number of submissions */
						_n( '%1$d form submission could not be deleted.', '%1$d form submissions could not be deleted.', $retained, 'forms-notifier-for-elementor' ),
						$retained
					),
				]
				: [],
			// Rows that cannot be deleted would keep the tool running for ever.
			'done'           => ( count( $ids ) <= self::PER_PAGE && count( $loose ) < self::MAX_MESSAGES ) || $retained > 0,
		];
	}

	/** Suggested text for the privacy policy. */
	public static function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$paragraphs = [];
		if ( Settings::bool( 'general.store_submissions', true ) ) {
			$stored = [
				__( 'the values you enter into a form and the files you upload', 'forms-notifier-for-elementor' ),
				__( 'the page you were on and the date', 'forms-notifier-for-elementor' ),
			];
			if ( Settings::str( 'general.store_ip', 'full' ) === 'full' ) {
				$stored[] = __( 'your IP address', 'forms-notifier-for-elementor' );
			} elseif ( Settings::str( 'general.store_ip', 'full' ) === 'anonymize' ) {
				$stored[] = __( 'a shortened form of your IP address', 'forms-notifier-for-elementor' );
			}
			if ( Settings::bool( 'general.store_user_agent', true ) ) {
				$stored[] = __( 'the name and version of your browser', 'forms-notifier-for-elementor' );
			}
			$paragraphs[] = sprintf(
				/* translators: %1$s: list of stored data */
				__( 'When you send a form we store: %1$s. If you are logged in we also store your user account.', 'forms-notifier-for-elementor' ),
				implode( '; ', $stored )
			);
			$days         = Settings::int( 'privacy.retention_submissions_days', 0 );
			$paragraphs[] = $days > 0
				? sprintf(
					/* translators: %1$d: number of days */
					_n( 'Submissions are deleted after %1$d day.', 'Submissions are deleted after %1$d days.', $days, 'forms-notifier-for-elementor' ),
					$days
				)
				: __( 'Submissions are kept until they are deleted by us or until you ask us to delete them.', 'forms-notifier-for-elementor' );
		} else {
			$paragraphs[] = __( 'Forms you send are forwarded to us and not stored on this site.', 'forms-notifier-for-elementor' );
		}

		$services = [];
		foreach ( Registry::channels() as $channel ) {
			if ( $channel->is_enabled() ) {
				$services[] = $channel->label();
			}
		}
		if ( $services ) {
			$paragraphs[] = sprintf(
				/* translators: %1$s: list of services */
				__( 'The content of a form may be sent to our team, and a confirmation to you, through these services: %1$s.', 'forms-notifier-for-elementor' ),
				implode( ', ', $services )
			);
		}
		$days = Settings::int( 'privacy.retention_messages_days', 90 );
		if ( $days > 0 ) {
			$paragraphs[] = sprintf(
				/* translators: %1$d: number of days */
				_n( 'A log of sent messages with their recipients is kept for %1$d day.', 'A log of sent messages with their recipients is kept for %1$d days.', $days, 'forms-notifier-for-elementor' ),
				$days
			);
		}

		wp_add_privacy_policy_content(
			'Forms Notifier for Elementor',
			'<p class="privacy-policy-tutorial">' . esc_html__( 'The text below follows the current settings of the plugin. Add the services you use and the reason you need the data.', 'forms-notifier-for-elementor' ) . '</p>'
				. '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>'
		);
	}

	/**
	 * Submissions of a person: the address is in a field, or the account with that address sent them.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	private static function ids( string $email ): array {
		if ( ! is_email( $email ) ) {
			return [];
		}
		$ids  = SubmissionRepository::ids_by_email( $email, 1, PHP_INT_MAX );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			global $wpdb;
			$own = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d', SubmissionRepository::table(), (int) $user->ID ) );
			$ids = array_merge( $ids, array_map( 'intval', $own ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Messages to the address that belong to no submission.
	 *
	 * @param string $email Email address.
	 * @return array[]
	 */
	private static function loose_messages( string $email ): array {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE submission_id = 0 AND recipient = %s AND purpose = 'submitter' ORDER BY id ASC LIMIT %d", MessageRepository::table(), $email, self::MAX_MESSAGES ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Addresses under which the person of a submission is reached.
	 *
	 * @param Submission $s     Submission.
	 * @param string     $email Email address.
	 * @return string[]
	 */
	private static function addresses( Submission $s, string $email ): array {
		$form  = FormRepository::config( $s->form_id );
		$field = (string) $form->get( 'fields.phone', '' );
		$tel   = $s->first_of_type( 'tel' );
		$phone = $field !== '' ? $s->value( $field ) : (string) ( $tel['value'] ?? '' );
		$out   = [ $email ];
		if ( $phone !== '' ) {
			$out[] = $phone;
			$e164  = Phone::normalize( Text::en_digits( $phone ), $s->value( (string) $form->get( 'fields.country', '' ) ) );
			if ( $e164 !== null && $e164 !== '' ) {
				$out[] = $e164;
			}
		}
		return $out;
	}

	/**
	 * Name and value pairs of a submission.
	 *
	 * @param Submission $s Submission.
	 */
	private static function submission_data( Submission $s ): array {
		$out = [
			[
				'name'  => __( 'Form', 'forms-notifier-for-elementor' ),
				'value' => esc_html( $s->form_name ),
			],
			[
				'name'  => __( 'Date', 'forms-notifier-for-elementor' ),
				'value' => esc_html( get_date_from_gmt( $s->created_gmt, 'Y-m-d H:i:s' ) ),
			],
		];
		foreach ( $s->fields as $field ) {
			if ( (string) $field['value'] !== '' ) {
				$out[] = [
					'name'  => esc_html( (string) $field['label'] ),
					'value' => nl2br( esc_html( (string) $field['value'] ) ),
				];
			}
		}
		$meta = [
			'page_url'   => __( 'Page', 'forms-notifier-for-elementor' ),
			'user_login' => __( 'User name', 'forms-notifier-for-elementor' ),
			'user_ip'    => __( 'IP address', 'forms-notifier-for-elementor' ),
			'user_agent' => __( 'Browser', 'forms-notifier-for-elementor' ),
		];
		foreach ( $meta as $key => $label ) {
			$value = $s->meta[ $key ] ?? '';
			if ( is_string( $value ) && $value !== '' ) {
				$out[] = [
					'name'  => $label,
					'value' => esc_html( $value ),
				];
			}
		}
		return $out;
	}

	/**
	 * Export item of a message.
	 *
	 * @param array $row Message row.
	 */
	private static function message_item( array $row ): array {
		$channel = Registry::channel( (string) $row['channel'] );
		return [
			'group_id'    => 'fnfe-messages',
			'group_label' => __( 'Notification messages', 'forms-notifier-for-elementor' ),
			'item_id'     => 'fnfe-message-' . (int) $row['id'],
			'data'        => [
				[
					'name'  => __( 'Channel', 'forms-notifier-for-elementor' ),
					'value' => esc_html( $channel ? $channel->label() : (string) $row['channel'] ),
				],
				[
					'name'  => __( 'Recipient', 'forms-notifier-for-elementor' ),
					'value' => esc_html( (string) $row['recipient'] ),
				],
				[
					'name'  => __( 'Date', 'forms-notifier-for-elementor' ),
					'value' => esc_html( get_date_from_gmt( (string) $row['created_gmt'], 'Y-m-d H:i:s' ) ),
				],
			],
		];
	}
}
