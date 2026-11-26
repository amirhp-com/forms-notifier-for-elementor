<?php
/**
 * Routing.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\Context;

defined( 'ABSPATH' ) || exit;

/** Inheritance: global settings <- form config <- editor controls <- routing rules <- language. */
final class Resolver {

	/** Address of channels without a receiver list. */
	public const IMPLICIT = 'default';

	/**
	 * Decide who gets what for a submission. Pure: no sending, no DB writes.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids ([] = all).
	 * @return Delivery[]
	 */
	public static function plan( Submission $s, FormConfig $form, array $only_channels = [] ): array {
		return self::resolve( $s, $form, $only_channels )['deliveries'];
	}

	/**
	 * Plan plus the destinations that cannot be used.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids ([] = all).
	 * @return array{deliveries:Delivery[],skipped:array<int,array{delivery:Delivery,reason:string}>}
	 */
	public static function resolve( Submission $s, FormConfig $form, array $only_channels = [] ): array {
		$out = [
			'deliveries' => [],
			'skipped'    => [],
		];

		$context       = Context::for_submission( $s, $s->lang );
		$context->form = $form;

		$set = Rules::apply( self::base_set( $s, $form, $only_channels ), (array) $form->get( 'rules', [] ), $context );
		if ( $set === null ) {
			return $out;
		}

		foreach ( $set as $id => $entry ) {
			if ( $entry['on'] ) {
				self::admin_deliveries( (string) $id, $entry, $s, $form, $out );
			}
		}
		self::submitter_deliveries( $s, $form, $only_channels, $out );

		$out['deliveries'] = self::filtered( $out['deliveries'], $s, $form );
		return $out;
	}

	/**
	 * Deliveries of one channel with its usual receivers, whether the form uses the channel or not (channel fallback).
	 *
	 * @param string     $channel Channel id.
	 * @param Submission $s       Submission.
	 * @param FormConfig $form    Form config.
	 * @return Delivery[]
	 */
	public static function fallback( string $channel, Submission $s, FormConfig $form ): array {
		$out   = [
			'deliveries' => [],
			'skipped'    => [],
		];
		$entry = self::base_set( $s, $form, [ $channel ] )[ $channel ] ?? null;
		if ( ! $entry ) {
			return [];
		}
		self::admin_deliveries( $channel, $entry, $s, $form, $out );
		return self::filtered( $out['deliveries'], $s, $form );
	}

	/**
	 * Is this form notifying at all (capture mode, form switch, editor override).
	 *
	 * @param FormConfig $form   Form config.
	 * @param array      $editor Editor overrides. In "selected" mode the caller adds 'via' => 'action' when the
	 *                           submission came through the form action.
	 */
	public static function form_active( FormConfig $form, array $editor = [] ): bool {
		$mode = is_scalar( $editor['fnfe_enabled'] ?? '' ) ? (string) ( $editor['fnfe_enabled'] ?? '' ) : '';
		if ( $mode === 'no' || $form->enabled() === 'off' ) {
			return false;
		}
		if ( $mode === 'yes' || $form->enabled() === 'on' ) {
			return true;
		}
		return Settings::str( 'general.capture_mode', 'all' ) !== 'selected' || ( $editor['via'] ?? '' ) === 'action';
	}

	/**
	 * Effective receivers of one channel for a form (after inheritance, before rules and language filtering).
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @return array[] Receiver[]
	 */
	public static function receivers( string $channel, FormConfig $form ): array {
		$target = Registry::channel( $channel );
		if ( $target && ( $target->capabilities()['receivers'] ?? 'list' ) === 'none' ) {
			return [ self::receiver( self::IMPLICIT, $target->label() ) ];
		}

		$route  = $form->route( $channel );
		$global = self::enabled_only( Settings::arr( "channels.$channel.receivers" ) );
		$own    = self::enabled_only( (array) $route['receivers'] );
		switch ( $route['receivers_mode'] ) {
			case 'append':
				return array_merge( $global, $own );
			case 'replace':
				return $own;
		}
		return $global;
	}

	/**
	 * Effective template source for channel/purpose (localized value).
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param string     $purpose Purpose.
	 * @return string|array
	 */
	public static function template( string $channel, FormConfig $form, string $purpose = 'admin' ) {
		$last = '';
		foreach ( self::text_sources( 'template', $channel, $form, $purpose ) as $source ) {
			$last = $source[0];
			if ( self::has_text( $last ) ) {
				break;
			}
		}
		return $last;
	}

	/**
	 * Effective buttons of one channel for a form.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @return array[] Button[]
	 */
	public static function buttons( string $channel, FormConfig $form ): array {
		$route  = $form->route( $channel );
		$global = Settings::arr( "channels.$channel.buttons" );
		$own    = (array) $route['buttons'];
		switch ( $route['buttons_mode'] ) {
			case 'append':
				// Own buttons follow the global ones on lines of their own.
				$rows = array_map( 'intval', array_column( $global, 'row' ) );
				$next = $rows ? max( $rows ) + 1 : 0;
				foreach ( $own as $i => $button ) {
					$own[ $i ]['row'] = $next + (int) ( $button['row'] ?? 0 );
				}
				return array_merge( $global, $own );
			case 'replace':
				return $own;
			case 'none':
				return [];
		}
		return $global;
	}

	/**
	 * Routing set before rules: every channel with its on/off state and receivers.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids.
	 */
	private static function base_set( Submission $s, FormConfig $form, array $only_channels ): array {
		$editor   = self::editor( $s );
		$selected = array_filter( (array) ( $editor['fnfe_channels'] ?? [] ), 'is_string' );
		$selected = array_filter( $selected, 'strlen' );
		$set      = [];

		foreach ( Registry::channels() as $id => $channel ) {
			if ( $only_channels && ! in_array( $id, $only_channels, true ) ) {
				continue;
			}
			$mode = $form->route( $id )['enabled'];
			$on   = $mode === 'on' || ( $mode !== 'off' && $channel->is_enabled() );
			if ( $selected && ! in_array( $id, $selected, true ) ) {
				$on = false;
			}
			$implicit   = ( $channel->capabilities()['receivers'] ?? 'list' ) === 'none';
			$set[ $id ] = [
				'on'        => $on,
				'implicit'  => $implicit,
				'receivers' => $implicit ? self::receivers( $id, $form ) : self::with_editor( $id, self::receivers( $id, $form ), $editor ),
			];
		}
		return $set;
	}

	/**
	 * Receivers after the editor controls.
	 *
	 * @param string $channel   Channel id.
	 * @param array  $receivers Receivers.
	 * @param array  $editor    Editor overrides.
	 */
	private static function with_editor( string $channel, array $receivers, array $editor ): array {
		$raw   = $editor[ 'fnfe_receivers_' . $channel ] ?? '';
		$added = [];
		foreach ( is_string( $raw ) ? preg_split( '/[,\r\n]+/', $raw ) : [] as $address ) {
			$address = trim( sanitize_text_field( $address ) );
			if ( $address !== '' ) {
				$added[] = self::receiver( $address );
			}
		}
		if ( ! $added ) {
			return $receivers;
		}
		return ( $editor['fnfe_receivers_mode'] ?? '' ) === 'replace' ? $added : array_merge( $receivers, $added );
	}

	/**
	 * Deliveries of one channel to the site's receivers.
	 *
	 * @param string     $id    Channel id.
	 * @param array      $entry Entry of the routing set.
	 * @param Submission $s     Submission.
	 * @param FormConfig $form  Form config.
	 * @param array      $out   Result of resolve(), by reference.
	 */
	private static function admin_deliveries( string $id, array $entry, Submission $s, FormConfig $form, array &$out ): void {
		$channel = Registry::channel( $id );
		if ( ! $channel ) {
			return;
		}
		$receivers = (array) apply_filters( 'fnfe_recipients', $entry['receivers'], $id, $s, $form );
		$editor    = self::editor( $s );
		$buttons   = self::effective_buttons( $channel, $form, $editor );
		$options   = self::options( $channel, $form );
		$policy    = self::policy( $id, $form, $editor );
		$kind      = (string) ( $channel->capabilities()['receiver_kind'] ?? '' );
		$seen      = [];

		foreach ( $receivers as $receiver ) {
			if ( ! is_array( $receiver ) || ! is_scalar( $receiver['address'] ?? null ) ) {
				continue;
			}
			$langs = (array) ( $receiver['langs'] ?? [] );
			if ( $langs && ! in_array( $s->lang, $langs, true ) ) {
				continue;
			}
			foreach ( self::expand( (string) $receiver['address'], $s, $kind ) as $raw ) {
				$address = self::normalize( $channel, $raw, $s );
				$key     = $address === '' ? 'invalid:' . $raw : $address;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$delivery                    = self::delivery( $id, $address, $receiver );
				$delivery->lang              = self::admin_lang( $delivery->recipient->lang, $s );
				$delivery->template          = self::admin_template( $id, $form, $editor, $delivery->lang );
				$delivery->subject           = self::text( 'subject', $id, $form, 'admin', $delivery->lang );
				$delivery->buttons           = $buttons;
				$delivery->options           = $options;
				$delivery->attachment_policy = $policy;

				if ( $address === '' ) {
					$delivery->recipient->address = $raw;
					$out['skipped'][]             = self::invalid( $delivery );
				} else {
					$out['deliveries'][] = $delivery;
				}
			}
		}
	}

	/**
	 * Auto-replies to the person who sent the form.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids.
	 * @param array      $out           Result of resolve(), by reference.
	 */
	private static function submitter_deliveries( Submission $s, FormConfig $form, array $only_channels, array &$out ): void {
		if ( ! $form->get( 'submitter.enabled' ) ) {
			return;
		}
		$consent = (string) $form->get( 'submitter.consent_field', '' );
		if ( $consent !== '' && trim( $s->value( $consent ) ) === '' ) {
			return;
		}

		foreach ( (array) $form->get( 'submitter.channels', [] ) as $id => $entry ) {
			$id      = (string) $id;
			$channel = Registry::channel( $id );
			if ( ! $channel || empty( $entry['enabled'] ) || ( $only_channels && ! in_array( $id, $only_channels, true ) ) ) {
				continue;
			}
			$capabilities = $channel->capabilities();
			if ( empty( $capabilities['submitter'] ) || ! $channel->is_enabled() ) {
				continue;
			}
			$raw     = self::submitter_address( (string) ( $entry['field'] ?? '' ), (string) ( $capabilities['receiver_kind'] ?? '' ), $s, $form );
			$address = $raw === '' ? '' : self::normalize( $channel, $raw, $s, $form );
			// An address typed by a visitor must never reach into the secret store.
			$address = Settings::is_placeholder( $address ) ? '' : $address;

			$recipient       = new Recipient( $id, $address === '' ? $raw : $address, '', 'submitter' );
			$recipient->lang = $s->lang;

			$delivery           = new Delivery( $id, $recipient, 'submitter' );
			$delivery->lang     = $s->lang === '' ? I18n::default_lang() : $s->lang;
			$delivery->template = self::text( 'template', $id, $form, 'submitter', $delivery->lang );
			$delivery->subject  = self::text( 'subject', $id, $form, 'submitter', $delivery->lang );
			$delivery->buttons  = self::submitter_buttons( self::effective_buttons( $channel, $form, [] ) );
			$delivery->options  = self::options( $channel, $form );

			$delivery->attachment_policy = [ 'mode' => 'off' ];

			if ( $address === '' ) {
				$out['skipped'][] = self::invalid( $delivery );
			} else {
				$out['deliveries'][] = $delivery;
			}
		}
	}

	/**
	 * Raw address of the submitter.
	 *
	 * @param string     $field Field id, '' = detect.
	 * @param string     $kind  Receiver kind of the channel.
	 * @param Submission $s     Submission.
	 * @param FormConfig $form  Form config.
	 */
	private static function submitter_address( string $field, string $kind, Submission $s, FormConfig $form ): string {
		if ( $field === '' && $kind === 'phone' ) {
			$field = (string) $form->get( 'fields.phone', '' );
			$found = $s->first_of_type( 'tel' );
		} elseif ( $field === '' && $kind === 'email' ) {
			$field = (string) $form->get( 'fields.email', '' );
			$found = $s->first_of_type( 'email' );
		}
		$found = $field === '' ? ( $found ?? null ) : $s->field( $field );
		return $found && empty( $found['redacted'] ) ? trim( (string) ( $found['value'] ?? '' ) ) : '';
	}

	/**
	 * Buttons a visitor may see: plain links and copy buttons.
	 *
	 * @param array $buttons Button definitions.
	 */
	private static function submitter_buttons( array $buttons ): array {
		return array_values(
			array_filter(
				$buttons,
				static function ( array $button ): bool {
					$inside = strpos( (string) ( $button['value'] ?? '' ), 'submission_url' ) !== false;
					return in_array( $button['type'] ?? '', [ 'url', 'copy' ], true ) && ! $inside;
				}
			)
		);
	}

	/**
	 * Buttons of a channel after the editor controls; [] for channels without buttons.
	 *
	 * @param ChannelInterface $channel Channel.
	 * @param FormConfig       $form    Form config.
	 * @param array            $editor  Editor overrides.
	 */
	private static function effective_buttons( ChannelInterface $channel, FormConfig $form, array $editor ): array {
		if ( empty( $channel->capabilities()['buttons'] ) ) {
			return [];
		}
		$own = [];
		foreach ( array_values( array_filter( (array) ( $editor['fnfe_buttons'] ?? [] ), 'is_array' ) ) as $i => $row ) {
			if ( trim( (string) ( $row['text'] ?? '' ) ) !== '' ) {
				$row['id']  = (string) ( $row['_id'] ?? '' );
				$row['row'] = $i;
				$own[]      = $row;
			}
		}
		return array_values( array_filter( $own ? FieldSanitizer::buttons( $own ) : self::buttons( $channel->id(), $form ), 'is_array' ) );
	}

	/**
	 * Channel options: global value or field default <- route options.
	 *
	 * @param ChannelInterface $channel Channel.
	 * @param FormConfig       $form    Form config.
	 */
	private static function options( ChannelInterface $channel, FormConfig $form ): array {
		$options = [];
		foreach ( $channel->form_schema() as $field ) {
			$field = Schema::normalize_field( (array) $field );
			if ( $field['key'] === '' || $field['type'] === 'secret' || ! in_array( $field['type'], Schema::VALUE_TYPES, true ) ) {
				continue;
			}
			$options = Settings::path_set( $options, $field['key'], Settings::get( 'channels.' . $channel->id() . '.' . $field['key'], $field['default'] ) );
		}
		return Settings::merge( $options, (array) $form->route( $channel->id() )['options'] );
	}

	/**
	 * Attachment policy after the editor control.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param array      $editor  Editor overrides.
	 */
	private static function policy( string $channel, FormConfig $form, array $editor ): array {
		$policy = Policy::resolve( $channel, $form );
		if ( in_array( $editor['fnfe_attach'] ?? '', [ 'off', 'link', 'send', 'both' ], true ) ) {
			$policy['mode'] = $editor['fnfe_attach'];
		}
		return $policy;
	}

	/**
	 * Admin template in one language: editor text, else the configured chain.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param array      $editor  Editor overrides.
	 * @param string     $lang    Language.
	 */
	private static function admin_template( string $channel, FormConfig $form, array $editor, string $lang ): string {
		$own = $editor['fnfe_template'] ?? '';
		if ( is_string( $own ) && trim( $own ) !== '' ) {
			return FieldSanitizer::template( $own );
		}
		return self::text( 'template', $channel, $form, 'admin', $lang );
	}

	/**
	 * First configured text in one language, '' when none is set.
	 *
	 * @param string     $kind    template | subject.
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param string     $purpose Purpose.
	 * @param string     $lang    Language.
	 */
	private static function text( string $kind, string $channel, FormConfig $form, string $purpose, string $lang ): string {
		foreach ( self::text_sources( $kind, $channel, $form, $purpose ) as $source ) {
			$text = Settings::localize( $source[0], $lang, $source[1] );
			if ( trim( $text ) !== '' ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * Configured values from the most specific one to the global one, each with its string name.
	 *
	 * @param string     $kind    template | subject.
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param string     $purpose Purpose.
	 * @return array[] [ localized value, string name ]
	 */
	private static function text_sources( string $kind, string $channel, FormConfig $form, string $purpose ): array {
		$sources = [];
		$prefix  = 'form:' . self::owner_key( $form ) . ':';
		if ( $purpose === 'submitter' ) {
			$sources[] = [ $form->get( "submitter.channels.$channel.$kind", '' ), $prefix . "submitter.channels.$channel.$kind" ];
		} elseif ( $purpose === 'admin' ) {
			$route = $form->route( $channel );
			if ( $route['template_mode'] === 'custom' ) {
				$sources[] = [ $route[ $kind ], $prefix . "channels.$channel.$kind" ];
			}
		}
		$sources[] = [ Settings::get( "channels.$channel.$kind.$purpose", '' ), "setting:channels.$channel.$kind.$purpose" ];
		return $sources;
	}

	/**
	 * Key of the form that owns the config texts: the linked form when there is one.
	 *
	 * @param FormConfig $form Form config.
	 */
	public static function owner_key( FormConfig $form ): string {
		static $keys = [];
		$link        = (int) $form->get( 'link', 0 );
		if ( ! $link || ! I18n::provider() ) {
			return $form->form_key;
		}
		if ( ! isset( $keys[ $link ] ) ) {
			$row           = FormRepository::find( $link );
			$keys[ $link ] = $row ? (string) $row['form_key'] : '';
		}
		return $keys[ $link ] === '' ? $form->form_key : $keys[ $link ];
	}

	/**
	 * Language of a message to the site's receivers.
	 *
	 * @param string     $receiver_lang Language of the receiver.
	 * @param Submission $s             Submission.
	 */
	private static function admin_lang( string $receiver_lang, Submission $s ): string {
		if ( $receiver_lang !== '' ) {
			return $receiver_lang;
		}
		$mode = Settings::str( 'general.admin_lang', 'site' );
		if ( $mode === 'submission' && $s->lang !== '' ) {
			return $s->lang;
		}
		return $mode === '' || $mode === 'site' || $mode === 'submission' ? I18n::default_lang() : $mode;
	}

	/**
	 * Addresses behind a receiver address: {field:x} and [field id="x"] become the values of that field.
	 *
	 * @param string     $address Address.
	 * @param Submission $s       Submission.
	 * @param string     $kind    Receiver kind of the channel.
	 * @return string[]
	 */
	private static function expand( string $address, Submission $s, string $kind ): array {
		$address = trim( $address );
		if ( ! preg_match( '/^(?:\{field:([^{}|]+)\}|\[field id=["\']?([^"\'\[\]]+)["\']?\])$/', $address, $m ) ) {
			return $address === '' ? [] : [ $address ];
		}
		$field = $s->field( trim( $m[2] ?? $m[1] ) );
		// Visitors may name a phone, an email address or a chat, never a URL this site would call.
		if ( ! $field || ! empty( $field['redacted'] ) || in_array( $kind, [ 'secret', 'endpoint' ], true ) ) {
			return [];
		}
		$values = array_filter( (array) ( $field['values'] ?? [] ), 'is_scalar' );
		$values = array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' );
		return array_values(
			array_filter(
				$values,
				static function ( string $value ): bool {
					// An address typed by a visitor must never reach into the secret store.
					return ! Settings::is_placeholder( $value );
				}
			)
		);
	}

	/**
	 * Address normalised by its channel, '' when invalid.
	 *
	 * @param ChannelInterface $channel Channel.
	 * @param string           $address Address.
	 * @param Submission       $s       Submission.
	 * @param FormConfig|null  $form    Form config, for the country hint of submitter addresses.
	 */
	private static function normalize( ChannelInterface $channel, string $address, Submission $s, ?FormConfig $form = null ): string {
		if ( $address === self::IMPLICIT && ( $channel->capabilities()['receivers'] ?? 'list' ) === 'none' ) {
			return $address;
		}
		if ( Settings::is_placeholder( $address ) ) {
			return $address;
		}
		$hints = [ 'submission' => $s ];
		if ( $form ) {
			$hints['country'] = $s->value( (string) $form->get( 'fields.country', '' ) );
		}
		try {
			return $channel->normalize_address( $address, $hints );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Delivery to a receiver.
	 *
	 * @param string $channel  Channel id.
	 * @param string $address  Normalised address.
	 * @param array  $receiver Receiver.
	 */
	private static function delivery( string $channel, string $address, array $receiver ): Delivery {
		$recipient       = new Recipient( $channel, $address, (string) ( $receiver['label'] ?? '' ), 'admin' );
		$recipient->lang = (string) ( $receiver['lang'] ?? '' );
		$recipient->meta = array_merge( (array) ( $receiver['meta'] ?? [] ), [ 'rid' => (string) ( $receiver['id'] ?? '' ) ] );

		$delivery          = new Delivery( $channel, $recipient, 'admin' );
		$delivery->rule_id = (string) ( $receiver['rule_id'] ?? '' );
		return $delivery;
	}

	/**
	 * Destination that cannot be used.
	 *
	 * @param Delivery $delivery Delivery.
	 */
	private static function invalid( Delivery $delivery ): array {
		return [
			'delivery' => $delivery,
			'reason'   => __( 'There is no valid address.', 'forms-notifier-for-elementor' ),
		];
	}

	/**
	 * Filter fnfe_deliveries.
	 *
	 * @param Delivery[] $plan Plan.
	 * @param Submission $s    Submission.
	 * @param FormConfig $form Form config.
	 * @return Delivery[]
	 */
	private static function filtered( array $plan, Submission $s, FormConfig $form ): array {
		return array_values(
			array_filter(
				(array) apply_filters( 'fnfe_deliveries', $plan, $s, $form ),
				static function ( $delivery ): bool {
					return $delivery instanceof Delivery;
				}
			)
		);
	}

	/**
	 * Editor overrides of a submission.
	 *
	 * @param Submission $s Submission.
	 */
	private static function editor( Submission $s ): array {
		return is_array( $s->meta['editor'] ?? null ) ? $s->meta['editor'] : [];
	}

	/**
	 * Receiver shape for an address.
	 *
	 * @param string $address Address.
	 * @param string $label   Label.
	 */
	private static function receiver( string $address, string $label = '' ): array {
		return [
			'id'      => '',
			'address' => $address,
			'label'   => $label,
			'enabled' => true,
			'lang'    => '',
			'langs'   => [],
			'meta'    => [],
		];
	}

	/**
	 * Receivers that are switched on.
	 *
	 * @param array $receivers Receivers.
	 */
	private static function enabled_only( array $receivers ): array {
		return array_values(
			array_filter(
				$receivers,
				static function ( $receiver ): bool {
					return is_array( $receiver ) && ! empty( $receiver['enabled'] ) && (string) ( $receiver['address'] ?? '' ) !== '';
				}
			)
		);
	}

	/**
	 * A localized value has text in any language.
	 *
	 * @param mixed $value Value.
	 */
	private static function has_text( $value ): bool {
		foreach ( (array) $value as $text ) {
			if ( is_string( $text ) && trim( $text ) !== '' ) {
				return true;
			}
		}
		return false;
	}
}
