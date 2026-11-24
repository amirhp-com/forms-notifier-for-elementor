<?php
/**
 * Registries.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

use AmirhpCom\FormsNotifier\Channels;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Contracts\FormatterInterface;
use AmirhpCom\FormsNotifier\Contracts\FormSourceInterface;
use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Elementor;
use AmirhpCom\FormsNotifier\Template\Format;

defined( 'ABSPATH' ) || exit;

/** Channels, providers, formatters and form sources. Filter based, instantiated lazily, cached per site. */
final class Registry {

	/** @var bool */
	private static $booted = false;

	/** @var array<string,array<string,object>> */
	private static $cache = [];

	/** Fires fnfe_register once on init. */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		do_action( 'fnfe_register', self::class );
	}

	/** Built-in channels. Missing classes are skipped at instantiation. */
	public static function default_channels(): array {
		return [
			'telegram'   => Channels\Bot\TelegramChannel::class,
			'bale'       => Channels\Bot\BaleChannel::class,
			'sms'        => Channels\Sms\SmsChannel::class,
			'whatsapp'   => Channels\WhatsApp\WhatsAppChannel::class,
			'email'      => Channels\Email\EmailChannel::class,
			'webhook'    => Channels\Webhook\WebhookChannel::class,
			'slack'      => Channels\Chat\SlackChannel::class,
			'discord'    => Channels\Chat\DiscordChannel::class,
			'googlechat' => Channels\Chat\GoogleChatChannel::class,
			'teams'      => Channels\Chat\TeamsChannel::class,
			'mattermost' => Channels\Chat\MattermostChannel::class,
			'rocketchat' => Channels\Chat\RocketChatChannel::class,
			'ntfy'       => Channels\Push\NtfyChannel::class,
			'pushover'   => Channels\Push\PushoverChannel::class,
			'gotify'     => Channels\Push\GotifyChannel::class,
			'eitaa'      => Channels\Bot\EitaaChannel::class,
			'rubika'     => Channels\Bot\RubikaChannel::class,
			'soroush'    => Channels\Bot\SoroushChannel::class,
			'gap'        => Channels\Bot\GapChannel::class,
		];
	}

	/** Built-in formatters. */
	public static function default_formatters(): array {
		return [
			'text'          => Format\TextFormatter::class,
			'telegram_html' => Format\TelegramHtmlFormatter::class,
			'telegram_md'   => Format\TelegramMarkdownFormatter::class,
			'bale_md'       => Format\BaleFormatter::class,
			'whatsapp'      => Format\WhatsAppFormatter::class,
			'slack'         => Format\SlackFormatter::class,
			'markdown'      => Format\MarkdownFormatter::class,
			'email_html'    => Format\EmailFormatter::class,
			'json'          => Format\TextFormatter::class,
		];
	}

	/** Built-in form sources. */
	public static function default_form_sources(): array {
		return [
			'pro'    => Elementor\ProFormSource::class,
			'atomic' => Elementor\AtomicFormSource::class,
			'ehp'    => Elementor\HelloPlusFormSource::class,
		];
	}

	/**
	 * Ids of every registered channel, without instantiating anything.
	 *
	 * @return string[]
	 */
	public static function channel_ids(): array {
		return array_keys( self::map( 'fnfe_channels', self::default_channels() ) );
	}

	/**
	 * All instantiable channels, in registration order.
	 *
	 * @return array<string,ChannelInterface>
	 */
	public static function channels(): array {
		return self::instances( 'fnfe_channels', self::default_channels(), ChannelInterface::class );
	}

	/**
	 * One channel.
	 *
	 * @param string $id Channel id.
	 */
	public static function channel( string $id ): ?ChannelInterface {
		return self::channels()[ $id ] ?? null;
	}

	/**
	 * Enabled and ready.
	 *
	 * @return array<string,ChannelInterface>
	 */
	public static function ready_channels(): array {
		return array_filter(
			self::channels(),
			static function ( ChannelInterface $channel ): bool {
				return $channel->is_ready();
			}
		);
	}

	/**
	 * Providers behind a channel. Filter: fnfe_{channel}_providers.
	 *
	 * @param string $channel Channel id.
	 * @return array<string,ProviderInterface>
	 */
	public static function providers( string $channel ): array {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $channel ) ) {
			return [];
		}
		return self::instances( 'fnfe_' . $channel . '_providers', [], ProviderInterface::class );
	}

	/**
	 * One provider.
	 *
	 * @param string $channel Channel id.
	 * @param string $id      Provider id.
	 */
	public static function provider( string $channel, string $id ): ?ProviderInterface {
		return self::providers( $channel )[ $id ] ?? null;
	}

	/**
	 * Formatter of a format; falls back to the 'text' formatter.
	 *
	 * @param string $format Format id.
	 */
	public static function formatter( string $format ): FormatterInterface {
		$all = self::instances( 'fnfe_formatters', self::default_formatters(), FormatterInterface::class );
		return $all[ $format ] ?? $all['text'] ?? new Format\TextFormatter();
	}

	/**
	 * Form sources. Filter: fnfe_form_sources.
	 *
	 * @return array<string,FormSourceInterface>
	 */
	public static function form_sources(): array {
		return self::instances( 'fnfe_form_sources', self::default_form_sources(), FormSourceInterface::class );
	}

	/** Forget every instance. */
	public static function reset(): void {
		self::$cache = [];
		Schema::flush();
		Settings::flush_cache();
	}

	/**
	 * Filtered class map with valid ids only.
	 *
	 * @param string $hook     Filter name.
	 * @param array  $defaults Built-in map.
	 */
	private static function map( string $hook, array $defaults ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Every caller passes an fnfe_ name.
		$map = apply_filters( $hook, $defaults );
		$out = [];
		foreach ( is_array( $map ) ? $map : $defaults as $id => $entry ) {
			if ( is_string( $id ) && preg_match( '/^[a-z0-9_]+$/', $id ) && ( is_string( $entry ) || is_object( $entry ) ) ) {
				$out[ $id ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Instances of a map that implement a contract.
	 *
	 * @param string $hook      Filter name.
	 * @param array  $defaults  Built-in map.
	 * @param string $interface Contract.
	 */
	private static function instances( string $hook, array $defaults, string $interface ): array {
		$key = get_current_blog_id() . '|' . $hook;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$out = [];
		foreach ( self::map( $hook, $defaults ) as $id => $entry ) {
			try {
				if ( is_string( $entry ) ) {
					if ( ! class_exists( $entry ) ) {
						continue;
					}
					$entry = new $entry();
				}
			} catch ( \Throwable $e ) {
				Logger::error( 'registry', sprintf( 'Could not create "%s": %s', $id, $e->getMessage() ) );
				continue;
			}
			if ( $entry instanceof $interface ) {
				$out[ $id ] = $entry;
			}
		}
		// Plugins may still register before init has finished.
		if ( did_action( 'init' ) > 0 ) {
			self::$cache[ $key ] = $out;
		}
		return $out;
	}
}
