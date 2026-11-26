<?php
/**
 * Site Health.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Health;

use AmirhpCom\FormsNotifier\Attachments\Storage;
use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Crypto;
use AmirhpCom\FormsNotifier\Core\DbSchema;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Ticker;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;

defined( 'ABSPATH' ) || exit;

/** Health checks for Tools > Site Health, the dashboard and the digest. */
final class SiteHealth implements ModuleInterface {

	/** Share of failed messages that is worth a warning. */
	private const FAILURE_RATE = 0.2;

	/** Fewer messages do not make a rate. */
	private const MIN_MESSAGES = 5;

	/** Platforms with a reachability test. */
	private const BOTS = [ 'telegram', 'bale' ];

	/** Attach hooks. */
	public function register(): void {
		add_filter( 'site_status_tests', [ self::class, 'tests' ] );
		add_filter( 'debug_information', [ self::class, 'debug_information' ] );
		add_action( 'wp_ajax_health-check-fnfe-bots', [ self::class, 'ajax_bots' ] );
		add_action( 'rest_api_init', [ self::class, 'register_route' ] );
		add_action( 'fnfe_settings_saved', [ Storage::class, 'forget_probe' ] );
	}

	/**
	 * Filter site_status_tests.
	 *
	 * @param array $tests Tests.
	 */
	public static function tests( $tests ): array {
		$tests = is_array( $tests ) ? $tests : [];
		foreach ( self::labels() as $id => $label ) {
			$tests['direct'][ 'fnfe_' . $id ] = [
				'label' => 'Forms Notifier: ' . $label,
				'test'  => static function () use ( $id ): array {
					return self::result( $id, self::check( $id ) );
				},
			];
		}
		$tests['async']['fnfe_bots'] = [
			'label'             => 'Forms Notifier: ' . __( 'Bot connection', 'forms-notifier-for-elementor' ),
			'test'              => rest_url( 'fnfe/v1/health/bots' ),
			'has_rest'          => true,
			'async_direct_test' => [ self::class, 'bots_result' ],
		];
		return $tests;
	}

	/** Every check that applies to this site. */
	public static function checks(): array {
		$out = [];
		foreach ( array_keys( self::labels() ) as $id ) {
			$out[] = self::check( $id );
		}
		return $out;
	}

	/**
	 * Run one check.
	 *
	 * @param string $id Check id.
	 * @return array{id:string,label:string,status:string,message:string,route:string}
	 */
	public static function check( string $id ): array {
		try {
			list( $status, $message, $route ) = self::run( $id );
		} catch ( \Throwable $e ) {
			Logger::warning( 'health', $e->getMessage(), [ 'check' => $id ] );
			$status  = 'warning';
			$message = __( 'This could not be checked.', 'forms-notifier-for-elementor' );
			$route   = '';
		}
		return [
			'id'      => $id,
			'label'   => self::labels()[ $id ] ?? $id,
			'status'  => $status,
			'message' => $message,
			'route'   => $route,
		];
	}

	/**
	 * Filter debug_information. No secrets.
	 *
	 * @param array $info Sections.
	 */
	public static function debug_information( $info ): array {
		$info   = is_array( $info ) ? $info : [];
		$counts = MessageRepository::counts();
		$next   = Ticker::next_run();
		$yes    = __( 'Yes', 'forms-notifier-for-elementor' );
		$no     = __( 'No', 'forms-notifier-for-elementor' );
		$fields = [
			'version'    => [ __( 'Version', 'forms-notifier-for-elementor' ), FNFE_VERSION ],
			'db_version' => [ __( 'Database version', 'forms-notifier-for-elementor' ), (string) get_option( 'fnfe_db_version', '' ) ],
			'capture'    => [ __( 'Capture mode', 'forms-notifier-for-elementor' ), Settings::str( 'general.capture_mode', 'all' ) ],
			'delivery'   => [ __( 'Delivery mode', 'forms-notifier-for-elementor' ), Settings::str( 'general.delivery_mode', 'background' ) ],
			'ready'      => [ __( 'Channels that are ready', 'forms-notifier-for-elementor' ), implode( ', ', array_keys( Registry::ready_channels() ) ) ],
			'ticker'     => [ __( 'Queue driver', 'forms-notifier-for-elementor' ), Ticker::driver() ],
			'next_run'   => [ __( 'Next queue run', 'forms-notifier-for-elementor' ), $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : '-' ],
			'sodium'     => [ 'sodium', extension_loaded( 'sodium' ) ? $yes : $no ],
			'mbstring'   => [ 'mbstring', extension_loaded( 'mbstring' ) ? $yes : $no ],
		];
		foreach ( $counts as $status => $count ) {
			/* translators: %1$s: message status */
			$fields[ 'count_' . $status ] = [ sprintf( __( 'Messages: %1$s', 'forms-notifier-for-elementor' ), $status ), (string) $count ];
		}

		$section = [
			'label'  => 'Forms Notifier for Elementor',
			'fields' => [],
		];
		foreach ( $fields as $key => $field ) {
			$section['fields'][ $key ] = [
				'label' => $field[0],
				'value' => $field[1],
			];
		}
		$info['forms-notifier-for-elementor'] = $section;
		return $info;
	}

	/** Route of the async test. */
	public static function register_route(): void {
		register_rest_route(
			'fnfe/v1',
			'/health/bots',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( self::bots_result() );
				},
				'permission_callback' => [ self::class, 'can_run' ],
				'args'                => [],
			]
		);
	}

	/** Who may run the async test. */
	public static function can_run(): bool {
		return current_user_can( 'view_site_health_checks' ) || Capabilities::can( Capabilities::MANAGE );
	}

	/** Async test for installations where Site Health uses admin-ajax. */
	public static function ajax_bots(): void {
		check_ajax_referer( 'health-check-site-status' );
		if ( ! self::can_run() ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( self::bots_result() );
	}

	/** Result of the bot reachability test. One short request per configured bot. */
	public static function bots_result(): array {
		$bad  = [];
		$good = 0;
		foreach ( self::BOTS as $platform ) {
			$channel = Registry::channel( $platform );
			if ( ! $channel || ! $channel->is_enabled() ) {
				continue;
			}
			try {
				$client = BotClient::for_platform( $platform );
				if ( ! $client->has_token() ) {
					continue;
				}
				$answer = $client->call( 'getMe', [], [], 5 );
				if ( ! empty( $answer['ok'] ) ) {
					++$good;
					continue;
				}
				$error = Logger::clean( (string) ( $answer['error'] ?? '' ) );
			} catch ( \Throwable $e ) {
				$error = Logger::clean( $e->getMessage() );
			}
			$bad[] = $channel->label() . ': ' . ( $error === '' ? __( 'no answer', 'forms-notifier-for-elementor' ) : $error );
		}

		if ( $bad ) {
			$message = __( 'A bot cannot be reached from this server.', 'forms-notifier-for-elementor' ) . ' ' . implode( ' | ', $bad );
			$status  = 'critical';
		} elseif ( $good ) {
			$message = __( 'The connected bots answer.', 'forms-notifier-for-elementor' );
			$status  = 'good';
		} else {
			$message = __( 'No bot is connected.', 'forms-notifier-for-elementor' );
			$status  = 'good';
		}
		return self::result(
			'bots',
			[
				'label'   => __( 'Bot connection', 'forms-notifier-for-elementor' ),
				'status'  => $status,
				'message' => $message,
				'route'   => 'channels',
			]
		);
	}

	/** Checks: id => label. */
	public static function labels(): array {
		return [
			'forms'      => __( 'Form builder', 'forms-notifier-for-elementor' ),
			'channels'   => __( 'Channels', 'forms-notifier-for-elementor' ),
			'cron'       => __( 'Background tasks', 'forms-notifier-for-elementor' ),
			'queue'      => __( 'Message queue', 'forms-notifier-for-elementor' ),
			'failures'   => __( 'Failed messages', 'forms-notifier-for-elementor' ),
			'secrets'    => __( 'Saved credentials', 'forms-notifier-for-elementor' ),
			'salts'      => __( 'Security keys', 'forms-notifier-for-elementor' ),
			'storage'    => __( 'File storage', 'forms-notifier-for-elementor' ),
			'https'      => 'HTTPS',
			'permalinks' => __( 'Permalinks', 'forms-notifier-for-elementor' ),
			'database'   => __( 'Database tables', 'forms-notifier-for-elementor' ),
			'extensions' => __( 'PHP extensions', 'forms-notifier-for-elementor' ),
		];
	}

	/**
	 * One check.
	 *
	 * @param string $id Check id.
	 * @return array{0:string,1:string,2:string} status, message, admin route
	 */
	private static function run( string $id ): array {
		switch ( $id ) {
			case 'forms':
				foreach ( Registry::form_sources() as $source ) {
					if ( $source->is_available() ) {
						return [ 'good', __( 'A supported form builder is active.', 'forms-notifier-for-elementor' ), 'forms' ];
					}
				}
				return [ 'critical', __( 'No supported form builder was found. Activate Elementor Pro.', 'forms-notifier-for-elementor' ), 'help' ];

			case 'channels':
				return self::channels();

			case 'cron':
				return self::cron();

			case 'queue':
				$age = Queue::oldest_due_age();
				if ( $age > Ticker::STUCK ) {
					$minutes = (int) floor( $age / 60 );
					return [
						'critical',
						sprintf(
							/* translators: %1$d: number of minutes */
							_n( 'Messages are stuck: the oldest has been waiting for %1$d minute.', 'Messages are stuck: the oldest has been waiting for %1$d minutes.', $minutes, 'forms-notifier-for-elementor' ),
							$minutes
						),
						'log',
					];
				}
				return [ 'good', __( 'Messages are sent on time.', 'forms-notifier-for-elementor' ), 'log' ];

			case 'failures':
				return self::failures();

			case 'secrets':
				$broken = array_filter( Settings::secret_paths(), [ Settings::class, 'secret_is_broken' ] );
				return $broken
					/* translators: %1$s: comma separated list of setting names */
					? [ 'critical', sprintf( __( 'These credentials cannot be read any more. Enter them again: %1$s', 'forms-notifier-for-elementor' ), implode( ', ', $broken ) ), 'channels' ]
					: [ 'good', __( 'All saved credentials can be read.', 'forms-notifier-for-elementor' ), 'channels' ];

			case 'salts':
				return Crypto::uses_weak_salts()
					? [ 'warning', __( 'The security keys in wp-config.php are missing or still the default ones. Credentials cannot be protected well.', 'forms-notifier-for-elementor' ), '' ]
					: [ 'good', __( 'The security keys of WordPress are set.', 'forms-notifier-for-elementor' ), '' ];

			case 'storage':
				return self::storage();

			case 'https':
				$needed = Settings::bool( 'miniapp.enabled' );
				foreach ( self::BOTS as $platform ) {
					$needed = $needed || ( Settings::bool( "channels.$platform.enabled" ) && Settings::str( "channels.$platform.update_mode" ) === 'webhook' );
				}
				return $needed && strncmp( home_url(), 'https://', 8 ) !== 0
					? [ 'critical', __( 'Bot webhooks and the mini app need HTTPS, but the address of this site starts with http.', 'forms-notifier-for-elementor' ), 'settings/miniapp' ]
					: [ 'good', __( 'The address of this site fits the features in use.', 'forms-notifier-for-elementor' ), '' ];

			case 'permalinks':
				return get_option( 'permalink_structure' )
					? [ 'good', __( 'Pretty permalinks are on.', 'forms-notifier-for-elementor' ), '' ]
					: [ 'good', __( 'Plain permalinks are in use. Everything works, with longer addresses.', 'forms-notifier-for-elementor' ), '' ];

			case 'database':
				return DbSchema::exists() && (string) get_option( 'fnfe_db_version', '' ) === FNFE_DB_VERSION
					? [ 'good', __( 'All tables exist and are up to date.', 'forms-notifier-for-elementor' ), '' ]
					: [ 'critical', __( 'Tables are missing or outdated. Deactivate and activate the plugin again.', 'forms-notifier-for-elementor' ), 'tools' ];

			case 'extensions':
				return self::extensions( Text::has_mbstring() );
		}
		return [ 'good', '', '' ];
	}

	/**
	 * The mbstring extension: without it everything works, but only Latin letters change case.
	 *
	 * @param bool $mbstring The extension is there.
	 * @return array{0:string,1:string,2:string}
	 */
	public static function extensions( bool $mbstring ): array {
		return $mbstring
			? [ 'good', __( 'The PHP extensions the plugin uses are installed.', 'forms-notifier-for-elementor' ), '' ]
			: [ 'warning', self::mbstring_text(), '' ];
	}

	/** Notice on the plugins screen while mbstring is missing. */
	public static function mbstring_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'plugins-network', 'site-health' ], true ) || ! Capabilities::can( Capabilities::MANAGE ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( self::mbstring_text() ) . '</p></div>';
	}

	/** Text about the missing extension. */
	private static function mbstring_text(): string {
		return __( 'Forms Notifier for Elementor: the PHP extension mbstring is missing. Submissions are still saved and sent, but upper and lower case work for Latin letters only and some texts are shortened less precisely. Ask your host to enable mbstring.', 'forms-notifier-for-elementor' );
	}

	/** At least one channel can send; enabled ones have no problems. */
	private static function channels(): array {
		$ready  = 0;
		$broken = [];
		foreach ( Registry::channels() as $channel ) {
			if ( ! $channel->is_enabled() ) {
				continue;
			}
			if ( $channel->is_ready() ) {
				++$ready;
			} else {
				$broken[] = $channel->label();
			}
		}
		if ( $broken ) {
			return [
				$ready ? 'warning' : 'critical',
				/* translators: %1$s: comma separated list of channel names */
				sprintf( __( 'These channels are switched on but not set up: %1$s', 'forms-notifier-for-elementor' ), implode( ', ', $broken ) ),
				'channels',
			];
		}
		if ( ! $ready ) {
			return [ 'warning', __( 'No channel is connected yet, so nothing is sent.', 'forms-notifier-for-elementor' ), 'channels' ];
		}
		return [
			'good',
			sprintf(
				/* translators: %1$d: number of channels */
				_n( '%1$d channel is ready.', '%1$d channels are ready.', $ready, 'forms-notifier-for-elementor' ),
				$ready
			),
			'channels',
		];
	}

	/** Something runs the queue. */
	private static function cron(): array {
		$next = Ticker::next_run();
		if ( ! $next ) {
			return [ 'warning', __( 'The background task of the message queue is not scheduled. Deactivate and activate the plugin again.', 'forms-notifier-for-elementor' ), 'settings/queue' ];
		}
		if ( $next < (int) apply_filters( 'fnfe_now', time() ) - HOUR_IN_SECONDS ) {
			return [ 'warning', __( 'Background tasks do not run. Set up a system cron job for wp-cron.php.', 'forms-notifier-for-elementor' ), 'settings/queue' ];
		}
		if ( Cron::uses_action_scheduler() ) {
			return [ 'good', __( 'Action Scheduler runs the message queue.', 'forms-notifier-for-elementor' ), 'settings/queue' ];
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return [ 'warning', __( 'WP-Cron is switched off in wp-config.php. Make sure a system cron job calls wp-cron.php every minute.', 'forms-notifier-for-elementor' ), 'settings/queue' ];
		}
		return [ 'good', __( 'WP-Cron runs the message queue.', 'forms-notifier-for-elementor' ), 'settings/queue' ];
	}

	/** Failure rate of the last day. */
	private static function failures(): array {
		$counts = MessageRepository::counts( Queue::gmt( -DAY_IN_SECONDS ) );
		$failed = $counts['failed'] ?? 0;
		$total  = $failed + ( $counts['sent'] ?? 0 );
		if ( $total >= self::MIN_MESSAGES && $failed / $total > self::FAILURE_RATE ) {
			return [
				'warning',
				sprintf(
					/* translators: %1$d: failed messages, %2$d: all messages */
					__( '%1$d of %2$d messages failed in the last 24 hours.', 'forms-notifier-for-elementor' ),
					$failed,
					$total
				),
				'log',
			];
		}
		return [ 'good', __( 'Deliveries of the last 24 hours look fine.', 'forms-notifier-for-elementor' ), 'log' ];
	}

	/** Stored files can be written, and the web server does not hand them out by their direct address. */
	private static function storage(): array {
		if ( ! Settings::bool( 'attachments.keep_copy', true ) ) {
			return [ 'good', __( 'Uploaded files are not copied.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
		}
		$dir    = trailingslashit( Storage::base() );
		$exists = is_dir( $dir );
		if ( ! wp_is_writable( $exists ? $dir : self::nearest( $dir ) ) ) {
			return [ 'critical', __( 'The folder for uploaded files is not writable.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
		}
		if ( Storage::public_url() === '' ) {
			return [ 'good', __( 'Uploaded files are kept outside the public folder.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
		}
		$dir = Storage::dir();
		if ( ! is_file( $dir . 'index.php' ) || ! is_file( $dir . '.htaccess' ) ) {
			return [ 'warning', __( 'The protection files of the upload folder are missing.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
		}
		$open = Storage::is_exposed();
		if ( $open === true ) {
			return [ 'warning', __( 'Stored files can be opened by their direct address. The names cannot be guessed, but links then never expire. Close the folder on the web server or move it with FNFE_FILES_DIR.', 'forms-notifier-for-elementor' ), 'help' ];
		}
		if ( $open === false ) {
			return [ 'good', __( 'The folder for uploaded files is writable and closed.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
		}
		return [ 'good', __( 'The folder for uploaded files is writable. Whether the web server closes it could not be tested.', 'forms-notifier-for-elementor' ), 'settings/attachments' ];
	}

	/**
	 * First parent directory that exists.
	 *
	 * @param string $dir Directory.
	 */
	private static function nearest( string $dir ): string {
		$dir = untrailingslashit( $dir );
		while ( ! is_dir( $dir ) && dirname( $dir ) !== $dir ) {
			$dir = dirname( $dir );
		}
		return $dir;
	}

	/**
	 * Site Health result.
	 *
	 * @param string $id    Check id.
	 * @param array  $check Output of check().
	 */
	private static function result( string $id, array $check ): array {
		$status = [
			'good'     => 'good',
			'warning'  => 'recommended',
			'critical' => 'critical',
		][ $check['status'] ] ?? 'recommended';
		$colors = [
			'good'        => 'blue',
			'recommended' => 'orange',
			'critical'    => 'red',
		];
		$link   = '';
		if ( (string) ( $check['route'] ?? '' ) !== '' ) {
			$link = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Urls::admin( $check['route'] ) ), esc_html__( 'Open Forms Notifier', 'forms-notifier-for-elementor' ) );
		}
		return [
			'label'       => 'Forms Notifier: ' . $check['label'],
			'status'      => $status,
			'badge'       => [
				'label' => 'Forms Notifier',
				'color' => $colors[ $status ],
			],
			'description' => '<p>' . esc_html( $check['message'] ) . '</p>' . ( $id === 'storage' && $status !== 'good' ? self::storage_steps() : '' ),
			'actions'     => $link,
			'test'        => 'fnfe_' . $id,
		];
	}

	/** How to close the folder of stored files on the common web servers, as HTML. */
	public static function storage_steps(): string {
		$path = (string) wp_parse_url( Storage::public_url(), PHP_URL_PATH );
		$path = $path === '' ? '/wp-content/uploads/' . Storage::FOLDER : $path;
		$dir  = Storage::base();
		$rows = [
			[
				'Apache',
				/* translators: %s: folder on the disk */
				sprintf( __( 'The .htaccess file in the folder closes it when the server allows overrides (AllowOverride All or AuthConfig). Otherwise add this to the site configuration, with the path of %s:', 'forms-notifier-for-elementor' ), $dir ),
				'<Directory "' . $dir . '">' . "\n\tRequire all denied\n</Directory>",
			],
			[
				'nginx',
				__( 'nginx ignores .htaccess files. Add this to the server block of the site and reload nginx:', 'forms-notifier-for-elementor' ),
				'location ^~ ' . trailingslashit( $path ) . " {\n\tdeny all;\n}",
			],
			[
				'LiteSpeed',
				__( 'LiteSpeed Enterprise reads the .htaccess file when overrides are on. OpenLiteSpeed does not: add a context for this address with access turned off in the WebAdmin console, then restart.', 'forms-notifier-for-elementor' ),
				trailingslashit( $path ),
			],
			[
				__( 'Any server', 'forms-notifier-for-elementor' ),
				__( 'Move the folder out of the public folder in wp-config.php. Files stored before the move stay where they are and keep working.', 'forms-notifier-for-elementor' ),
				"define( 'FNFE_FILES_DIR', '/path/outside/public_html/fnfe-files' );",
			],
		];
		$html = '';
		foreach ( $rows as $row ) {
			$html .= '<p><strong>' . esc_html( $row[0] ) . '</strong>: ' . esc_html( $row[1] ) . '</p><pre><code>' . esc_html( $row[2] ) . '</code></pre>';
		}
		return $html;
	}
}
