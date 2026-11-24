<?php
/**
 * WhatsApp gateway base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\WhatsApp\Providers;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\AbstractProvider;
use AmirhpCom\FormsNotifier\Channels\Sms\GatewayHelpers;
use AmirhpCom\FormsNotifier\Contracts\WhatsAppProviderInterface;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** Shared behaviour: text in parts, then the files; a file that cannot be sent becomes a link. */
abstract class AbstractWhatsAppProvider extends AbstractProvider implements WhatsAppProviderInterface {

	use GatewayHelpers;

	/** Longest text of one message. */
	protected const MAX_TEXT = 4096;

	/** Files above this size are sent as links. */
	protected const MAX_FILE = 16777216;

	/** Channel id. */
	protected function channel(): string {
		return 'whatsapp';
	}

	/** Region. */
	public function region(): string {
		return 'international';
	}

	/** Approved templates. */
	public function supports_template(): bool {
		return false;
	}

	/** Media. */
	public function supports_media(): bool {
		return false;
	}

	/**
	 * Send one message.
	 *
	 * @param string  $to      E.164.
	 * @param Message $message Message.
	 */
	public function send( string $to, Message $message ): SendResult {
		if ( ! $this->is_configured() ) {
			return $this->fail( __( 'The gateway is not fully set up.', 'forms-notifier-for-elementor' ), true );
		}
		$code = trim( (string) ( $message->pattern['code'] ?? '' ) );
		if ( $code !== '' && $this->supports_template() ) {
			$result = $this->send_template( $to, $code, $message );
			// A template cannot carry files.
			return $message->attachments ? $result->with_meta( [ 'files_skipped' => count( $message->attachments ) ] ) : $result;
		}

		$files = $this->supports_media() ? $message->attachments : [];
		$links = $this->links( $message, $this->supports_media() ? [] : $message->attachments );
		$text  = trim( $message->body );
		$body  = $this->with_links( $text, $links );
		if ( $body === '' && ! $files ) {
			return $this->fail( __( 'There is no normal text for this gateway.', 'forms-notifier-for-elementor' ), true );
		}

		$first = null;
		$ids   = [];
		if ( $body !== '' ) {
			$parts = $this->parts( $body );
			foreach ( $parts as $i => $part ) {
				$result = $this->send_text( $to, $part, $message );
				if ( ! $result->ok ) {
					if ( $i === 0 ) {
						return $result;
					}
					// A retry would repeat the parts that arrived.
					$result->permanent = true;
					$result->error     = sprintf(
						/* translators: %1$d: number of parts sent, %2$d: number of parts, %3$s: error text */
						__( 'Only %1$d of %2$d parts were sent: %3$s', 'forms-notifier-for-elementor' ),
						$i,
						count( $parts ),
						$result->error
					);
					return $result;
				}
				$first = $first ?? $result;
				$ids[] = $result->remote_id;
			}
		}
		return $this->add_files( $to, $files, $message, $first, $ids );
	}

	/**
	 * Send one part of a text.
	 *
	 * @param string  $to      E.164.
	 * @param string  $text    Text with WhatsApp marks.
	 * @param Message $message Message.
	 */
	abstract protected function send_text( string $to, string $text, Message $message ): SendResult;

	/**
	 * Send an approved template.
	 *
	 * @param string  $to      E.164.
	 * @param string  $code    Template.
	 * @param Message $message Message.
	 */
	protected function send_template( string $to, string $code, Message $message ): SendResult {
		unset( $to, $code, $message );
		return $this->fail( __( 'This gateway cannot send templates.', 'forms-notifier-for-elementor' ), true );
	}

	/**
	 * Send one file.
	 *
	 * @param string     $to   E.164.
	 * @param Attachment $file File.
	 */
	protected function send_file( string $to, Attachment $file ): SendResult {
		unset( $to, $file );
		return $this->fail( __( 'This gateway cannot send files.', 'forms-notifier-for-elementor' ), true );
	}

	/**
	 * Chat id of gateways that drive a normal account.
	 *
	 * @param string $to E.164.
	 */
	protected function chat_id( string $to ): string {
		return Phone::digits( $to ) . '@c.us';
	}

	/** Base URL setting without a trailing slash. */
	protected function base(): string {
		return untrailingslashit( $this->option( 'base' ) );
	}

	/**
	 * Link through which the gateway can download a file, '' when there is none.
	 *
	 * @param Attachment $file File.
	 */
	protected function link( Attachment $file ): string {
		$url = $file->id > 0 && $file->stored ? Storage::link( $file, Settings::int( 'attachments.link_ttl_days', 30 ) ) : $file->url;
		return preg_match( '~^https?://~i', $url ) ? $url : '';
	}

	/**
	 * Base64 content of a local file, '' when it may not be read.
	 *
	 * @param Attachment $file File.
	 */
	protected function base64( Attachment $file ): string {
		if ( ! $this->readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside an allowed directory.
		$content = (string) file_get_contents( $file->path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- File content inside JSON.
		return base64_encode( $content );
	}

	/**
	 * The file exists, is inside an allowed directory and is not too large.
	 *
	 * @param Attachment $file File.
	 */
	protected function readable( Attachment $file ): bool {
		return $file->exists() && PathGuard::is_safe( $file->path ) && (int) filesize( $file->path ) <= static::MAX_FILE;
	}

	/** Result for a file that cannot be read. */
	protected function unreadable(): SendResult {
		return $this->fail( __( 'The file cannot be sent.', 'forms-notifier-for-elementor' ), true );
	}

	/** Base URL field. */
	protected function base_field( string $placeholder, string $desc = '' ): array {
		return $this->field(
			'base',
			'url',
			__( 'Server address', 'forms-notifier-for-elementor' ),
			[
				'placeholder' => $placeholder,
				'desc'        => $desc,
				'attrs'       => [ 'dir' => 'ltr' ],
			]
		);
	}

	/** Warning of gateways that drive a normal account. */
	protected function account_note(): array {
		return [
			'key'   => 'note_account',
			'type'  => 'notice',
			'level' => 'warning',
			'desc'  => __( 'This gateway drives a normal WhatsApp account. WhatsApp\'s terms do not allow that for automated or bulk messages, and the account may be blocked. Use a number you can do without.', 'forms-notifier-for-elementor' ),
		];
	}

	/**
	 * Send files after the text. Once something was sent the result is a success: a retry would repeat it.
	 *
	 * @param string          $to      E.164.
	 * @param Attachment[]    $files   Files.
	 * @param Message         $message Message.
	 * @param SendResult|null $first   Result of the text.
	 * @param string[]        $ids     Remote ids.
	 */
	private function add_files( string $to, array $files, Message $message, ?SendResult $first, array $ids ): SendResult {
		$failed = [];
		$last   = null;
		foreach ( $files as $file ) {
			$result = $this->send_file( $to, $file );
			if ( $result->ok ) {
				$first = $first ?? $result;
				$ids[] = $result->remote_id;
				continue;
			}
			$failed[] = $file;
			$last     = $result;
		}

		// Links of the message itself are in the text already.
		$rest = $this->with_links( '', array_diff_key( $this->links( $message, $failed ), $this->links( $message, [] ) ) );
		if ( $rest !== '' ) {
			$result = $this->send_text( $to, $this->parts( $rest )[0] ?? '', $message );
			if ( $result->ok ) {
				$first = $first ?? $result;
				$ids[] = $result->remote_id;
			} else {
				$last = $result;
			}
		}

		if ( ! $first ) {
			return $last ?? $this->fail( __( 'There is nothing to send.', 'forms-notifier-for-elementor' ), true );
		}
		$first->remote_id = implode( ',', array_filter( $ids, 'strlen' ) );
		$meta             = [ 'parts' => count( $ids ) ];
		if ( $last ) {
			$meta['files_failed'] = array_map(
				static function ( Attachment $file ): string {
					return $file->name;
				},
				$failed
			);
			$meta['file_error']   = $last->error;
		}
		return $first->with_meta( $meta );
	}

	/**
	 * Links of a message and of files that cannot be uploaded: name => URL.
	 *
	 * @param Message      $message Message.
	 * @param Attachment[] $files   Files.
	 */
	private function links( Message $message, array $files ): array {
		$out   = [];
		$known = [];
		foreach ( $message->links as $link ) {
			$url = (string) ( $link['url'] ?? '' );
			if ( $url !== '' ) {
				$out[ $url ]                               = (string) ( $link['name'] ?? '' );
				$known[ (string) ( $link['name'] ?? '' ) ] = true;
			}
		}
		foreach ( $files as $file ) {
			$url = isset( $known[ $file->name ] ) ? '' : $this->link( $file );
			if ( $url !== '' ) {
				$out[ $url ] = $file->name;
			}
		}
		return $out;
	}

	/**
	 * Text with the links that it does not hold yet.
	 *
	 * @param string $text  Text.
	 * @param array  $links URL => name.
	 */
	private function with_links( string $text, array $links ): string {
		foreach ( $links as $url => $name ) {
			if ( strpos( $text, (string) $url ) === false ) {
				$name  = Registry::formatter( 'whatsapp' )->escape( $name );
				$text .= ( $text === '' ? '' : "\n" ) . ( $name === '' ? '' : $name . ': ' ) . $url;
			}
		}
		return $text;
	}

	/**
	 * Parts of a long text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function parts( string $text ): array {
		if ( mb_strlen( $text ) <= static::MAX_TEXT ) {
			return [ $text ];
		}
		$parts = array_values( array_filter( Registry::formatter( 'whatsapp' )->split( $text, static::MAX_TEXT ), 'strlen' ) );
		return $parts ? $parts : [ mb_substr( $text, 0, static::MAX_TEXT ) ];
	}
}
