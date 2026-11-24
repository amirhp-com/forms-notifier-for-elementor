<?php
/**
 * Fake channel.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Tests;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;

/**
 * Records messages and returns scripted results. Implements the interface directly, like a third-party channel would.
 *
 *   FakeChannel::install();                              registers 'fake'
 *   FakeChannel::install( 'fake2', [ 'buttons' => [ 'url' ] ], 'telegram_html' );
 *   FakeChannel::$results = [ SendResult::failure( ... ), SendResult::success( 'fake' ) ];
 */
final class FakeChannel implements ChannelInterface {

	/** @var Message[] sent messages, in order */
	public static $sent = [];

	/** @var SendResult[]|\Throwable[] next results; success when empty */
	public static $results = [];

	/** @var string[] problems of every fake channel */
	public static $problems = [];

	/** @var array[] params of the 'ping' action */
	public static $pings = [];

	/** @var string */
	private $id;

	/** @var array */
	private $capabilities;

	/** @var string */
	private $format;

	public function __construct( string $id = 'fake', array $capabilities = [], string $format = 'text' ) {
		$this->id           = $id;
		$this->format       = $format;
		$this->capabilities = array_merge(
			[
				'receivers'     => 'list',
				'receiver_kind' => 'text',
				'submitter'     => false,
				'buttons'       => [],
				'attachments'   => false,
				'links'         => true,
				'subject'       => false,
				'pattern'       => false,
				'max_length'    => 0,
				'discover'      => false,
			],
			$capabilities
		);
	}

	/** Register an instance through the public function. Returns it. */
	public static function install( string $id = 'fake', array $capabilities = [], string $format = 'text' ): self {
		$channel = new self( $id, $capabilities, $format );
		fnfe_register_channel( $id, $channel );
		return $channel;
	}

	/** Forget messages and scripted results. */
	public static function reset(): void {
		self::$sent     = [];
		self::$results  = [];
		self::$problems = [];
		self::$pings    = [];
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Fake ' . $this->id;
	}

	public function description(): string {
		return 'Test channel.';
	}

	public function group(): string {
		return 'chat';
	}

	public function icon(): string {
		return 'send';
	}

	public function capabilities(): array {
		return $this->capabilities;
	}

	public function format(): string {
		return $this->format;
	}

	public function settings_schema(): array {
		return [
			[
				'id'     => $this->id . '_connection',
				'title'  => 'Connection',
				'fields' => [
					[
						'key'   => 'enabled',
						'type'  => 'toggle',
						'label' => 'Enabled',
					],
					[
						'key'   => 'token',
						'type'  => 'secret',
						'label' => 'Token',
					],
					[
						'key'     => 'mode',
						'type'    => 'select',
						'label'   => 'Mode',
						'default' => 'a',
						'options' => [
							'a' => 'A',
							'b' => 'B',
						],
					],
					[
						'key'       => 'greeting',
						'type'      => 'text',
						'label'     => 'Greeting',
						'localized' => true,
						'show_if'   => [
							[
								'key'   => 'mode',
								'op'    => 'is',
								'value' => 'b',
							],
						],
					],
					[
						'key'        => 'endpoints',
						'type'       => 'repeater',
						'label'      => 'Endpoints',
						'item_label' => 'name',
						'fields'     => [
							[
								'key'      => 'name',
								'type'     => 'text',
								'label'    => 'Name',
								'required' => true,
							],
							[
								'key'   => 'url',
								'type'  => 'url',
								'label' => 'URL',
							],
							[
								'key'   => 'sign_secret',
								'type'  => 'secret',
								'label' => 'Secret',
							],
						],
					],
					[
						'key'     => 'receivers',
						'type'    => 'receivers',
						'label'   => 'Receivers',
						'channel' => $this->id,
					],
					[
						'key'       => 'template.admin',
						'type'      => 'template',
						'label'     => 'Template',
						'localized' => true,
						'channel'   => $this->id,
					],
					[
						'key'  => 'hint',
						'type' => 'notice',
						'desc' => 'Only a hint.',
					],
				],
			],
		];
	}

	public function form_schema(): array {
		return [
			[
				'key'   => 'silent',
				'type'  => 'toggle',
				'label' => 'Silent',
			],
		];
	}

	public function is_enabled(): bool {
		return Settings::bool( "channels.{$this->id}.enabled" );
	}

	public function is_ready(): bool {
		return $this->is_enabled() && ! $this->problems();
	}

	public function problems(): array {
		return self::$problems;
	}

	/** "bad..." is invalid, everything else is lowercased. */
	public function normalize_address( string $raw, array $context = [] ): string {
		$raw = strtolower( trim( $raw ) );
		return strncmp( $raw, 'bad', 3 ) === 0 ? '' : $raw;
	}

	public function default_template( string $purpose ): string {
		return $purpose . ': {form_name}';
	}

	public function send( Message $message ): SendResult {
		self::$sent[] = $message;
		$result       = array_shift( self::$results );
		if ( $result instanceof \Throwable ) {
			throw $result;
		}
		return $result ?? SendResult::success( $this->id, 'fake ok', (string) count( self::$sent ) );
	}

	/** Echoes the token state so tests can see an overlay. */
	public function test( Recipient $to ): SendResult {
		$message          = new Message( $this->id, $to );
		$message->purpose = 'test';
		$message->body    = 'mode=' . Settings::str( "channels.{$this->id}.mode" ) . ' token=' . Settings::secret( "channels.{$this->id}.token" );
		return $this->send( $message );
	}

	public function actions(): array {
		return [
			'ping' => static function ( array $params ): array {
				self::$pings[] = $params;
				return [
					'ok'      => true,
					'message' => 'pong',
					'html'    => '<strong>ok</strong><script>1</script>',
					'rows'    => [ [ 'a' => 1 ] ],
					'columns' => [
						[
							'key'   => 'a',
							'label' => 'A',
						],
					],
				];
			},
			'boom' => static function (): array {
				throw new \RuntimeException( 'boom' );
			},
		];
	}
}
