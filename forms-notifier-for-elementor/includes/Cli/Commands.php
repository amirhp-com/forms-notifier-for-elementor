<?php
/**
 * WP-CLI commands.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Cli;

use AmirhpCom\FormsNotifier\Capture\Capture;
use AmirhpCom\FormsNotifier\Channels\Bot\BotClient;
use AmirhpCom\FormsNotifier\Channels\Bot\Updates;
use AmirhpCom\FormsNotifier\Core\Cron;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Cleanup;
use AmirhpCom\FormsNotifier\Delivery\Digest;
use AmirhpCom\FormsNotifier\Delivery\Dispatcher;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Resolver;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\Delivery\Ticker;
use AmirhpCom\FormsNotifier\Elementor\FormScanner;
use AmirhpCom\FormsNotifier\I18n\Strings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Rest\Controller;
use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Tools\ImportExport;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage form notifications: channels, forms, the message queue, the log and the digest.
 */
final class Commands {

	/** Seconds a scan may take. */
	private const SCAN_LIMIT = 300;

	/** Statuses of messages. */
	private const STATUSES = [ 'queued', 'sending', 'sent', 'retry', 'failed', 'skipped', 'cancelled' ];

	/**
	 * Show channels, queue and health.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format of the tables.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe status
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ): void {
		unset( $args );
		$format = (string) ( $assoc['format'] ?? 'table' );
		$rows   = [];
		foreach ( Registry::channels() as $id => $channel ) {
			$rows[] = [
				'channel'  => $id,
				'enabled'  => $channel->is_enabled() ? 'yes' : 'no',
				'ready'    => $channel->is_ready() ? 'yes' : 'no',
				'problems' => $channel->is_enabled() ? implode( ' ', $channel->problems() ) : '',
			];
		}
		$health = [];
		foreach ( Stats::health() as $check ) {
			$health[] = [
				'check'   => $check['id'],
				'status'  => $check['status'],
				'message' => $check['message'],
			];
		}
		$next  = Ticker::next_run();
		$queue = [
			[
				'pending'  => Queue::pending(),
				'driver'   => Ticker::driver(),
				'next_run' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : '-',
			],
		];
		if ( $format === 'json' || $format === 'yaml' ) {
			WP_CLI::print_value(
				[
					'version'  => FNFE_VERSION,
					'channels' => $rows,
					'queue'    => $queue[0],
					'health'   => $health,
				],
				[ 'format' => $format ]
			);
			return;
		}
		self::table( $format, $rows, [ 'channel', 'enabled', 'ready', 'problems' ] );
		self::table( $format, $queue, [ 'pending', 'driver', 'next_run' ] );
		self::table( $format, $health, [ 'check', 'status', 'message' ] );
	}

	/**
	 * Find or list forms.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - scan
	 *   - list
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe forms scan
	 *     wp fnfe forms list --format=json
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function forms( $args, $assoc ): void {
		if ( $args[0] === 'scan' ) {
			if ( ! class_exists( FormScanner::class ) ) {
				WP_CLI::error( __( 'The form scanner is not available.', 'forms-notifier-for-elementor' ) );
			}
			$until = time() + self::SCAN_LIMIT;
			do {
				$total = FormScanner::run( 20 );
				$done  = ! array_key_exists( 'done', $total ) || $total['done'];
				if ( ! $done ) {
					sleep( 1 ); // Another scan may hold the lock.
				}
			} while ( ! $done && time() < $until );
			unset( $total['done'] );
			$parts = [];
			foreach ( $total as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$parts[] = $key . ': ' . $value;
				}
			}
			/* translators: %1$s: list of numbers */
			WP_CLI::success( sprintf( __( 'Scan finished. %1$s', 'forms-notifier-for-elementor' ), implode( ', ', $parts ) ) );
			return;
		}

		$rows = [];
		$page = 1;
		do {
			$list = FormRepository::query(
				[
					'page'     => $page,
					'per_page' => 100,
				]
			);
			foreach ( $list['items'] as $row ) {
				$summary = Controller::form_summary( $row );
				$rows[]  = [
					'id'          => $summary['id'],
					'key'         => $summary['form_key'],
					'name'        => $summary['name'],
					'source'      => $summary['source'],
					'status'      => $summary['status'],
					'active'      => $summary['active'] ? 'yes' : 'no',
					'channels'    => implode( ', ', $summary['channels'] ),
					'submissions' => $summary['submissions_count'],
				];
			}
		} while ( $list['total'] > 100 * $page++ );

		if ( ( $assoc['format'] ?? '' ) === 'ids' ) {
			WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}
		self::table( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'id', 'key', 'name', 'source', 'status', 'active', 'channels', 'submissions' ] );
	}

	/**
	 * Send a test message through a channel.
	 *
	 * ## OPTIONS
	 *
	 * <channel>
	 * : Channel id.
	 *
	 * [--to=<address>]
	 * : Destination. Default: the first receiver of the channel.
	 *
	 * [--text=<text>]
	 * : Text to send instead of the standard one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe test telegram --to=-1001234567890
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function test( $args, $assoc ): void {
		$channel = Registry::channel( (string) $args[0] );
		if ( ! $channel ) {
			WP_CLI::error( __( 'Unknown channel.', 'forms-notifier-for-elementor' ) );
		}
		$id       = $channel->id();
		$receiver = [];
		$address  = trim( (string) ( $assoc['to'] ?? '' ) );
		if ( $address !== '' ) {
			$address = $channel->normalize_address( $address );
		} elseif ( ( $channel->capabilities()['receivers'] ?? 'list' ) === 'none' ) {
			$address = Resolver::IMPLICIT;
		} else {
			foreach ( Settings::arr( "channels.$id.receivers" ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['enabled'] ) ) {
					$receiver = $row;
					$address  = (string) $row['address'];
					break;
				}
			}
		}
		if ( $address === '' ) {
			WP_CLI::error( __( 'There is no valid address. Use --to=<address>.', 'forms-notifier-for-elementor' ) );
		}

		$recipient       = new Recipient( $id, $address, (string) ( $receiver['label'] ?? '' ), 'test' );
		$recipient->lang = (string) ( $receiver['lang'] ?? '' );
		$recipient->meta = array_merge( (array) ( $receiver['meta'] ?? [] ), [ 'rid' => (string) ( $receiver['id'] ?? '' ) ] );

		$text = (string) ( $assoc['text'] ?? '' );
		if ( $text === '' ) {
			$result = $channel->test( $recipient );
		} else {
			$message          = new Message( $id, $recipient );
			$message->purpose = 'test';
			$message->format  = $channel->format();
			$message->body    = Registry::formatter( $message->format )->escape( $text );
			$message->plain   = $text;
			$message->meta    = [ 'test' => true ];
			$message->data    = [
				'event' => 'test',
				'text'  => $text,
			];

			$result = Dispatcher::send_now( $message );
		}
		if ( ! $result->ok ) {
			WP_CLI::error( $result->error === '' ? __( 'The test message could not be sent.', 'forms-notifier-for-elementor' ) : $result->error );
		}
		WP_CLI::success( __( 'The test message was sent.', 'forms-notifier-for-elementor' ) );
	}

	/**
	 * Create a test submission of a form and send its notifications.
	 *
	 * ## OPTIONS
	 *
	 * --form=<form>
	 * : Form id or form key.
	 *
	 * [--field=<field>]
	 * : Field value as id=value. May be given several times. Default: sample values.
	 *
	 * [--channels=<channels>]
	 * : Comma separated channel ids. Default: every channel the form uses.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe send --form=12 --field=name=Sara --field=email=sara@example.com
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function send( $args, $assoc ): void {
		unset( $args );
		$form = (string) $assoc['form'];
		$row  = ctype_digit( $form ) ? FormRepository::find( (int) $form ) : FormRepository::find_by_key( $form );
		if ( ! $row ) {
			WP_CLI::error( __( 'Unknown form.', 'forms-notifier-for-elementor' ) );
		}

		$draft             = new Submission();
		$draft->form_key   = (string) $row['form_key'];
		$draft->post_id    = (int) $row['post_id'];
		$draft->element_id = (string) $row['element_id'];
		$draft->source     = (string) $row['source'];
		$draft->form_name  = (string) $row['name'];
		$draft->fields     = self::fields( (string) ( $assoc['field'] ?? '' ), $row );
		$draft->meta       = [
			'via'    => 'test',
			'editor' => array_merge( (array) $row['editor'], [ 'fnfe_enabled' => 'yes' ] ),
		];
		$channels          = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc['channels'] ?? '' ) ) ) );
		if ( $channels ) {
			$draft->meta['editor']['fnfe_channels'] = array_values( $channels );
		}

		$saved = Capture::ingest( $draft );
		if ( ! $saved ) {
			WP_CLI::error( __( 'The submission was skipped.', 'forms-notifier-for-elementor' ) );
		}
		if ( ! $saved->id ) {
			$totals = Queue::run();
			/* translators: %1$d: messages sent, %2$d: messages that failed */
			WP_CLI::success( sprintf( __( 'Sent %1$d, failed %2$d.', 'forms-notifier-for-elementor' ), $totals['sent'], $totals['failed'] ) );
			return;
		}

		$ids = [];
		foreach ( MessageRepository::for_submission( $saved->id ) as $message ) {
			if ( in_array( $message['status'], [ 'queued', 'retry' ], true ) ) {
				$ids[] = (int) $message['id'];
			}
		}
		Queue::run_ids( $ids );
		self::messages( 'table', MessageRepository::for_submission( $saved->id ) );
		/* translators: %1$d: submission number */
		WP_CLI::success( sprintf( __( 'Submission %1$d was created.', 'forms-notifier-for-elementor' ), $saved->id ) );
	}

	/**
	 * Run, list or retry queued messages.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - run
	 *   - list
	 *   - retry
	 * ---
	 *
	 * [<id>]
	 * : Message id (retry).
	 *
	 * [--limit=<limit>]
	 * : Most messages.
	 *
	 * [--status=<status>]
	 * : Status (list). Default: queued and retry.
	 *
	 * [--all-failed]
	 * : Retry every failed message.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe queue run --limit=50
	 *     wp fnfe queue retry --all-failed
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function queue( $args, $assoc ): void {
		$limit = (int) ( $assoc['limit'] ?? 0 );
		switch ( $args[0] ) {
			case 'list':
				$list = MessageRepository::query(
					[
						'status'   => self::status_arg( $assoc, [ 'queued', 'retry' ] ),
						'per_page' => $limit > 0 ? $limit : 50,
					]
				);
				self::messages( (string) ( $assoc['format'] ?? 'table' ), $list['items'] );
				return;

			case 'retry':
				$this->retry( $args, $assoc );
				return;
		}
		MessageRepository::release_stale();
		$totals = Queue::run( $limit );
		WP_CLI::success(
			sprintf(
				/* translators: %1$d: processed, %2$d: sent, %3$d: failed, %4$d: still waiting */
				__( 'Processed %1$d, sent %2$d, failed %3$d, waiting %4$d.', 'forms-notifier-for-elementor' ),
				$totals['processed'],
				$totals['sent'],
				$totals['failed'],
				$totals['remaining']
			)
		);
	}

	/**
	 * List or purge the message log.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - list
	 *   - purge
	 * ---
	 *
	 * [--status=<status>]
	 * : Only this status.
	 *
	 * [--channel=<channel>]
	 * : Only this channel.
	 *
	 * [--limit=<limit>]
	 * : Most entries.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--days=<days>]
	 * : Purge finished messages older than this.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe log list --status=failed --limit=10
	 *     wp fnfe log purge --days=30
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function log( $args, $assoc ): void {
		if ( $args[0] === 'purge' ) {
			$days = (int) ( $assoc['days'] ?? 0 );
			if ( $days < 1 ) {
				WP_CLI::error( __( 'Use --days=<days> with a number from 1.', 'forms-notifier-for-elementor' ) );
			}
			$total = 0;
			do {
				$done   = MessageRepository::purge_older_than( $days );
				$total += $done;
			} while ( $done > 0 );
			/* translators: %1$d: number of entries */
			WP_CLI::success( sprintf( _n( 'Deleted %1$d entry.', 'Deleted %1$d entries.', $total, 'forms-notifier-for-elementor' ), $total ) );
			return;
		}
		$list = MessageRepository::query(
			[
				'status'   => self::status_arg( $assoc, [] ),
				'channel'  => sanitize_key( (string) ( $assoc['channel'] ?? '' ) ),
				'per_page' => max( 1, (int) ( $assoc['limit'] ?? 20 ) ),
			]
		);
		self::messages( (string) ( $assoc['format'] ?? 'table' ), $list['items'] );
	}

	/**
	 * Inspect or connect a bot.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : info, webhook or poll.
	 *
	 * <target>
	 * : Platform (telegram or bale); for webhook: set or delete.
	 *
	 * [<platform>]
	 * : Platform, for webhook.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe bot info telegram
	 *     wp fnfe bot webhook set telegram
	 *     wp fnfe bot poll bale
	 *
	 * @param array $args Positional arguments.
	 */
	public function bot( $args ): void {
		$action   = (string) $args[0];
		$platform = (string) ( $action === 'webhook' ? ( $args[2] ?? '' ) : $args[1] );
		if ( ! in_array( $action, [ 'info', 'webhook', 'poll' ], true ) || ! in_array( $platform, [ 'telegram', 'bale' ], true ) ) {
			WP_CLI::error( 'wp fnfe bot info|poll <telegram|bale>, wp fnfe bot webhook set|delete <telegram|bale>' );
		}
		if ( ! class_exists( Updates::class ) || ! BotClient::for_platform( $platform )->has_token() ) {
			WP_CLI::error( __( 'The bot token is not set.', 'forms-notifier-for-elementor' ) );
		}

		if ( $action === 'info' ) {
			WP_CLI::print_value( Updates::status( $platform ), [ 'format' => 'yaml' ] );
			return;
		}
		if ( $action === 'poll' ) {
			$result = Updates::poll( $platform );
			if ( empty( $result['ok'] ) ) {
				WP_CLI::error( (string) ( $result['message'] ?? '' ) );
			}
			/* translators: %1$d: number of updates, %2$d: number of chats */
			WP_CLI::success( sprintf( __( 'Updates: %1$d, new chats: %2$d.', 'forms-notifier-for-elementor' ), $result['updates'], $result['chats'] ) );
			return;
		}

		$mode  = [
			'set'    => 'webhook',
			'delete' => 'off',
		][ (string) $args[1] ] ?? '';
		$error = $mode === '' ? 'wp fnfe bot webhook set|delete <telegram|bale>' : Updates::connect( $platform, $mode );
		if ( $error !== '' ) {
			WP_CLI::error( $error );
		}
		WP_CLI::success( $mode === 'webhook' ? __( 'The webhook is set.', 'forms-notifier-for-elementor' ) : __( 'The webhook was removed.', 'forms-notifier-for-elementor' ) );
	}

	/**
	 * Show the digest, or send it now.
	 *
	 * ## OPTIONS
	 *
	 * [--send]
	 * : Send it to the configured receivers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe digest
	 *     wp fnfe digest --send
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function digest( $args, $assoc ): void {
		unset( $args );
		$data = Digest::data();
		if ( empty( $assoc['send'] ) ) {
			$rows = [];
			foreach ( Digest::tags( $data ) as $tag => $value ) {
				$rows[] = [
					'tag'   => $tag,
					'value' => str_replace( "\n", '; ', $value ),
				];
			}
			self::table( 'table', $rows, [ 'tag', 'value' ] );
			return;
		}
		$results = Digest::send( $data );
		if ( ! $results ) {
			WP_CLI::error( __( 'Switch on a channel that is ready and add a receiver first.', 'forms-notifier-for-elementor' ) );
		}
		$sent = 0;
		foreach ( $results as $item ) {
			if ( $item['result']->ok ) {
				++$sent;
			} else {
				WP_CLI::warning( $item['channel'] . ' ' . $item['recipient'] . ': ' . $item['result']->error );
			}
		}
		/* translators: %1$d: messages sent, %2$d: all messages */
		$text = sprintf( __( 'Sent %1$d of %2$d messages.', 'forms-notifier-for-elementor' ), $sent, count( $results ) );
		if ( ! $sent ) {
			WP_CLI::error( $text );
		}
		WP_CLI::success( $text );
	}

	/**
	 * Register texts with the multilingual plugin.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - sync
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe strings sync
	 */
	public function strings(): void {
		if ( ! class_exists( Strings::class ) ) {
			WP_CLI::error( __( 'Text registration is not available.', 'forms-notifier-for-elementor' ) );
		}
		Strings::sync_all();
		WP_CLI::success( __( 'The texts were registered.', 'forms-notifier-for-elementor' ) );
	}

	/**
	 * Export settings and form configurations as JSON. Secrets are never exported.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Write to this file instead of the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe export --file=notifier.json
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function export( $args, $assoc ): void {
		unset( $args );
		$json = (string) wp_json_encode( ImportExport::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$file = (string) ( $assoc['file'] ?? '' );
		if ( $file === '' ) {
			WP_CLI::line( $json );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The path is chosen on the command line.
		if ( file_put_contents( $file, $json . "\n" ) === false ) {
			WP_CLI::error( __( 'The file could not be written.', 'forms-notifier-for-elementor' ) );
		}
		/* translators: %1$s: file name */
		WP_CLI::success( sprintf( __( 'Written to %1$s.', 'forms-notifier-for-elementor' ), $file ) );
	}

	/**
	 * Import settings and form configurations.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Export file.
	 *
	 * [--mode=<mode>]
	 * : How to treat existing settings.
	 * ---
	 * default: merge
	 * options:
	 *   - merge
	 *   - replace
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe import notifier.json --mode=replace
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function import( $args, $assoc ): void {
		$file = (string) $args[0];
		if ( ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) > ImportExport::MAX_BYTES ) {
			WP_CLI::error( __( 'The file cannot be read or is larger than 2 MB.', 'forms-notifier-for-elementor' ) );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file named on the command line.
		if ( ! is_array( $data ) ) {
			WP_CLI::error( __( 'This is not valid JSON.', 'forms-notifier-for-elementor' ) );
		}
		$result = ImportExport::import( $data, (string) ( $assoc['mode'] ?? 'merge' ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		/* translators: %1$d: number of settings, %2$d: number of forms */
		WP_CLI::success( sprintf( __( 'Imported %1$d settings and %2$d forms.', 'forms-notifier-for-elementor' ), $result['imported']['settings'], $result['imported']['forms'] ) );
	}

	/**
	 * Apply the retention settings now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fnfe cleanup
	 */
	public function cleanup(): void {
		$result = Cleanup::run();
		// The other modules attach their own retention to this hook.
		do_action( Cron::CLEANUP ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Constant with the prefix fnfe_.
		/* translators: %1$d: number of messages, %2$d: number of system log entries */
		WP_CLI::success( sprintf( __( 'Deleted %1$d messages and %2$d system log entries.', 'forms-notifier-for-elementor' ), $result['messages'], $result['log'] ) );
	}

	/**
	 * Retry one message or every failed one.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	private function retry( array $args, array $assoc ): void {
		$ids = [];
		if ( ! empty( $assoc['all-failed'] ) ) {
			$page = 1;
			do {
				$list = MessageRepository::query(
					[
						'status'   => 'failed',
						'per_page' => 100,
						'page'     => $page,
						'order'    => 'asc',
					]
				);
				$ids  = array_merge( $ids, array_map( 'intval', array_column( $list['items'], 'id' ) ) );
			} while ( $list['total'] > 100 * $page++ );
		} elseif ( (int) ( $args[1] ?? 0 ) > 0 ) {
			$ids = [ (int) $args[1] ];
		} else {
			WP_CLI::error( __( 'Give a message id or use --all-failed.', 'forms-notifier-for-elementor' ) );
		}

		$ready = array_filter( $ids, [ Queue::class, 'retry' ] );
		if ( ! $ready && $ids ) {
			WP_CLI::error( __( 'No message can be sent again.', 'forms-notifier-for-elementor' ) );
		}
		$totals = Queue::run_ids( array_values( $ready ) );
		/* translators: %1$d: messages sent, %2$d: messages that failed */
		WP_CLI::success( sprintf( __( 'Sent %1$d, failed %2$d.', 'forms-notifier-for-elementor' ), $totals['sent'], $totals['failed'] ) );
	}

	/**
	 * Fields of a test submission.
	 *
	 * @param string $single Value of --field.
	 * @param array  $row    Form row.
	 */
	private static function fields( string $single, array $row ): array {
		// WP-CLI keeps only the last one of a repeated option.
		$pairs = [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Command line arguments, sanitised below.
		foreach ( isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : [] as $argument ) {
			if ( is_string( $argument ) && strncmp( $argument, '--field=', 8 ) === 0 ) {
				$pairs[] = substr( $argument, 8 );
			}
		}
		if ( ! $pairs && $single !== '' ) {
			$pairs[] = $single;
		}
		if ( ! $pairs ) {
			return Engine::sample_submission( (int) $row['id'] )->fields;
		}

		$known = array_column( (array) $row['fields'], null, 'id' );
		$out   = [];
		foreach ( $pairs as $pair ) {
			list( $id, $value ) = array_pad( explode( '=', $pair, 2 ), 2, '' );

			$id = sanitize_text_field( $id );
			if ( $id === '' ) {
				continue;
			}
			$value      = sanitize_textarea_field( $value );
			$out[ $id ] = [
				'id'       => $id,
				'type'     => (string) ( $known[ $id ]['type'] ?? 'text' ),
				'label'    => (string) ( $known[ $id ]['label'] ?? $id ),
				'value'    => $value,
				'values'   => $value === '' ? [] : [ $value ],
				'raw'      => $value,
				'is_file'  => false,
				'redacted' => false,
				'hidden'   => false,
			];
		}
		return $out;
	}

	/**
	 * Status option.
	 *
	 * @param array    $assoc   Options.
	 * @param string[] $default Default.
	 * @return string[]
	 */
	private static function status_arg( array $assoc, array $default ): array {
		$list = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['status'] ?? '' ) ) ) );
		if ( array_diff( $list, self::STATUSES ) ) {
			/* translators: %1$s: list of statuses */
			WP_CLI::error( sprintf( __( 'Unknown status. Use one of: %1$s', 'forms-notifier-for-elementor' ), implode( ', ', self::STATUSES ) ) );
		}
		return $list ? array_values( $list ) : $default;
	}

	/**
	 * Table of messages.
	 *
	 * @param string  $format Format.
	 * @param array[] $rows   Rows of MessageRepository.
	 */
	private static function messages( string $format, array $rows ): void {
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'        => $row['id'],
				'created'   => $row['created_gmt'],
				'channel'   => $row['channel'],
				'purpose'   => $row['purpose'],
				'recipient' => $row['recipient_label'] === '' ? $row['recipient'] : $row['recipient_label'],
				'status'    => $row['status'],
				'attempts'  => $row['attempts'] . '/' . $row['max_attempts'],
				'error'     => $row['error'],
			];
		}
		self::table( $format, $items, [ 'id', 'created', 'channel', 'purpose', 'recipient', 'status', 'attempts', 'error' ] );
	}

	/**
	 * Print rows.
	 *
	 * @param string   $format  Format.
	 * @param array[]  $rows    Rows.
	 * @param string[] $columns Columns.
	 */
	private static function table( string $format, array $rows, array $columns ): void {
		\WP_CLI\Utils\format_items( $format, $rows, $columns );
	}
}
