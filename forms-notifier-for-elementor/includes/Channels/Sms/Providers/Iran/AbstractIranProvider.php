<?php
/**
 * Iranian gateway base.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Channels\Sms\Providers\Iran;

use AmirhpCom\FormsNotifier\Channels\Sms\Providers\AbstractSmsProvider;

defined( 'ABSPATH' ) || exit;

/** Iranian numbers only, with patterns. */
abstract class AbstractIranProvider extends AbstractSmsProvider {

	/** Region. */
	public function region(): string {
		return 'iran';
	}

	/** Countries. */
	public function countries(): array {
		return [ 'IR' ];
	}

	/** Patterns. */
	public function supports_pattern(): bool {
		return true;
	}
}
