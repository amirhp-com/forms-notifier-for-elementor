<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Render context. */
final class Context {

	/** @var Submission */
	public Submission $submission;

	/** @var string */
	public string $lang = '';

	/** @var string */
	public string $channel = '';

	/** @var string */
	public string $purpose = 'admin';

	/** @var Recipient|null */
	public ?Recipient $recipient = null;

	/** @var FormConfig|null */
	public ?FormConfig $form = null;

	/** @var array extra tag values supplied by the caller: tag name => raw string */
	public array $extra = [];

	/**
	 * Context of a submission.
	 *
	 * @param Submission $s       Submission.
	 * @param string     $lang    Language.
	 * @param string     $channel Channel id.
	 * @param string     $purpose Purpose.
	 */
	public static function for_submission( Submission $s, string $lang = '', string $channel = '', string $purpose = 'admin' ): self {
		$context             = new self();
		$context->submission = $s;
		$context->lang       = $lang;
		$context->channel    = $channel;
		$context->purpose    = $purpose;
		return $context;
	}

	/**
	 * Synthetic context without a submission (digest, alert, test); fields are empty.
	 *
	 * @param array  $extra Tag values.
	 * @param string $lang  Language.
	 */
	public static function bare( array $extra = [], string $lang = '' ): self {
		$context             = new self();
		$context->submission = new Submission();
		$context->lang       = $lang;
		$context->extra      = $extra;
		return $context;
	}
}
