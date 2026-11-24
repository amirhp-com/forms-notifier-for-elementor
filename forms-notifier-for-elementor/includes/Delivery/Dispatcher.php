<?php
/**
 * Stub: replaced in Phase 1
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Delivery;

use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Model\Delivery;
use AmirhpCom\FormsNotifier\Model\FormConfig;
use AmirhpCom\FormsNotifier\Model\Message;
use AmirhpCom\FormsNotifier\Model\SendResult;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\Engine;

defined( 'ABSPATH' ) || exit;

/** Delivery -> rendered message -> queue row. */
final class Dispatcher {

	/**
	 * Plan, render and enqueue everything for a submission.
	 *
	 * @param Submission $s             Submission.
	 * @param FormConfig $form          Form config.
	 * @param string[]   $only_channels Restrict to these channel ids.
	 * @return int[] fnfe_messages ids
	 */
	public static function dispatch( Submission $s, FormConfig $form, array $only_channels = [] ): array {
		$ids = [];
		foreach ( Resolver::plan( $s, $form, $only_channels ) as $delivery ) {
			$ids[] = Queue::push( self::build( $delivery, $s, $form ) );
		}
		return $ids;
	}

	/**
	 * Render one delivery into a message (no queueing).
	 *
	 * @param Delivery   $d    Delivery.
	 * @param Submission $s    Submission.
	 * @param FormConfig $form Form config.
	 */
	public static function build( Delivery $d, Submission $s, FormConfig $form ): Message {
		$channel  = Registry::channel( $d->channel );
		$context  = Context::for_submission( $s, $d->lang, $d->channel, $d->purpose );
		$template = $d->template;
		if ( Settings::localize( $template, $d->lang ) === '' ) {
			$template = $channel ? $channel->default_template( $d->purpose ) : '{all_fields}';
		}
		$context->form      = $form;
		$context->recipient = $d->recipient;

		$message          = new Message( $d->channel, $d->recipient );
		$message->purpose = $d->purpose;
		$message->lang    = $d->lang;
		$message->format  = $channel ? $channel->format() : 'text';
		$message->subject = Engine::render_inline( $d->subject, $context );
		$message->body    = Engine::render( $template, $context, $message->format );
		$message->plain   = Engine::render( $template, $context, 'text' );
		$message->options = $d->options;
		$message->meta    = [
			'submission_id' => $s->id,
			'form_id'       => $s->form_id,
			'form_key'      => $s->form_key,
			'rule_id'       => $d->rule_id,
		];
		return apply_filters( 'fnfe_message', $message, $d, $s );
	}

	/**
	 * Send a message right now through its channel, bypassing the queue, and log it.
	 *
	 * @param Message $m Message.
	 */
	public static function send_now( Message $m ): SendResult {
		$id     = MessageRepository::insert( Queue::row( $m, 'sending' ) );
		$result = Queue::deliver( $m );
		Queue::finish( $id, $result );
		return $result;
	}
}
