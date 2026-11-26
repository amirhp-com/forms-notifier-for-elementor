<?php
/**
 * Render context.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Template;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** What a template is rendered for. */
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

	/** @var array resolved attachment policy; [] = the one of the channel and form */
	public array $policy = [];

	/** @var array|null file links of the message, resolved once; null = resolve them when a tag needs them */
	public ?array $links = null;

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

	/** Render language. */
	public function language(): string {
		return $this->lang === '' ? I18n::current() : $this->lang;
	}

	/** The render language is Persian. */
	public function is_persian(): bool {
		return $this->language() === 'fa' || strncmp( $this->language(), 'fa-', 3 ) === 0 || strncmp( $this->language(), 'fa_', 3 ) === 0;
	}
}
