<?php
/**
 * Help topics.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Admin;

use AmirhpCom\FormsNotifier\Contracts\ModuleInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/** Core help topics and GET /help, which lists the topics of every part of the plugin. */
final class HelpContent extends Controller implements ModuleInterface {

	/** HTML kept in topic bodies. */
	private const HTML = [
		'a'      => [
			'href'   => true,
			'target' => true,
			'rel'    => true,
		],
		'code'   => [],
		'strong' => [],
		'em'     => [],
		'b'      => [],
		'i'      => [],
		'br'     => [],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
		'p'      => [],
		'kbd'    => [],
	];

	/** Attach hooks. */
	public function register(): void {
		add_filter( 'fnfe_help_topics', [ self::class, 'topics' ], 5 );
		add_action( 'fnfe_rest_api_init', [ $this, 'register_routes' ] );
	}

	/** Register GET /help. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/help',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( [ 'items' => HelpContent::all() ] );
				},
				'permission_callback' => $this->can( ...Capabilities::all() ),
				'args'                => [],
			]
		);
	}

	/**
	 * Every topic, cleaned and ordered by group.
	 *
	 * @return array<int,array{id:string,title:string,group:string,body:string,steps:array[]}>
	 */
	public static function all(): array {
		$groups = self::groups();
		$order  = array_flip( array_keys( $groups ) );
		$rows   = [];
		foreach ( (array) apply_filters( 'fnfe_help_topics', [] ) as $id => $topic ) {
			$id = is_string( $id ) ? sanitize_key( $id ) : '';
			if ( $id === '' || ! is_array( $topic ) || ! is_scalar( $topic['title'] ?? null ) ) {
				continue;
			}
			$steps = [];
			foreach ( is_array( $topic['steps'] ?? null ) ? $topic['steps'] : [] as $step ) {
				if ( is_array( $step ) ) {
					$steps[] = [
						'title' => self::text( $step['title'] ?? '' ),
						'body'  => self::html( $step['body'] ?? '' ),
						'code'  => is_scalar( $step['code'] ?? null ) ? (string) $step['code'] : '',
						'lang'  => sanitize_key( self::text( $step['lang'] ?? '' ) ),
						'copy'  => ! empty( $step['copy'] ),
					];
				}
			}
			$group = self::text( $topic['group'] ?? '' );
			$key   = isset( $groups[ $group ] ) ? $group : array_search( $group, $groups, true );
			$key   = $key === false ? '' : (string) $key;
			if ( $key === '' && $group === '' ) {
				$key = 'other';
			}
			$rows[] = [
				$key === '' ? count( $order ) - 2 : $order[ $key ],
				count( $rows ),
				[
					'id'    => $id,
					'title' => self::text( $topic['title'] ),
					'group' => $key === '' ? $group : $groups[ $key ],
					'body'  => self::html( $topic['body'] ?? '' ),
					'steps' => $steps,
				],
			];
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ];
			}
		);
		return array_column( $rows, 2 );
	}

	/** Group id => label, in display order. */
	private static function groups(): array {
		return [
			'start'           => __( 'Getting started', 'forms-notifier-for-elementor' ),
			'channels'        => __( 'Channels', 'forms-notifier-for-elementor' ),
			'templates'       => __( 'Templates & tags', 'forms-notifier-for-elementor' ),
			'bots'            => __( 'Bots and Mini App', 'forms-notifier-for-elementor' ),
			'sms'             => __( 'SMS and WhatsApp', 'forms-notifier-for-elementor' ),
			'webhooks'        => __( 'Webhooks', 'forms-notifier-for-elementor' ),
			'developers'      => __( 'Developers', 'forms-notifier-for-elementor' ),
			'troubleshooting' => __( 'Troubleshooting', 'forms-notifier-for-elementor' ),
			'other'           => __( 'More', 'forms-notifier-for-elementor' ),
			'credits'         => __( 'Credits', 'forms-notifier-for-elementor' ),
		];
	}

	/**
	 * Filter fnfe_help_topics: the core topics.
	 *
	 * @param array $topics Topics.
	 */
	public static function topics( $topics ): array {
		return array_merge( (array) $topics, self::user_topics(), self::developer_topics(), self::last_topics() );
	}

	/** Topics for everybody. */
	private static function user_topics(): array {
		return [
			'quick-start' => [
				'title' => __( 'Quick start', 'forms-notifier-for-elementor' ),
				'group' => 'start',
				'body'  => '<p>' . esc_html__( 'Five steps from a fresh install to the first message.', 'forms-notifier-for-elementor' ) . '</p>',
				'steps' => [
					self::step( __( 'Find your forms', 'forms-notifier-for-elementor' ), __( 'Open Forms and press Scan now. Forms in pages, popups, templates and global widgets appear in the list. A form that is missing is added the first time somebody submits it.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Connect a channel', 'forms-notifier-for-elementor' ), __( 'Open Channels, choose one and fill in the connection. Email works without any account.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Add receivers', 'forms-notifier-for-elementor' ), __( 'On the same screen add who gets the messages. These receivers get the submissions of every form.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Send a test', 'forms-notifier-for-elementor' ), __( 'Press Send test. It also works before you save. The Log shows what the service answered.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Fine-tune single forms', 'forms-notifier-for-elementor' ), __( 'Open a form to give it own receivers, texts, buttons, rules or a reply to the person who sent it.', 'forms-notifier-for-elementor' ) ),
				],
			],
			'forms'       => [
				'title' => __( 'Settings of one form', 'forms-notifier-for-elementor' ),
				'group' => 'start',
				'body'  => self::paragraphs(
					[
						__( 'A form follows the site-wide settings until you change something on its screen. The more specific setting wins: site, then form, then the form action in the Elementor editor, then routing rules.', 'forms-notifier-for-elementor' ),
						__( 'You do not have to add an action to the form. The action "Forms Notifier" in the editor is for people who prefer to set a form up there.', 'forms-notifier-for-elementor' ),
						__( 'Rules are read from top to bottom. A rule can add receivers, replace them, keep only some channels or stop all notifications, depending on what was entered.', 'forms-notifier-for-elementor' ),
						__( 'For translations of one page link the forms: one form keeps the settings and every text can have a version per language.', 'forms-notifier-for-elementor' ),
					]
				),
			],
			'submissions' => [
				'title' => __( 'Saved submissions', 'forms-notifier-for-elementor' ),
				'group' => 'start',
				'body'  => self::paragraphs(
					[
						__( 'Submissions are saved on this site, with their files, unless you switch saving off in the general settings. Passwords and fields marked as sensitive are never saved.', 'forms-notifier-for-elementor' ),
						__( 'If Elementor Pro collected submissions earlier, the Import button copies them. Nobody is notified and nothing is imported twice.', 'forms-notifier-for-elementor' ),
						__( 'How long data is kept is set under Settings, Privacy and data. The personal data tools of WordPress include submissions, files and messages.', 'forms-notifier-for-elementor' ),
					]
				),
			],
			'channels'    => [
				'title' => __( 'Channels and receivers', 'forms-notifier-for-elementor' ),
				'group' => 'channels',
				'body'  => self::paragraphs(
					[
						__( 'A channel is a way to send: Telegram, Bale, SMS, WhatsApp, email, webhooks and others. Receivers are the people, groups or addresses behind it.', 'forms-notifier-for-elementor' ),
						__( 'A channel is ready when its lamp is green. Amber means something is missing; the screen of the channel says what.', 'forms-notifier-for-elementor' ),
						__( 'Tokens and passwords are stored encrypted and never shown again. They can also be set as constants in wp-config.php; the name of the constant is shown next to a locked field.', 'forms-notifier-for-elementor' ),
						__( 'A receiver can have its own message language and can be limited to submissions in some languages.', 'forms-notifier-for-elementor' ),
					]
				),
			],
			'alerts'      => [
				'title' => __( 'Failure alerts', 'forms-notifier-for-elementor' ),
				'group' => 'channels',
				'body'  => self::paragraphs(
					[
						__( 'Alerts tell administrators that a message could not be delivered. They have their own channels and receivers.', 'forms-notifier-for-elementor' ),
						__( 'Choose a channel other than the one you want to watch: a broken channel cannot report itself. Alerts are limited per hour and never cause further alerts.', 'forms-notifier-for-elementor' ),
					]
				),
			],
			'digest'      => [
				'title' => __( 'Digest', 'forms-notifier-for-elementor' ),
				'group' => 'channels',
				'body'  => self::paragraphs( [ __( 'A daily or weekly report: submissions per form, sent and failed messages, problems and a comparison with the period before. Send digest now sends one at once with the values on the screen.', 'forms-notifier-for-elementor' ) ] ),
			],
			'log'         => [
				'title' => __( 'Log, queue and retries', 'forms-notifier-for-elementor' ),
				'group' => 'channels',
				'body'  => self::paragraphs(
					[
						__( 'Messages are sent in the background right after the form answered, so visitors do not wait.', 'forms-notifier-for-elementor' ),
						__( 'A temporary failure is tried again with growing pauses. A permanent failure, such as a blocked bot or a wrong number, is not. Both can be sent again by hand.', 'forms-notifier-for-elementor' ),
						__( 'The small bars show attempts: filled ones are used, the last one has the colour of the result.', 'forms-notifier-for-elementor' ),
					]
				),
			],
		];
	}

	/** Topics for developers. */
	private static function developer_topics(): array {
		return [
			'developer-hooks'   => [
				'title' => __( 'Hooks', 'forms-notifier-for-elementor' ),
				'group' => 'developers',
				'body'  => '<p>' . esc_html__( 'The most used actions and filters. The developer guide lists all of them.', 'forms-notifier-for-elementor' ) . '</p>'
					. self::code_list(
						[
							'fnfe_submission_captured' => __( 'A submission was captured.', 'forms-notifier-for-elementor' ),
							'fnfe_should_capture'      => __( 'Refuse a submission.', 'forms-notifier-for-elementor' ),
							'fnfe_submission'          => __( 'Change a submission before it is saved.', 'forms-notifier-for-elementor' ),
							'fnfe_deliveries'          => __( 'Change who gets a submission.', 'forms-notifier-for-elementor' ),
							'fnfe_message'             => __( 'Change a message before it is queued.', 'forms-notifier-for-elementor' ),
							'fnfe_message_sent'        => __( 'A message was sent.', 'forms-notifier-for-elementor' ),
							'fnfe_message_failed'      => __( 'An attempt failed.', 'forms-notifier-for-elementor' ),
							'fnfe_template_tags'       => __( 'Add template tags.', 'forms-notifier-for-elementor' ),
							'fnfe_template_filters'    => __( 'Add template filters.', 'forms-notifier-for-elementor' ),
							'fnfe_condition_ops'       => __( 'Add comparisons for rules.', 'forms-notifier-for-elementor' ),
							'fnfe_webhook_payload'     => __( 'Change the body of a webhook.', 'forms-notifier-for-elementor' ),
							'fnfe_http_args'           => __( 'Change every outgoing request.', 'forms-notifier-for-elementor' ),
							'fnfe_bot_commands'        => __( 'Add bot commands.', 'forms-notifier-for-elementor' ),
							'fnfe_callback_actions'    => __( 'Add actions for inline buttons.', 'forms-notifier-for-elementor' ),
							'fnfe_settings_sections'   => __( 'Add settings.', 'forms-notifier-for-elementor' ),
							'fnfe_help_topics'         => __( 'Add help topics.', 'forms-notifier-for-elementor' ),
							'fnfe_capability'          => __( 'Change who may do what.', 'forms-notifier-for-elementor' ),
						]
					),
				'steps' => [
					self::code(
						__( 'Do not notify about test addresses', 'forms-notifier-for-elementor' ),
						"add_filter( 'fnfe_should_capture', function ( \$capture, \$submission, \$form ) {\n\treturn \$submission->value( 'email' ) === 'test@example.com' ? false : \$capture;\n}, 10, 3 );"
					),
					self::code(
						__( 'Add a template tag {order_total}', 'forms-notifier-for-elementor' ),
						"add_filter( 'fnfe_template_tags', function ( \$tags ) {\n\t\$tags['order_total'] = function ( \$arg, \$context ) {\n\t\treturn (string) get_option( 'my_order_total', '' );\n\t};\n\treturn \$tags;\n} );"
					),
				],
			],
			'developer-send'    => [
				'title' => __( 'Send from your own code', 'forms-notifier-for-elementor' ),
				'group' => 'developers',
				'body'  => '<p>' . esc_html__( 'Use the same channels, receivers and templates for data that does not come from Elementor.', 'forms-notifier-for-elementor' ) . '</p>',
				'steps' => [
					self::code(
						__( 'A notification with fields', 'forms-notifier-for-elementor' ),
						"if ( function_exists( 'fnfe_send' ) ) {\n\tfnfe_send( [\n\t\t'form_name' => 'Callback request',\n\t\t'fields'    => [\n\t\t\t'name'  => 'Sara',\n\t\t\t'phone' => [ 'label' => 'Phone', 'value' => '+989120000000', 'type' => 'tel' ],\n\t\t],\n\t\t'channels'  => [ 'telegram', 'email' ],\n\t] );\n}"
					),
					self::code(
						__( 'One text to one address', 'forms-notifier-for-elementor' ),
						"\$result = fnfe_send_message( 'telegram', '-1001234567890', 'The backup is finished.' );\nif ( ! \$result->ok ) {\n\terror_log( \$result->error );\n}"
					),
				],
			],
			'developer-channel' => [
				'title' => __( 'Add a channel', 'forms-notifier-for-elementor' ),
				'group' => 'developers',
				'body'  => '<p>' . esc_html__( 'A channel is one class. Its settings screen, receivers, per-form options, test button, log and alerts come from the description it returns; no JavaScript is needed.', 'forms-notifier-for-elementor' ) . '</p>',
				'steps' => [
					self::code(
						__( 'Smallest channel', 'forms-notifier-for-elementor' ),
						"use AmirhpCom\\FormsNotifier\\Channels\\AbstractChannel;\nuse AmirhpCom\\FormsNotifier\\Core\\Http;\nuse AmirhpCom\\FormsNotifier\\Model\\Message;\nuse AmirhpCom\\FormsNotifier\\Model\\SendResult;\n\nfinal class DeskChannel extends AbstractChannel {\n\tpublic function id(): string {\n\t\treturn 'desk';\n\t}\n\tpublic function label(): string {\n\t\treturn 'Desk';\n\t}\n\tprotected function own_sections(): array {\n\t\treturn [ [\n\t\t\t'id'     => 'desk_connection',\n\t\t\t'title'  => 'Connection',\n\t\t\t'fields' => [ \$this->field( 'token', 'secret', 'Token' ) ],\n\t\t] ];\n\t}\n\tpublic function problems(): array {\n\t\treturn \$this->secret( 'token' ) === '' ? [ 'Enter the token.' ] : [];\n\t}\n\tpublic function send( Message \$message ): SendResult {\n\t\treturn \$this->from_http( Http::post_json( 'https://desk.example/api/messages', [\n\t\t\t'to'   => \$message->recipient->address,\n\t\t\t'text' => \$message->body,\n\t\t] ) );\n\t}\n}\n\nadd_filter( 'fnfe_channels', function ( \$channels ) {\n\t\$channels['desk'] = DeskChannel::class;\n\treturn \$channels;\n} );"
					),
				],
			],
			'developer-panel'   => [
				'title' => __( 'Extend the panel', 'forms-notifier-for-elementor' ),
				'group' => 'developers',
				'body'  => '<p>' . esc_html__( 'Scripts can add field types and change sections and routes through the hooks of WordPress.', 'forms-notifier-for-elementor' ) . '</p>'
					. self::code_list(
						[
							'fnfe.fieldRenderers'         => __( 'Add or replace field types.', 'forms-notifier-for-elementor' ),
							'fnfe.channelScreen.sections' => __( 'Change the sections of a channel screen.', 'forms-notifier-for-elementor' ),
							'fnfe.routes'                 => __( 'Change the main navigation.', 'forms-notifier-for-elementor' ),
							'fnfe.screen.rendered'        => __( 'A screen was drawn.', 'forms-notifier-for-elementor' ),
						]
					),
				'steps' => [
					self::code(
						__( 'A field type of your own', 'forms-notifier-for-elementor' ),
						"wp.hooks.addFilter( 'fnfe.fieldRenderers', 'my/stars', ( renderers ) => Object.assign( {}, renderers, {\n\tstars: ( field, value, onChange, ctx ) => fnfe.h( 'input', {\n\t\ttype: 'range', min: 0, max: 5, value: value || 0, id: ctx.id,\n\t\tonInput: ( event ) => onChange( Number( event.target.value ) ),\n\t} ),\n} ) );",
						'js'
					),
				],
			],
		];
	}

	/** Troubleshooting and credits. */
	private static function last_topics(): array {
		return [
			'troubleshooting' => [
				'title' => __( 'When nothing arrives', 'forms-notifier-for-elementor' ),
				'group' => 'troubleshooting',
				'body'  => '<p>' . esc_html__( 'Check these in order.', 'forms-notifier-for-elementor' ) . '</p>',
				'steps' => [
					self::step( __( 'Is there a submission?', 'forms-notifier-for-elementor' ), __( 'Open Submissions. If the test is not there, the form is switched off, or saving is switched off in the general settings.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Is there a message in the log?', 'forms-notifier-for-elementor' ), __( 'No entry: the form has no active channel with receivers. Skipped: the channel is not ready; the entry says why.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Does it stay in the queue?', 'forms-notifier-for-elementor' ), __( 'Background tasks do not run on the host. Press Run queue now. If that works, set up a real cron job for WordPress, or choose Immediately in the general settings.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Did it fail?', 'forms-notifier-for-elementor' ), __( 'Open the entry: the answer of the service is at the bottom. Fix the cause and press Send again.', 'forms-notifier-for-elementor' ) ),
					self::step( __( 'Cannot reach the service at all?', 'forms-notifier-for-elementor' ), __( 'Some hosts block Telegram or other services. Use a custom API address, as the guide on the channel screen shows.', 'forms-notifier-for-elementor' ) ),
				],
			],
			'faq'             => [
				'title' => __( 'Questions and answers', 'forms-notifier-for-elementor' ),
				'group' => 'troubleshooting',
				'body'  => self::answers(
					[
						__( 'Do I have to add an action to every form?', 'forms-notifier-for-elementor' ) => __( 'No. By default every form notifies. Under Settings, General you can choose selected forms only.', 'forms-notifier-for-elementor' ),
						__( 'Why is my group not in the list of chats?', 'forms-notifier-for-elementor' ) => __( 'A bot only knows chats it heard from. Add it to the group and send /id there.', 'forms-notifier-for-elementor' ),
						__( 'Why does a button not appear?', 'forms-notifier-for-elementor' ) => __( 'Messengers reject links to local or private addresses, and the mini app needs HTTPS. Such buttons are left out so the message still arrives.', 'forms-notifier-for-elementor' ),
						__( 'Where are tokens stored?', 'forms-notifier-for-elementor' ) => __( 'Encrypted in the database with a key made from the salts of the site. If the salts change, enter the tokens again.', 'forms-notifier-for-elementor' ),
						__( 'What happens when I delete the plugin?', 'forms-notifier-for-elementor' ) => __( 'Nothing is lost unless you switched on deletion under Settings, Privacy and data.', 'forms-notifier-for-elementor' ),
					]
				),
			],
			'credits'         => [
				'title' => __( 'Credits', 'forms-notifier-for-elementor' ),
				'group' => 'credits',
				'body'  => self::paragraphs(
					[
						__( 'Forms Notifier for Elementor is made by Amirhossein Hosseinpour (amirhp.com) and licensed under GPLv2 or later.', 'forms-notifier-for-elementor' ),
						__( 'The Persian panel uses the Dastkhat font, version 1.220, by Dastyar, based on Vazirmatn by Saber Rastikerdar. Licence: SIL Open Font License 1.1. Source: github.com/dastyar-io/dastkhat-font. The font files and the licence are part of the plugin; nothing is loaded from other servers.', 'forms-notifier-for-elementor' ),
						__( 'Product and service names belong to their owners. The plugin is not affiliated with Elementor, Telegram, Bale, Meta or any other service it connects to.', 'forms-notifier-for-elementor' ),
					]
				),
			],
		];
	}

	/**
	 * Step without code.
	 *
	 * @param string $title Title.
	 * @param string $body  Plain text.
	 */
	private static function step( string $title, string $body ): array {
		return [
			'title' => $title,
			'body'  => esc_html( $body ),
		];
	}

	/**
	 * Step with a code sample.
	 *
	 * @param string $title Title.
	 * @param string $code  Code.
	 * @param string $lang  Language.
	 */
	private static function code( string $title, string $code, string $lang = 'php' ): array {
		return [
			'title' => $title,
			'code'  => $code,
			'lang'  => $lang,
			'copy'  => true,
		];
	}

	/**
	 * Paragraphs from plain texts.
	 *
	 * @param string[] $texts Texts.
	 */
	private static function paragraphs( array $texts ): string {
		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $texts ) ) . '</p>';
	}

	/**
	 * Questions and answers.
	 *
	 * @param array<string,string> $items Question => answer.
	 */
	private static function answers( array $items ): string {
		$out = '';
		foreach ( $items as $question => $answer ) {
			$out .= '<p><strong>' . esc_html( $question ) . '</strong><br>' . esc_html( $answer ) . '</p>';
		}
		return $out;
	}

	/**
	 * List of code names.
	 *
	 * @param array<string,string> $items Name => description.
	 */
	private static function code_list( array $items ): string {
		$out = '';
		foreach ( $items as $name => $text ) {
			$out .= '<li><code>' . esc_html( $name ) . '</code> ' . esc_html( $text ) . '</li>';
		}
		return '<ul>' . $out . '</ul>';
	}

	/**
	 * Plain text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Limited HTML.
	 *
	 * @param mixed $value Value.
	 */
	private static function html( $value ): string {
		return is_scalar( $value ) ? wp_kses( (string) $value, self::HTML ) : '';
	}
}
