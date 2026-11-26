<?php
/**
 * Default settings.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Core;

defined( 'ABSPATH' ) || exit;

/** Default settings tree. */
final class Defaults {

	/** @var array<int,array> */
	private static $base = [];

	/** Default tree passed through filter fnfe_default_settings and merged with schema field defaults. */
	public static function all(): array {
		$tree = self::base();
		if ( ! self::is_complete() ) {
			return $tree;
		}
		foreach ( Schema::fields() as $path => $field ) {
			if ( $field['type'] === 'secret' ) {
				continue;
			}
			Settings::path_get( $tree, $path, $found );
			if ( ! $found ) {
				$tree = Settings::path_set( $tree, $path, $field['default'] );
			}
		}
		return $tree;
	}

	/** Schema defaults can be read: labels may be translated and the schema is not being built right now. */
	public static function is_complete(): bool {
		return did_action( 'init' ) > 0 && ! Schema::is_building();
	}

	/** Default tree without the schema. */
	public static function base(): array {
		$blog = get_current_blog_id();
		if ( isset( self::$base[ $blog ] ) ) {
			return self::$base[ $blog ];
		}

		$tree = self::tree();
		foreach ( Registry::channel_ids() as $id ) {
			$tree['channels'][ $id ] = self::channel();
		}
		$tree = (array) apply_filters( 'fnfe_default_settings', $tree );

		if ( did_action( 'init' ) > 0 ) {
			self::$base[ $blog ] = $tree;
		}
		return $tree;
	}

	/** Keys every channel has. */
	public static function channel(): array {
		return [
			'enabled'   => false,
			'receivers' => [],
			'template'  => [
				'admin'     => [ '_' => '' ],
				'submitter' => [ '_' => '' ],
			],
			'subject'   => [
				'admin'     => [ '_' => '' ],
				'submitter' => [ '_' => '' ],
			],
			'buttons'   => [],
			'fallback'  => '',
		];
	}

	/** Forget the cached tree. */
	public static function flush(): void {
		self::$base = [];
	}

	/** Authoritative paths. */
	private static function tree(): array {
		return [
			'general'     => [
				'capture_mode'            => 'all',
				'delivery_mode'           => 'background',
				'notify_on_action_errors' => true,
				'store_submissions'       => true,
				'store_ip'                => 'full',
				'store_user_agent'        => true,
				'normalize_digits'        => true,
				'default_country'         => '',
				'calendar'                => 'auto',
				'admin_lang'              => 'site',
				'duplicate_window'        => 30,
				'skip_types'              => [ 'step', 'html', 'honeypot', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile' ],
				'quiet_hours'             => [
					'enabled'  => false,
					'from'     => '23:00',
					'to'       => '07:00',
					'channels' => [ 'sms', 'whatsapp' ],
				],
			],
			'channels'    => [],
			'attachments' => [
				'mode'          => 'link',
				'types'         => 'all',
				'extensions'    => [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ],
				'max_mb'        => 20,
				'max_files'     => 10,
				'keep_copy'     => true,
				'link_ttl_days' => 30,
				'channels'      => [],
			],
			'queue'       => [
				'max_attempts' => 5,
				'backoff'      => [ 60, 300, 900, 3600, 21600 ],
				'batch'        => 20,
				'time_budget'  => 20,
				'ticker'       => 'auto',
			],
			'alerts'      => [
				'enabled'      => false,
				'when'         => 'final',
				'max_per_hour' => 10,
				'channels'     => [],
				'template'     => [ '_' => '' ],
			],
			'digest'      => [
				'enabled'    => false,
				'frequency'  => 'daily',
				'time'       => '09:00',
				'weekday'    => 6,
				'channels'   => [],
				'template'   => [ '_' => '' ],
				'skip_empty' => true,
			],
			'miniapp'     => [
				'enabled'       => false,
				'max_age'       => 86400,
				'session_ttl'   => 7200,
				'page_size'     => 20,
				'show_ip'       => false,
				'allow_actions' => true,
			],
			'privacy'     => [
				'retention_submissions_days' => 0,
				'retention_files_days'       => 0,
				'retention_messages_days'    => 90,
				'retention_log_days'         => 30,
				'log_bodies'                 => true,
				'delete_on_uninstall'        => false,
			],
			'advanced'    => [
				'http_timeout'        => 20,
				'allow_private_hosts' => false,
				'proxy_header'        => '',
				'debug_log'           => false,
			],
		];
	}
}
