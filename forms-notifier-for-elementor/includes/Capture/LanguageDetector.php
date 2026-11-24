<?php
/**
 * Language of a submission.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Capture;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** First known language wins: multilingual plugin, visited page, form document, page address, site default. */
final class LanguageDetector {

	/**
	 * Language code of a submission. Filter: fnfe_submission_language.
	 *
	 * @param Submission $s Submission.
	 */
	public static function detect( Submission $s ): string {
		$languages = I18n::languages();
		$lang      = '';

		if ( count( $languages ) > 1 ) {
			$candidates = [
				static function (): string {
					return self::active();
				},
				static function () use ( $s ): string {
					return I18n::post_lang( (int) ( $s->meta['queried_id'] ?? 0 ) );
				},
				static function () use ( $s ): string {
					return I18n::post_lang( $s->post_id );
				},
				static function () use ( $s, $languages ): string {
					return self::from_url( (string) ( $s->meta['page_url'] ?? '' ), array_map( 'strval', array_keys( $languages ) ) );
				},
			];
			foreach ( $candidates as $candidate ) {
				$found = $candidate();
				if ( $found !== '' && isset( $languages[ $found ] ) ) {
					$lang = $found;
					break;
				}
			}
		}
		if ( $lang === '' ) {
			$lang = I18n::default_lang();
		}

		$filtered = apply_filters( 'fnfe_submission_language', $lang, $s );
		return is_string( $filtered ) && isset( $languages[ $filtered ] ) ? $filtered : $lang;
	}

	/**
	 * Language found in an address: directory, sub-domain or lang parameter.
	 *
	 * @param string   $url   URL.
	 * @param string[] $codes Known codes.
	 */
	public static function from_url( string $url, array $codes ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$query = [];
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$path = (string) ( $parts['path'] ?? '' );
		if ( $home !== '' && strncmp( $path, $home . '/', strlen( $home ) + 1 ) === 0 ) {
			$path = substr( $path, strlen( $home ) );
		}

		$found = [
			isset( $query['lang'] ) && is_string( $query['lang'] ) ? $query['lang'] : '',
			explode( '/', ltrim( $path, '/' ) )[0],
			explode( '.', (string) ( $parts['host'] ?? '' ) )[0],
		];
		foreach ( $found as $candidate ) {
			foreach ( $codes as $code ) {
				if ( $candidate !== '' && strcasecmp( $candidate, $code ) === 0 ) {
					return $code;
				}
			}
		}
		return '';
	}

	/** Language the multilingual plugin reports for this request, '' when it reports none. */
	private static function active(): string {
		$lang     = '';
		$provider = I18n::provider();
		if ( $provider === 'wpml' ) {
			$lang = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		} elseif ( $provider === 'polylang' && function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
		}
		return is_string( $lang ) && $lang !== 'all' ? $lang : '';
	}
}
