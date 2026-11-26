<?php
/**
 * Template tags.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\I18n\Strings;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Built-in tag resolvers and the tag catalogue. */
final class Tags {

	/** Tags that stand for markup. */
	private const MARKUP = [ 'all_fields', 'fields_table', 'page_link', 'files', 'file_links' ];

	/** Tags with a plain value. */
	private const PLAIN = [
		'field',
		'field_raw',
		'label',
		'form_name',
		'form_id',
		'form_key',
		'form_url',
		'submission_id',
		'submission_uuid',
		'submission_url',
		'admin_submission_url',
		'miniapp_submission_url',
		'status',
		'lang',
		'files_count',
		'page_url',
		'page_title',
		'referrer',
		'utm',
		'user_id',
		'user_login',
		'user_email',
		'user_name',
		'user_ip',
		'user_agent',
		'browser',
		'device',
		'site_name',
		'site_url',
		'admin_url',
		'admin_email',
		'date',
		'time',
		'datetime',
		'jdate',
		'gdate',
		'timestamp',
		'name',
		'email',
		'phone',
	];

	/** Tags filled by the caller (Context::$extra). */
	private const SYSTEM = [
		'alert.channel',
		'alert.recipient',
		'alert.error',
		'alert.attempts',
		'alert.log_url',
		'digest.period',
		'digest.submissions',
		'digest.sent',
		'digest.failed',
		'digest.forms',
		'digest.health',
		'digest.queue',
	];

	/**
	 * Raw value of a tag, null when the tag is unknown.
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Argument.
	 * @param Context $context Context.
	 */
	public static function value( string $name, string $arg, Context $context ): ?string {
		if ( array_key_exists( $name, $context->extra ) && is_scalar( $context->extra[ $name ] ) ) {
			return Markup::clean_text( (string) $context->extra[ $name ] );
		}
		$custom = self::custom();
		if ( isset( $custom[ $name ] ) ) {
			try {
				$value = call_user_func( $custom[ $name ], $arg, $context );
				return is_scalar( $value ) ? Markup::clean_text( (string) $value ) : '';
			} catch ( \Throwable $e ) {
				Logger::error( 'template', sprintf( 'Tag "%s": %s', $name, $e->getMessage() ) );
				return '';
			}
		}
		if ( in_array( $name, self::SYSTEM, true ) ) {
			return '';
		}
		if ( ! in_array( $name, self::PLAIN, true ) ) {
			return null;
		}
		return Markup::clean_text( self::plain( $name, trim( $arg ), $context ) );
	}

	/**
	 * Pieces of a markup tag: [ 'c', canonical markup ], [ 'v', raw value ] or [ 't', rows of [ label, value ] ].
	 * null when the tag is not a markup tag.
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Argument.
	 * @param Context $context Context.
	 */
	public static function segments( string $name, string $arg, Context $context ): ?array {
		if ( ! in_array( $name, self::MARKUP, true ) || array_key_exists( $name, $context->extra ) || isset( self::custom()[ $name ] ) ) {
			return null;
		}
		switch ( $name ) {
			case 'all_fields':
				return self::all_fields( self::options( $arg ), $context );
			case 'fields_table':
				return self::all_fields( array_merge( self::options( $arg ), [ 'format' => 'table' ] ), $context );
			case 'page_link':
				$url   = self::meta( 'page_url', $context );
				$title = self::meta( 'page_title', $context );
				return self::link( $url, $title === '' ? $url : $title );
			case 'files':
				return self::join( self::file_items( $context->submission->files, $context, false ), ', ' );
		}
		return self::join( self::file_items( $context->submission->files, $context, true ), "\n" );
	}

	/**
	 * List form of a field value for filters like first and join.
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Argument.
	 * @param string  $value   Resolved value.
	 * @param Context $context Context.
	 * @return string[]
	 */
	public static function values( string $name, string $arg, string $value, Context $context ): array {
		if ( $name === 'field' || $name === 'field_raw' ) {
			$field = $context->submission->field( trim( $arg ) );
			if ( $field && empty( $field['redacted'] ) && ! empty( $field['values'] ) ) {
				return array_map( 'strval', array_values( array_filter( (array) $field['values'], 'is_scalar' ) ) );
			}
		}
		return $value === '' ? [] : [ $value ];
	}

	/**
	 * The tag exists.
	 *
	 * @param string $name Tag name.
	 */
	public static function known( string $name ): bool {
		return in_array( $name, self::PLAIN, true ) || in_array( $name, self::MARKUP, true ) || in_array( $name, self::SYSTEM, true ) || isset( self::custom()[ $name ] );
	}

	/**
	 * Value of the field that holds the name, email or phone of the person who sent the form.
	 *
	 * @param string          $kind name | email | phone.
	 * @param Submission      $s    Submission.
	 * @param FormConfig|null $form Form config.
	 */
	public static function submitter( string $kind, Submission $s, ?FormConfig $form ): string {
		$mapped = $form ? (string) $form->get( 'fields.' . $kind, '' ) : '';
		$field  = $mapped === '' ? null : $s->field( $mapped );
		if ( $mapped === '' && $kind === 'name' ) {
			foreach ( $s->fields as $id => $candidate ) {
				if ( preg_match( '/name/i', (string) $id ) && empty( $candidate['is_file'] ) && (string) ( $candidate['value'] ?? '' ) !== '' ) {
					$field = $candidate;
					break;
				}
			}
		} elseif ( $mapped === '' ) {
			$field = $s->first_of_type( $kind === 'phone' ? 'tel' : 'email' );
		}
		return $field && empty( $field['redacted'] ) ? trim( (string) ( $field['value'] ?? '' ) ) : '';
	}

	/**
	 * Country hint of a submission for phone numbers.
	 *
	 * @param Context $context Context.
	 */
	public static function country( Context $context ): string {
		$id = $context->form ? (string) $context->form->get( 'fields.country', '' ) : '';
		return $id === '' ? '' : trim( $context->submission->value( $id ) );
	}

	/**
	 * Format a moment; the calendar follows general.calendar and the render language.
	 *
	 * @param \DateTimeInterface $date     Moment.
	 * @param string             $format   PHP date format, '' = date format of the site.
	 * @param Context            $context  Context.
	 * @param string             $calendar '' = automatic, jalali, gregorian.
	 */
	public static function format_date( \DateTimeInterface $date, string $format, Context $context, string $calendar = '' ): string {
		$fa       = $context->is_persian();
		$own      = trim( $format ) !== '';
		$format   = $own ? $format : self::site_format( 'date', $fa );
		$calendar = $calendar === '' ? self::calendar( $context ) : $calendar;
		$local    = ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( wp_timezone() );
		$text     = $calendar === 'jalali'
			? Jalali::format( $format, $local, $fa )
			: (string) wp_date( $format, $local->getTimestamp(), wp_timezone() );
		// Own formats keep ASCII digits: they may end up in codes and URLs.
		return $fa && ! $own ? Text::fa_digits( $text ) : $text;
	}

	/**
	 * Calendar of a context: jalali | gregorian.
	 *
	 * @param Context $context Context.
	 */
	public static function calendar( Context $context ): string {
		$calendar = Settings::str( 'general.calendar', 'auto' );
		if ( $calendar === 'jalali' || $calendar === 'gregorian' ) {
			return $calendar;
		}
		return $context->is_persian() ? 'jalali' : 'gregorian';
	}

	/**
	 * Label of a field with the override of the form.
	 *
	 * @param string  $id      Field id.
	 * @param Context $context Context.
	 */
	public static function label( string $id, Context $context ): string {
		if ( $context->form && ! empty( $context->form->data['fields']['labels'][ $id ] ) ) {
			$label = Settings::localize( $context->form->data['fields']['labels'][ $id ], $context->lang, Strings::name_for( 'fields.labels.' . $id, $context->form->form_key ) );
			if ( $label !== '' ) {
				return $label;
			}
		}
		$field = $context->submission->field( $id );
		return $field ? (string) ( $field['label'] ?? $id ) : '';
	}

	/**
	 * Catalogue for the UI: [ tag, label, group, desc, purposes ]. Filter: fnfe_template_tags_catalog.
	 *
	 * @param int    $form_id Adds one entry per known field of this form.
	 * @param string $purpose Only tags that make sense for this purpose, '' = all.
	 */
	public static function catalog( int $form_id = 0, string $purpose = 'admin' ): array {
		$form    = [ 'admin', 'submitter' ];
		$all     = [ 'admin', 'submitter', 'alert', 'digest', 'test' ];
		$catalog = [];
		$row     = $form_id > 0 ? FormRepository::find( $form_id ) : null;
		foreach ( $row && is_array( $row['fields'] ) ? $row['fields'] : [] as $field ) {
			$id = is_array( $field ) ? (string) ( $field['id'] ?? '' ) : '';
			if ( $id !== '' ) {
				$label     = (string) ( $field['label'] ?? '' );
				$catalog[] = self::entry( '{field:' . $id . '}', $label === '' ? $id : $label, 'fields', (string) ( $field['type'] ?? '' ), $form );
			}
		}

		$fields = [
			'{all_fields}'   => [ __( 'All fields', 'forms-notifier-for-elementor' ), __( 'Every field with a value, one per line. Options: empty=1, exclude=id+id, only=id+id, max=3, format=lines|inline|table, labels=0, sep=": "', 'forms-notifier-for-elementor' ) ],
			'{fields_table}' => [ __( 'Table of all fields', 'forms-notifier-for-elementor' ), __( 'A table in email, lines elsewhere', 'forms-notifier-for-elementor' ) ],
			'{field:ID}'     => [ __( 'Value of one field', 'forms-notifier-for-elementor' ), __( 'ID is the field id from the Elementor form', 'forms-notifier-for-elementor' ) ],
			'{label:ID}'     => [ __( 'Label of one field', 'forms-notifier-for-elementor' ), '' ],
			'{field_raw:ID}' => [ __( 'Value as the form reported it', 'forms-notifier-for-elementor' ), '' ],
		];
		$groups = [
			'fields'     => [ $form, $fields ],
			'submission' => [
				$form,
				[
					'{name}'            => [ __( 'Name of the sender', 'forms-notifier-for-elementor' ), '' ],
					'{email}'           => [ __( 'Email of the sender', 'forms-notifier-for-elementor' ), '' ],
					'{phone}'           => [ __( 'Phone of the sender', 'forms-notifier-for-elementor' ), '' ],
					'{submission_id}'   => [ __( 'Submission number', 'forms-notifier-for-elementor' ), '' ],
					'{submission_uuid}' => [ __( 'Unique code of the submission', 'forms-notifier-for-elementor' ), '' ],
					'{status}'          => [ __( 'Submission status', 'forms-notifier-for-elementor' ), '' ],
					'{lang}'            => [ __( 'Language of the submission', 'forms-notifier-for-elementor' ), '' ],
					'{files}'           => [ __( 'Uploaded files', 'forms-notifier-for-elementor' ), __( 'Names, or links when the attachment settings allow it', 'forms-notifier-for-elementor' ) ],
					'{files_count}'     => [ __( 'Number of uploaded files', 'forms-notifier-for-elementor' ), '' ],
					'{file_links}'      => [ __( 'Links to the uploaded files', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'form'       => [
				[ 'admin', 'submitter', 'alert' ],
				[
					'{form_name}' => [ __( 'Form name', 'forms-notifier-for-elementor' ), '' ],
					'{form_id}'   => [ __( 'Form number', 'forms-notifier-for-elementor' ), '' ],
					'{form_key}'  => [ __( 'Form key', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'links'      => [
				[ 'admin', 'alert' ],
				[
					'{admin_submission_url}'   => [ __( 'Submission in the admin panel', 'forms-notifier-for-elementor' ), '' ],
					'{miniapp_submission_url}' => [ __( 'Submission in the mini app', 'forms-notifier-for-elementor' ), '' ],
					'{form_url}'               => [ __( 'Form settings in the admin panel', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'page'       => [
				$form,
				[
					'{page_link}'  => [ __( 'Page title linked to the page', 'forms-notifier-for-elementor' ), '' ],
					'{page_title}' => [ __( 'Page title', 'forms-notifier-for-elementor' ), '' ],
					'{page_url}'   => [ __( 'Page address', 'forms-notifier-for-elementor' ), '' ],
					'{referrer}'   => [ __( 'Previous page', 'forms-notifier-for-elementor' ), '' ],
					'{utm:source}' => [ __( 'Campaign value', 'forms-notifier-for-elementor' ), __( 'source, medium, campaign, term or content', 'forms-notifier-for-elementor' ) ],
				],
			],
			'user'       => [
				[ 'admin' ],
				[
					'{user_name}'  => [ __( 'Name of the logged-in user', 'forms-notifier-for-elementor' ), '' ],
					'{user_login}' => [ __( 'User name of the logged-in user', 'forms-notifier-for-elementor' ), '' ],
					'{user_email}' => [ __( 'Email of the logged-in user', 'forms-notifier-for-elementor' ), '' ],
					'{user_id}'    => [ __( 'Id of the logged-in user', 'forms-notifier-for-elementor' ), '' ],
					'{user_ip}'    => [ __( 'IP address', 'forms-notifier-for-elementor' ), '' ],
					'{browser}'    => [ __( 'Browser', 'forms-notifier-for-elementor' ), '' ],
					'{device}'     => [ __( 'Device', 'forms-notifier-for-elementor' ), '' ],
					'{user_agent}' => [ __( 'Full browser name', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'site'       => [
				$all,
				[
					'{site_name}'   => [ __( 'Site name', 'forms-notifier-for-elementor' ), '' ],
					'{site_url}'    => [ __( 'Site address', 'forms-notifier-for-elementor' ), '' ],
					'{admin_url}'   => [ __( 'Admin address', 'forms-notifier-for-elementor' ), '' ],
					'{admin_email}' => [ __( 'Email of the site administrator', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'date'       => [
				$all,
				[
					'{datetime}'    => [ __( 'Date and time', 'forms-notifier-for-elementor' ), '' ],
					'{date}'        => [ __( 'Date', 'forms-notifier-for-elementor' ), '' ],
					'{time}'        => [ __( 'Time', 'forms-notifier-for-elementor' ), '' ],
					'{date:Y-m-d}'  => [ __( 'Date in your own format', 'forms-notifier-for-elementor' ), __( 'PHP date format', 'forms-notifier-for-elementor' ) ],
					'{jdate:Y/m/d}' => [ __( 'Jalali date', 'forms-notifier-for-elementor' ), '' ],
					'{gdate:Y-m-d}' => [ __( 'Gregorian date', 'forms-notifier-for-elementor' ), '' ],
					'{timestamp}'   => [ __( 'Unix time', 'forms-notifier-for-elementor' ), '' ],
				],
			],
			'system'     => [
				[ 'alert' ],
				[
					'{alert.channel}'   => [ __( 'Channel that failed', 'forms-notifier-for-elementor' ), '' ],
					'{alert.recipient}' => [ __( 'Receiver that was not reached', 'forms-notifier-for-elementor' ), '' ],
					'{alert.error}'     => [ __( 'Error message', 'forms-notifier-for-elementor' ), '' ],
					'{alert.attempts}'  => [ __( 'Number of attempts', 'forms-notifier-for-elementor' ), '' ],
					'{alert.log_url}'   => [ __( 'Link to the log entry', 'forms-notifier-for-elementor' ), '' ],
				],
			],
		];

		foreach ( $groups as $group => $set ) {
			foreach ( $set[1] as $tag => $text ) {
				$catalog[] = self::entry( $tag, $text[0], $group, $text[1], $set[0] );
			}
		}
		$digest = [
			'{digest.period}'      => __( 'Period of the digest', 'forms-notifier-for-elementor' ),
			'{digest.submissions}' => __( 'Number of submissions', 'forms-notifier-for-elementor' ),
			'{digest.sent}'        => __( 'Messages sent', 'forms-notifier-for-elementor' ),
			'{digest.failed}'      => __( 'Messages that failed', 'forms-notifier-for-elementor' ),
			'{digest.forms}'       => __( 'Submissions by form', 'forms-notifier-for-elementor' ),
			'{digest.health}'      => __( 'Health of the site', 'forms-notifier-for-elementor' ),
			'{digest.queue}'       => __( 'Messages waiting', 'forms-notifier-for-elementor' ),
		];
		foreach ( $digest as $tag => $label ) {
			$catalog[] = self::entry( $tag, $label, 'system', '', [ 'digest' ] );
		}

		if ( $purpose !== '' ) {
			$catalog = array_values(
				array_filter(
					$catalog,
					static function ( array $entry ) use ( $purpose ): bool {
						return in_array( $purpose, $entry['purposes'], true );
					}
				)
			);
		}
		return array_values( (array) apply_filters( 'fnfe_template_tags_catalog', $catalog, $form_id, $purpose ) );
	}

	/**
	 * Catalogue entry.
	 *
	 * @param string   $tag      Tag.
	 * @param string   $label    Label.
	 * @param string   $group    Group.
	 * @param string   $desc     Description.
	 * @param string[] $purposes Purposes.
	 */
	private static function entry( string $tag, string $label, string $group, string $desc, array $purposes ): array {
		return [
			'tag'      => $tag,
			'label'    => $label,
			'group'    => $group,
			'desc'     => $desc,
			'purposes' => $purposes,
		];
	}

	/** Custom resolvers. Filter: fnfe_template_tags. */
	private static function custom(): array {
		$custom = apply_filters( 'fnfe_template_tags', [] );
		return is_array( $custom ) ? array_filter( $custom, 'is_callable' ) : [];
	}

	/**
	 * Value of a built-in plain tag.
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Argument.
	 * @param Context $context Context.
	 */
	private static function plain( string $name, string $arg, Context $context ): string {
		$s = $context->submission;
		switch ( $name ) {
			case 'field':
				return $s->value( $arg );
			case 'field_raw':
				return self::raw( $arg, $s );
			case 'label':
				return self::label( $arg, $context );

			case 'form_name':
				return $s->form_name;
			case 'form_id':
				return $s->form_id ? (string) $s->form_id : '';
			case 'form_key':
				return $s->form_key;
			case 'form_url':
				return $s->form_id ? Urls::form_admin( $s->form_id ) : '';

			case 'submission_id':
				return $s->id ? (string) $s->id : '';
			case 'submission_uuid':
				return $s->uuid;
			case 'submission_url':
			case 'admin_submission_url':
				return $s->id ? Urls::submission_admin( $s->id ) : '';
			case 'miniapp_submission_url':
				return self::miniapp_url( $context );
			case 'status':
				return self::status( $s->status );
			case 'lang':
				return $s->lang === '' ? $context->lang : $s->lang;
			case 'files_count':
				return (string) count( $s->files );

			case 'utm':
				$utm = $s->meta['utm'] ?? [];
				$key = (string) preg_replace( '/^utm_/', '', $arg );
				return is_array( $utm ) && is_scalar( $utm[ $key ] ?? $utm[ 'utm_' . $key ] ?? null ) ? (string) ( $utm[ $key ] ?? $utm[ 'utm_' . $key ] ) : '';
			case 'user_id':
				return (int) self::meta( 'user_id', $context ) > 0 ? self::meta( 'user_id', $context ) : '';
			case 'user_name':
				$user = function_exists( 'get_userdata' ) ? get_userdata( (int) self::meta( 'user_id', $context ) ) : false;
				return $user ? (string) $user->display_name : self::meta( 'user_login', $context );
			case 'page_url':
			case 'page_title':
			case 'referrer':
			case 'user_login':
			case 'user_email':
			case 'user_ip':
			case 'user_agent':
				return self::meta( $name, $context );
			case 'browser':
				return self::browser( self::meta( 'user_agent', $context ) );
			case 'device':
				return self::device( self::meta( 'user_agent', $context ) );

			case 'site_name':
				return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			case 'site_url':
				return home_url( '/' );
			case 'admin_url':
				return admin_url();
			case 'admin_email':
				return (string) get_option( 'admin_email', '' );

			case 'name':
			case 'email':
			case 'phone':
				return self::submitter( $name, $s, $context->form );
		}
		return self::moment( $name, $arg, $context );
	}

	/**
	 * Date tags.
	 *
	 * @param string  $name    Tag name.
	 * @param string  $arg     Format.
	 * @param Context $context Context.
	 */
	private static function moment( string $name, string $arg, Context $context ): string {
		$utc  = new \DateTimeZone( 'UTC' );
		$date = $context->submission->created_gmt === '' ? false : \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $context->submission->created_gmt, $utc );
		if ( ! $date ) {
			$date = new \DateTimeImmutable( '@' . (int) apply_filters( 'fnfe_now', time() ) );
		}
		$fa = $context->is_persian();
		switch ( $name ) {
			case 'timestamp':
				return (string) $date->getTimestamp();
			case 'jdate':
				return self::format_date( $date, $arg, $context, 'jalali' );
			case 'gdate':
				return self::format_date( $date, $arg, $context, 'gregorian' );
			case 'date':
				return self::format_date( $date, $arg, $context );
		}
		$format = self::site_format( 'time', $fa );
		$text   = self::format_date( $date, $arg === '' ? $format : $arg, $context );
		if ( $arg === '' && $fa ) {
			$text = Text::fa_digits( $text );
		}
		return $name === 'datetime' && $arg === '' ? self::format_date( $date, '', $context ) . ' ' . $text : $text;
	}

	/**
	 * Date or time format of the site.
	 *
	 * @param string $what date | time.
	 * @param bool   $fa   Persian order of the parts.
	 */
	private static function site_format( string $what, bool $fa ): string {
		$format = (string) get_option( $what . '_format', '' );
		if ( $format === '' ) {
			$format = $what === 'date' ? 'F j, Y' : 'H:i';
		}
		return $fa && $format === 'F j, Y' ? 'j F Y' : $format;
	}

	/**
	 * Submission meta as text.
	 *
	 * @param string  $key     Key.
	 * @param Context $context Context.
	 */
	private static function meta( string $key, Context $context ): string {
		$value = $context->submission->meta[ $key ] ?? '';
		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : '';
	}

	/**
	 * Reported value of a field. Never a file path.
	 *
	 * @param string     $id Field id.
	 * @param Submission $s  Submission.
	 */
	private static function raw( string $id, Submission $s ): string {
		$field = $s->field( $id );
		if ( ! $field || ! empty( $field['redacted'] ) ) {
			return '';
		}
		$raw = $field['raw'] ?? '';
		if ( ! empty( $field['is_file'] ) || ( is_array( $raw ) && ! $raw ) ) {
			return (string) ( $field['value'] ?? '' );
		}
		if ( is_array( $raw ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Translated status.
	 *
	 * @param string $status Status.
	 */
	private static function status( string $status ): string {
		$names = [
			'new'   => _x( 'New', 'submission status', 'forms-notifier-for-elementor' ),
			'read'  => _x( 'Read', 'submission status', 'forms-notifier-for-elementor' ),
			'done'  => _x( 'Done', 'submission status', 'forms-notifier-for-elementor' ),
			'spam'  => _x( 'Spam', 'submission status', 'forms-notifier-for-elementor' ),
			'trash' => _x( 'Trash', 'submission status', 'forms-notifier-for-elementor' ),
		];
		return $names[ $status ] ?? $status;
	}

	/**
	 * Deep link that opens the submission in the mini app, '' when it is not available.
	 *
	 * @param Context $context Context.
	 */
	private static function miniapp_url( Context $context ): string {
		if ( ! $context->submission->id || ! Settings::bool( 'miniapp.enabled' ) ) {
			return '';
		}
		$platforms = in_array( $context->channel, [ 'telegram', 'bale' ], true ) ? [ $context->channel ] : [ 'telegram', 'bale' ];
		foreach ( $platforms as $platform ) {
			$username = State::get( "bot.$platform.me.username", '' );
			if ( ! is_string( $username ) || ! preg_match( '/^[A-Za-z0-9_]{3,64}$/', $username ) ) {
				continue;
			}
			$short = $platform === 'telegram' ? Settings::str( 'channels.telegram.miniapp_short_name' ) : '';
			$host  = $platform === 'telegram' ? 'https://t.me/' : 'https://ble.ir/';
			return $host . $username . ( preg_match( '/^[A-Za-z0-9_]+$/', $short ) ? '/' . $short : '' ) . '?startapp=sub_' . $context->submission->id;
		}
		return '';
	}

	/**
	 * Options "key=value,key=value" of {all_fields}.
	 *
	 * @param string $arg Argument.
	 */
	private static function options( string $arg ): array {
		$out = [];
		if ( preg_match_all( '~([a-z]+)\s*=\s*("[^"]*"|[^,]*)~', $arg, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$quoted          = strncmp( $pair[2], '"', 1 ) === 0;
				$out[ $pair[1] ] = $quoted ? trim( $pair[2], '"' ) : trim( $pair[2] );
			}
		}
		return $out;
	}

	/**
	 * Pieces of {all_fields}.
	 *
	 * @param array   $options Options.
	 * @param Context $context Context.
	 */
	private static function all_fields( array $options, Context $context ): array {
		$exclude = self::ids( $options['exclude'] ?? '' );
		$only    = self::ids( $options['only'] ?? '' );
		$empty   = ! empty( $options['empty'] );
		$max     = (int) ( $options['max'] ?? 0 );
		$labels  = ( $options['labels'] ?? '1' ) !== '0';
		$sep     = (string) ( $options['sep'] ?? ': ' );
		$format  = (string) ( $options['format'] ?? 'lines' );
		if ( $context->form ) {
			$exclude = array_merge( $exclude, array_map( 'strval', (array) $context->form->get( 'fields.exclude', [] ) ) );
		}

		$rows = [];
		foreach ( $context->submission->fields as $id => $field ) {
			$id    = (string) ( $field['id'] ?? $id );
			$value = (string) ( $field['value'] ?? '' );
			if ( in_array( $id, $exclude, true ) || ( $only && ! in_array( $id, $only, true ) ) || ( ! $only && ! empty( $field['hidden'] ) ) || ( $value === '' && ! $empty ) ) {
				continue;
			}
			$pieces = [ [ 'v', Markup::clean_text( $value ) ] ];
			$files  = empty( $field['is_file'] ) ? [] : $context->submission->files_for( $id );
			if ( $files ) {
				// Names only: the links belong to {files} and {file_links}.
				$pieces = [ [ 'v', Markup::clean_text( implode( ', ', self::names( $files ) ) ) ] ];
			}
			$rows[] = [ Markup::clean_text( self::label( $id, $context ) ), $pieces, $value ];
			if ( $max > 0 && count( $rows ) >= $max ) {
				break;
			}
		}

		if ( $format === 'table' ) {
			return $rows ? [ [ 't', $rows ] ] : [];
		}
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = self::line( $labels ? $row[0] : '', $row[1], $row[2], $format === 'inline' ? ': ' : $sep, $format === 'inline' );
		}
		return self::join( $items, $format === 'inline' ? '; ' : "\n" );
	}

	/**
	 * One labelled value.
	 *
	 * @param string $label  Label, '' = none.
	 * @param array  $pieces Pieces of the value.
	 * @param string $value  Plain value.
	 * @param string $sep    Separator.
	 * @param bool   $inline One line only.
	 */
	public static function line( string $label, array $pieces, string $value, string $sep = ': ', bool $inline = false ): array {
		if ( $inline ) {
			foreach ( $pieces as $i => $piece ) {
				if ( $piece[0] === 'v' ) {
					$pieces[ $i ][1] = trim( (string) preg_replace( '/\s+/u', ' ', $piece[1] ) );
				}
			}
		}
		if ( $label === '' ) {
			return $pieces;
		}
		if ( ! $inline && strpos( $value, "\n" ) !== false ) {
			$sep = rtrim( $sep ) . "\n";
		}
		return array_merge( [ [ 'c', '<b>' ], [ 'v', $label ], [ 'c', '</b>' . Markup::escape( $sep ) ] ], $pieces );
	}

	/**
	 * Ids joined with +.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function ids( string $text ): array {
		return array_values( array_filter( array_map( 'trim', explode( '+', $text ) ), 'strlen' ) );
	}

	/**
	 * Join lists of pieces.
	 *
	 * @param array[] $items Lists of pieces.
	 * @param string  $glue  Plain glue.
	 */
	private static function join( array $items, string $glue ): array {
		$out = [];
		foreach ( $items as $i => $pieces ) {
			if ( $i > 0 ) {
				$out[] = [ 'c', $glue ];
			}
			$out = array_merge( $out, $pieces );
		}
		return $out;
	}

	/**
	 * Pieces of a link; plain text without a valid address.
	 *
	 * @param string $url  Address.
	 * @param string $text Text.
	 */
	private static function link( string $url, string $text ): array {
		if ( $text === '' ) {
			return [];
		}
		return $url === ''
			? [ [ 'v', Markup::clean_text( $text ) ] ]
			: [ [ 'c', '<a href="' ], [ 'v', Markup::clean_text( $url ) ], [ 'c', '">' ], [ 'v', Markup::clean_text( $text ) ], [ 'c', '</a>' ] ];
	}

	/**
	 * Names of files.
	 *
	 * @param \AmirhpCom\FormsNotifier\Model\Attachment[] $files Files.
	 * @return string[]
	 */
	private static function names( array $files ): array {
		$names = [];
		foreach ( $files as $file ) {
			$names[] = $file->name;
		}
		return $names;
	}

	/**
	 * Pieces per file: a link when the policy links files, the name otherwise.
	 *
	 * @param \AmirhpCom\FormsNotifier\Model\Attachment[] $files   Files.
	 * @param Context                                     $context Context.
	 * @param bool                                        $force   Links even when the policy only uploads.
	 */
	private static function file_items( array $files, Context $context, bool $force ): array {
		$links = $files ? self::file_urls( $context, $force ) : [];
		$out   = [];
		foreach ( $files as $file ) {
			$url = '';
			foreach ( $links as $i => $link ) {
				if ( ( $link['name'] ?? '' ) === $file->name ) {
					$url = (string) ( $link['url'] ?? '' );
					unset( $links[ $i ] );
					break;
				}
			}
			$out[] = self::link( $url, $file->name );
		}
		return $out;
	}

	/**
	 * Links the attachment policy allows.
	 *
	 * @param Context $context Context.
	 * @param bool    $force   Links even when the policy only uploads.
	 */
	private static function file_urls( Context $context, bool $force ): array {
		if ( $context->purpose === 'submitter' ) {
			return [];
		}
		$channel = Registry::channel( $context->channel );
		if ( ! $channel ) {
			return [];
		}
		$policy = $context->policy;
		if ( ! $policy ) {
			$policy = Policy::resolve( $context->channel, $context->form );
			$editor = $context->submission->meta['editor']['fnfe_attach'] ?? '';
			if ( in_array( $editor, [ 'off', 'link', 'send', 'both' ], true ) ) {
				$policy['mode'] = $editor;
			}
		}
		$mode = $policy['mode'] ?? 'off';
		if ( $mode === 'send' && ! $force ) {
			// Only files that could not be uploaded have a link.
			return (array) $context->links;
		}
		if ( $mode === 'off' ) {
			return [];
		}
		// The links of the message itself, so a file has one address everywhere.
		if ( $context->links !== null && $mode !== 'send' ) {
			return $context->links;
		}
		$policy['mode'] = 'link';
		return (array) ( Policy::apply( $policy, $context->submission, $channel )['links'] ?? [] );
	}

	/**
	 * Browser of a user agent.
	 *
	 * @param string $agent User agent.
	 */
	private static function browser( string $agent ): string {
		$known = [
			'~Edg(?:e|A|iOS)?/(\d+)~'                      => 'Edge',
			'~(?:OPR|Opera)/(\d+)~'                        => 'Opera',
			'~SamsungBrowser/(\d+)~'                       => 'Samsung Internet',
			'~(?:Firefox|FxiOS)/(\d+)~'                    => 'Firefox',
			'~(?:Chrome|CriOS)/(\d+)~'                     => 'Chrome',
			'~Version/(\d+)[\d.]*(?: Mobile/\S+)? Safari~' => 'Safari',
		];
		foreach ( $known as $pattern => $name ) {
			if ( preg_match( $pattern, $agent, $m ) ) {
				return $name . ' ' . $m[1];
			}
		}
		return '';
	}

	/**
	 * Device of a user agent.
	 *
	 * @param string $agent User agent.
	 */
	private static function device( string $agent ): string {
		$known = [
			'~iPhone~'             => 'iPhone',
			'~iPad~'               => 'iPad',
			'~Android~'            => 'Android',
			'~Windows~'            => 'Windows',
			'~CrOS~'               => 'ChromeOS',
			'~Macintosh|Mac OS X~' => 'Mac',
			'~Linux~'              => 'Linux',
		];
		foreach ( $known as $pattern => $name ) {
			if ( preg_match( $pattern, $agent ) ) {
				return $name;
			}
		}
		return '';
	}
}
