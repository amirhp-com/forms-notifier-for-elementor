<?php
/**
 * Planned delivery.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Model;

defined( 'ABSPATH' ) || exit;

/** A planned delivery before rendering (output of Delivery\Resolver). */
final class Delivery {

	/** @var string */
	public string $channel = '';

	/** @var Recipient */
	public Recipient $recipient;

	/** @var string */
	public string $purpose = 'admin';

	/** @var string */
	public string $lang = '';

	/** @var string|array localized text or string: template to render */
	public $template = '';

	/** @var string|array localized text or string: subject */
	public $subject = '';

	/** @var array button definitions */
	public array $buttons = [];

	/** @var array channel form options merged (global <- form <- editor) */
	public array $options = [];

	/** @var array resolved attachment policy */
	public array $attachment_policy = [];

	/** @var string routing rule that produced it, '' otherwise */
	public string $rule_id = '';

	/**
	 * @param string    $channel   Channel id.
	 * @param Recipient $recipient Recipient.
	 * @param string    $purpose   Purpose.
	 */
	public function __construct( string $channel, Recipient $recipient, string $purpose = 'admin' ) {
		$this->channel   = $channel;
		$this->recipient = $recipient;
		$this->purpose   = $purpose;
	}
}
