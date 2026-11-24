<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Recipient;
use AmirhpCom\FormsNotifier\Model\Submission;

defined( 'ABSPATH' ) || exit;

/** Routing: global receivers of enabled channels only. */
final class Resolver {

	/**
	 * Decide who gets what for a submission.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids ([] = all).
	 * @return Delivery[]
	 */
	public static function plan( Submission $s, FormConfig $form, array $only_channels = [] ): array {
		$plan = [];
		foreach ( Registry::channels() as $id => $channel ) {
			if ( ! $channel->is_enabled() || ( $only_channels && ! in_array( $id, $only_channels, true ) ) ) {
				continue;
			}
			foreach ( self::receivers( $id, $form ) as $receiver ) {
				$recipient       = new Recipient( $id, (string) $receiver['address'], (string) $receiver['label'] );
				$recipient->lang = (string) $receiver['lang'];
				$recipient->meta = array_merge( (array) $receiver['meta'], [ 'rid' => $receiver['id'] ] );

				$delivery           = new Delivery( $id, $recipient );
				$delivery->lang     = $recipient->lang === '' ? I18n::default_lang() : $recipient->lang;
				$delivery->template = self::template( $id, $form );
				$plan[]             = $delivery;
			}
		}
		return (array) apply_filters( 'fnfe_deliveries', $plan, $s, $form );
	}

	/**
	 * Is this form notifying at all.
	 *
	 * @param FormConfig $form   Form config.
	 * @param array      $editor Editor overrides.
	 */
	public static function form_active( FormConfig $form, array $editor = [] ): bool {
		$mode = (string) ( $editor['fnfe_enabled'] ?? '' );
		if ( $mode === 'no' || $form->enabled() === 'off' ) {
			return false;
		}
		return $mode === 'yes' || $form->enabled() === 'on' || Settings::str( 'general.capture_mode', 'all' ) === 'all';
	}

	/**
	 * Effective receivers of one channel for a form.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 */
	public static function receivers( string $channel, FormConfig $form ): array {
		unset( $form );
		return array_values(
			array_filter(
				Settings::arr( "channels.$channel.receivers" ),
				static function ( $receiver ): bool {
					return is_array( $receiver ) && ! empty( $receiver['enabled'] );
				}
			)
		);
	}

	/**
	 * Effective template source for channel/purpose (localized value).
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 * @param string     $purpose Purpose.
	 * @return string|array
	 */
	public static function template( string $channel, FormConfig $form, string $purpose = 'admin' ) {
		unset( $form );
		return Settings::get( "channels.$channel.template.$purpose", '' );
	}

	/**
	 * Effective buttons of one channel for a form.
	 *
	 * @param string     $channel Channel id.
	 * @param FormConfig $form    Form config.
	 */
	public static function buttons( string $channel, FormConfig $form ): array {
		unset( $form );
		return Settings::arr( "channels.$channel.buttons" );
	}
}
