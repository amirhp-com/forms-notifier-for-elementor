<?php
/**
 * File downloads.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Repository\FileRepository;

defined( 'ABSPATH' ) || exit;

/** /file/{id}: signed links, or a session that may view submissions. */
final class FilesController extends Controller {

	/** Downloads per minute and address. */
	private const RATE = 60;

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/file/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'download' ],
				'permission_callback' => [ $this, 'may_download' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					't'  => $this->string_arg( '', [], 'sanitize_key' ),
					'e'  => [
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Signature or capability. Failed attempts count against the rate limit as well.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function may_download( \WP_REST_Request $request ) {
		if ( Capabilities::can( Capabilities::SUBMISSIONS ) ) {
			return true;
		}
		if ( ! RateLimit::hit( 'file_' . md5( Request::ip() ), self::RATE, MINUTE_IN_SECONDS ) ) {
			return $this->error( 'rate_limited', __( 'Too many downloads. Try again in a minute.', 'forms-notifier-for-elementor' ), 429 );
		}
		if ( ! Storage::verify( (int) $request['id'], (string) $request['t'], (int) $request['e'] ) ) {
			return $this->error( 'bad_signature', __( 'This link is not valid or has expired.', 'forms-notifier-for-elementor' ), 403 );
		}
		return true;
	}

	/**
	 * Stream a file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function download( \WP_REST_Request $request ) {
		$file = FileRepository::find( (int) $request['id'] );
		if ( ! $file || ! $file->exists() || ! PathGuard::is_safe( $file->path ) ) {
			return $this->error( 'file_not_found', __( 'This file does not exist any more.', 'forms-notifier-for-elementor' ), 404 );
		}

		$response = new \WP_REST_Response( null, 200 );
		foreach ( self::headers( $file ) as $name => $value ) {
			$response->header( $name, $value );
		}

		$path = $file->path;
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $served_request ) use ( $response, $request, $path ) {
				if ( $served || $served_request !== $request || ! $result instanceof \WP_REST_Response || $result->get_headers() !== $response->get_headers() ) {
					return $served;
				}
				while ( ob_get_level() > 0 && ob_end_clean() ) {
					continue;
				}
				if ( $request->get_method() !== 'HEAD' ) {
					readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a file that may be large.
				}
				return true;
			},
			10,
			3
		);
		return $response;
	}

	/**
	 * Headers of a download.
	 *
	 * @param Attachment $file File.
	 * @return array<string,string>
	 */
	private static function headers( Attachment $file ): array {
		$mime  = $file->is_image() || $file->mime === 'application/pdf' ? $file->mime : 'application/octet-stream';
		$name  = trim( (string) preg_replace( '/[\x00-\x1F\x7F"\\\\\/]+/', '_', $file->name === '' ? 'file-' . $file->id : $file->name ) );
		$ascii = (string) preg_replace( '/[^A-Za-z0-9._\- ]+/', '_', $name );
		return [
			'Content-Type'            => $mime,
			'Content-Length'          => (string) filesize( $file->path ),
			'Content-Disposition'     => sprintf( 'attachment; filename="%1$s"; filename*=UTF-8\'\'%2$s', $ascii, rawurlencode( $name ) ),
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => "default-src 'none'; sandbox",
			'Cache-Control'           => 'private, no-store',
			'X-Robots-Tag'            => 'noindex',
			'Referrer-Policy'         => 'no-referrer',
		];
	}
}
