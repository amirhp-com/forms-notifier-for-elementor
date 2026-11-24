<?php
/**
 * Dispatcher.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Attachments\Policy;
use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** Delivery -> rendered message -> queue row. */
final class Dispatcher {

	/** Longest text of a copy button. */
	private const MAX_COPY = 256;

	/** Platforms with a mini app. */
	private const MINIAPP_PLATFORMS = [ 'telegram', 'bale' ];

	/**
	 * Plan, render and enqueue everything for a submission.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids.
	 * @return int[] fnfe_messages ids, skipped rows included
	 */
	public static function dispatch( Submission $s, FormConfig $form, array $only_channels = [] ): array {
		$plan = Resolver::resolve( $s, $form, $only_channels );
		$ids  = [];
		$due  = [];

		foreach ( $plan['skipped'] as $item ) {
			$ids[] = self::skip( $item['delivery'], $s, $item['reason'] );
		}

		$test = self::is_test( $s );
		foreach ( $plan['deliveries'] as $delivery ) {
			$delay = $delivery->purpose === 'admin' && ! $test ? QuietHours::delay( $delivery->channel, $form ) : 0;
			$id    = self::enqueue( $delivery, $s, $form, $delay );
			$ids[] = $id;
			if ( $id && ! $delay ) {
				$due[] = $id;
			}
		}

		$ids = array_values( array_filter( $ids ) );
		if ( $ids ) {
			Worker::update_submission( $s->id );
		}
		if ( $due && Settings::str( 'general.delivery_mode', 'background' ) === 'inline' ) {
			Queue::run_ids( $due );
		} elseif ( $ids ) {
			Queue::kick();
		}
		if ( ! $s->id ) {
			Cleanup::release_files( $s->files );
		}
		return $ids;
	}

	/**
	 * Render and queue one delivery; not-ready channels become skipped rows, broken renderings failed ones.
	 *
	 * @param Delivery   $d     Delivery.
	 * @param Submission $s     Submission.
	 * @param FormConfig $form  Form config.
	 * @param int        $delay Seconds.
	 * @param array      $meta  Extra message meta.
	 * @return int row id
	 */
	public static function enqueue( Delivery $d, Submission $s, FormConfig $form, int $delay = 0, array $meta = [] ): int {
		$problem = self::problem( Registry::channel( $d->channel ) );
		if ( $problem !== '' ) {
			return self::skip( $d, $s, $problem );
		}
		try {
			$message = self::build( $d, $s, $form );
		} catch ( \Throwable $e ) {
			Logger::error( 'queue', $e->getMessage(), [ 'channel' => $d->channel ] );
			/* translators: %1$s: technical error message */
			$error = sprintf( __( 'The message could not be prepared: %1$s', 'forms-notifier-for-elementor' ), Logger::clean( $e->getMessage() ) );
			$id    = self::skip( $d, $s, $error, 'failed' );
			$row   = MessageRepository::find( $id );
			if ( $row ) {
				Alerts::on_failure( $row, SendResult::failure( $d->channel, $error, true ), true );
			}
			return $id;
		}
		$message->meta = array_merge( $message->meta, $meta );
		return Queue::push( $message, $delay );
	}

	/**
	 * Render one delivery into a message (no queueing).
	 *
	 * @param Delivery   $d    Delivery.
	 * @param Submission $s    Submission.
	 * @param FormConfig $form Form config.
	 */
	public static function build( Delivery $d, Submission $s, FormConfig $form ): Message {
		return I18n::with_language(
			$d->lang,
			static function () use ( $d, $s, $form ): Message {
				return self::render( $d, $s, $form );
			}
		);
	}

	/**
	 * Send a message right now through its channel, bypassing the queue, and log it.
	 *
	 * @param Message $m Message. meta.parent_id links the log entry to another one.
	 */
	public static function send_now( Message $m ): SendResult {
		// The row is final from the start: a request that dies here must not be picked up by the queue.
		$row = array_merge(
			Queue::row( $m, 'failed' ),
			[
				'attempts'     => 1,
				'max_attempts' => 1,
				'error'        => __( 'Sending was interrupted.', 'forms-notifier-for-elementor' ),
			]
		);
		$id  = MessageRepository::insert( $row );

		$m->meta['message_id'] = $id;
		$m->meta['attempt']    = 1;

		$result = Worker::deliver( $m );
		$patch  = Worker::outcome( $result );

		$patch['status'] = $result->ok ? 'sent' : 'failed';
		if ( ! Settings::bool( 'privacy.log_bodies', true ) ) {
			$patch['body']    = null;
			$patch['payload'] = null;
		}
		MessageRepository::update( $id, $patch );
		return $result;
	}

	/**
	 * Why a channel cannot send, '' when it can.
	 *
	 * @param ChannelInterface|null $channel Channel.
	 */
	public static function problem( ?ChannelInterface $channel ): string {
		if ( ! $channel ) {
			return __( 'This channel does not exist any more.', 'forms-notifier-for-elementor' );
		}
		if ( ! $channel->is_enabled() ) {
			return __( 'This channel is switched off in the settings.', 'forms-notifier-for-elementor' );
		}
		if ( $channel->is_ready() ) {
			return '';
		}
		$problems = array_filter( $channel->problems(), 'is_string' );
		return $problems ? implode( ' ', $problems ) : __( 'This channel is not set up yet.', 'forms-notifier-for-elementor' );
	}

	/** Site block of structured data. */
	public static function site(): array {
		return [
			'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'  => home_url(),
		];
	}

	/**
	 * Render in the active language.
	 *
	 * @param Delivery   $d    Delivery.
	 * @param Submission $s    Submission.
	 * @param FormConfig $form Form config.
	 */
	private static function render( Delivery $d, Submission $s, FormConfig $form ): Message {
		$channel            = Registry::channel( $d->channel );
		$capabilities       = $channel ? $channel->capabilities() : [];
		$context            = Context::for_submission( $s, $d->lang, $d->channel, $d->purpose );
		$context->form      = $form;
		$context->recipient = $d->recipient;
		$context->policy    = $d->attachment_policy;

		$template = Settings::localize( $d->template, $d->lang );
		if ( trim( $template ) === '' ) {
			$template = $channel ? $channel->default_template( $d->purpose ) : DefaultTemplates::for_format( 'text', $d->purpose );
		}

		$message          = new Message( $d->channel, $d->recipient );
		$message->purpose = $d->purpose;
		$message->lang    = $d->lang;
		$message->format  = $channel ? $channel->format() : 'text';
		$message->options = $d->options;
		$message->meta    = [
			'submission_id' => $s->id,
			'form_id'       => $s->form_id,
			'form_key'      => $s->form_key,
			'uuid'          => $s->uuid,
			'rule_id'       => $d->rule_id,
		];
		if ( self::is_test( $s ) ) {
			$message->meta['test'] = true;
		}

		if ( ! empty( $capabilities['subject'] ) ) {
			$subject          = Settings::localize( $d->subject, $d->lang );
			$message->subject = Engine::render_inline( trim( $subject ) === '' ? DefaultTemplates::subject( $d->purpose ) : $subject, $context );
		}

		// Patterns are read from the raw template, so nothing a visitor typed can become one.
		$pattern = empty( $capabilities['pattern'] ) ? null : PatternText::parse( $template );
		if ( $pattern ) {
			$template         = (string) ( $pattern['text'] ?? '' );
			$message->pattern = self::pattern( $pattern, $context );
		}
		$message->body  = $message->format === 'json' || $template === '' ? '' : Engine::render( $template, $context, $message->format );
		$message->plain = $template === '' ? '' : Engine::render( $template, $context, 'text' );

		if ( $channel && $s->files && ( $d->attachment_policy['mode'] ?? 'off' ) !== 'off' ) {
			self::attach( $message, $d, $s, $channel );
		}
		$message->buttons = self::buttons( $d, $message, $s, $context, (array) ( $capabilities['buttons'] ?? [] ) );

		try {
			$filtered = apply_filters( 'fnfe_message', $message, $d, $s );
		} catch ( \Throwable $e ) {
			// A broken listener must not cost the owner a submission.
			Logger::error( 'queue', $e->getMessage(), [ 'channel' => $d->channel ] );
			$filtered = null;
		}
		return $filtered instanceof Message ? $filtered : $message;
	}

	/**
	 * Pattern with rendered variables.
	 *
	 * @param array   $pattern Output of PatternText::parse().
	 * @param Context $context Context.
	 */
	private static function pattern( array $pattern, Context $context ): array {
		$vars = [];
		foreach ( (array) ( $pattern['vars'] ?? [] ) as $name => $value ) {
			$vars[ $name ] = Engine::render_inline( (string) $value, $context );
		}
		return [
			'code'  => (string) ( $pattern['code'] ?? '' ),
			'vars'  => $vars,
			'lang'  => (string) ( $pattern['lang'] ?? '' ),
			'codes' => (array) ( $pattern['codes'] ?? [] ),
		];
	}

	/**
	 * Files and links by policy.
	 *
	 * @param Message          $message Message.
	 * @param Delivery         $d       Delivery.
	 * @param Submission       $s       Submission.
	 * @param ChannelInterface $channel Channel.
	 */
	private static function attach( Message $message, Delivery $d, Submission $s, ChannelInterface $channel ): void {
		$result = Policy::apply( $d->attachment_policy, $s, $channel, self::max_upload_bytes( $channel ) );

		$message->attachments = array_values( (array) ( $result['send'] ?? [] ) );
		$message->links       = array_values( (array) ( $result['links'] ?? [] ) );
	}

	/**
	 * Upload limit of a channel, 0 = unknown.
	 *
	 * @param ChannelInterface $channel Channel.
	 */
	private static function max_upload_bytes( ChannelInterface $channel ): int {
		try {
			if ( method_exists( $channel, 'max_upload_bytes' ) ) {
				return max( 0, (int) $channel->max_upload_bytes() );
			}
			if ( in_array( $channel->id(), self::MINIAPP_PLATFORMS, true ) ) {
				return max( 0, BotClient::for_platform( $channel->id() )->max_upload_bytes() );
			}
		} catch ( \Throwable $e ) {
			Logger::debug( 'queue', $e->getMessage() );
		}
		return 0;
	}

	/**
	 * Rendered buttons in rows.
	 *
	 * @param Delivery   $d         Delivery.
	 * @param Message    $message   Message.
	 * @param Submission $s         Submission.
	 * @param Context    $context   Context.
	 * @param string[]   $supported Button types of the channel.
	 */
	private static function buttons( Delivery $d, Message $message, Submission $s, Context $context, array $supported ): array {
		$rows = [];
		foreach ( $d->buttons as $button ) {
			$rendered = is_array( $button ) ? self::button( $button, $d, $s, $context, $supported ) : null;
			if ( $rendered ) {
				$rows[ (int) ( $button['row'] ?? 0 ) ][] = $rendered;
			}
		}
		ksort( $rows );
		return (array) apply_filters( 'fnfe_buttons', array_values( $rows ), $message, $s );
	}

	/**
	 * One rendered button, null when it cannot be shown.
	 *
	 * @param array      $button    Button definition.
	 * @param Delivery   $d         Delivery.
	 * @param Submission $s         Submission.
	 * @param Context    $context   Context.
	 * @param string[]   $supported Button types of the channel.
	 */
	private static function button( array $button, Delivery $d, Submission $s, Context $context, array $supported ): ?array {
		$type  = (string) ( $button['type'] ?? '' );
		$value = (string) ( $button['value'] ?? '' );
		$text  = Engine::render_inline( $button['text'] ?? '', $context );

		if ( $type === 'submission' ) {
			if ( ! $s->id ) {
				return null;
			}
			$app   = in_array( 'web_app', $supported, true ) && self::miniapp_url( $d->channel, 'submission', $s ) !== '';
			$type  = $app ? 'web_app' : 'url';
			$value = $app ? 'submission' : '{admin_submission_url}';
			if ( ! $app && ! in_array( 'url', $supported, true ) ) {
				return null;
			}
		}
		if ( $text === '' || ! in_array( $type, $supported, true ) ) {
			return null;
		}

		switch ( $type ) {
			case 'url':
				$value = $value === '{admin_submission_url}' ? ( $s->id ? Urls::submission_admin( $s->id ) : '' ) : self::url( $value, $context );
				break;
			case 'callback':
				$value = $s->id && $d->purpose === 'admin' ? sanitize_key( $value ) : '';
				break;
			case 'web_app':
				$value = self::miniapp_url( $d->channel, $value, $s );
				break;
			case 'copy':
				$value = mb_substr( Engine::render_inline( $value, $context ), 0, self::MAX_COPY );
				break;
			default:
				$value = Engine::render_inline( $value, $context );
		}
		if ( $value === '' ) {
			return null;
		}
		return [
			'text'  => $text,
			'type'  => $type,
			'value' => $value,
			'style' => (string) ( $button['style'] ?? '' ),
		];
	}

	/**
	 * Render a URL template, '' when the result is not an address a button may open.
	 *
	 * @param string  $template URL template.
	 * @param Context $context  Context.
	 */
	private static function url( string $template, Context $context ): string {
		$url = Engine::render_inline( $template, $context, true );
		return preg_match( '~^(https?://[^/\s]|tg://|mailto:|tel:)~i', $url ) ? esc_url_raw( $url, [ 'http', 'https', 'tg', 'mailto', 'tel' ] ) : '';
	}

	/**
	 * Mini app URL with its start parameter, '' when the app cannot be used.
	 *
	 * @param string     $platform Channel id.
	 * @param string     $path     Path inside the app, '' = home, 'submission' = this submission, 'form' = this form.
	 * @param Submission $s        Submission.
	 */
	private static function miniapp_url( string $platform, string $path, Submission $s ): string {
		if ( ! in_array( $platform, self::MINIAPP_PLATFORMS, true ) || ! Settings::bool( 'miniapp.enabled' ) ) {
			return '';
		}
		$path  = trim( $path, " /\t" );
		$param = '';
		if ( $path === 'submission' ) {
			if ( ! $s->id ) {
				return '';
			}
			$param = 'sub_' . $s->id;
		} elseif ( $path === 'form' && $s->form_id ) {
			$param = 'form_' . $s->form_id;
		}
		$url = Urls::miniapp( $platform, $param === '' ? preg_replace( '~[^a-z0-9/_-]~i', '', $path ) : '' );
		if ( strncmp( $url, 'https://', 8 ) !== 0 ) {
			return '';
		}
		return $param === '' ? $url : add_query_arg( 'startapp', $param, $url );
	}

	/**
	 * Log a delivery that is not sent.
	 *
	 * @param Delivery   $d      Delivery.
	 * @param Submission $s      Submission.
	 * @param string     $reason Reason.
	 * @param string     $status skipped | failed.
	 * @return int row id
	 */
	private static function skip( Delivery $d, Submission $s, string $reason, string $status = 'skipped' ): int {
		return MessageRepository::insert(
			[
				'submission_id'   => $s->id,
				'form_id'         => $s->form_id,
				'channel'         => $d->channel,
				'purpose'         => $d->purpose,
				'recipient'       => Queue::shown_address( $d->channel, $d->recipient->address ),
				'recipient_label' => $d->recipient->name,
				'lang'            => $d->lang,
				'status'          => $status,
				'max_attempts'    => Settings::int( 'queue.max_attempts', 5 ),
				'error'           => $reason,
			]
		);
	}

	/**
	 * Test submission.
	 *
	 * @param Submission $s Submission.
	 */
	private static function is_test( Submission $s ): bool {
		return ( $s->meta['via'] ?? '' ) === 'test';
	}
}
