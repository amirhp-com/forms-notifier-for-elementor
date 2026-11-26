<?php
/**
 * User-entered texts.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\I18n;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the default texts of settings and form configs with WPML String Translation or Polylang, and finds the name
 * a text was registered under.
 */
final class Strings {

	public const CONTEXT = 'Forms Notifier for Elementor';

	private const CACHE = 'fnfe_strings';

	/** WPML keeps 160 characters of a name. */
	private const MAX_NAME = 150;

	/** @var array<int,array<string,string>> name => text per site */
	private static $texts = [];

	/** @var array<int,array<string,string[]>> text => names per site */
	private static $names = [];

	/**
	 * Deterministic name: "setting:{path}" or "form:{form_key}:{path}".
	 *
	 * @param string $path     Path of the text.
	 * @param string $form_key Form key, '' = settings.
	 */
	public static function name_for( string $path, string $form_key = '' ): string {
		$name = $form_key === '' ? 'setting:' . $path : 'form:' . $form_key . ':' . $path;
		return strlen( $name ) > self::MAX_NAME ? Text::cut_bytes( $name, self::MAX_NAME - 33 ) . ':' . md5( $name ) : $name;
	}

	/**
	 * Register one string (default text) with the active multilingual plugin. No-op without one.
	 *
	 * @param string $name      Name.
	 * @param string $value     Text.
	 * @param bool   $multiline Several lines.
	 */
	public static function register( string $name, string $value, bool $multiline = true ): void {
		if ( $name === '' || trim( $value ) === '' ) {
			return;
		}
		$provider = I18n::provider();
		if ( $provider === 'wpml' ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( $provider === 'polylang' && function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $value, self::CONTEXT, $multiline );
		}
	}

	/** Walk schema fields flagged localized + all form configs and register their default texts. */
	public static function sync_all(): void {
		self::forget();
		self::register_all();
	}

	/**
	 * Register every known text.
	 *
	 * @return int number of texts
	 */
	public static function register_all(): int {
		$texts = self::all();
		if ( I18n::provider() !== '' ) {
			foreach ( $texts as $name => $value ) {
				self::register( (string) $name, $value, strpos( $value, "\n" ) !== false || strlen( $value ) > 80 );
			}
		}
		return count( $texts );
	}

	/**
	 * Every default text. Cached: Polylang needs the list on each admin request.
	 *
	 * @return array<string,string> name => text
	 */
	public static function all(): array {
		$blog = get_current_blog_id();
		if ( isset( self::$texts[ $blog ] ) ) {
			return self::$texts[ $blog ];
		}
		$texts = get_transient( self::CACHE );
		if ( ! is_array( $texts ) ) {
			$texts = self::collect();
			set_transient( self::CACHE, $texts, DAY_IN_SECONDS );
		}
		self::$texts[ $blog ] = $texts;
		return $texts;
	}

	/**
	 * Name a text was registered under, '' when it is not registered or the site has one language. The text of the
	 * form is preferred to the one of the settings.
	 *
	 * @param string $text     Default text.
	 * @param string $form_key Form key.
	 */
	public static function find_name( string $text, string $form_key = '' ): string {
		if ( trim( $text ) === '' || I18n::provider() === '' ) {
			return '';
		}
		$blog = get_current_blog_id();
		if ( ! isset( self::$names[ $blog ] ) ) {
			self::$names[ $blog ] = [];
			foreach ( self::all() as $name => $value ) {
				self::$names[ $blog ][ $value ][] = (string) $name;
			}
		}
		$names = self::$names[ $blog ][ $text ] ?? [];
		foreach ( $names as $name ) {
			if ( $form_key !== '' && strncmp( $name, 'form:' . $form_key . ':', strlen( $form_key ) + 6 ) === 0 ) {
				return $name;
			}
		}
		return $names[0] ?? '';
	}

	/**
	 * First of several names under which a text is registered, '' when it is none of them.
	 *
	 * @param string[]     $names Names.
	 * @param string|array $value Localized value or string.
	 */
	public static function first_match( array $names, $value ): string {
		$text = is_array( $value ) ? ( $value['_'] ?? '' ) : $value;
		if ( ! is_string( $text ) || trim( $text ) === '' || I18n::provider() === '' ) {
			return '';
		}
		$known = self::all();
		foreach ( $names as $name ) {
			if ( isset( $known[ $name ] ) && $known[ $name ] === $text ) {
				return $name;
			}
		}
		return '';
	}

	/** Forget the collected texts; they are read again when needed. */
	public static function forget(): void {
		delete_transient( self::CACHE );
		self::$texts = [];
		self::$names = [];
	}

	/** Read every default text from the settings and the form configs. */
	private static function collect(): array {
		$out = [];
		foreach ( Schema::fields() as $path => $field ) {
			$path = (string) $path;
			if ( ! empty( $field['localized'] ) ) {
				self::add( $out, $path, Settings::get( $path ) );
			} elseif ( $field['type'] === 'buttons' ) {
				self::add_buttons( $out, $path, Settings::arr( $path ) );
			} elseif ( $field['type'] === 'repeater' ) {
				self::add_rows( $out, $path, $field['fields'], Settings::arr( $path ) );
			}
		}
		foreach ( FormRepository::export_rows() as $row ) {
			self::add_form( $out, (string) $row['form_key'], FormConfig::normalize( (array) $row['config'] ) );
		}
		return $out;
	}

	/**
	 * Texts of one form config.
	 *
	 * @param array  $out      Collected texts.
	 * @param string $form_key Form key.
	 * @param array  $config   Normalised config.
	 */
	private static function add_form( array &$out, string $form_key, array $config ): void {
		foreach ( $config['channels'] as $id => $route ) {
			self::add( $out, "channels.$id.template", $route['template'], $form_key );
			self::add( $out, "channels.$id.subject", $route['subject'], $form_key );
			self::add_buttons( $out, "channels.$id.buttons", $route['buttons'], $form_key );
		}
		foreach ( $config['submitter']['channels'] as $id => $entry ) {
			self::add( $out, "submitter.channels.$id.template", $entry['template'], $form_key );
			self::add( $out, "submitter.channels.$id.subject", $entry['subject'], $form_key );
		}
		foreach ( $config['fields']['labels'] as $id => $label ) {
			self::add( $out, "fields.labels.$id", $label, $form_key );
		}
	}

	/**
	 * Texts of buttons.
	 *
	 * @param array  $out      Collected texts.
	 * @param string $path     Path of the list.
	 * @param array  $buttons  Buttons.
	 * @param string $form_key Form key.
	 */
	private static function add_buttons( array &$out, string $path, array $buttons, string $form_key = '' ): void {
		foreach ( $buttons as $button ) {
			if ( is_array( $button ) && isset( $button['id'], $button['text'] ) ) {
				self::add( $out, $path . '.' . $button['id'] . '.text', $button['text'], $form_key );
			}
		}
	}

	/**
	 * Localized sub-fields of repeater rows.
	 *
	 * @param array  $out    Collected texts.
	 * @param string $path   Path of the list.
	 * @param array  $fields Sub-fields.
	 * @param array  $rows   Rows.
	 */
	private static function add_rows( array &$out, string $path, array $fields, array $rows ): void {
		foreach ( $fields as $field ) {
			foreach ( empty( $field['localized'] ) ? [] : $rows as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					self::add( $out, $path . '.' . $row['id'] . '.' . $field['key'], Settings::path_get( $row, $field['key'] ) );
				}
			}
		}
	}

	/**
	 * Default text of a localized value.
	 *
	 * @param array  $out      Collected texts.
	 * @param string $path     Path.
	 * @param mixed  $value    Localized value or string.
	 * @param string $form_key Form key.
	 */
	private static function add( array &$out, string $path, $value, string $form_key = '' ): void {
		$text = is_array( $value ) ? ( $value['_'] ?? '' ) : $value;
		if ( is_string( $text ) && trim( $text ) !== '' ) {
			$out[ self::name_for( $path, $form_key ) ] = $text;
		}
	}
}
