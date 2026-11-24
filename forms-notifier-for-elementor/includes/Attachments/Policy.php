<?php
/**
 * Attachment policy.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Attachments;

use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Model\Attachment;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** What may be sent or linked for a channel. */
final class Policy {

	private const MODES = [ 'off', 'link', 'send', 'both' ];

	/**
	 * Effective policy for a channel: global, then the global mode of the channel, then the form.
	 * Filter: fnfe_attachment_policy.
	 *
	 * @param string          $channel Channel id, '' = no channel.
	 * @param FormConfig|null $form    Form config.
	 */
	public static function resolve( string $channel, ?FormConfig $form = null ): array {
		$policy = array_merge( self::defaults(), Settings::arr( 'attachments' ) );
		$modes  = is_array( $policy['channels'] ?? null ) ? $policy['channels'] : [];
		unset( $policy['channels'] );

		$mode = $modes[ $channel ] ?? 'inherit';
		if ( in_array( $mode, self::MODES, true ) ) {
			$policy['mode'] = $mode;
		}
		if ( $form ) {
			$own = (array) $form->get( 'attachments', [] );
			if ( in_array( $own['mode'] ?? 'inherit', self::MODES, true ) ) {
				$policy = array_merge( $policy, $own );
			}
		}
		$policy = apply_filters( 'fnfe_attachment_policy', $policy, $channel, $form );
		return array_merge( self::defaults(), is_array( $policy ) ? $policy : [] );
	}

	/**
	 * Apply a policy to a submission's files for one channel. Filter: fnfe_attachments.
	 *
	 * @param array            $policy            Policy.
	 * @param Submission       $s                 Submission.
	 * @param ChannelInterface $channel           Channel.
	 * @param int              $channel_max_bytes Upload limit of the channel, 0 = unknown.
	 * @return array{send:Attachment[],links:array<int,array{name:string,url:string,size:int}>,skipped:array<int,array{name:string,reason:string}>}
	 */
	public static function apply( array $policy, Submission $s, ChannelInterface $channel, int $channel_max_bytes = 0 ): array {
		$policy = array_merge( self::defaults(), $policy );
		$mode   = in_array( $policy['mode'], self::MODES, true ) ? $policy['mode'] : 'off';
		$result = [
			'send'    => [],
			'links'   => [],
			'skipped' => [],
		];

		if ( $mode !== 'off' && $s->files ) {
			$can    = $channel->capabilities();
			$upload = $mode !== 'link' && ! empty( $can['attachments'] );
			$limit  = max( 0, (int) $policy['max_mb'] ) * MB_IN_BYTES;
			if ( $channel_max_bytes > 0 ) {
				$limit = $limit > 0 ? min( $limit, $channel_max_bytes ) : $channel_max_bytes;
			}

			foreach ( $s->files as $file ) {
				if ( ! $file instanceof Attachment ) {
					continue;
				}
				$reason = self::problem( $policy, $file );
				if ( $reason === '' && $upload ) {
					$size = (int) filesize( $file->path );
					if ( $limit > 0 && $size > $limit ) {
						$reason = 'size';
					} elseif ( count( $result['send'] ) >= max( 1, (int) $policy['max_files'] ) ) {
						$reason = 'count';
					} else {
						$result['send'][] = $file;
						if ( $mode !== 'both' ) {
							continue;
						}
					}
				} elseif ( $reason !== '' ) {
					$result['skipped'][] = self::skipped( $file, $reason );
					continue;
				}

				$url = empty( $can['links'] ) ? '' : self::url( $policy, $file );
				if ( $url !== '' ) {
					$result['links'][] = [
						'name' => $file->name,
						'url'  => $url,
						'size' => $file->size,
					];
				} elseif ( $reason !== '' || ! in_array( $file, $result['send'], true ) ) {
					$result['skipped'][] = self::skipped( $file, $reason === '' ? 'no_link' : $reason );
				}
			}
		}

		// The message is rendered after the policy ran: the filter gets one that only names the channel and the submission.
		$message       = new Message( $channel->id(), new Recipient( $channel->id(), '' ) );
		$message->meta = [
			'submission_id' => $s->id,
			'form_id'       => $s->form_id,
			'form_key'      => $s->form_key,
		];

		$filtered = apply_filters( 'fnfe_attachments', $result, $message, $s );
		if ( ! is_array( $filtered ) ) {
			return $result;
		}
		foreach ( [ 'send', 'links', 'skipped' ] as $key ) {
			$result[ $key ] = array_values( is_array( $filtered[ $key ] ?? null ) ? $filtered[ $key ] : [] );
		}
		// A filter may not smuggle other files in.
		$result['send'] = array_values(
			array_filter(
				$result['send'],
				static function ( $file ): bool {
					return $file instanceof Attachment && PathGuard::is_safe( $file->path );
				}
			)
		);
		return $result;
	}

	/**
	 * Type/extension check only.
	 *
	 * @param array      $policy Policy.
	 * @param Attachment $file   File.
	 */
	public static function allows( array $policy, Attachment $file ): bool {
		if ( PathGuard::is_denied_ext( $file->ext ) || PathGuard::is_denied_ext( pathinfo( $file->name, PATHINFO_EXTENSION ) ) ) {
			return false;
		}
		switch ( $policy['types'] ?? 'all' ) {
			case 'images':
				return $file->is_image();
			case 'custom':
				return in_array( $file->ext, array_map( 'strtolower', array_filter( (array) ( $policy['extensions'] ?? [] ), 'is_string' ) ), true );
		}
		return true;
	}

	/**
	 * Uploads of this form are copied into the plugin's storage.
	 *
	 * @param FormConfig|null $form Form config.
	 */
	public static function keeps_copies( ?FormConfig $form = null ): bool {
		return Text::bool( self::resolve( '', $form )['keep_copy'] );
	}

	/** Every key of a policy. */
	private static function defaults(): array {
		return [
			'mode'          => 'link',
			'types'         => 'all',
			'extensions'    => [],
			'max_mb'        => 20,
			'max_files'     => 10,
			'keep_copy'     => true,
			'link_ttl_days' => 30,
		];
	}

	/**
	 * Reason a file is not usable at all, '' when it is.
	 *
	 * @param array      $policy Policy.
	 * @param Attachment $file   File.
	 */
	private static function problem( array $policy, Attachment $file ): string {
		if ( ! $file->exists() ) {
			return 'missing';
		}
		if ( ! PathGuard::is_safe( $file->path ) ) {
			return 'unsafe';
		}
		return self::allows( $policy, $file ) ? '' : 'type';
	}

	/**
	 * Download URL: signed for stored files, Elementor's own URL otherwise.
	 *
	 * @param array      $policy Policy.
	 * @param Attachment $file   File.
	 */
	private static function url( array $policy, Attachment $file ): string {
		if ( $file->stored && $file->id ) {
			return Storage::link( $file, max( 0, (int) $policy['link_ttl_days'] ) );
		}
		return esc_url_raw( $file->url, [ 'http', 'https' ] );
	}

	/**
	 * Entry of the skipped list.
	 *
	 * @param Attachment $file   File.
	 * @param string     $reason Reason.
	 */
	private static function skipped( Attachment $file, string $reason ): array {
		return [
			'name'   => $file->name,
			'reason' => $reason,
		];
	}
}
