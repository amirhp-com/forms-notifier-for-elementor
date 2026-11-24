<?php
/**
 * Settings.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Dot-path settings, secrets, localized values, request overlay. */
final class Settings {

	public const OPTION  = 'fnfe_settings';
	public const SECRETS = 'fnfe_secrets';

	/** Prefix of a receiver address that points into the secret store. */
	public const PLACEHOLDER = 'secret:';

	/** @var array<int,array> stored option per site */
	private static $stored = [];

	/** @var array<int,array> merged tree per site */
	private static $merged = [];

	/** @var array<string,mixed> */
	private static $overlay = [];

	/** @var array<string,string> */
	private static $overlay_secrets = [];

	/** @var bool */
	private static $hooked = false;

	/** Whole tree: defaults deep-merged with the stored option. Lists are replaced, not merged. */
	public static function all(): array {
		$blog = get_current_blog_id();
		if ( isset( self::$merged[ $blog ] ) ) {
			$tree = self::$merged[ $blog ];
		} else {
			$complete = Defaults::is_complete();
			$stored   = self::stored();
			$tree     = self::merge( Defaults::all(), $stored );

			if ( $complete ) {
				// Values that only make sense as a whole are never merged with their default.
				foreach ( Schema::fields() as $path => $field ) {
					if ( $field['type'] === 'keyvalue' || ! empty( $field['localized'] ) ) {
						$value = self::path_get( $stored, $path, $found );
						if ( $found ) {
							$tree = self::path_set( $tree, $path, $value );
						}
					}
				}
				self::$merged[ $blog ] = $tree;
			}
		}

		foreach ( self::$overlay as $path => $value ) {
			$tree = self::path_set( $tree, $path, $value );
		}
		return $tree;
	}

	/**
	 * Read one value.
	 *
	 * @param string $path    Dot path, e.g. 'channels.telegram.enabled'.
	 * @param mixed  $default Returned when the path is unknown.
	 * @return mixed
	 */
	public static function get( string $path, $default = null ) {
		if ( ! self::$overlay ) {
			// Scalars are answered without building the schema.
			$value = self::path_get( self::stored(), $path, $found );
			if ( ! $found ) {
				$value = self::path_get( Defaults::base(), $path, $found );
			}
			if ( $found && ! is_array( $value ) ) {
				return $value;
			}
		}
		$value = self::path_get( self::all(), $path, $found );
		return $found ? $value : $default;
	}

	/**
	 * @param string $path    Dot path.
	 * @param bool   $default Returned when the path is unknown.
	 */
	public static function bool( string $path, bool $default = false ): bool {
		$value = self::get( $path );
		return $value === null ? $default : Text::bool( $value );
	}

	/**
	 * @param string $path    Dot path.
	 * @param int    $default Returned when the value is not numeric.
	 */
	public static function int( string $path, int $default = 0 ): int {
		$value = self::get( $path );
		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * @param string $path    Dot path.
	 * @param string $default Returned when the value is unknown or not scalar.
	 */
	public static function str( string $path, string $default = '' ): string {
		$value = self::get( $path );
		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $default;
	}

	/**
	 * @param string $path    Dot path.
	 * @param array  $default Returned when the value is not an array.
	 */
	public static function arr( string $path, array $default = [] ): array {
		$value = self::get( $path );
		return is_array( $value ) ? $value : $default;
	}

	/**
	 * Write one value (no schema check: internal use).
	 *
	 * @param string $path  Dot path.
	 * @param mixed  $value JSON-safe value.
	 */
	public static function set( string $path, $value ): void {
		$old = self::plain_get( $path );
		self::write( self::with_value( self::stored(), $path, $value ) );
		do_action( 'fnfe_setting_updated', $path, $value, $old );
	}

	/**
	 * Write many values in one option update.
	 *
	 * @param array $paths_to_values Dot path => value.
	 */
	public static function set_many( array $paths_to_values ): void {
		$stored  = self::stored();
		$changed = [];
		foreach ( $paths_to_values as $path => $value ) {
			$path = (string) $path;
			if ( self::plain_get( $path ) !== $value ) {
				$changed[] = $path;
			}
			$stored = self::with_value( $stored, $path, $value );
		}
		self::write( $stored );
		do_action( 'fnfe_settings_saved', $changed );
	}

	/**
	 * Back to the default.
	 *
	 * @param string $path Dot path.
	 */
	public static function delete( string $path ): void {
		$old    = self::plain_get( $path );
		$stored = self::stored();
		self::path_get( $stored, $path, $found );
		if ( ! $found ) {
			return;
		}
		self::write( self::path_delete( $stored, $path ) );
		do_action( 'fnfe_setting_updated', $path, self::plain_get( $path ), $old );
	}

	/**
	 * Localized text lookup. Order: explicit value for $lang -> translation of the default through the multilingual
	 * plugin -> default ('_') -> $default.
	 *
	 * @param string $path    Dot path.
	 * @param string $lang    Language, '' = I18n::current().
	 * @param string $default Returned when nothing is set.
	 */
	public static function text( string $path, string $lang = '', string $default = '' ): string {
		$text = self::localize( self::get( $path ), $lang, 'setting:' . $path );
		return $text === '' ? $default : $text;
	}

	/**
	 * Same resolution for an already loaded localized value (form configs).
	 *
	 * @param mixed  $value String or localized array.
	 * @param string $lang  Language, '' = I18n::current().
	 * @param string $name  Unique string name for the translation lookup, '' = no lookup.
	 */
	public static function localize( $value, string $lang = '', string $name = '' ): string {
		if ( is_scalar( $value ) && ! is_bool( $value ) ) {
			$value = [ '_' => (string) $value ];
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$lang = $lang === '' ? I18n::current() : $lang;
		if ( $lang !== '_' && isset( $value[ $lang ] ) && is_string( $value[ $lang ] ) && trim( $value[ $lang ] ) !== '' ) {
			return $value[ $lang ];
		}
		$base = isset( $value['_'] ) && is_string( $value['_'] ) ? $value['_'] : '';
		if ( trim( $base ) === '' ) {
			return '';
		}
		if ( $name !== '' ) {
			$translated = I18n::translate_string( $base, $name, $lang );
			if ( $translated !== '' && $translated !== $base ) {
				return $translated;
			}
		}
		return $base;
	}

	/**
	 * Secret value: constant > overlay > decrypted store > ''.
	 *
	 * @param string $path Dot path.
	 */
	public static function secret( string $path ): string {
		if ( self::secret_is_constant( $path ) ) {
			$value = (string) constant( self::secret_constant( $path ) );
		} elseif ( array_key_exists( $path, self::$overlay_secrets ) ) {
			$value = self::$overlay_secrets[ $path ];
		} else {
			$store = self::secret_store();
			$value = isset( $store[ $path ] ) ? Crypto::decrypt( (string) $store[ $path ] ) : '';
		}
		Logger::scrub( $value );
		return $value;
	}

	/**
	 * A value exists (it may still be unreadable, see secret_is_broken()).
	 *
	 * @param string $path Dot path.
	 */
	public static function has_secret( string $path ): bool {
		if ( self::secret_is_constant( $path ) ) {
			return true;
		}
		if ( array_key_exists( $path, self::$overlay_secrets ) ) {
			return self::$overlay_secrets[ $path ] !== '';
		}
		$store = self::secret_store();
		return ! empty( $store[ $path ] );
	}

	/**
	 * Store a secret; '' deletes it.
	 *
	 * @param string $path  Dot path.
	 * @param string $value Clear text.
	 */
	public static function set_secret( string $path, string $value ): void {
		$store = self::secret_store();
		if ( $value === '' ) {
			if ( ! array_key_exists( $path, $store ) ) {
				return;
			}
			unset( $store[ $path ] );
		} else {
			Logger::scrub( $value );
			$store[ $path ] = Crypto::encrypt( $value );
		}
		update_option( self::SECRETS, $store, false );
	}

	/**
	 * Name of the wp-config constant for a path.
	 *
	 * @param string $path Dot path.
	 */
	public static function secret_constant( string $path ): string {
		return 'FNFE_' . strtoupper( str_replace( [ '.', '-' ], '_', $path ) );
	}

	/**
	 * The constant is defined and not empty.
	 *
	 * @param string $path Dot path.
	 */
	public static function secret_is_constant( string $path ): bool {
		$name = self::secret_constant( $path );
		return defined( $name ) && is_scalar( constant( $name ) ) && (string) constant( $name ) !== '';
	}

	/**
	 * Mask for the UI: six bullets and the last four characters, '' when unset.
	 *
	 * @param string $path Dot path.
	 */
	public static function masked( string $path ): string {
		if ( ! self::has_secret( $path ) ) {
			return '';
		}
		$value = self::secret( $path );
		// A short value would be given away by its last four characters.
		return '••••••' . ( mb_strlen( $value ) < 8 ? '' : mb_substr( $value, -4 ) );
	}

	/**
	 * A stored value that cannot be decrypted any more (salts changed).
	 *
	 * @param string $path Dot path.
	 */
	public static function secret_is_broken( string $path ): bool {
		$store = self::secret_store();
		return ! self::secret_is_constant( $path ) && ! empty( $store[ $path ] ) && Crypto::decrypt( (string) $store[ $path ] ) === '';
	}

	/**
	 * Paths in the secret store.
	 *
	 * @param string $prefix Only paths that start with this.
	 * @return string[]
	 */
	public static function secret_paths( string $prefix = '' ): array {
		$paths = array_map( 'strval', array_keys( self::secret_store() ) );
		return array_values(
			array_filter(
				$paths,
				static function ( string $path ) use ( $prefix ): bool {
					return $prefix === '' || strncmp( $path, $prefix, strlen( $prefix ) ) === 0;
				}
			)
		);
	}

	/**
	 * Request-scoped overlay so "Test" buttons work with unsaved values. Never persisted.
	 *
	 * @param array $values  Path => value.
	 * @param array $secrets Path => string.
	 */
	public static function overlay( array $values, array $secrets = [] ): void {
		foreach ( $values as $path => $value ) {
			self::$overlay[ (string) $path ] = $value;
		}
		foreach ( $secrets as $path => $secret ) {
			self::$overlay_secrets[ (string) $path ] = (string) $secret;
		}
	}

	/** Remove the overlay. */
	public static function clear_overlay(): void {
		self::$overlay         = [];
		self::$overlay_secrets = [];
	}

	/**
	 * Moves raw addresses of 'secret' kind receivers into the secret store; returns the receivers with placeholders.
	 * Receivers of other channels are returned unchanged.
	 *
	 * @param string $channel   Channel id.
	 * @param array  $receivers Receivers.
	 */
	public static function store_receiver_secrets( string $channel, array $receivers ): array {
		$target = Registry::channel( $channel );
		if ( ! $target || ( $target->capabilities()['receiver_kind'] ?? '' ) !== 'secret' ) {
			return $receivers;
		}
		foreach ( $receivers as $i => $receiver ) {
			$address = is_array( $receiver ) ? (string) ( $receiver['address'] ?? '' ) : '';
			if ( $address === '' || self::is_placeholder( $address ) ) {
				continue;
			}
			$id = (string) ( $receiver['id'] ?? '' );
			if ( $id === '' ) {
				$id                    = Text::uid( 'r_' );
				$receivers[ $i ]['id'] = $id;
			}
			self::set_secret( "channels.$channel.receiver_secrets.$id", $address );
			$receivers[ $i ]['address'] = self::PLACEHOLDER . $id;
		}
		return $receivers;
	}

	/**
	 * Delete receiver secrets that no stored receiver points to any more.
	 *
	 * @param string $channel Channel id.
	 */
	public static function prune_receiver_secrets( string $channel ): void {
		$prefix = "channels.$channel.receiver_secrets.";
		$paths  = self::secret_paths( $prefix );
		if ( ! $paths ) {
			return;
		}
		$haystack = wp_json_encode( self::stored(), JSON_UNESCAPED_SLASHES ) . FormRepository::raw_configs_containing( self::PLACEHOLDER );
		foreach ( $paths as $path ) {
			if ( strpos( $haystack, '"' . self::PLACEHOLDER . substr( $path, strlen( $prefix ) ) . '"' ) === false ) {
				self::set_secret( $path, '' );
			}
		}
	}

	/**
	 * Delete the secrets of repeater rows that do not exist any more.
	 *
	 * @param string $path Repeater path.
	 * @param array  $rows Remaining rows.
	 */
	public static function prune_row_secrets( string $path, array $rows ): void {
		$ids = array_map( 'strval', array_column( $rows, 'id' ) );
		foreach ( self::secret_paths( $path . '.' ) as $secret ) {
			$row = explode( '.', substr( $secret, strlen( $path ) + 1 ) )[0];
			if ( ! in_array( $row, $ids, true ) ) {
				self::set_secret( $secret, '' );
			}
		}
	}

	/**
	 * The address points into the secret store.
	 *
	 * @param string $address Receiver address.
	 */
	public static function is_placeholder( string $address ): bool {
		return (bool) preg_match( '/^secret:[A-Za-z0-9_]+$/', $address );
	}

	/** Forget everything read from the database. */
	public static function flush_cache(): void {
		self::$stored = [];
		self::$merged = [];
		Defaults::flush();
	}

	/**
	 * Read a dot path.
	 *
	 * @param array  $tree  Nested array.
	 * @param string $path  Dot path.
	 * @param bool   $found Set to whether the path exists.
	 * @return mixed
	 */
	public static function path_get( array $tree, string $path, &$found = null ) {
		$found = false;
		$node  = $tree;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		$found = true;
		return $node;
	}

	/**
	 * Copy of a tree with a dot path set.
	 *
	 * @param array  $tree  Nested array.
	 * @param string $path  Dot path.
	 * @param mixed  $value Value.
	 */
	public static function path_set( array $tree, string $path, $value ): array {
		$node = &$tree;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) ) {
				$node = [];
			}
			if ( ! array_key_exists( $key, $node ) ) {
				$node[ $key ] = [];
			}
			$node = &$node[ $key ];
		}
		$node = $value;
		unset( $node );
		return $tree;
	}

	/**
	 * Copy of a tree without a dot path; parents that become empty are removed too.
	 *
	 * @param array  $tree Nested array.
	 * @param string $path Dot path.
	 */
	public static function path_delete( array $tree, string $path ): array {
		$parts = explode( '.', $path, 2 );
		if ( ! array_key_exists( $parts[0], $tree ) ) {
			return $tree;
		}
		if ( ! isset( $parts[1] ) ) {
			unset( $tree[ $parts[0] ] );
			return $tree;
		}
		if ( is_array( $tree[ $parts[0] ] ) ) {
			$before = $tree[ $parts[0] ];
			$child  = self::path_delete( $before, $parts[1] );
			if ( $child === [] && $before !== [] ) {
				unset( $tree[ $parts[0] ] );
			} else {
				$tree[ $parts[0] ] = $child;
			}
		}
		return $tree;
	}

	/**
	 * Deep merge. Lists and localized texts of $over replace the ones of $base.
	 *
	 * @param array $base Defaults.
	 * @param array $over Stored values.
	 */
	public static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			$mergeable = is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] )
				&& ! self::is_list( $value ) && ! self::is_list( $base[ $key ] )
				&& ! array_key_exists( '_', $value ) && ! array_key_exists( '_', $base[ $key ] );
			$base[ $key ] = $mergeable ? self::merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/**
	 * Numeric array (an empty array counts as a list).
	 *
	 * @param array $value Array.
	 */
	private static function is_list( array $value ): bool {
		return $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Stored value or default, overlay ignored.
	 *
	 * @param string $path Dot path.
	 * @return mixed
	 */
	private static function plain_get( string $path ) {
		$overlay       = self::$overlay;
		self::$overlay = [];
		$value         = self::get( $path );
		self::$overlay = $overlay;
		return $value;
	}

	/**
	 * Copy of the stored tree with a value; default values are not stored.
	 *
	 * @param array  $stored Stored tree.
	 * @param string $path   Dot path.
	 * @param mixed  $value  Value.
	 */
	private static function with_value( array $stored, string $path, $value ): array {
		$default = self::path_get( Defaults::all(), $path, $found );
		return $found && $default === $value
			? self::path_delete( $stored, $path )
			: self::path_set( $stored, $path, $value );
	}

	/**
	 * Save the option.
	 *
	 * @param array $stored Tree.
	 */
	private static function write( array $stored ): void {
		update_option( self::OPTION, $stored, true );
		self::$stored = [];
		self::$merged = [];
	}

	/** Stored option of the current site. */
	private static function stored(): array {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			foreach ( [ 'add_option_', 'update_option_', 'delete_option_' ] as $hook ) {
				add_action( $hook . self::OPTION, [ self::class, 'flush_cache' ], 0, 0 );
			}
		}
		$blog = get_current_blog_id();
		if ( ! isset( self::$stored[ $blog ] ) ) {
			$stored                = get_option( self::OPTION, [] );
			self::$stored[ $blog ] = is_array( $stored ) ? $stored : [];
		}
		return self::$stored[ $blog ];
	}

	/** Encrypted secrets of the current site. */
	private static function secret_store(): array {
		$store = get_option( self::SECRETS, [] );
		return is_array( $store ) ? $store : [];
	}
}
