<?php
/**
 * Template routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Channels\Sms\PatternText;
use AmirhpCom\FormsNotifier\Contracts\ChannelInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\FieldSanitizer;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Text;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\I18n\Strings;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;
use AmirhpCom\FormsNotifier\Template\Context;
use AmirhpCom\FormsNotifier\Template\DefaultTemplates;
use AmirhpCom\FormsNotifier\Template\Engine;
use AmirhpCom\FormsNotifier\Template\Tags;

defined( 'ABSPATH' ) || exit;

/** Preview and tag palette of the template editor, registration of texts for translation. */
final class TemplateController extends Controller {

	private const PURPOSES = [ 'admin', 'submitter', 'alert', 'digest', 'test' ];

	/** Register the routes. */
	public function register_routes(): void {
		$id = [
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];

		register_rest_route(
			self::NS,
			'/template/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => $this->can( Capabilities::FORMS, Capabilities::MANAGE ),
				'args'                => [
					'template'      => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ FieldSanitizer::class, 'template' ],
						'validate_callback' => 'rest_validate_request_arg',
					],
					'subject'       => $this->string_arg(),
					'channel'       => $this->string_arg( '', [], 'sanitize_key' ),
					'purpose'       => $this->string_arg( 'admin', self::PURPOSES ),
					'form_id'       => $id,
					'submission_id' => $id,
					'lang'          => $this->string_arg(),
					'buttons'       => [
						'type'              => 'array',
						'default'           => [],
						'sanitize_callback' => [ FieldSanitizer::class, 'buttons' ],
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/template/tags',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'tags' ],
				'permission_callback' => $this->can( Capabilities::FORMS, Capabilities::MANAGE ),
				'args'                => [
					'form_id' => $id,
					'purpose' => $this->string_arg( 'admin', array_merge( [ '' ], self::PURPOSES ) ),
				],
			]
		);

		register_rest_route(
			self::NS,
			'/strings/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'sync' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [],
			]
		);
	}

	/**
	 * Tag catalogue.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function tags( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( [ 'items' => Tags::catalog( (int) $request['form_id'], (string) $request['purpose'] ) ] );
	}

	/** Register every text with the multilingual plugin. */
	public function sync(): \WP_REST_Response {
		$provider = I18n::provider();
		Strings::forget();
		return rest_ensure_response(
			[
				'ok'         => $provider !== '',
				'provider'   => $provider,
				'registered' => $provider === '' ? 0 : Strings::register_all(),
			]
		);
	}

	/**
	 * Render a template the way its channel would.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$submission = null;
		if ( $request['submission_id'] ) {
			if ( ! Capabilities::can( Capabilities::SUBMISSIONS ) ) {
				return $this->error( 'forbidden', __( 'You do not have access to this.', 'forms-notifier-for-elementor' ), rest_authorization_required_code() );
			}
			$submission = SubmissionRepository::find( (int) $request['submission_id'] );
			if ( ! $submission ) {
				return $this->error( 'not_found', __( 'This submission does not exist.', 'forms-notifier-for-elementor' ), 404 );
			}
		}
		$lang = (string) $request['lang'];
		$lang = array_key_exists( $lang, I18n::languages() ) ? $lang : '';

		return I18n::with_language(
			$lang,
			function () use ( $request, $submission, $lang ) {
				return rest_ensure_response( $this->build( $request, $submission, $lang ) );
			}
		);
	}

	/**
	 * Preview data in the active language.
	 *
	 * @param \WP_REST_Request                                   $request    Request.
	 * @param \AmirhpCom\FormsNotifier\Model\Submission|null $submission Submission.
	 * @param string                                             $lang       Language.
	 */
	private function build( \WP_REST_Request $request, $submission, string $lang ): array {
		$channel   = Registry::channel( (string) $request['channel'] );
		$purpose   = (string) $request['purpose'];
		$form_id   = $submission ? $submission->form_id : (int) $request['form_id'];
		$s         = $submission ?? Engine::sample_submission( $form_id, $lang );
		$context   = Context::for_submission( $s, $lang, $channel ? $channel->id() : '', $purpose );
		$caps      = $channel ? $channel->capabilities() : [];
		$format    = $channel ? $channel->format() : 'text';
		$formatter = Registry::formatter( $format );
		$template  = (string) $request['template'];
		$subject   = (string) $request['subject'];
		if ( $form_id ) {
			$context->form = FormRepository::config( $form_id );
		}
		$context->extra = self::sample_extra( $purpose );

		if ( trim( $template ) === '' ) {
			$template = $channel ? Settings::text( 'channels.' . $channel->id() . '.template.' . $purpose, $lang ) : '';
			if ( $template === '' ) {
				$template = $channel ? $channel->default_template( $purpose ) : DefaultTemplates::for_format( 'json', $purpose );
			}
		}
		if ( $subject === '' && $channel && ! empty( $caps['subject'] ) ) {
			$subject = Settings::text( 'channels.' . $channel->id() . '.subject.' . $purpose, $lang );
		}
		$subject = $subject === '' ? DefaultTemplates::subject( $purpose ) : $subject;

		$warnings = [];
		foreach ( Engine::validate( $template ) as $problem ) {
			/* translators: %1$d: line number, %2$s: problem */
			$warnings[] = sprintf( __( 'Line %1$d: %2$s', 'forms-notifier-for-elementor' ), $problem['line'], $problem['message'] );
		}

		$pattern = ! empty( $caps['pattern'] ) && class_exists( PatternText::class ) ? PatternText::parse( $template ) : null;
		if ( $pattern ) {
			$lines = [ (string) $pattern['code'] ];
			foreach ( $pattern['vars'] as $name => $value ) {
				$lines[] = $name . ': ' . Engine::render_inline( $value, $context );
			}
			$plain = Engine::render( (string) $pattern['text'], $context, 'text' );
			$text  = implode( "\n", $lines ) . ( $plain === '' ? '' : "\n---\n" . $plain );
		} else {
			$text  = Engine::render( $template, $context, $format );
			$plain = Engine::render( $template, $context, 'text' );
		}

		$limit = (int) ( $caps['max_length'] ?? 0 );
		$limit = $limit > 0 ? $limit : $formatter->limit();
		$parts = $formatter->split( $text, $limit );
		if ( $text === '' ) {
			$warnings[] = __( 'The message is empty.', 'forms-notifier-for-elementor' );
		} elseif ( count( $parts ) > 1 ) {
			/* translators: %1$d: number of messages */
			$warnings[] = sprintf( _n( 'The text is long and is sent as %1$d message.', 'The text is long and is sent as %1$d messages.', count( $parts ), 'forms-notifier-for-elementor' ), count( $parts ) );
		}

		return [
			'text'     => $text,
			'parts'    => $parts,
			'length'   => $formatter->length( $text ),
			'limit'    => $limit,
			'plain'    => $plain,
			'subject'  => Engine::render_inline( $subject, $context ),
			'buttons'  => self::buttons( (array) $request['buttons'], $context, $channel, $warnings ),
			'warnings' => $warnings,
		];
	}

	/**
	 * Rendered buttons as rows.
	 *
	 * @param array                 $buttons  Definitions.
	 * @param Context               $context  Context.
	 * @param ChannelInterface|null $channel  Channel.
	 * @param string[]              $warnings Warnings.
	 */
	private static function buttons( array $buttons, Context $context, ?ChannelInterface $channel, array &$warnings ): array {
		$types = $channel ? (array) ( $channel->capabilities()['buttons'] ?? [] ) : [ 'url', 'callback', 'web_app', 'copy' ];
		$rows  = [];
		foreach ( $buttons as $button ) {
			$text = Engine::render_inline( $button['text'], $context );
			$type = $button['type'] === 'submission' ? 'url' : $button['type'];
			if ( $text === '' ) {
				$warnings[] = __( 'A button without text is left out.', 'forms-notifier-for-elementor' );
				continue;
			}
			if ( ! in_array( $type, $types, true ) ) {
				/* translators: %1$s: button text */
				$warnings[] = sprintf( __( 'This channel cannot show the button "%1$s".', 'forms-notifier-for-elementor' ), $text );
				continue;
			}
			$rows[ (int) $button['row'] ][] = [
				'text'  => $text,
				'type'  => $type,
				'value' => self::button_value( $button, $context ),
			];
		}
		ksort( $rows );
		return array_values( $rows );
	}

	/**
	 * Rendered target of a button.
	 *
	 * @param array   $button  Definition.
	 * @param Context $context Context.
	 */
	private static function button_value( array $button, Context $context ): string {
		$value = (string) $button['value'];
		switch ( $button['type'] ) {
			case 'submission':
				return Urls::submission_admin( $context->submission->id );
			case 'url':
				return Engine::render_inline( $value, $context, true );
			case 'copy':
				return Text::truncate( Engine::render_inline( $value, $context ), 256 );
			case 'web_app':
				return Urls::miniapp( $context->channel === 'bale' ? 'bale' : 'telegram', $value );
		}
		return $value;
	}

	/**
	 * Example values of the system tags.
	 *
	 * @param string $purpose Purpose.
	 */
	private static function sample_extra( string $purpose ): array {
		if ( $purpose === 'alert' ) {
			return [
				'alert.channel'   => 'Telegram',
				'alert.recipient' => __( 'Sales group', 'forms-notifier-for-elementor' ),
				'alert.error'     => __( 'The service could not be reached.', 'forms-notifier-for-elementor' ),
				'alert.attempts'  => '5',
				'alert.log_url'   => Urls::admin( 'log' ),
			];
		}
		if ( $purpose === 'digest' ) {
			return [
				'digest.period'      => Tags::format_date( new \DateTimeImmutable( '@' . (int) apply_filters( 'fnfe_now', time() ) ), '', Context::bare() ),
				'digest.submissions' => '12',
				'digest.sent'        => '31',
				'digest.failed'      => '1',
				'digest.forms'       => __( 'Contact: 9', 'forms-notifier-for-elementor' ) . "\n" . __( 'Callback: 3', 'forms-notifier-for-elementor' ),
				'digest.health'      => __( 'Everything works.', 'forms-notifier-for-elementor' ),
				'digest.queue'       => '2',
			];
		}
		return [];
	}
}
