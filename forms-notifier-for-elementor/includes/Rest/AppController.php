<?php
/**
 * REST routes of the mini app.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Logger;
use AmirhpCom\FormsNotifier\Core\RateLimit;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Request;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Delivery\Queue;
use AmirhpCom\FormsNotifier\Delivery\Stats;
use AmirhpCom\FormsNotifier\MiniApp\Access;
use AmirhpCom\FormsNotifier\MiniApp\InitData;
use AmirhpCom\FormsNotifier\MiniApp\Module;
use AmirhpCom\FormsNotifier\MiniApp\Session;
use AmirhpCom\FormsNotifier\Model\Submission;
use AmirhpCom\FormsNotifier\Repository\FormRepository;
use AmirhpCom\FormsNotifier\Repository\MessageRepository;
use AmirhpCom\FormsNotifier\Repository\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/** /app/auth is public and checks signed launch data; every other route needs the session token it hands out. */
final class AppController extends Controller {

	/** Statuses the app shows; the trash stays in the panel. */
	private const VISIBLE = [ 'new', 'read', 'done', 'spam' ];

	/** Message purposes of the app; test messages are noise there. */
	private const PURPOSES = [ 'admin', 'submitter', 'alert', 'digest' ];

	/** Keys of the statistics the app gets. */
	private const STATS = [ 'range', 'submissions', 'messages', 'by_channel', 'by_form', 'series' ];

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/app/auth',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'auth' ],
				// The signature of the launch data is the credential.
				'permission_callback' => '__return_true',
				'args'                => [
					'platform'  => array_merge( $this->string_arg( '', InitData::PLATFORMS, 'sanitize_key' ), [ 'required' => true ] ),
					// Signed byte by byte: cleaning it would break the signature. InitData checks length, syntax and hash.
					'init_data' => [
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 8192,
						'sanitize_callback' => static function ( $value ): string {
							return is_string( $value ) ? wp_check_invalid_utf8( $value ) : '';
						},
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
		$this->token_route( '/app/digest', 'digest', [ 'range' => $this->string_arg( '7d', [ 'today', '7d', '30d', 'all' ], 'sanitize_key' ) ] );
		$this->token_route(
			'/app/forms',
			'forms',
			[
				'search' => $this->string_arg(),
				'page'   => $this->page_arg(),
			]
		);
		$this->token_route(
			'/app/forms/(?P<id>\d+)/submissions',
			'submissions',
			[
				'id'     => $this->id_arg(),
				'status' => $this->string_arg( '', array_merge( [ '', 'all' ], self::VISIBLE ), 'sanitize_key' ),
				'search' => $this->string_arg(),
				'page'   => $this->page_arg(),
			]
		);
		$this->token_route( '/app/submissions/(?P<id>\d+)', 'submission', [ 'id' => $this->id_arg() ] );
		$this->token_route(
			'/app/submissions/(?P<id>\d+)/status',
			'set_status',
			[
				'id'     => $this->id_arg(),
				'status' => array_merge( $this->string_arg( '', self::VISIBLE, 'sanitize_key' ), [ 'required' => true ] ),
			],
			'act'
		);
		$this->token_route(
			'/app/messages',
			'messages',
			[
				'status' => $this->string_arg( '', [ '', 'all', 'queued', 'sending', 'retry', 'sent', 'failed', 'skipped', 'cancelled' ], 'sanitize_key' ),
				'page'   => $this->page_arg(),
			]
		);
		$this->token_route( '/app/messages/(?P<id>\d+)/retry', 'retry', [ 'id' => $this->id_arg() ], 'act' );

		// For the panel.
		register_rest_route(
			self::NS,
			'/app/setup',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( [ 'platforms' => Module::setup() ] );
				},
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [],
			]
		);
		register_rest_route(
			self::NS,
			'/app/revoke',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					Session::revoke_all();
					return rest_ensure_response( [ 'ok' => true ] );
				},
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [],
			]
		);
	}

	/**
	 * Exchange launch data for a session token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function auth( \WP_REST_Request $request ) {
		if ( ! Settings::bool( 'miniapp.enabled' ) ) {
			return $this->disabled();
		}
		if ( ! RateLimit::hit( 'app_auth_' . md5( Request::ip() ), 20, MINUTE_IN_SECONDS ) ) {
			return $this->error( 'rate_limited', __( 'Too many attempts. Wait a minute and try again.', 'forms-notifier-for-elementor' ), 429 );
		}

		$platform = (string) $request['platform'];
		$data     = InitData::validate( $platform, (string) $request['init_data'] );
		if ( ! $data['ok'] ) {
			Logger::warning(
				'miniapp',
				__( 'A sign-in to the mini app was refused.', 'forms-notifier-for-elementor' ),
				[
					'platform' => $platform,
					'reason'   => $data['error'],
				]
			);
			return $data['error'] === 'expired'
				? $this->error( 'app_expired', __( 'The session expired. Open the app again from the bot.', 'forms-notifier-for-elementor' ), 401 )
				: $this->error( 'app_invalid', __( 'The messenger could not be verified. Open the app from the bot.', 'forms-notifier-for-elementor' ), 401 );
		}

		$user    = $data['user'];
		$allowed = Access::allowed( $platform, $user['id'] );
		do_action( 'fnfe_miniapp_auth', $platform, $user, $allowed );
		if ( ! $allowed ) {
			return $this->error(
				'app_not_allowed',
				sprintf(
					/* translators: %s: numeric messenger user id */
					__( 'You do not have access to this app. Ask the site owner to add your id: %s', 'forms-notifier-for-elementor' ),
					$user['id']
				),
				403,
				[ 'user_id' => $user['id'] ]
			);
		}

		$perms   = Access::perms( $platform, $user['id'] );
		$session = Session::issue( $platform, $user['id'], $perms );
		return self::no_store(
			[
				'token'   => $session['token'],
				'expires' => $session['expires'],
				'user'    => [
					'id'       => $user['id'],
					'name'     => trim( $user['first_name'] . ' ' . $user['last_name'] ),
					'username' => $user['username'],
					'photo'    => $user['photo_url'],
				],
				'perms'   => $perms,
				'site'    => self::site(),
			]
		);
	}

	/**
	 * Numbers, health and facts about the site.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function digest( \WP_REST_Request $request ): \WP_REST_Response {
		$stats  = array_intersect_key( Stats::summary( (string) $request['range'] ), array_flip( self::STATS ) );
		$health = [];
		foreach ( Stats::health() as $check ) {
			$health[] = [
				'id'      => (string) ( $check['id'] ?? '' ),
				'label'   => wp_strip_all_tags( (string) ( $check['label'] ?? '' ) ),
				'status'  => in_array( $check['status'] ?? '', [ 'good', 'warning', 'critical' ], true ) ? $check['status'] : 'warning',
				'message' => wp_strip_all_tags( (string) ( $check['message'] ?? '' ) ),
			];
		}
		$stats['by_channel'] = (object) $stats['by_channel'];

		return self::no_store(
			[
				'stats'  => $stats,
				'health' => $health,
				'queue'  => [ 'pending' => Queue::pending() ],
				'site'   => array_merge( self::site(), self::site_facts() ),
			]
		);
	}

	/**
	 * Forms, most recent activity first.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function forms( \WP_REST_Request $request ): \WP_REST_Response {
		$list = FormRepository::query(
			[
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => self::page_size(),
				'orderby'  => 'last_submission_gmt',
				'order'    => 'desc',
			]
		);
		return self::private_list( $this->list_response( $list, [ self::class, 'app_form' ] ) );
	}

	/**
	 * Submissions of a form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submissions( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $id || ! FormRepository::find( $id ) ) {
			return $this->error( 'not_found', __( 'This form does not exist any more.', 'forms-notifier-for-elementor' ), 404 );
		}
		$status = (string) $request['status'];
		$list   = SubmissionRepository::query(
			[
				'form_id'  => $id,
				'status'   => in_array( $status, self::VISIBLE, true ) ? $status : self::VISIBLE,
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => self::page_size(),
			]
		);
		return self::private_list( $this->list_response( $list, [ self::class, 'submission_summary' ] ) );
	}

	/**
	 * One submission with its messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submission( \WP_REST_Request $request ) {
		$submission = $this->find( (int) $request['id'] );
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}
		$messages = array_filter(
			MessageRepository::for_submission( $submission->id ),
			static function ( array $row ): bool {
				return in_array( $row['purpose'], self::PURPOSES, true );
			}
		);
		return self::no_store(
			[
				'submission' => self::app_submission( $submission ),
				'messages'   => array_map( [ self::class, 'message_summary' ], array_values( $messages ) ),
			]
		);
	}

	/**
	 * Change the status of a submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_status( \WP_REST_Request $request ) {
		$submission = $this->find( (int) $request['id'] );
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}
		$old = $submission->status;
		$new = (string) $request['status'];
		if ( $new !== $old ) {
			if ( ! SubmissionRepository::set_status( $submission->id, $new ) ) {
				return $this->error( 'not_saved', __( 'The status could not be changed.', 'forms-notifier-for-elementor' ), 500 );
			}
			do_action( 'fnfe_submission_status_changed', $submission->id, $new, $old, 'app' );
			$submission->status = $new;
		}
		return self::no_store(
			[
				'ok'         => true,
				'submission' => self::app_submission( $submission ),
			]
		);
	}

	/**
	 * Messages; without a status the ones that need attention.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function messages( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) $request['status'];
		$list   = MessageRepository::query(
			[
				'status'   => $status === '' ? [ 'failed', 'retry' ] : ( $status === 'all' ? '' : $status ),
				'purpose'  => self::PURPOSES,
				'page'     => (int) $request['page'],
				'per_page' => self::page_size(),
			]
		);
		return self::private_list( $this->list_response( $list, [ self::class, 'message_summary' ] ) );
	}

	/**
	 * Send a message again now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( \WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = MessageRepository::find( $id );
		if ( ! $row || ! in_array( $row['purpose'], self::PURPOSES, true ) ) {
			return $this->error( 'not_found', __( 'This message does not exist any more.', 'forms-notifier-for-elementor' ), 404 );
		}
		if ( ! in_array( $row['status'], [ 'failed', 'retry' ], true ) || ! Queue::retry( $id ) ) {
			return $this->error( 'not_retryable', __( 'This message cannot be sent again.', 'forms-notifier-for-elementor' ), 409 );
		}
		Queue::run_ids( [ $id ] );
		return self::no_store(
			[
				'ok'      => true,
				'message' => self::message_summary( MessageRepository::find( $id ) ?: $row ),
			]
		);
	}

	/**
	 * FormSummary for the app: no link into wp-admin, plus the number of unread submissions.
	 *
	 * @param array $row Row of FormRepository.
	 */
	public static function app_form( array $row ): array {
		$form             = self::form_summary( $row );
		$form['edit_url'] = '';
		$form['unread']   = SubmissionRepository::count(
			[
				'form_id' => $form['id'],
				'status'  => 'new',
			]
		);
		return $form;
	}

	/**
	 * SubmissionFull for the app: signed file links, and no visitor details the screens do not show.
	 *
	 * @param Submission $submission Submission.
	 */
	public static function app_submission( Submission $submission ): array {
		$out = self::submission_full( $submission, true, HOUR_IN_SECONDS );

		$out['meta']['user_agent'] = '';
		if ( ! Settings::bool( 'miniapp.show_ip' ) ) {
			$out['meta']['user_ip'] = '';
		}
		return $out;
	}

	/**
	 * Register a route that needs a session.
	 *
	 * @param string $path   Route.
	 * @param string $method Method of this class.
	 * @param array  $args   Arg schemas.
	 * @param string $perm   view | act.
	 */
	private function token_route( string $path, string $method, array $args, string $perm = 'view' ): void {
		register_rest_route(
			self::NS,
			$path,
			[
				'methods'             => $perm === 'act' ? \WP_REST_Server::CREATABLE : \WP_REST_Server::READABLE,
				'callback'            => [ $this, $method ],
				'permission_callback' => function ( \WP_REST_Request $request ) use ( $perm ) {
					return $this->check_token( $request, $perm );
				},
				'args'                => $args,
			]
		);
	}

	/**
	 * Permission check of the app routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $perm    view | act.
	 * @return true|\WP_Error
	 */
	private function check_token( \WP_REST_Request $request, string $perm ) {
		if ( ! Settings::bool( 'miniapp.enabled' ) ) {
			return $this->disabled();
		}
		$session = Session::verify( (string) $request->get_header( 'x_fnfe_token' ) );
		if ( $session === null ) {
			return $this->error( 'app_session', __( 'The session expired. Open the app again from the bot.', 'forms-notifier-for-elementor' ), 401 );
		}
		if ( ! in_array( $perm, $session['perms'], true ) ) {
			return $this->error( 'app_forbidden', __( 'You can look at this, but you may not change it.', 'forms-notifier-for-elementor' ), 403 );
		}
		if ( $perm === 'act' && ! RateLimit::hit( 'app_act_' . md5( $session['platform'] . $session['uid'] ), 60, MINUTE_IN_SECONDS ) ) {
			return $this->error( 'rate_limited', __( 'Too many attempts. Wait a minute and try again.', 'forms-notifier-for-elementor' ), 429 );
		}
		return true;
	}

	/** The app is off. */
	private function disabled(): \WP_Error {
		return $this->error( 'app_disabled', __( 'The mini app is turned off on this site.', 'forms-notifier-for-elementor' ), 403 );
	}

	/**
	 * Submission that the app may show.
	 *
	 * @param int $id Submission id.
	 * @return Submission|\WP_Error
	 */
	private function find( int $id ) {
		$submission = SubmissionRepository::find( $id );
		if ( ! $submission || ! in_array( $submission->status, self::VISIBLE, true ) ) {
			return $this->error( 'not_found', __( 'This submission does not exist any more.', 'forms-notifier-for-elementor' ), 404 );
		}
		return $submission;
	}

	/** Public facts about the site. */
	private static function site(): array {
		return [
			'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'  => home_url( '/' ),
			'lang' => I18n::default_lang(),
			'rtl'  => is_rtl(),
		];
	}

	/** Facts for the digest. Everything is local: the update counts come from what WordPress already checked. */
	private static function site_facts(): array {
		$posts    = wp_count_posts();
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$updates  = 0;
		foreach ( [ 'update_plugins', 'update_themes' ] as $name ) {
			$checked  = get_site_transient( $name );
			$updates += is_object( $checked ) && isset( $checked->response ) ? count( (array) $checked->response ) : 0;
		}

		$channels = [];
		foreach ( Registry::channels() as $id => $channel ) {
			if ( $channel->is_enabled() ) {
				$channels[] = [
					'id'    => (string) $id,
					'label' => $channel->label(),
					'ready' => $channel->is_ready(),
				];
			}
		}

		return [
			'forms'    => FormRepository::query( [ 'per_page' => 1 ] )['total'],
			'unread'   => SubmissionRepository::count( [ 'status' => 'new' ] ),
			'total'    => SubmissionRepository::count( [ 'status' => self::VISIBLE ] ),
			'pages'    => (int) ( $pages->publish ?? 0 ),
			'posts'    => (int) ( $posts->publish ?? 0 ),
			'users'    => (int) get_user_count(),
			'comments' => (int) ( $comments->moderated ?? 0 ),
			'updates'  => $updates,
			'wp'       => get_bloginfo( 'version' ),
			'php'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'plugin'   => FNFE_VERSION,
			'theme'    => (string) wp_get_theme()->get( 'Name' ),
			'time'     => self::local_datetime( gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'fnfe_now', time() ) ) ),
			'channels' => $channels,
		];
	}

	/** Rows per list page. */
	private static function page_size(): int {
		return max( 5, min( 50, Settings::int( 'miniapp.page_size', 20 ) ) );
	}

	/** Arg schema of a path id. */
	private function id_arg(): array {
		return [
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		];
	}

	/** Arg schema of a page number. */
	private function page_arg(): array {
		return $this->paging_args()['page'];
	}

	/**
	 * Response that no cache may keep.
	 *
	 * @param array $data Data.
	 */
	private static function no_store( array $data ): \WP_REST_Response {
		return self::private_list( new \WP_REST_Response( $data ) );
	}

	/**
	 * Mark a response as private. The token is a header of its own, so a cache in front of the site must not guess.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private static function private_list( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
