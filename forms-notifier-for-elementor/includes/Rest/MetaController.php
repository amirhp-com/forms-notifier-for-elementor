<?php
/**
 * Bootstrap and schema routes.
 *
 * @package FormsNotifier
 */

namespace AmirhpCom\FormsNotifier\Rest;

use AmirhpCom\FormsNotifier\Contracts\ProviderInterface;
use AmirhpCom\FormsNotifier\Core\Capabilities;
use AmirhpCom\FormsNotifier\Core\I18n;
use AmirhpCom\FormsNotifier\Core\Registry;
use AmirhpCom\FormsNotifier\Core\Schema;
use AmirhpCom\FormsNotifier\Core\Settings;
use AmirhpCom\FormsNotifier\Core\Urls;
use AmirhpCom\FormsNotifier\Phone\DialCodes;
use AmirhpCom\FormsNotifier\Phone\Phone;

defined( 'ABSPATH' ) || exit;

/** GET /bootstrap, POST /bootstrap/ui, GET /schema. */
final class MetaController extends Controller {

	/** Themes a user may pick. */
	private const THEMES = [ 'auto', 'light', 'dark' ];

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/bootstrap',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( MetaController::bootstrap() );
				},
				'permission_callback' => $this->can( ...Capabilities::all() ),
				'args'                => [],
			]
		);
		register_rest_route(
			self::NS,
			'/bootstrap/ui',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'save_ui' ],
				'permission_callback' => $this->can( ...Capabilities::all() ),
				'args'                => [
					'theme'     => [
						'type'              => 'string',
						'enum'              => self::THEMES,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'dismissed' => [
						'type'              => 'array',
						'items'             => [ 'type' => 'string' ],
						'sanitize_callback' => static function ( $value ): array {
							return array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) ), 0, 200 );
						},
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/schema',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'schema' ],
				'permission_callback' => $this->can( Capabilities::MANAGE ),
				'args'                => [ 'tab' => $this->string_arg( '', [], 'sanitize_key' ) ],
			]
		);
	}

	/**
	 * Save UI preferences of the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function save_ui( \WP_REST_Request $request ): \WP_REST_Response {
		$ui = self::ui();
		if ( $request->get_param( 'theme' ) !== null ) {
			$ui['theme'] = $request->get_param( 'theme' );
		}
		if ( $request->get_param( 'dismissed' ) !== null ) {
			$ui['dismissed'] = $request->get_param( 'dismissed' );
		}
		update_user_meta( get_current_user_id(), 'fnfe_ui', $ui );
		return rest_ensure_response(
			[
				'ok' => true,
				'ui' => $ui,
			]
		);
	}

	/**
	 * Tabs and sections.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function schema( \WP_REST_Request $request ): \WP_REST_Response {
		$tab = (string) $request->get_param( 'tab' );
		return rest_ensure_response(
			[
				'tabs'     => (object) Schema::tabs(),
				'sections' => self::client_sections( $tab === '' ? Schema::sections() : Schema::sections_for( $tab ) ),
			]
		);
	}

	/** Bootstrap object of the admin panel. */
	public static function bootstrap(): array {
		$user      = wp_get_current_user();
		$channels  = array_map( [ self::class, 'channel_meta' ], array_values( Registry::channels() ) );
		$languages = array_values( I18n::languages() );
		$sources   = [];
		foreach ( Registry::form_sources() as $id => $source ) {
			$sources[] = [
				'id'        => $id,
				'label'     => $source->label(),
				'available' => $source->is_available(),
			];
		}
		$atomic = Registry::form_sources()['atomic'] ?? null;

		return [
			'version'  => FNFE_VERSION,
			'rest'     => [
				'root'  => Urls::rest(),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'urls'     => [
				'admin'                 => Urls::admin(),
				'assets'                => FNFE_URL . 'assets/',
				'help'                  => Urls::admin( 'help' ),
				'elementor_submissions' => defined( 'ELEMENTOR_PRO_VERSION' ) ? admin_url( 'admin.php?page=e-form-submissions' ) : '',
			],
			'user'     => [
				'id'     => (int) $user->ID,
				'name'   => (string) $user->display_name,
				'locale' => get_user_locale(),
				'caps'   => [
					'manage'      => Capabilities::can( Capabilities::MANAGE ),
					'forms'       => Capabilities::can( Capabilities::FORMS ),
					'submissions' => Capabilities::can( Capabilities::SUBMISSIONS ),
					'logs'        => Capabilities::can( Capabilities::LOGS ),
				],
				'ui'     => self::ui(),
			],
			'site'     => [
				'name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'      => home_url( '/' ),
				'lang'     => I18n::default_lang(),
				'locale'   => I18n::site_locale(),
				'rtl'      => is_rtl(),
				'timezone' => wp_timezone_string(),
				'calendar' => self::calendar(),
			],
			'i18n'     => [
				'provider'  => I18n::provider(),
				'default'   => I18n::default_lang(),
				'languages' => $languages,
			],
			'channels' => $channels,
			'groups'   => self::groups(),
			'tabs'     => (object) Schema::tabs(),
			'sources'  => $sources,
			'features' => [
				'elementor_pro'     => defined( 'ELEMENTOR_PRO_VERSION' ),
				'atomic_forms'      => $atomic ? $atomic->is_available() : false,
				'action_scheduler'  => function_exists( 'as_schedule_recurring_action' ),
				'pretty_permalinks' => (bool) get_option( 'permalink_structure' ),
				'https'             => is_ssl() || strncmp( home_url(), 'https://', 8 ) === 0,
				'miniapp'           => Settings::bool( 'miniapp.enabled' ),
			],
			'options'  => [
				'countries'          => self::countries(),
				'sms_providers'      => self::provider_options( 'sms' ),
				'whatsapp_providers' => self::provider_options( 'whatsapp' ),
				'roles'              => self::roles(),
			],
			'fonts'    => [ 'fa' => strpos( get_user_locale(), 'fa' ) === 0 ],
			'routes'   => self::routes(),
		];
	}

	/** UI preferences of the current user. */
	public static function ui(): array {
		$stored = get_user_meta( get_current_user_id(), 'fnfe_ui', true );
		$stored = is_array( $stored ) ? $stored : [];
		$theme  = $stored['theme'] ?? 'auto';

		$stored['theme']     = in_array( $theme, self::THEMES, true ) ? $theme : 'auto';
		$stored['dismissed'] = array_values( array_filter( (array) ( $stored['dismissed'] ?? [] ), 'is_string' ) );
		return $stored;
	}

	/** Main routes of the panel. 'cap' is a key of user.caps or 'any'. */
	private static function routes(): array {
		$routes = [
			[ 'dashboard', __( 'Dashboard', 'forms-notifier-for-elementor' ), 'dashboard', 'any' ],
			[ 'channels', __( 'Channels', 'forms-notifier-for-elementor' ), 'channels', 'manage' ],
			[ 'forms', __( 'Forms', 'forms-notifier-for-elementor' ), 'forms', 'forms' ],
			[ 'submissions', __( 'Submissions', 'forms-notifier-for-elementor' ), 'inbox', 'submissions' ],
			[ 'log', __( 'Log', 'forms-notifier-for-elementor' ), 'log', 'logs' ],
			[ 'settings', __( 'Settings', 'forms-notifier-for-elementor' ), 'settings', 'manage' ],
			[ 'help', __( 'Help', 'forms-notifier-for-elementor' ), 'help', 'any' ],
		];
		return array_map(
			static function ( array $route ): array {
				return [
					'path'  => $route[0],
					'label' => $route[1],
					'icon'  => $route[2],
					'cap'   => $route[3],
				];
			},
			$routes
		);
	}

	/** Channel groups. */
	private static function groups(): array {
		return [
			'messenger'  => __( 'Messengers', 'forms-notifier-for-elementor' ),
			'sms'        => __( 'SMS', 'forms-notifier-for-elementor' ),
			'email'      => __( 'Email', 'forms-notifier-for-elementor' ),
			'automation' => __( 'Automation', 'forms-notifier-for-elementor' ),
			'chat'       => __( 'Team chat', 'forms-notifier-for-elementor' ),
			'push'       => __( 'Push notifications', 'forms-notifier-for-elementor' ),
		];
	}

	/** Countries with dial codes. */
	private static function countries(): array {
		if ( is_callable( [ DialCodes::class, 'all' ] ) ) {
			$codes = (array) DialCodes::all();
		} else {
			$codes = [];
			foreach ( [ 'AE', 'AF', 'DE', 'FR', 'GB', 'IQ', 'IR', 'TR', 'US' ] as $iso2 ) {
				$codes[ $iso2 ] = Phone::dial_for( $iso2 );
			}
			$codes = (array) apply_filters( 'fnfe_dial_codes', $codes );
		}

		$out = [];
		foreach ( $codes as $iso2 => $dial ) {
			$name = $iso2;
			if ( class_exists( '\Locale' ) ) {
				$display = \Locale::getDisplayRegion( '-' . $iso2, determine_locale() );
				$name    = is_string( $display ) && $display !== '' ? $display : $iso2;
			}
			$out[] = [
				'value' => (string) $iso2,
				/* translators: %1$s: country name, %2$s: international dialing code */
				'label' => sprintf( __( '%1$s (+%2$s)', 'forms-notifier-for-elementor' ), $name, $dial ),
			];
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcoll( $a['label'], $b['label'] );
			}
		);
		return $out;
	}

	/**
	 * Providers of a channel as options.
	 *
	 * @param string $channel Channel id.
	 */
	private static function provider_options( string $channel ): array {
		return array_values(
			array_map(
				static function ( ProviderInterface $provider ): array {
					return [
						'value' => $provider->id(),
						'label' => $provider->label(),
						'group' => $provider->region(),
					];
				},
				Registry::providers( $channel )
			)
		);
	}

	/** User roles as options. */
	private static function roles(): array {
		$out = [];
		foreach ( wp_roles()->get_names() as $value => $label ) {
			$out[] = [
				'value' => (string) $value,
				'label' => translate_user_role( $label ),
			];
		}
		return $out;
	}
}
