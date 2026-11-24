<?php
/**
 * Field sanitiser.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Phone\Phone;
use AmirhpCom\FormsNotifier\Template\Conditions;
use AmirhpCom\FormsNotifier\Template\Markup;

defined( 'ABSPATH' ) || exit;

/** Sanitises a value against a field definition. */
final class FieldSanitizer {

	/** Largest value of a code field in bytes. */
	private const MAX_CODE = 102400;

	/** Deepest nesting kept in free-form meta arrays. */
	private const MAX_DEPTH = 5;

	/**
	 * Sanitise one value.
	 *
	 * @param array $field Field definition (normalised or raw).
	 * @param mixed $value Untrusted value.
	 * @return array{0:mixed,1:string} [ clean value, error message or '' ]
	 */
	public static function sanitize( array $field, $value ): array {
		$field = isset( $field['show_if'], $field['localized'], $field['attrs'] ) ? $field : Schema::normalize_field( $field );
		$error = '';
		$clean = self::by_type( $field, $value, $error );

		if ( $error === '' && $field['required'] && self::is_empty( $clean ) ) {
			$error = __( 'This field is required.', 'forms-notifier-for-elementor' );
		}
		return [ $clean, $error ];
	}

	/**
	 * Sanitise a map of settings against the schema. Unknown and non-value keys are errors.
	 *
	 * @param array $values Absolute path => untrusted value.
	 * @return array{0:array<string,mixed>,1:array<string,string>} [ clean values, errors by path ]
	 */
	public static function settings( array $values ): array {
		$clean  = [];
		$errors = [];
		foreach ( $values as $path => $value ) {
			$path  = (string) $path;
			$field = Schema::field( $path );
			if ( ! $field || $field['type'] === 'secret' ) {
				$errors[ $path ] = __( 'Unknown setting.', 'forms-notifier-for-elementor' );
				continue;
			}
			list( $item, $error ) = self::sanitize( $field, $value );
			if ( $error === '' ) {
				$clean[ $path ] = $item;
			} else {
				$errors[ $path ] = $error;
			}
		}
		return [ $clean, $errors ];
	}

	/**
	 * Localized text: [ '_' => default, lang => text ].
	 *
	 * @param mixed  $value Untrusted string or array.
	 * @param string $type  text | textarea | template.
	 */
	public static function localized( $value, string $type = 'text' ): array {
		$value = is_array( $value ) ? $value : [ '_' => $value ];
		$out   = [ '_' => '' ];
		foreach ( $value as $lang => $text ) {
			$lang = (string) $lang;
			if ( ! is_scalar( $text ) || ( $lang !== '_' && ! self::is_lang( $lang ) ) ) {
				continue;
			}
			$text = self::plain( $type, (string) $text );
			if ( $lang === '_' || $text !== '' ) {
				$out[ $lang ] = $text;
			}
		}
		return $out;
	}

	/**
	 * Receivers in the stored shape. Addresses are not validated here (see sanitize() with type receivers).
	 *
	 * @param mixed $value Untrusted list.
	 */
	public static function receivers( $value ): array {
		$out  = [];
		$used = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$address = self::line( $row['address'] ?? '' );
			if ( $address === '' ) {
				continue;
			}
			$id          = self::row_id( $row['id'] ?? '', 'r_', $used );
			$used[ $id ] = true;
			$langs       = [];
			foreach ( is_array( $row['langs'] ?? null ) ? $row['langs'] : [] as $lang ) {
				if ( is_string( $lang ) && self::is_lang( $lang ) ) {
					$langs[] = $lang;
				}
			}
			$lang  = is_string( $row['lang'] ?? null ) && self::is_lang( $row['lang'] ) ? $row['lang'] : '';
			$out[] = [
				'id'      => $id,
				'address' => $address,
				'label'   => self::line( $row['label'] ?? '' ),
				'enabled' => ! array_key_exists( 'enabled', $row ) || Text::bool( $row['enabled'] ),
				'lang'    => $lang,
				'langs'   => array_values( array_unique( $langs ) ),
				'meta'    => self::meta( $row['meta'] ?? [] ),
			];
		}
		return $out;
	}

	/**
	 * Buttons in the stored shape.
	 *
	 * @param mixed $value Untrusted list.
	 */
	public static function buttons( $value ): array {
		$out  = [];
		$used = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type   = self::one_of( $row['type'] ?? '', [ 'url', 'callback', 'web_app', 'copy', 'submission' ] );
			$id     = self::row_id( $row['id'] ?? '', 'b_', $used );
			$target = self::line( $row['value'] ?? '' );
			if ( $type === 'url' && ! self::is_button_url( $target ) ) {
				$target = '';
			} elseif ( $type === 'callback' ) {
				$target = sanitize_key( $target );
			}
			$used[ $id ] = true;
			$out[]       = [
				'id'    => $id,
				'text'  => self::localized( $row['text'] ?? '', 'text' ),
				'type'  => $type,
				'value' => $target,
				'row'   => max( 0, min( 99, (int) ( $row['row'] ?? 0 ) ) ),
				'style' => self::one_of( $row['style'] ?? '', [ '', 'primary', 'success', 'danger' ] ),
			];
		}
		return $out;
	}

	/**
	 * Routing rules in the stored shape.
	 *
	 * @param mixed $value Untrusted list.
	 */
	public static function rules( $value ): array {
		$ops  = array_merge( Conditions::OPS, array_keys( (array) apply_filters( 'fnfe_condition_ops', [] ) ) );
		$out  = [];
		$used = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$conditions = [];
			foreach ( is_array( $row['conditions'] ?? null ) ? $row['conditions'] : [] as $condition ) {
				$field = is_array( $condition ) ? self::line( $condition['field'] ?? '' ) : '';
				if ( $field !== '' ) {
					$conditions[] = [
						'field' => $field,
						'op'    => self::one_of( $condition['op'] ?? '', $ops ),
						'value' => self::line( $condition['value'] ?? '' ),
					];
				}
			}
			$receivers = [];
			foreach ( is_array( $row['receivers'] ?? null ) ? $row['receivers'] : [] as $channel => $list ) {
				if ( self::is_slug( $channel ) ) {
					$receivers[ $channel ] = self::receivers( $list );
				}
			}
			$id          = self::row_id( $row['id'] ?? '', 'q_', $used );
			$used[ $id ] = true;
			$out[]       = [
				'id'         => $id,
				'name'       => self::line( $row['name'] ?? '' ),
				'enabled'    => ! array_key_exists( 'enabled', $row ) || Text::bool( $row['enabled'] ),
				'match'      => self::one_of( $row['match'] ?? '', [ 'all', 'any' ] ),
				'conditions' => $conditions,
				'effect'     => self::one_of( $row['effect'] ?? '', [ 'add', 'only', 'skip', 'replace' ] ),
				'channels'   => array_values( array_unique( array_filter( is_array( $row['channels'] ?? null ) ? $row['channels'] : [], [ self::class, 'is_slug' ] ) ) ),
				'receivers'  => $receivers,
				'stop'       => Text::bool( $row['stop'] ?? false ),
			];
		}
		return $out;
	}

	/**
	 * Per-form option values of a channel (ChannelInterface::form_schema()).
	 *
	 * @param string $channel Channel id.
	 * @param mixed  $value   Untrusted values.
	 */
	public static function options( string $channel, $value ): array {
		if ( ! is_array( $value ) || ! $value ) {
			return [];
		}
		$target = Registry::channel( $channel );
		if ( ! $target ) {
			// The channel may only be missing for now; keep plain values.
			return self::meta( $value );
		}
		$out = [];
		foreach ( $target->form_schema() as $field ) {
			$field = Schema::normalize_field( $field );
			if ( ! in_array( $field['type'], Schema::VALUE_TYPES, true ) || $field['type'] === 'secret' ) {
				continue;
			}
			Settings::path_get( $value, $field['key'], $found );
			if ( ! $found ) {
				continue;
			}
			list( $clean, $error ) = self::sanitize( $field, Settings::path_get( $value, $field['key'] ) );
			if ( $error === '' ) {
				$out = Settings::path_set( $out, $field['key'], $clean );
			}
		}
		return $out;
	}

	/**
	 * Secret: trimmed, no tags, no control characters.
	 *
	 * @param mixed $value Untrusted value.
	 */
	public static function secret( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		// Complete tags only: a lone "<" may be part of a password.
		return trim( preg_replace( '~</?[a-zA-Z!][^>]*>~', '', $value ) );
	}

	/**
	 * Template markup.
	 *
	 * @param string $template Untrusted template.
	 */
	public static function template( string $template ): string {
		$template = wp_check_invalid_utf8( $template );
		if ( class_exists( Markup::class ) ) {
			return Markup::sanitize( $template );
		}
		$allowed = array_fill_keys( [ 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'code', 'pre', 'blockquote', 'spoiler', 'br' ], [] );

		$allowed['a'] = [ 'href' => true ];

		$clean = wp_kses( $template, $allowed, [ 'http', 'https', 'tg', 'mailto', 'tel' ] );
		return preg_replace_callback(
			'/\{[^{}]*\}/',
			static function ( array $m ): string {
				return htmlspecialchars_decode( $m[0], ENT_NOQUOTES );
			},
			$clean
		);
	}

	/**
	 * Dispatch on the field type.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 * @return mixed
	 */
	private static function by_type( array $field, $value, string &$error ) {
		$type = $field['type'];

		if ( $field['localized'] ) {
			return self::localized( $value, $type );
		}
		if ( $field['sanitize'] !== '' && ! in_array( $type, [ 'tags', 'repeater', 'receivers', 'buttons', 'rules', 'keyvalue', 'secret' ], true ) ) {
			return self::named( $field['sanitize'], $type, $value, $error );
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'template':
				return is_scalar( $value ) ? self::plain( $type, (string) $value ) : '';

			case 'number':
				return self::number( $field, $value, $error );

			case 'url':
				return self::url( $value, $error );

			case 'email':
				$raw   = self::line( $value );
				$clean = sanitize_email( $raw );
				if ( $raw !== '' && ! is_email( $clean ) ) {
					$error = __( 'Enter a valid email address.', 'forms-notifier-for-elementor' );
				}
				return $clean;

			case 'tel':
				return self::tel( $value );

			case 'time':
				$clean = Text::en_digits( self::line( $value ) );
				if ( $clean !== '' && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $clean ) ) {
					$error = __( 'Enter a time as HH:MM.', 'forms-notifier-for-elementor' );
					return '';
				}
				return $clean;

			case 'toggle':
				return Text::bool( $value );

			case 'select':
			case 'radio':
				$clean = is_scalar( $value ) ? (string) $value : '';
				if ( ! self::in_options( $field, $clean ) && ( $clean !== '' || $field['required'] ) ) {
					$error = __( 'Choose one of the listed options.', 'forms-notifier-for-elementor' );
					return '';
				}
				return $clean;

			case 'multiselect':
			case 'checkboxes':
				return self::choices( $field, $value, $error );

			case 'secret':
				return self::secret( $value );

			case 'code':
				return self::code( $value, $error );

			case 'receivers':
				return self::strict_receivers( $field, $value, $error );

			case 'buttons':
				return self::buttons( $value );

			case 'rules':
				return self::rules( $value );

			case 'repeater':
				return self::repeater( $field, $value, $error );

			case 'keyvalue':
				return self::key_value( $value );

			case 'tags':
				return self::tags( $field, $value );
		}

		$error = __( 'This field does not hold a value.', 'forms-notifier-for-elementor' );
		return null;
	}

	/**
	 * Text by type.
	 *
	 * @param string $type text | textarea | template.
	 * @param string $text Untrusted text.
	 */
	private static function plain( string $type, string $text ): string {
		if ( $type === 'template' ) {
			return self::template( $text );
		}
		return $type === 'textarea' ? sanitize_textarea_field( $text ) : self::line( $text );
	}

	/**
	 * One line of text. Unlike sanitize_text_field() it keeps percent-encoded characters, which URL templates need.
	 *
	 * @param mixed $value Untrusted value.
	 */
	private static function line( $value ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value );
		if ( strpos( $value, '<' ) !== false ) {
			$value = wp_strip_all_tags( $value );
		}
		return trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}

	/**
	 * Named sanitiser.
	 *
	 * @param string $name  Sanitiser name.
	 * @param string $type  Field type.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 * @return mixed
	 */
	private static function named( string $name, string $type, $value, string &$error ) {
		$text = is_scalar( $value ) ? (string) $value : '';
		switch ( $name ) {
			case 'textarea':
				return sanitize_textarea_field( $text );
			case 'html':
				return wp_kses_post( $text );
			case 'url':
				return self::url( $value, $error );
			case 'key':
				return sanitize_key( $text );
			case 'int':
				return (int) Text::en_digits( $text );
			case 'float':
				return (float) Text::en_digits( $text );
			case 'bool':
				return Text::bool( $value );
			case 'phone':
				$phone = $text === '' ? '' : Phone::normalize( $text );
				if ( $phone === null ) {
					$error = __( 'Enter a valid phone number.', 'forms-notifier-for-elementor' );
					return '';
				}
				return $phone;
			case 'json':
				$clean = self::code( $value, $error );
				if ( $error === '' && trim( $clean ) !== '' && json_decode( $clean ) === null && trim( $clean ) !== 'null' ) {
					$error = __( 'This is not valid JSON.', 'forms-notifier-for-elementor' );
				}
				return $clean;
			case 'none':
				if ( in_array( $type, [ 'template', 'code' ], true ) ) {
					return self::code( $value, $error );
				}
				break;
		}
		return self::line( $value );
	}

	/**
	 * Number within attrs min/max. Integer unless the step or a bound has decimals.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 * @return int|float
	 */
	private static function number( array $field, $value, string &$error ) {
		$text = is_scalar( $value ) ? trim( Text::en_digits( (string) $value ) ) : '';
		if ( $text === '' ) {
			return is_numeric( $field['default'] ) ? $field['default'] + 0 : 0;
		}
		if ( ! is_numeric( $text ) ) {
			$error = __( 'Enter a number.', 'forms-notifier-for-elementor' );
			return 0;
		}
		$attrs = $field['attrs'];
		$step  = $attrs['step'] ?? 1;
		$float = ! is_numeric( $step ) || (float) $step !== floor( (float) $step ) || (float) $step === 0.0;
		$min   = isset( $attrs['min'] ) && is_numeric( $attrs['min'] ) ? $attrs['min'] + 0 : 0;
		$clean = $float ? (float) $text : (int) round( (float) $text );
		$clean = max( $min, $clean );
		if ( isset( $attrs['max'] ) && is_numeric( $attrs['max'] ) ) {
			$clean = min( $attrs['max'] + 0, $clean );
		}
		return $float ? (float) $clean : (int) $clean;
	}

	/**
	 * Http(s) URL.
	 *
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 */
	private static function url( $value, string &$error ): string {
		$raw   = is_scalar( $value ) ? trim( (string) $value ) : '';
		$clean = $raw === '' ? '' : esc_url_raw( $raw, [ 'http', 'https' ] );
		if ( $raw !== '' && ( $clean === '' || ! preg_match( '~^https?://[^/\s]+~i', $clean ) ) ) {
			$error = __( 'Enter a full address that starts with http:// or https://.', 'forms-notifier-for-elementor' );
			return '';
		}
		return $clean;
	}

	/**
	 * Digits and a leading plus.
	 *
	 * @param mixed $value Untrusted value.
	 */
	private static function tel( $value ): string {
		$raw  = Text::en_digits( self::line( $value ) );
		$plus = strncmp( $raw, '+', 1 ) === 0 ? '+' : '';
		return $plus . preg_replace( '/\D/', '', $raw );
	}

	/**
	 * Source text without tag stripping.
	 *
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 */
	private static function code( $value, string &$error ): string {
		$clean = is_scalar( $value ) ? wp_check_invalid_utf8( (string) $value ) : '';
		if ( strlen( $clean ) > self::MAX_CODE ) {
			$error = __( 'This text is too long.', 'forms-notifier-for-elementor' );
			return '';
		}
		return $clean;
	}

	/**
	 * Several options.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 */
	private static function choices( array $field, $value, string &$error ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			$item = is_scalar( $item ) ? (string) $item : '';
			if ( $item === '' || ! self::in_options( $field, $item ) ) {
				$error = __( 'Choose only listed options.', 'forms-notifier-for-elementor' );
				continue;
			}
			$out[] = $item;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A value is one of the options of a field.
	 *
	 * @param array  $field Field.
	 * @param string $value Value.
	 */
	private static function in_options( array $field, string $value ): bool {
		foreach ( $field['options'] as $key => $option ) {
			$allowed = is_array( $option ) ? (string) ( $option['value'] ?? $key ) : (string) $key;
			if ( $allowed === $value ) {
				return true;
			}
		}
		switch ( $field['options_source'] ) {
			case 'channels':
			case 'ready_channels':
				return in_array( $value, array_map( 'strval', Registry::channel_ids() ), true );
			case 'languages':
				return array_key_exists( $value, I18n::languages() );
			case 'sms_providers':
				return array_key_exists( $value, Registry::providers( 'sms' ) );
			case 'whatsapp_providers':
				return array_key_exists( $value, Registry::providers( 'whatsapp' ) );
			case 'countries':
				return (bool) preg_match( '/^[A-Z]{2}$/', $value );
			case 'forms':
				return (bool) preg_match( '/^[1-9]\d*$/', $value );
			case 'roles':
				return array_key_exists( $value, wp_roles()->roles );
		}
		return false;
	}

	/**
	 * Receivers with every address checked by its channel.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 */
	private static function strict_receivers( array $field, $value, string &$error ): array {
		$channel = Registry::channel( (string) $field['channel'] );
		$out     = [];
		$invalid = [];
		foreach ( self::receivers( $value ) as $receiver ) {
			$address = self::address( $receiver['address'], $channel );
			if ( $address === '' ) {
				$invalid[] = $receiver['address'];
				continue;
			}
			$receiver['address'] = $address;
			$out[]               = $receiver;
		}
		if ( $invalid ) {
			$error = sprintf(
				/* translators: %1$s: comma separated list of addresses */
				__( 'These addresses are not valid: %1$s', 'forms-notifier-for-elementor' ),
				implode( ', ', array_map( [ Logger::class, 'clean' ], $invalid ) )
			);
		}
		return $out;
	}

	/**
	 * Address normalised by its channel, '' when invalid. Placeholders and form field tags are kept.
	 *
	 * @param string                $address Raw address.
	 * @param ChannelInterface|null $channel Channel.
	 */
	private static function address( string $address, ?ChannelInterface $channel ): string {
		if ( Settings::is_placeholder( $address ) || preg_match( '/^(\{field:[^{}|]+\}|\[field id="[^"\[\]]+"\])$/', $address ) ) {
			return $address;
		}
		if ( ! $channel ) {
			return $address;
		}
		try {
			return $channel->normalize_address( $address );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Rows of a repeater. Secret sub-fields are dropped: they live in the secret store.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Untrusted value.
	 * @param string $error Error message.
	 */
	private static function repeater( array $field, $value, string &$error ): array {
		$out  = [];
		$used = [];
		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id          = self::row_id( $row['id'] ?? '', 'e_', $used );
			$used[ $id ] = true;
			$clean       = [ 'id' => $id ];
			foreach ( $field['fields'] as $sub ) {
				$sub = isset( $sub['show_if'], $sub['localized'] ) ? $sub : Schema::normalize_field( $sub );
				if ( $sub['key'] === 'id' || $sub['type'] === 'secret' || ! in_array( $sub['type'], Schema::VALUE_TYPES, true ) ) {
					continue;
				}
				Settings::path_get( $row, $sub['key'], $found );
				list( $item, $sub_error ) = $found
					? self::sanitize( $sub, Settings::path_get( $row, $sub['key'] ) )
					: [ $sub['default'], '' ];
				if ( $sub_error !== '' && $error === '' ) {
					$error = $sub['label'] === '' ? $sub_error : $sub['label'] . ': ' . $sub_error;
				}
				$clean = Settings::path_set( $clean, $sub['key'], $item );
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * String map. Accepts an object or a list of [ key, value ] rows.
	 *
	 * @param mixed $value Untrusted value.
	 */
	private static function key_value( $value ): array {
		$out = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( is_array( $item ) && isset( $item['key'] ) ) {
				$key  = $item['key'];
				$item = $item['value'] ?? '';
			}
			$key = is_scalar( $key ) ? sanitize_text_field( (string) $key ) : '';
			if ( $key !== '' && is_scalar( $item ) ) {
				$out[ $key ] = self::line( $item );
			}
		}
		return $out;
	}

	/**
	 * List of short strings. Accepts a comma or newline separated string.
	 *
	 * @param array $field Field.
	 * @param mixed $value Untrusted value.
	 */
	private static function tags( array $field, $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[,\r\n،]+/u', $value );
		}
		$out   = [];
		$error = '';
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$text = sanitize_text_field( (string) $item );
			if ( $text !== '' ) {
				$out[] = $field['sanitize'] === '' ? $text : self::named( $field['sanitize'], 'tags', $text, $error );
			}
		}
		// Repeated numbers are meaningful (equal delays), repeated words are not.
		return in_array( $field['sanitize'], [ 'int', 'float' ], true ) ? $out : array_values( array_unique( $out ) );
	}

	/**
	 * Free-form JSON data reduced to plain values.
	 *
	 * @param mixed $value Untrusted value.
	 * @param int   $depth Current depth.
	 */
	private static function meta( $value, int $depth = 0 ): array {
		$out = [];
		if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
			return $out;
		}
		foreach ( $value as $key => $item ) {
			$key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
			if ( is_array( $item ) ) {
				$out[ $key ] = self::meta( $item, $depth + 1 );
			} elseif ( is_string( $item ) ) {
				$out[ $key ] = sanitize_textarea_field( $item );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Row id, generated when missing, invalid or used twice.
	 *
	 * @param mixed  $id     Untrusted id.
	 * @param string $prefix Prefix of new ids.
	 * @param array  $used   Ids already used (as keys).
	 */
	private static function row_id( $id, string $prefix, array $used ): string {
		$id = is_string( $id ) && preg_match( '/^[A-Za-z0-9_]{1,40}$/', $id ) ? $id : '';
		while ( $id === '' || isset( $used[ $id ] ) ) {
			$id = Text::uid( $prefix );
		}
		return $id;
	}

	/**
	 * Button URL template: http(s), tg, mailto, tel, or a tag at the start.
	 *
	 * @param string $url URL template.
	 */
	private static function is_button_url( string $url ): bool {
		return (bool) preg_match( '~^(\{|https?://|tg://|mailto:|tel:)~i', $url );
	}

	/**
	 * First entry of $allowed is the fallback.
	 *
	 * @param mixed    $value   Untrusted value.
	 * @param string[] $allowed Accepted values.
	 */
	private static function one_of( $value, array $allowed ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * Language code as multilingual plugins report it.
	 *
	 * @param string $lang Code.
	 */
	private static function is_lang( string $lang ): bool {
		return (bool) preg_match( '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $lang );
	}

	/**
	 * Channel id.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_slug( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-z0-9_]+$/', $value );
	}

	/**
	 * Nothing was entered.
	 *
	 * @param mixed $value Clean value.
	 */
	private static function is_empty( $value ): bool {
		if ( is_array( $value ) ) {
			return $value === [] || ( isset( $value['_'] ) && count( $value ) === 1 && $value['_'] === '' );
		}
		return $value === '' || $value === null;
	}
}
