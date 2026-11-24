<?php
/**
 * Facts about the submitting request.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Elementor;

use AmirhpCom\FormsNotifier\Core\Request;

defined( 'ABSPATH' ) || exit;

/** Meta every adapter adds to a draft. */
final class RequestMeta {

	private const UTM = [ 'source', 'medium', 'campaign', 'term', 'content' ];

	/** Meta of the current form request. */
	public static function from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Elementor's public form endpoint has no nonce by design (pages are cached); the values are facts for the record and are never acted upon.
		$referrer = isset( $_POST['referrer'] ) && is_string( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		$title    = isset( $_POST['referer_title'] ) && is_string( $_POST['referer_title'] ) ? sanitize_text_field( wp_unslash( $_POST['referer_title'] ) ) : '';
		$queried  = isset( $_POST['queried_id'] ) && is_scalar( $_POST['queried_id'] ) ? absint( wp_unslash( $_POST['queried_id'] ) ) : 0;
		// phpcs:enable
		return self::build( $referrer, $title, $queried );
	}

	/**
	 * Meta from known values.
	 *
	 * @param string $referrer   Page address.
	 * @param string $title      Page title.
	 * @param int    $queried_id Post the visitor was on.
	 */
	public static function build( string $referrer, string $title, int $queried_id ): array {
		$url  = remove_query_arg( [ 'preview_id', 'preview_nonce', 'preview' ], esc_url_raw( $referrer, [ 'http', 'https' ] ) );
		$user = wp_get_current_user();
		if ( $title === '' && $queried_id > 0 && get_post( $queried_id ) ) {
			$title = get_the_title( $queried_id );
		}
		return [
			'page_url'   => $url,
			'page_title' => FieldNormalizer::label( $title, '', '' ),
			'referrer'   => $url,
			'queried_id' => $queried_id,
			'user_id'    => (int) $user->ID,
			'user_login' => $user->ID ? (string) $user->user_login : '',
			'user_email' => $user->ID ? (string) $user->user_email : '',
			'user_ip'    => Request::ip(),
			'user_agent' => Request::user_agent(),
			'locale'     => determine_locale(),
			'utm'        => self::utm( $url ),
		];
	}

	/**
	 * Campaign parameters of an address.
	 *
	 * @param string $url URL.
	 * @return array<string,string>
	 */
	private static function utm( string $url ): array {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$out = [];
		foreach ( self::UTM as $key ) {
			$value = $query[ 'utm_' . $key ] ?? '';
			if ( is_string( $value ) && $value !== '' ) {
				$out[ $key ] = mb_substr( sanitize_text_field( $value ), 0, 200 );
			}
		}
		return $out;
	}
}
