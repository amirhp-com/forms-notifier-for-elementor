<?php
/**
 * Delivery channel contract.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Contracts;

use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\SendResult;

defined( 'ABSPATH' ) || exit;

interface ChannelInterface {

	/** Unique slug, [a-z0-9_]+. Also the settings root: channels.{id}. */
	public function id(): string;

	/** Translated display name. */
	public function label(): string;

	/** One translated sentence for the channel tile. */
	public function description(): string;

	/** Group for the Channels screen: 'messenger' | 'sms' | 'email' | 'automation' | 'chat' | 'push'. */
	public function group(): string;

	/** Icon key from the admin icon set or a raw 24x24 SVG path "d" string starting with "M". */
	public function icon(): string;

	/**
	 * Feature flags used by the Resolver, Dispatcher and UI. All keys required:
	 *   'receivers'     => 'list' | 'none'   list = stored receivers picked per form; none = single implicit target
	 *   'receiver_kind' => 'chat' | 'phone' | 'email' | 'endpoint' | 'text' | 'secret'
	 *                      (input widget + validation used by the picker; 'secret' = the address itself is a credential
	 *                      such as a webhook URL: it is kept in the secret store)
	 *   'submitter'     => bool   can address the person who submitted (needs a phone/email field)
	 *   'buttons'       => string[]  supported button types out of url, callback, web_app, copy
	 *   'attachments'   => bool   can upload files
	 *   'links'         => bool   can show file links
	 *   'subject'       => bool   has a subject/title line
	 *   'pattern'       => bool   understands pattern text
	 *   'max_length'    => int    hard limit of one message body in characters, 0 = none
	 *   'discover'      => bool   has a discovery source for receivers (bots)
	 */
	public function capabilities(): array;

	/** Canonical format the body must be rendered in. May depend on settings. */
	public function format(): string;

	/**
	 * Settings sections for the channel screen. Field keys are RELATIVE to channels.{id} (e.g. 'token').
	 * AbstractChannel prepends the standard sections (enable, receivers, templates, buttons).
	 */
	public function settings_schema(): array;

	/** Per-form option fields (flat list of field arrays, keys relative to the route's 'options'). [] if none. */
	public function form_schema(): array;

	/** Switched on in settings. */
	public function is_enabled(): bool;

	/** Enabled AND has everything needed to send. */
	public function is_ready(): bool;

	/** Translated human readable problems ([] when ready), shown on the channel tile and in Site Health. */
	public function problems(): array;

	/**
	 * Normalise a raw destination typed by an admin or taken from a form field.
	 *
	 * @param string $raw     Raw destination.
	 * @param array  $context Optional hints: 'country' (ISO2 or dial code), 'submission' (Submission).
	 * @return string canonical address, '' when invalid
	 */
	public function normalize_address( string $raw, array $context = [] ): string;

	/**
	 * Default template (canonical markup, English source passed through __()) for a purpose.
	 *
	 * @param string $purpose Purpose.
	 */
	public function default_template( string $purpose ): string;

	/**
	 * Send one message. Must never throw: catch \Throwable and return a failure. Must not sleep longer than 2 s.
	 *
	 * @param Message $message Rendered message.
	 */
	public function send( Message $message ): SendResult;

	/**
	 * Send a short test message to a recipient (used by the Test button).
	 *
	 * @param Recipient $to Recipient.
	 */
	public function test( Recipient $to ): SendResult;

	/**
	 * Extra admin actions for the channel screen: [ action_id => callable( array $params ): array ActionResult ].
	 * Called through POST /channels/{id}/action/{action_id}.
	 * Reserved action id 'receiver_options': channels with capability 'discover' (or receiver_kind 'endpoint') implement it;
	 * params [ 'search' => string, 'type' => string, 'page' => int ]; result rows are
	 * [ 'address' => string, 'label' => string, 'group' => string, 'hint' => string, 'meta' => array ].
	 */
	public function actions(): array;
}
