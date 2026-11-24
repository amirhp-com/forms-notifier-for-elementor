<?php
/**
 * WhatsApp Cloud API.
 *
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Core\Http;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

/** Official Cloud API: text inside the 24 hour window, approved templates, uploaded media. */
final class MetaCloudApi extends AbstractWhatsAppProvider {

	/** Id. */
	public function id(): string {
		return 'meta';
	}

	/** Name. */
	public function label(): string {
		return 'WhatsApp Cloud API (Meta)';
	}

	/** Website. */
	public function panel_url(): string {
		return 'https://developers.facebook.com/apps/';
	}

	/** Fields. */
	public function fields(): array {
		return [
			$this->field( 'phone_number_id', 'text', __( 'Phone number ID', 'forms-notifier-for-elementor' ), [ 'attrs' => [ 'dir' => 'ltr' ] ] ),
			$this->field( 'token', 'secret', __( 'Permanent access token', 'forms-notifier-for-elementor' ) ),
			$this->field(
				'graph_version',
				'text',
				__( 'Graph API version', 'forms-notifier-for-elementor' ),
				[
					'default' => 'v27.0',
					'width'   => 'half',
					'attrs'   => [ 'dir' => 'ltr' ],
				]
			),
			$this->field(
				'template_lang',
				'text',
				__( 'Language of templates', 'forms-notifier-for-elementor' ),
				[
					'default' => 'en_US',
					'desc'    => __( 'Used when the pattern text has no lang line.', 'forms-notifier-for-elementor' ),
					'width'   => 'half',
					'attrs'   => [ 'dir' => 'ltr' ],
				]
			),
			$this->note( __( 'Free text reaches a person only within 24 hours after that person wrote to you. For other messages write pattern text that starts with template:NAME. Numbered variables are sent in order, named ones by name.', 'forms-notifier-for-elementor' ) ),
		];
	}

	/** Required. */
	protected function needs(): array {
		return [ 'phone_number_id', 'token' ];
	}

	/** Templates. */
	public function supports_template(): bool {
		return true;
	}

	/** Media. */
	public function supports_media(): bool {
		return true;
	}

	/** Connection check. */
	public function status(): ?string {
		$r    = $this->http( 'GET', $this->url( '' ), [ 'headers' => $this->headers() ] );
		$name = $this->first_text( $r['json'], [ 'verified_name', 'display_phone_number', 'id' ] );
		if ( $r['ok'] && $name !== '' ) {
			/* translators: %1$s: account name or phone number */
			return sprintf( __( 'Connected: %1$s', 'forms-notifier-for-elementor' ), $name );
		}
		return $this->result( $r )->error;
	}

	/** Text. */
	protected function send_text( string $to, string $text, Message $message ): SendResult {
		return $this->call(
			$to,
			[
				'type' => 'text',
				'text' => [
					'preview_url' => empty( $message->options['disable_preview'] ),
					'body'        => $text,
				],
			]
		);
	}

	/** Template. */
	protected function send_template( string $to, string $code, Message $message ): SendResult {
		$vars       = (array) ( $message->pattern['vars'] ?? [] );
		$named      = ! PatternText::is_positional( $vars );
		$parameters = [];
		if ( ! $named ) {
			ksort( $vars );
		}
		foreach ( $vars as $name => $value ) {
			$parameter = [ 'type' => 'text' ];
			if ( $named ) {
				$parameter['parameter_name'] = (string) $name;
			}
			// Parameters may not hold line breaks.
			$parameter['text'] = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
			$parameters[]      = $parameter;
		}
		$lang = trim( (string) ( $message->pattern['lang'] ?? '' ) );
		if ( $lang === '' ) {
			$lang = $this->option( 'template_lang' );
		}
		$template = [
			'name'     => $code,
			'language' => [ 'code' => $lang === '' ? 'en_US' : $lang ],
		];
		if ( $parameters ) {
			$template['components'] = [
				[
					'type'       => 'body',
					'parameters' => $parameters,
				],
			];
		}
		return $this->call(
			$to,
			[
				'type'     => 'template',
				'template' => $template,
			]
		);
	}

	/** Upload, then send as image or document. */
	protected function send_file( string $to, Attachment $file ): SendResult {
		$image = in_array( $file->mime, [ 'image/jpeg', 'image/png' ], true ) && $file->size <= 5242880;
		$media = [];
		if ( $this->readable( $file ) ) {
			list( $body, $type ) = Http::multipart(
				[
					'messaging_product' => 'whatsapp',
					'type'              => $file->mime,
				],
				[
					'file' => [
						'path' => $file->path,
						'name' => $file->name,
						'mime' => $file->mime,
					],
				]
			);
			$r                   = $this->http(
				'POST',
				$this->url( '/media' ),
				[
					'body'    => $body,
					'headers' => [ 'Content-Type' => $type ] + $this->headers(),
				]
			);
			$id                  = $this->text_at( $r['json'], 'id' );
			if ( ! $r['ok'] || $id === '' ) {
				return $this->result( $r );
			}
			$media['id'] = $id;
		} else {
			$link = $this->link( $file );
			if ( $link === '' ) {
				return $this->unreadable();
			}
			$media['link'] = $link;
		}
		if ( ! $image ) {
			$media['filename'] = $file->name;
		}
		$kind = $image ? 'image' : 'document';
		return $this->call(
			$to,
			[
				'type' => $kind,
				$kind  => $media,
			]
		);
	}

	/** Headers. */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret( 'token' ),
			'Accept'        => 'application/json',
		];
	}

	/**
	 * URL.
	 *
	 * @param string $path Path below the phone number.
	 */
	private function url( string $path ): string {
		$version = $this->option( 'graph_version' );
		return 'https://graph.facebook.com/' . ( preg_match( '/^v\d+\.\d+$/', $version ) ? $version : 'v27.0' ) . '/' . rawurlencode( $this->option( 'phone_number_id' ) ) . $path;
	}

	/**
	 * Send.
	 *
	 * @param string $to   E.164.
	 * @param array  $body Body.
	 */
	private function call( string $to, array $body ): SendResult {
		return $this->result(
			$this->post_json(
				$this->url( '/messages' ),
				[
					'messaging_product' => 'whatsapp',
					'recipient_type'    => 'individual',
					'to'                => $to,
				] + $body,
				$this->headers()
			)
		);
	}

	/**
	 * Result. 130429, 131056 and 80007 are rate limits, 131000 and 131016 service errors.
	 *
	 * @param array $r Response.
	 */
	private function result( array $r ): SendResult {
		$id    = $this->text_at( $r['json'], 'messages.0.id' );
		$code  = (int) $this->dig( $r['json'], 'error.code' );
		$error = $this->first_text( $r['json'], [ 'error.error_data.details', 'error.message' ] );
		if ( $code ) {
			$error = trim( '#' . $code . ' ' . $error );
		}
		if ( $code === 131047 ) {
			$error .= ' ' . __( 'The person did not write to you in the last 24 hours: send an approved template instead (pattern text that starts with template:NAME).', 'forms-notifier-for-elementor' );
		}
		return $this->outcome( $r, $id !== '' && ! $code, $id, $error, $code ? ! in_array( $code, [ 130429, 131056, 80007, 131000, 131016 ], true ) : null );
	}
}
