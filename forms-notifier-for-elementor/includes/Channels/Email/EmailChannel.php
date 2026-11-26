<?php
/**
 * Email channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Email;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Template\Extras;
use AmirhpCom\FormsNotifier\Template\Tags;

defined( 'ABSPATH' ) || exit;

/** Email through wp_mail(), one message per receiver. */
final class EmailChannel extends AbstractChannel {

	/** Total size of the attachments of one email. */
	private const MAX_ATTACHMENTS = 15 * MB_IN_BYTES;

	/** Channel id. */
	public function id(): string {
		return 'email';
	}

	/** Display name. */
	public function label(): string {
		return __( 'Email', 'forms-notifier-for-elementor' );
	}

	/** Tile text. */
	public function description(): string {
		return __( 'Email to your team and an automatic reply to the sender, through the mailer of this site.', 'forms-notifier-for-elementor' );
	}

	/** Group. */
	public function group(): string {
		return 'email';
	}

	/** Icon. */
	public function icon(): string {
		return 'mail';
	}

	/** HTML body. */
	public function format(): string {
		return 'email_html';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'email',
				'submitter'     => true,
				'buttons'       => [ 'url' ],
				'attachments'   => true,
				'subject'       => true,
			]
		);
	}

	/** Upload limit per file. */
	public function max_upload_bytes(): int {
		return self::MAX_ATTACHMENTS;
	}

	/** Sender and layout. */
	protected function own_sections(): array {
		return [
			[
				'id'     => 'email_sender',
				'title'  => __( 'Sender and layout', 'forms-notifier-for-elementor' ),
				'fields' => [
					$this->field(
						'from_name',
						'text',
						__( 'Sender name', 'forms-notifier-for-elementor' ),
						[
							'width' => 'half',
							'desc'  => __( 'Empty: the default of WordPress.', 'forms-notifier-for-elementor' ),
						]
					),
					$this->field(
						'from_email',
						'email',
						__( 'Sender address', 'forms-notifier-for-elementor' ),
						[
							'width' => 'half',
							'desc'  => __( 'Use an address of your own domain, otherwise messages may land in spam.', 'forms-notifier-for-elementor' ),
						]
					),
					$this->field( 'reply_to_submitter', 'toggle', __( 'Replies go to the person who sent the form', 'forms-notifier-for-elementor' ), [ 'default' => true ] ),
					$this->field(
						'layout',
						'radio',
						__( 'Layout', 'forms-notifier-for-elementor' ),
						[
							'default' => 'branded',
							'options' => [
								[
									'value' => 'branded',
									'label' => __( 'Card', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'A card with the name of your site.', 'forms-notifier-for-elementor' ),
								],
								[
									'value' => 'plain',
									'label' => __( 'Plain', 'forms-notifier-for-elementor' ),
									'desc'  => __( 'Only the message.', 'forms-notifier-for-elementor' ),
								],
							],
						]
					),
				],
			],
		];
	}

	/**
	 * Email address.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		$raw   = trim( $raw );
		$email = sanitize_email( $raw );
		return $email === $raw && is_email( $email ) ? $email : '';
	}

	/**
	 * Filter fnfe_message: the reply address, read while the submission is at hand.
	 *
	 * @param Message    $message  Message.
	 * @param Delivery   $delivery Delivery.
	 * @param Submission $s        Submission.
	 */
	public static function attach_reply_to( $message, $delivery, $s ) {
		if ( $message instanceof Message && $message->channel === 'email' && $message->purpose === 'admin' && $s instanceof Submission ) {
			$email = Tags::submitter( 'email', $s, $s->form_id ? FormRepository::config( $s->form_id ) : null );
			if ( is_email( $email ) ) {
				$message->meta['reply_to_email'] = $email;
			}
		}
		return $message;
	}

	/**
	 * Send one message.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		$error   = '';
		$capture = static function ( $failure ) use ( &$error ): void {
			if ( $failure instanceof \WP_Error ) {
				$error = $failure->get_error_message();
			}
		};
		$plain   = $message->plain;
		$alt     = static function ( $mailer ) use ( $plain ): void {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			$mailer->AltBody = $plain;
		};

		try {
			$to = $this->normalize_address( $message->recipient->address );
			if ( $to === '' ) {
				return $this->fail( __( 'The email address is not valid.', 'forms-notifier-for-elementor' ), true );
			}
			// Files over the limit become links of this send only; the caller's message stays as it is.
			$message = clone $message;
			$files   = $this->attach( $message );
			$subject = self::line( $message->subject );
			if ( $subject === '' ) {
				$subject = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			}
			$html = $this->html( $message, $subject );

			add_action( 'wp_mail_failed', $capture );
			add_action( 'phpmailer_init', $alt, PHP_INT_MAX );
			$sent = wp_mail( $to, $subject, $html, $this->headers( $message ), $files );
			if ( $sent ) {
				return $this->ok( __( 'Accepted by the mailer of this site.', 'forms-notifier-for-elementor' ) );
			}
			return $this->fail( $error === '' ? __( 'The mailer of this site did not accept the message.', 'forms-notifier-for-elementor' ) : Logger::clean( $error ) );
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		} finally {
			remove_action( 'wp_mail_failed', $capture );
			remove_action( 'phpmailer_init', $alt, PHP_INT_MAX );
		}
	}

	/**
	 * Complete HTML document.
	 *
	 * @param Message $message Message.
	 * @param string  $subject Subject.
	 */
	private function html( Message $message, string $subject ): string {
		$lang = $message->lang === '' ? I18n::current() : $message->lang;
		return EmailLayout::wrap(
			Extras::compose( $message ),
			[
				'layout'  => (string) $this->setting( 'layout', 'branded' ),
				'subject' => $subject,
				'site'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'     => home_url( '/' ),
				'lang'    => $lang,
				'rtl'     => I18n::is_rtl( $lang ),
				'buttons' => Extras::url_buttons( $message, 10 ),
			]
		);
	}

	/**
	 * Headers.
	 *
	 * @param Message $message Message.
	 * @return string[]
	 */
	private function headers( Message $message ): array {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$name    = str_replace( [ '"', '<', '>' ], '', self::line( (string) $this->setting( 'from_name' ) ) );
		$email   = sanitize_email( (string) $this->setting( 'from_email' ) );
		if ( is_email( $email ) ) {
			$headers[] = 'From: ' . ( $name === '' ? $email : $name . ' <' . $email . '>' );
		} elseif ( $name !== '' ) {
			$headers[] = 'From: ' . $name . ' <' . self::default_sender() . '>';
		}
		if ( $message->purpose === 'admin' && $this->bool( 'reply_to_submitter', true ) ) {
			$reply = sanitize_email( (string) ( $message->meta['reply_to_email'] ?? '' ) );
			if ( is_email( $reply ) ) {
				$headers[] = 'Reply-To: ' . $reply;
			}
		}
		return $headers;
	}

	/**
	 * Files to attach: file name => path. Files over the total limit become links.
	 *
	 * @param Message $message Message.
	 */
	private function attach( Message $message ): array {
		$out  = [];
		$used = 0;
		foreach ( $message->attachments as $file ) {
			$size = $file->exists() ? (int) filesize( $file->path ) : 0;
			$safe = $file->exists() && ( ! class_exists( PathGuard::class ) || PathGuard::is_safe( $file->path ) );
			if ( $safe && $used + $size <= self::MAX_ATTACHMENTS ) {
				$name = self::line( $file->name === '' ? basename( $file->path ) : $file->name );
				for ( $i = 2; isset( $out[ $name ] ); $i++ ) {
					$name = $i . '-' . self::line( $file->name );
				}
				$out[ $name ] = $file->path;
				$used        += $size;
				continue;
			}
			$url = $file->id ? Storage::link( $file, Settings::int( 'attachments.link_ttl_days', 30 ) ) : $file->url;
			if ( $url !== '' ) {
				$message->links[] = [
					'name' => $file->name,
					'url'  => $url,
					'size' => $file->size,
				];
			}
		}
		return $out;
	}

	/** Sender address WordPress would use. */
	private static function default_sender(): string {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = strncmp( $host, 'www.', 4 ) === 0 ? substr( $host, 4 ) : $host;
		return (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress filter.
	}

	/**
	 * One line without control characters.
	 *
	 * @param string $text Text.
	 */
	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
	}
}
