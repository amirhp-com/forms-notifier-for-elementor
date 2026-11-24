<?php
/**
 * Worker guide.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Bot;

defined( 'ABSPATH' ) || exit;

/** Guide for hosts that cannot reach a messenger: forwarder on a free Worker, own server or a self-hosted API server. */
final class WorkerGuide {

	/** Sample code shown in the guide. */
	private const FILE = 'assets/worker/bot-api-proxy.js';

	/** @var string|null */
	private static $code = null;

	/** Source of the sample Worker. */
	public static function code(): string {
		if ( self::$code === null ) {
			$path = FNFE_DIR . self::FILE;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the plugin.
			self::$code = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		}
		return self::$code;
	}

	/**
	 * Steps of the guide: [ title, body (limited HTML), code, lang, copy ].
	 *
	 * @param string $platform telegram | bale.
	 */
	public static function steps( string $platform ): array {
		$upstream = BotClient::default_base( $platform );
		$host     = (string) wp_parse_url( $upstream, PHP_URL_HOST );
		$base     = 'https://name.account.workers.dev';

		$steps = [
			self::step(
				__( 'What this is for', 'forms-notifier-for-elementor' ),
				sprintf(
					/* translators: %1$s: host name of the API */
					esc_html__( 'Some hosting companies cannot reach %1$s. A Worker is a small program that runs on the network of Cloudflare: your site calls the Worker, the Worker calls the messenger. The free plan is enough.', 'forms-notifier-for-elementor' ),
					'<code>' . esc_html( $host ) . '</code>'
				)
			),
			self::step(
				__( 'Create the Worker', 'forms-notifier-for-elementor' ),
				esc_html__( 'Create a free account in the Cloudflare dashboard. Open "Workers & Pages", choose "Create", then "Worker", give it a name and press "Deploy".', 'forms-notifier-for-elementor' )
			),
			self::step(
				__( 'Paste the code', 'forms-notifier-for-elementor' ),
				esc_html__( 'Press "Edit code", delete everything in the editor, paste this code and press "Deploy".', 'forms-notifier-for-elementor' ),
				self::code(),
				'js'
			),
		];

		$variables = esc_html__( 'Recommended: in the settings of the Worker add a secret variable named ALLOWED_BOT_ID. Its value is the numeric part of your token before the colon. Other bots are then refused.', 'forms-notifier-for-elementor' );
		$sample    = 'ALLOWED_BOT_ID = 123456789';
		if ( $platform === 'bale' ) {
			$variables .= '<br>' . esc_html__( 'Required for Bale: add a variable named UPSTREAM with the address of the Bale API.', 'forms-notifier-for-elementor' );
			$sample    .= "\nUPSTREAM = " . $upstream;
		}
		$steps[] = self::step( __( 'Set the variables', 'forms-notifier-for-elementor' ), $variables, $sample, 'text' );

		$steps[] = self::step(
			__( 'Copy the address', 'forms-notifier-for-elementor' ),
			esc_html__( 'The dashboard shows the address of the Worker. Where workers.dev is filtered, attach your own domain to the Worker ("Domains & Routes") and use that address.', 'forms-notifier-for-elementor' ),
			$base,
			'text'
		);
		$steps[] = self::step(
			__( 'Connect it', 'forms-notifier-for-elementor' ),
			esc_html__( 'Paste the address into "API base URL", without a slash at the end, press "Test connection" and save.', 'forms-notifier-for-elementor' )
		);
		$steps[] = self::step(
			__( 'Check it by hand', 'forms-notifier-for-elementor' ),
			esc_html__( 'Run this from any computer. The answer "Unauthorized" proves that the path works: the made-up token reached the messenger. With ALLOWED_BOT_ID set, the answer is "Bot not allowed".', 'forms-notifier-for-elementor' ),
			'curl ' . $base . "/bot123456:TEST/getMe\n\n{\"ok\":false,\"error_code\":401,\"description\":\"Unauthorized\"}",
			'bash'
		);
		$steps[] = self::step(
			__( 'If it does not work', 'forms-notifier-for-elementor' ),
			implode(
				'<br>',
				[
					'<strong>404</strong> ' . esc_html__( 'The path is wrong: enter only the address of the Worker, nothing after it.', 'forms-notifier-for-elementor' ),
					'<strong>403</strong> ' . esc_html__( 'ALLOWED_BOT_ID does not match the number at the start of your token.', 'forms-notifier-for-elementor' ),
					'<strong>1101</strong> ' . esc_html__( 'The code has an error: paste it again completely.', 'forms-notifier-for-elementor' ),
					'<strong>1015</strong> ' . esc_html__( 'Too many requests, or the daily limit of the free plan is reached.', 'forms-notifier-for-elementor' ),
					'<strong>' . esc_html__( 'Timeout', 'forms-notifier-for-elementor' ) . '</strong> ' . esc_html__( 'Your host cannot reach the Worker either: use your own domain.', 'forms-notifier-for-elementor' ),
				]
			)
		);
		$steps[] = self::step(
			__( 'Limits and privacy', 'forms-notifier-for-elementor' ),
			esc_html__( 'The free plan limits the number of requests per day and the size of an upload. Send large files as links. The token and the messages pass through your own Worker; the sample code stores nothing. Do not use a Worker that belongs to somebody else.', 'forms-notifier-for-elementor' )
			. '<br>' . esc_html__( 'The Worker only carries requests from the site to the messenger. If the messenger cannot call your site, choose "Regular checks" for receiving messages.', 'forms-notifier-for-elementor' )
		);
		$steps[] = self::step(
			__( 'Other ways: your own server', 'forms-notifier-for-elementor' ),
			esc_html__( 'A server that reaches the messenger can forward the requests too. Example for nginx, inside a server block with HTTPS:', 'forms-notifier-for-elementor' ),
			self::nginx( $upstream, $host ),
			'nginx'
		);
		if ( $platform === 'telegram' ) {
			$steps[] = self::step(
				__( 'Other ways: self-hosted API server', 'forms-notifier-for-elementor' ),
				esc_html__( 'Telegram publishes its API server as open source (telegram-bot-api). Run it on your own server to upload files up to 2000 MB. Enter its address as the API base URL; a plain http:// address in your own network also needs "Allow private hosts" under Advanced.', 'forms-notifier-for-elementor' )
			);
		}
		return $steps;
	}

	/** Help topic. */
	public static function topic(): array {
		return [
			'title' => __( 'Reach a messenger through your own forwarder', 'forms-notifier-for-elementor' ),
			'group' => BotModule::help_group(),
			'body'  => esc_html__( 'For sites whose hosting cannot reach Telegram or Bale. The steps are for Telegram; for Bale add the variable UPSTREAM with the address of the Bale API.', 'forms-notifier-for-elementor' ),
			'steps' => self::steps( 'telegram' ),
		];
	}

	/**
	 * Example for nginx.
	 *
	 * @param string $upstream API address.
	 * @param string $host     API host.
	 */
	private static function nginx( string $upstream, string $host ): string {
		return implode(
			"\n",
			[
				'location ~ ^/(file/)?bot[0-9]+: {',
				'    resolver 1.1.1.1;',
				'    proxy_pass ' . $upstream . '$request_uri;',
				'    proxy_set_header Host ' . $host . ';',
				'    proxy_ssl_server_name on;',
				'    client_max_body_size 60m;',
				'    access_log off;',
				'}',
			]
		);
	}

	/**
	 * One step.
	 *
	 * @param string $title Title.
	 * @param string $body  Limited HTML.
	 * @param string $code  Code.
	 * @param string $lang  Language of the code.
	 */
	private static function step( string $title, string $body, string $code = '', string $lang = '' ): array {
		return [
			'title' => $title,
			'body'  => $body,
			'code'  => $code,
			'lang'  => $lang,
			'copy'  => $code !== '',
		];
	}
}
