<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Attachments;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** What may be sent or linked for a channel: nothing is sent yet. */
final class Policy {

	/**
	 * Effective policy for a channel. Filter: fnfe_attachment_policy.
	 *
	 * @param string          $channel Channel id.
	 * @param FormConfig|null $form    Form config.
	 */
	public static function resolve( string $channel, ?FormConfig $form = null ): array {
		$policy = Settings::arr( 'attachments' );
		unset( $policy['channels'] );
		return (array) apply_filters( 'fnfe_attachment_policy', $policy, $channel, $form );
	}

	/**
	 * Apply a policy to a submission's files for one channel.
	 *
	 * @param array            $policy            Policy.
	 * @param Submission       $s                 Submission.
	 * @param ChannelInterface $channel           Channel.
	 * @param int              $channel_max_bytes Upload limit of the channel, 0 = unknown.
	 * @return array{send:Attachment[],links:array,skipped:array}
	 */
	public static function apply( array $policy, Submission $s, ChannelInterface $channel, int $channel_max_bytes = 0 ): array {
		unset( $policy, $s, $channel, $channel_max_bytes );
		return [
			'send'    => [],
			'links'   => [],
			'skipped' => [],
		];
	}

	/**
	 * Type/extension check only.
	 *
	 * @param array      $policy Policy.
	 * @param Attachment $file   File.
	 */
	public static function allows( array $policy, Attachment $file ): bool {
		switch ( $policy['types'] ?? 'all' ) {
			case 'images':
				return $file->is_image();
			case 'custom':
				return in_array( $file->ext, (array) ( $policy['extensions'] ?? [] ), true );
		}
		return true;
	}
}
