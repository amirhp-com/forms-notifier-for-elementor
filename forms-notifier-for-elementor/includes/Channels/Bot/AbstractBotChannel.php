<?php
/**
 * Bot channel base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

use AmirhpCom\FormsNotifier\Attachments\PathGuard;
use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\AbstractChannel;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\State;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Repository\FormRepository;

defined( 'ABSPATH' ) || exit;

/** Send pipeline shared by Telegram, Bale and Soroush Plus. */
abstract class AbstractBotChannel extends AbstractChannel {

	/** Microseconds between two requests of one send. */
	private const PAUSE = 50000;

	/** Most microseconds slept in one send. */
	private const MAX_PAUSE = 2000000;

	/** @var int microseconds slept in this send */
	private $slept = 0;

	/** @var int requests of this send */
	private $requests = 0;

	/** Group of the Channels screen. */
	public function group(): string {
		return 'messenger';
	}

	/** Icon key. */
	public function icon(): string {
		return 'robot';
	}

	/** Feature flags. */
	public function capabilities(): array {
		return array_merge(
			parent::capabilities(),
			[
				'receiver_kind' => 'chat',
				'buttons'       => [ 'url', 'callback', 'web_app', 'copy' ],
				'attachments'   => true,
				'discover'      => true,
			]
		);
	}

	/**
	 * Canonical chat address.
	 *
	 * @param string $raw     Raw address.
	 * @param array  $context Hints.
	 */
	public function normalize_address( string $raw, array $context = [] ): string {
		unset( $context );
		return BotClient::normalize_address( $raw );
	}

	/** Largest file in bytes; used by the attachment policy. */
	public function max_upload_bytes(): int {
		return $this->client()->max_upload_bytes();
	}

	/**
	 * Enabled and configured. A token that was rejected earlier does not count: the message is tried, so the failure is
	 * logged and reported.
	 */
	public function is_ready(): bool {
		return $this->is_enabled() && ! $this->setup_problems();
	}

	/** Translated problems. */
	public function problems(): array {
		$problems = $this->setup_problems();
		if ( ! $problems && State::get( "bot.{$this->id()}.token_error" ) ) {
			$problems[] = __( 'The bot token was rejected. Enter a valid token.', 'forms-notifier-for-elementor' );
		}
		return $problems;
	}

	/**
	 * Send one message. Never throws.
	 *
	 * @param Message $message Message.
	 */
	public function send( Message $message ): SendResult {
		$this->slept    = 0;
		$this->requests = 0;
		try {
			$result = $this->deliver( $message );
		} catch ( \Throwable $e ) {
			return $this->fail( Logger::clean( $e->getMessage() ) );
		}
		$this->after_send( $message, $result );
		return $result;
	}

	/** Client with the current settings. */
	protected function client(): BotClient {
		return BotClient::for_platform( $this->id() );
	}

	/** What is missing before anything can be sent. */
	protected function setup_problems(): array {
		$client = $this->client();
		if ( Settings::secret_is_broken( $this->key( 'token' ) ) ) {
			return [ __( 'The saved bot token cannot be read any more because the security keys of the site changed. Enter it again.', 'forms-notifier-for-elementor' ) ];
		}
		if ( ! $client->has_token() ) {
			return [ __( 'Enter the bot token.', 'forms-notifier-for-elementor' ) ];
		}
		$error = $client->base_error();
		return $error === '' ? [] : [ $error ];
	}

	/**
	 * Connection fields every bot has.
	 *
	 * @param string $token_desc Where to get the token.
	 */
	protected function connection_fields( string $token_desc ): array {
		return [
			$this->field(
				'token',
				'secret',
				__( 'Bot token', 'forms-notifier-for-elementor' ),
				[
					'desc'        => $token_desc,
					'placeholder' => '123456789:ABC-DEF',
					'attrs'       => [ 'dir' => 'ltr' ],
					'required'    => true,
				]
			),
			$this->field(
				'test_connection',
				'action',
				__( 'Test the connection', 'forms-notifier-for-elementor' ),
				[
					'desc'   => __( 'Checks the token and shows which API address was used.', 'forms-notifier-for-elementor' ),
					'action' => [
						'channel' => $this->id(),
						'id'      => 'test_connection',
						'label'   => __( 'Test connection', 'forms-notifier-for-elementor' ),
						'send'    => [ 'token', 'api_base' ],
					],
				]
			),
		];
	}

	/** API base URL field. */
	protected function base_field(): array {
		return $this->field(
			'api_base',
			'url',
			__( 'API base URL', 'forms-notifier-for-elementor' ),
			[
				'desc'        => __( 'Only needed when this server cannot reach the messenger directly. Leave empty for the public API.', 'forms-notifier-for-elementor' ),
				'placeholder' => BotClient::default_base( $this->id() ),
				'attrs'       => [ 'dir' => 'ltr' ],
			]
		);
	}

	/** Limits and file options. */
	protected function file_fields(): array {
		return [
			$this->field(
				'images_as_photos',
				'toggle',
				__( 'Send images as photos', 'forms-notifier-for-elementor' ),
				[
					'default' => true,
					'desc'    => __( 'Photos show a preview but are compressed. Off: images are sent as files.', 'forms-notifier-for-elementor' ),
				]
			),
			$this->field(
				'max_upload_mb',
				'number',
				__( 'Largest file to upload (MB)', 'forms-notifier-for-elementor' ),
				[
					'default' => 50,
					'attrs'   => [
						'min' => 1,
						'max' => $this->id() === 'telegram' ? 2000 : 50,
					],
					'desc'    => $this->id() === 'telegram'
						? __( 'The public API accepts 50 MB. Larger values only work with a self-hosted API server.', 'forms-notifier-for-elementor' )
						: __( 'The API accepts 50 MB.', 'forms-notifier-for-elementor' ),
				]
			),
		];
	}

	/**
	 * Action test_connection.
	 *
	 * @param array $params Parameters.
	 */
	public function action_test_connection( array $params ): array {
		unset( $params );
		$client = $this->client();
		$start  = microtime( true );
		$me     = $client->get_me();
		$time   = (int) round( ( microtime( true ) - $start ) * 1000 );
		$base   = (string) wp_parse_url( $client->base(), PHP_URL_HOST );
		if ( ! $me['ok'] ) {
			$result = $client->to_result( $me );
			return [
				'ok'      => false,
				'message' => $result->error,
				'html'    => sprintf(
					/* translators: %1$s: host name of the API */
					esc_html__( 'API address: %1$s', 'forms-notifier-for-elementor' ),
					'<code>' . esc_html( $base ) . '</code>'
				) . ( $result->response !== '' && $result->response !== $result->error ? '<br>' . esc_html( $result->response ) : '' ),
			];
		}
		$bot      = is_array( $me['result'] ) ? $me['result'] : [];
		$name     = is_string( $bot['first_name'] ?? null ) ? sanitize_text_field( $bot['first_name'] ) : '';
		$username = is_string( $bot['username'] ?? null ) ? sanitize_text_field( $bot['username'] ) : '';
		return [
			'ok'      => true,
			'message' => sprintf(
				/* translators: %1$s: bot name, %2$s: bot username */
				__( 'Connected to %1$s (@%2$s).', 'forms-notifier-for-elementor' ),
				$name,
				$username
			),
			'html'    => sprintf(
				/* translators: %1$s: host name of the API, %2$s: milliseconds */
				esc_html__( 'API address: %1$s, answer in %2$s ms', 'forms-notifier-for-elementor' ),
				'<code>' . esc_html( $base ) . '</code>',
				esc_html( number_format_i18n( $time ) )
			),
			'data'    => [
				'username' => $username,
				'latency'  => $time,
				'custom'   => $client->has_custom_base(),
			],
		];
	}

	/**
	 * Text parts, then files.
	 *
	 * @param Message $message Message.
	 */
	private function deliver( Message $message ): SendResult {
		$client  = $this->client();
		$address = $message->recipient->address;
		$parsed  = BotClient::parse_address( $address );
		if ( ! $parsed ) {
			return $this->fail( __( 'The chat address is not valid.', 'forms-notifier-for-elementor' ), true );
		}
		$chat_type = ChatDiscovery::chat_type( $this->id(), $parsed['chat'] );
		$opts      = [
			'parse_mode'      => $this->parse_mode( $message->format ),
			'disable_preview' => $this->option( $message, 'disable_preview', true ),
			'silent'          => $this->option( $message, 'silent', false ),
			'protect'         => $this->option( $message, 'protect', false ),
			'chat_type'       => $chat_type,
		];

		$files = $this->sort_files( $message );
		$body  = $message->body . $this->links_block( $message );
		$parts = array_values(
			array_filter(
				Registry::formatter( $message->format )->split( $body, BotClient::MAX_TEXT ),
				static function ( string $part ): bool {
					return trim( $part ) !== '';
				}
			)
		);
		if ( ! $parts && ! $files['send'] && ! $files['large'] ) {
			return $this->fail( __( 'The message is empty.', 'forms-notifier-for-elementor' ), true );
		}

		$keyboard = Keyboard::build(
			$message->buttons,
			$this->id(),
			$chat_type,
			[
				'submission_id' => (int) ( $message->meta['submission_id'] ?? 0 ),
				'purpose'       => $message->purpose,
			]
		);

		$meta   = [];
		$result = $this->send_parts( $client, $message, $parts, $keyboard, $opts, $meta );
		if ( ! $result->ok ) {
			return $result->with_meta( $meta );
		}
		$ids   = $result->remote_id === '' ? [] : explode( ',', $result->remote_id );
		$notes = [ count( $parts ) . ' part(s)' ];

		if ( $files['send'] || $files['large'] ) {
			$file_opts = array_merge( $opts, [ 'reply_to' => $ids ? (int) $ids[0] : (int) ( $message->options['reply_to'] ?? 0 ) ] );
			$sent      = $this->send_files( $client, $message, $files, $file_opts );
			$ids       = array_merge( $ids, $sent['ids'] );
			$notes[]   = $sent['count'] . ' file(s)';
			foreach ( $sent['failed'] as $name => $error ) {
				$notes[] = 'file failed: ' . $name . ': ' . $error;
			}
			if ( $sent['failed'] ) {
				$meta['files_failed'] = array_map( 'strval', array_keys( $sent['failed'] ) );
			}
			if ( ! $parts && ! $sent['ids'] ) {
				return $sent['result']->with_meta( $meta );
			}
		}
		if ( $files['missing'] ) {
			$meta['files_failed'] = array_merge( $meta['files_failed'] ?? [], $files['missing'] );
			$notes[]              = 'missing: ' . implode( ', ', $files['missing'] );
		}
		$meta['parts'] = count( $parts );

		$result            = $this->ok( implode( '; ', $notes ), implode( ',', $ids ) );
		$result->http_code = 200;
		return $result->with_meta( $meta );
	}

	/**
	 * Send the text. Parts delivered by an earlier attempt of the same queue row are not repeated.
	 *
	 * @param BotClient $client   Client.
	 * @param Message   $message  Message.
	 * @param string[]  $parts    Parts.
	 * @param array     $keyboard Keyboard rows.
	 * @param array     $opts     Options.
	 * @param array     $meta     Result meta, by reference.
	 */
	private function send_parts( BotClient $client, Message $message, array $parts, array $keyboard, array $opts, array &$meta ): SendResult {
		$address = $message->recipient->address;
		$row     = (int) ( $message->meta['message_id'] ?? 0 );
		$key     = $row > 0 && count( $parts ) > 1 ? 'fnfe_parts_' . md5( $row . '|' . $address . '|' . implode( '', $parts ) ) : '';
		$resume  = $key === '' ? false : get_transient( $key );
		$ids     = is_array( $resume ) ? array_map( 'intval', $resume ) : [];
		$last    = count( $parts ) - 1;

		foreach ( $parts as $i => $part ) {
			if ( isset( $ids[ $i ] ) ) {
				continue;
			}
			$part_opts             = $opts;
			$part_opts['reply_to'] = $i > 0 && isset( $ids[0] ) ? $ids[0] : (int) ( $message->options['reply_to'] ?? 0 );
			if ( $i === $last && $keyboard ) {
				$part_opts['reply_markup'] = [ 'inline_keyboard' => $keyboard ];
			}
			$response = $this->send_part( $client, $message, $part, $part_opts, $meta );
			if ( ! $response['ok'] ) {
				if ( $ids && $key !== '' ) {
					set_transient( $key, $ids, DAY_IN_SECONDS );
				}
				return $client->to_result( $response )->with_meta( [ 'parts_sent' => count( $ids ) ] );
			}
			$sent      = BotClient::message_ids( $response );
			$ids[ $i ] = $sent ? $sent[0] : 0;
		}
		if ( is_array( $resume ) ) {
			delete_transient( $key );
		}
		return $this->ok( '', implode( ',', array_filter( $ids ) ) );
	}

	/**
	 * One part, with one retry for each refusal that can be worked around: buttons, markup.
	 *
	 * @param BotClient $client  Client.
	 * @param Message   $message Message.
	 * @param string    $part    Part.
	 * @param array     $opts    Options.
	 * @param array     $meta    Result meta, by reference.
	 */
	private function send_part( BotClient $client, Message $message, string $part, array $opts, array &$meta ): array {
		$address = $message->recipient->address;
		for ( $try = 0; $try < 3; $try++ ) {
			$this->pause();
			$response = $client->send_message( $address, $part, $opts );
			if ( $response['ok'] || $response['code'] !== 400 ) {
				return $response;
			}
			if ( isset( $opts['reply_markup'] ) && stripos( $response['error'], 'button' ) !== false ) {
				unset( $opts['reply_markup'] );
				$meta['buttons_dropped'] = true;
				Logger::warning( 'bot', $this->id() . ' refused a button, sent without buttons', [ 'error' => $response['error'] ] );
				continue;
			}
			if ( ( $opts['parse_mode'] ?? '' ) !== '' && preg_match( '/parse entities|can.t parse/i', $response['error'] ) ) {
				$part                 = Text::truncate( $this->plain( $part, $opts['parse_mode'] ), BotClient::MAX_TEXT - 96 );
				$opts['parse_mode']   = '';
				$meta['sent_as_text'] = true;
				Logger::warning( 'bot', $this->id() . ' refused the markup, sent as plain text', [ 'error' => $response['error'] ] );
				continue;
			}
			return $response;
		}
		return $response;
	}

	/**
	 * Files that can be uploaded and those that are too large.
	 *
	 * @param Message $message Message.
	 * @return array{send:Attachment[],large:Attachment[],missing:string[]}
	 */
	private function sort_files( Message $message ): array {
		$max = $this->max_upload_bytes();
		$out = [
			'send'    => [],
			'large'   => [],
			'missing' => [],
		];
		foreach ( $message->attachments as $file ) {
			if ( ! $file instanceof Attachment ) {
				continue;
			}
			if ( ! $file->exists() || ( class_exists( PathGuard::class ) && ! PathGuard::is_safe( $file->path ) ) ) {
				$out['missing'][] = $file->name;
			} elseif ( (int) filesize( $file->path ) > $max ) {
				$out['large'][] = $file;
			} else {
				$out['send'][] = $file;
			}
		}
		if ( $out['missing'] ) {
			Logger::warning( 'bot', $this->id() . ': files are not on disk any more', [ 'files' => $out['missing'] ] );
		}
		return $out;
	}

	/**
	 * Upload files as replies to the text; what cannot be uploaded becomes a link line.
	 *
	 * @param BotClient $client  Client.
	 * @param Message   $message Message.
	 * @param array     $files   Output of sort_files().
	 * @param array     $opts    Options.
	 * @return array{ids:int[],count:int,failed:array<string,string>,result:SendResult}
	 */
	private function send_files( BotClient $client, Message $message, array $files, array $opts ): array {
		$out     = [
			'ids'    => [],
			'count'  => 0,
			'failed' => [],
			'result' => $this->ok(),
		];
		$address = $message->recipient->address;
		$linked  = $files['large'];
		$form    = $this->form_name( $message );

		unset( $opts['parse_mode'], $opts['disable_preview'] );

		foreach ( $this->file_groups( $client, $files['send'] ) as $group ) {
			$uploads = [];
			foreach ( $group['files'] as $file ) {
				$uploads[] = [
					'path'    => $file->path,
					'name'    => $file->name,
					'mime'    => $file->mime,
					'caption' => $this->caption( $client, $message, $file, $form ),
				];
				$form      = '';
			}
			$response = $this->upload( $client, $address, $group['kind'], $uploads, $opts );
			if ( $response['ok'] ) {
				$out['ids']    = array_merge( $out['ids'], BotClient::message_ids( $response ) );
				$out['count'] += count( $uploads );
				continue;
			}
			$out['result'] = $client->to_result( $response );
			foreach ( $group['files'] as $file ) {
				$out['failed'][ $file->name ] = $response['error'];
				$linked[]                     = $file;
			}
		}

		$lines = $this->link_lines( $message, $linked );
		if ( $lines !== '' ) {
			$text = '<b>' . self::escape( __( 'These files could not be uploaded:', 'forms-notifier-for-elementor' ) ) . "</b>\n" . $lines;
			foreach ( $this->convert( $message->format, $text ) as $part ) {
				$this->pause();
				$response   = $client->send_message( $address, $part, array_merge( $opts, [ 'parse_mode' => $this->parse_mode( $message->format ) ] ) );
				$out['ids'] = array_merge( $out['ids'], BotClient::message_ids( $response ) );
				if ( ! $response['ok'] ) {
					$out['result'] = $client->to_result( $response );
				}
			}
		}
		return $out;
	}

	/**
	 * Albums and single files a platform accepts.
	 *
	 * @param BotClient    $client Client.
	 * @param Attachment[] $files  Files.
	 * @return array[] [ 'kind' => photo|document, 'files' => Attachment[] ]
	 */
	private function file_groups( BotClient $client, array $files ): array {
		$kinds = [
			'photo'    => [],
			'document' => [],
		];
		foreach ( $files as $file ) {
			$photo = $this->bool( 'images_as_photos', true ) && $file->is_image() && $file->ext !== 'gif' && (int) filesize( $file->path ) <= BotClient::MAX_PHOTO;

			$kinds[ $photo ? 'photo' : 'document' ][] = $file;
		}

		$groups = [];
		foreach ( $kinds as $kind => $list ) {
			$size  = 0;
			$album = [];
			foreach ( $list as $file ) {
				$bytes = (int) filesize( $file->path );
				$full  = count( $album ) >= BotClient::MAX_GROUP || $size + $bytes > $client->max_upload_bytes();
				if ( $album && ( $full || ! $client->supports( $kind . '_group' ) ) ) {
					$groups[] = [
						'kind'  => $kind,
						'files' => $album,
					];
					$album    = [];
					$size     = 0;
				}
				$album[] = $file;
				$size   += $bytes;
			}
			if ( $album ) {
				$groups[] = [
					'kind'  => $kind,
					'files' => $album,
				];
			}
		}
		return $groups;
	}

	/**
	 * Upload one album or file. A photo the API refuses (dimensions) is sent as a document.
	 *
	 * @param BotClient $client  Client.
	 * @param string    $address Address.
	 * @param string    $kind    photo | document.
	 * @param array     $uploads Files.
	 * @param array     $opts    Options.
	 */
	private function upload( BotClient $client, string $address, string $kind, array $uploads, array $opts ): array {
		$this->pause();
		if ( count( $uploads ) > 1 ) {
			return $client->send_media_group( $address, $kind, $uploads, '', $opts );
		}
		$response = $client->send_file( $address, $kind, $uploads[0], $uploads[0]['caption'], $opts );
		if ( ! $response['ok'] && $response['code'] === 400 && $kind === 'photo' && preg_match( '/photo|image/i', $response['error'] ) ) {
			$this->pause();
			$response = $client->send_file( $address, 'document', $uploads[0], $uploads[0]['caption'], $opts );
		}
		return $response;
	}

	/**
	 * Caption: file name, and the form name on the first file.
	 *
	 * @param BotClient  $client  Client.
	 * @param Message    $message Message.
	 * @param Attachment $file    File.
	 * @param string     $form    Form name.
	 */
	private function caption( BotClient $client, Message $message, Attachment $file, string $form ): string {
		$caption = Text::truncate( $form === '' ? $file->name : $file->name . ' · ' . $form, $client->max_caption() - 64 );
		// Without a parse mode captions are plain text, except on Bale, where text is always read as markdown.
		return $client->is_bale() ? Registry::formatter( $message->format )->escape( $caption ) : $caption;
	}

	/**
	 * "Files:" block appended to the text.
	 *
	 * @param Message $message Message.
	 */
	private function links_block( Message $message ): string {
		$lines = [];
		foreach ( $message->links as $link ) {
			$url = is_array( $link ) ? esc_url_raw( (string) ( $link['url'] ?? '' ), [ 'http', 'https' ] ) : '';
			if ( $url !== '' ) {
				$lines[] = self::link_line( (string) ( $link['name'] ?? '' ), $url, (int) ( $link['size'] ?? 0 ) );
			}
		}
		if ( ! $lines ) {
			return '';
		}
		$title = I18n::with_language(
			$message->lang,
			static function (): string {
				return __( 'Files:', 'forms-notifier-for-elementor' );
			}
		);
		$block = Registry::formatter( $message->format )->convert( '<b>' . self::escape( $title ) . "</b>\n" . implode( "\n", $lines ) );
		return ( trim( $message->body ) === '' ? '' : "\n\n" ) . $block;
	}

	/**
	 * Link lines of files that were not uploaded.
	 *
	 * @param Message      $message Message.
	 * @param Attachment[] $files   Files.
	 */
	private function link_lines( Message $message, array $files ): string {
		$lines = [];
		foreach ( $files as $file ) {
			$url = '';
			foreach ( $message->links as $link ) {
				if ( is_array( $link ) && (string) ( $link['name'] ?? '' ) === $file->name ) {
					$url = (string) ( $link['url'] ?? '' );
				}
			}
			if ( $url === '' && $file->id > 0 && $file->stored ) {
				$url = Storage::link( $file, Settings::int( 'attachments.link_ttl_days', 30 ) );
			}
			$url     = esc_url_raw( $url === '' ? $file->url : $url, [ 'http', 'https' ] );
			$lines[] = $url === '' ? self::escape( $file->name ) : self::link_line( $file->name, $url, $file->size );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Canonical link line.
	 *
	 * @param string $name Name.
	 * @param string $url  URL.
	 * @param int    $size Bytes.
	 */
	private static function link_line( string $name, string $url, int $size ): string {
		$line = '<a href="' . esc_attr( $url ) . '">' . self::escape( $name === '' ? $url : $name ) . '</a>';
		return $size > 0 ? $line . ' (' . self::escape( (string) size_format( $size ) ) . ')' : $line;
	}

	/**
	 * Converted and split text.
	 *
	 * @param string $format    Format.
	 * @param string $canonical Canonical markup.
	 * @return string[]
	 */
	private function convert( string $format, string $canonical ): array {
		$formatter = Registry::formatter( $format );
		return $formatter->split( $formatter->convert( $canonical ), BotClient::MAX_TEXT );
	}

	/**
	 * Bot API parse mode of a format.
	 *
	 * @param string $format Format.
	 */
	private function parse_mode( string $format ): string {
		$modes = [
			'telegram_html' => 'HTML',
			'telegram_md'   => 'MarkdownV2',
		];
		return $modes[ $format ] ?? '';
	}

	/**
	 * Text without markup, for a part the API could not parse.
	 *
	 * @param string $part Part.
	 * @param string $mode Parse mode.
	 */
	private function plain( string $part, string $mode ): string {
		if ( $mode === 'HTML' ) {
			return html_entity_decode( preg_replace( '~</?[a-zA-Z][^>]*>~', '', $part ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return (string) preg_replace( '/\\\\([_*\[\]()~`>#+\-=|{}.!\\\\])/', '$1', $part );
	}

	/**
	 * Option of a message: the form's choice, else the channel setting.
	 *
	 * @param Message $message Message.
	 * @param string  $key     Option.
	 * @param bool    $default Default of the setting.
	 */
	private function option( Message $message, string $key, bool $default ): bool {
		$value = $message->options[ $key ] ?? '';
		return $value === '' || is_array( $value ) ? $this->bool( $key, $default ) : Text::bool( $value );
	}

	/**
	 * Name of the form of a message.
	 *
	 * @param Message $message Message.
	 */
	private function form_name( Message $message ): string {
		$form = (int) ( $message->meta['form_id'] ?? 0 ) > 0 ? FormRepository::find( (int) $message->meta['form_id'] ) : null;
		return $form ? (string) $form['name'] : '';
	}

	/** Pause between two requests, never more than two seconds in one send. */
	private function pause(): void {
		if ( $this->requests > 0 && $this->slept + self::PAUSE <= self::MAX_PAUSE ) {
			usleep( self::PAUSE );
			$this->slept += self::PAUSE;
		}
		++$this->requests;
	}

	/**
	 * Keep the chat table and the token flag in step with the outcome.
	 *
	 * @param Message    $message Message.
	 * @param SendResult $result  Result.
	 */
	private function after_send( Message $message, SendResult $result ): void {
		$id     = $this->id();
		$parsed = BotClient::parse_address( $message->recipient->address );
		if ( $result->ok ) {
			if ( State::get( "bot.$id.token_error" ) ) {
				State::delete( "bot.$id.token_error" );
			}
			return;
		}
		if ( $result->http_code === 401 ) {
			Updates::flag_token( $id, 401 );
		} elseif ( $result->http_code === 403 && $parsed ) {
			ChatDiscovery::mark_lost( $id, $parsed['chat'] );
		}
		// The queue worker moves the receivers itself; tests and alerts do not go through it.
		if ( ! empty( $result->meta['migrate_to'] ) && $parsed && empty( $message->meta['message_id'] ) ) {
			ChatDiscovery::migrate( $id, $parsed['chat'], (string) $result->meta['migrate_to'] );
		}
	}

	/**
	 * Escape a value for canonical markup.
	 *
	 * @param string $value Value.
	 */
	private static function escape( string $value ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $value );
	}
}
