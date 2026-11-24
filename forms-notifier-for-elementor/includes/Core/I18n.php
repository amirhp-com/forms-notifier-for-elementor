<?php
/**
 * Languages.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Language list, current language, with_language(), string translation lookup. WPML and Polylang aware. */
final class I18n {

	/** String translation context; the same value as I18n\Strings::CONTEXT. */
	private const CONTEXT = 'Forms Notifier for Elementor';

	private const RTL = [ 'ar', 'arc', 'ary', 'azb', 'ckb', 'dv', 'fa', 'glk', 'haz', 'he', 'ku', 'mzn', 'ps', 'sd', 'ug', 'ur', 'yi' ];

	/** Locales whose code is not simply "xx_XX" of the language. */
	private const LOCALES = [
		'ar' => 'ar',
		'cs' => 'cs_CZ',
		'da' => 'da_DK',
		'el' => 'el',
		'en' => 'en_US',
		'fa' => 'fa_IR',
		'fi' => 'fi',
		'he' => 'he_IL',
		'ja' => 'ja',
		'ko' => 'ko_KR',
		'sv' => 'sv_SE',
		'uk' => 'uk',
		'ur' => 'ur',
		'vi' => 'vi',
		'zh' => 'zh_CN',
	];

	/** @var string[] languages of running with_language() calls */
	private static $stack = [];

	/** 'wpml' | 'polylang' | '' */
	public static function provider(): string {
		if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_languages_list' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ) ) {
			return 'wpml';
		}
		return '';
	}

	/**
	 * Languages keyed by code; one entry without a multilingual plugin.
	 *
	 * @return array<string,array{code:string,name:string,locale:string,rtl:bool,default:bool}>
	 */
	public static function languages(): array {
		$provider = self::provider();
		$found    = [];

		if ( $provider === 'wpml' ) {
			$found = self::wpml_languages();
		} elseif ( $provider === 'polylang' ) {
			$found = self::polylang_languages();
		}

		if ( $found ) {
			$default = self::provider_default( $provider );
			if ( ! isset( $found[ $default ] ) ) {
				$default = (string) key( $found );
			}
			$out = [];
			foreach ( $found as $code => $language ) {
				$out[ $code ] = [
					'code'    => (string) $code,
					'name'    => $language['name'] === '' ? (string) $code : $language['name'],
					'locale'  => $language['locale'] === '' ? self::guess_locale( (string) $code ) : $language['locale'],
					'rtl'     => self::is_rtl_code( (string) $code ),
					'default' => (string) $code === $default,
				];
			}
			return $out;
		}

		$locale = self::site_locale();
		$code   = self::code_of( $locale );
		return [
			$code => [
				'code'    => $code,
				'name'    => self::locale_name( $locale ),
				'locale'  => $locale,
				'rtl'     => self::is_rtl_code( $code ),
				'default' => true,
			],
		];
	}

	/** Code of the default language. */
	public static function default_lang(): string {
		foreach ( self::languages() as $code => $language ) {
			if ( $language['default'] ) {
				return (string) $code;
			}
		}
		return self::code_of( self::site_locale() );
	}

	/** Language of the current request (front-end/AJAX language as the multilingual plugin sees it). */
	public static function current(): string {
		if ( self::$stack ) {
			return (string) end( self::$stack );
		}
		$lang     = '';
		$provider = self::provider();
		if ( $provider === 'wpml' ) {
			$lang = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( $provider === 'polylang' && function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
		}
		if ( is_string( $lang ) && $lang !== '' && $lang !== 'all' ) {
			return $lang;
		}
		return self::default_lang();
	}

	/** The site has more than one language. */
	public static function is_multilingual(): bool {
		return count( self::languages() ) > 1;
	}

	/**
	 * 'fa' -> 'fa_IR'
	 *
	 * @param string $lang Language code.
	 */
	public static function locale_of( string $lang ): string {
		$languages = self::languages();
		return isset( $languages[ $lang ] ) ? $languages[ $lang ]['locale'] : self::guess_locale( $lang );
	}

	/**
	 * Right-to-left language.
	 *
	 * @param string $lang Language code.
	 */
	public static function is_rtl( string $lang ): bool {
		return self::is_rtl_code( $lang );
	}

	/**
	 * Language of a post ('' when unknown).
	 *
	 * @param int $post_id Post id.
	 */
	public static function post_lang( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$provider = self::provider();
		if ( $provider === 'wpml' ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			return is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
		}
		if ( $provider === 'polylang' && function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $post_id );
		}
		return '';
	}

	/**
	 * Run $fn with the given language active; always restored.
	 *
	 * @param string   $lang Language code, '' = run as is.
	 * @param callable $fn   Callback.
	 * @return mixed result of $fn
	 */
	public static function with_language( string $lang, callable $fn ) {
		if ( $lang === '' ) {
			return $fn();
		}

		$provider  = self::provider();
		$previous  = '';
		$pll_saved = null;

		if ( $provider === 'wpml' ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			$previous = (string) apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( $provider === 'polylang' && function_exists( 'PLL' ) && PLL() ) {
			$language = PLL()->model->get_language( $lang );
			if ( $language ) {
				$pll_saved = [ PLL()->curlang ?? null ];

				PLL()->curlang = $language;
			}
		}
		self::$stack[] = $lang;
		$switched      = switch_to_locale( self::locale_of( $lang ) );

		try {
			return $fn();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			array_pop( self::$stack );
			if ( $provider === 'wpml' ) {
				do_action( 'wpml_switch_language', $previous === '' ? null : $previous ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			} elseif ( $pll_saved !== null ) {
				PLL()->curlang = $pll_saved[0];
			}
		}
	}

	/**
	 * Translation of a registered user-entered string. Returns $original when no translation exists.
	 *
	 * @param string $original Text.
	 * @param string $name     Registration name (see I18n\Strings::name_for()).
	 * @param string $lang     Language code.
	 */
	public static function translate_string( string $original, string $name, string $lang ): string {
		if ( $original === '' || $name === '' || $lang === '' ) {
			return $original;
		}
		$provider   = self::provider();
		$translated = $original;
		if ( $provider === 'wpml' ) {
			$translated = apply_filters( 'wpml_translate_single_string', $original, self::CONTEXT, $name, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( $provider === 'polylang' && function_exists( 'pll_translate_string' ) ) {
			$translated = pll_translate_string( $original, $lang );
		}
		return is_string( $translated ) && $translated !== '' ? $translated : $original;
	}

	/** Locale of the site, unaffected by the user locale and by switch_to_locale(). */
	public static function site_locale(): string {
		$locale = get_option( 'WPLANG' );
		if ( $locale === false && is_multisite() ) {
			$locale = get_site_option( 'WPLANG' );
		}
		if ( ! is_string( $locale ) || $locale === '' ) {
			$locale = defined( 'WPLANG' ) && WPLANG ? (string) WPLANG : 'en_US';
		}
		return $locale;
	}

	/** WPML languages: code => name, locale. */
	private static function wpml_languages(): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		$rows = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( ! is_array( $rows ) || ! $rows ) {
			// The filter is empty on requests without a main query.
			global $sitepress;
			$rows = is_object( $sitepress ) && method_exists( $sitepress, 'get_active_languages' ) ? $sitepress->get_active_languages() : [];
		}
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $code => $row ) {
			$row          = (array) $row;
			$code         = (string) ( $row['language_code'] ?? $row['code'] ?? $code );
			$out[ $code ] = [
				'name'   => (string) ( $row['native_name'] ?? $row['display_name'] ?? '' ),
				'locale' => (string) ( $row['default_locale'] ?? '' ),
			];
		}
		return $out;
	}

	/** Polylang languages: code => name, locale. */
	private static function polylang_languages(): array {
		$slugs   = (array) pll_languages_list( [ 'fields' => 'slug' ] );
		$names   = (array) pll_languages_list( [ 'fields' => 'name' ] );
		$locales = (array) pll_languages_list( [ 'fields' => 'locale' ] );
		$out     = [];
		foreach ( array_values( $slugs ) as $i => $slug ) {
			$out[ (string) $slug ] = [
				'name'   => (string) ( array_values( $names )[ $i ] ?? '' ),
				'locale' => (string) ( array_values( $locales )[ $i ] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Default language of the multilingual plugin.
	 *
	 * @param string $provider Provider id.
	 */
	private static function provider_default( string $provider ): string {
		if ( $provider === 'wpml' ) {
			return (string) apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		}
		return function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
	}

	/**
	 * Locale for a code that is not in the language list.
	 *
	 * @param string $lang Language code.
	 */
	private static function guess_locale( string $lang ): string {
		$lang = strtolower( str_replace( '_', '-', $lang ) );
		if ( isset( self::LOCALES[ $lang ] ) ) {
			return self::LOCALES[ $lang ];
		}
		$parts = explode( '-', $lang );
		if ( isset( $parts[1] ) ) {
			return $parts[0] . '_' . strtoupper( $parts[1] );
		}
		foreach ( get_available_languages() as $locale ) {
			if ( self::code_of( $locale ) === $lang ) {
				return $locale;
			}
		}
		return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang . '_' . strtoupper( $lang ) : self::site_locale();
	}

	/**
	 * First part of a locale, lowercased.
	 *
	 * @param string $locale Locale.
	 */
	private static function code_of( string $locale ): string {
		return strtolower( explode( '_', str_replace( '-', '_', $locale ) )[0] );
	}

	/**
	 * Right-to-left language code.
	 *
	 * @param string $lang Language code.
	 */
	private static function is_rtl_code( string $lang ): bool {
		return in_array( self::code_of( $lang ), self::RTL, true );
	}

	/**
	 * Native name of a locale without any remote request.
	 *
	 * @param string $locale Locale.
	 */
	private static function locale_name( string $locale ): string {
		if ( $locale === 'en_US' ) {
			return 'English (United States)';
		}
		$known = get_site_transient( 'available_translations' );
		if ( is_array( $known ) && ! empty( $known[ $locale ]['native_name'] ) ) {
			return (string) $known[ $locale ]['native_name'];
		}
		if ( function_exists( 'locale_get_display_language' ) ) {
			$name = locale_get_display_language( $locale, $locale );
			if ( is_string( $name ) && $name !== '' ) {
				return $name;
			}
		}
		return $locale;
	}
}
